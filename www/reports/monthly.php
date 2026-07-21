<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/security.php';

if (!function_exists('getMonthNameArabic')) {
    function getMonthNameArabic($month) {
        $months = [1=>'جانفي',2=>'فيفري',3=>'مارس',4=>'أفريل',5=>'ماي',6=>'جوان',7=>'جويلية',8=>'أوت',9=>'سبتمبر',10=>'أكتوبر',11=>'نوفمبر',12=>'ديسمبر'];
        return $months[(int)$month] ?? '';
    }
}

// ============================================================
// جلب البيانات (قبل أي إخراج)
// ============================================================
$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : date('m');
$source_id = isset($_GET['source_id']) ? (int)$_GET['source_id'] : 0;
$employee_id = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;
$show_paid = isset($_GET['show_paid']) ? (int)$_GET['show_paid'] : 0;
$print = isset($_GET['print']) && $_GET['print'] == '1';

$month_name_ar = getMonthNameArabic($month);
$report_ym = sprintf("%04d-%02d", $year, $month);

$sources = $pdo->query("SELECT id, name FROM sources ORDER BY name")->fetchAll();
$employees = $pdo->query("SELECT id, name, category FROM employees ORDER BY name")->fetchAll();

// ========== الاقتطاعات العادية ==========
$sql = "SELECT 
            mi.id as installment_id,
            mi.amount as monthly_amount,
            mi.is_paid,
            e.id as employee_id,
            e.name as employee_name,
            e.category,
            s.name as source_name,
            s.id as source_id,
            d.is_loan,
            d.credit_balance,
            (SELECT MIN(ep.payment_date) FROM early_payments ep WHERE ep.deduction_id = d.id AND ep.is_reversed = 0) as first_early_payment_date,
            'regular' as type
        FROM monthly_installments mi
        JOIN employees e ON mi.employee_id = e.id
        JOIN sources s ON mi.source_id = s.id
        JOIN deductions d ON mi.deduction_id = d.id
        WHERE mi.year = :year AND mi.month = :month
          AND mi.is_postponed = 0
        ";
$params = [':year' => $year, ':month' => $month];

// إذا لم نطلب عرض المدفوعة، نستبعدها
if (!$show_paid) {
    $sql .= " AND mi.is_paid = 0";
}

if ($source_id > 0) { $sql .= " AND mi.source_id = :source_id"; $params[':source_id'] = $source_id; }
if ($employee_id > 0) { $sql .= " AND mi.employee_id = :employee_id"; $params[':employee_id'] = $employee_id; }
$sql .= " ORDER BY e.name ASC";

$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->execute();
$installments = $stmt->fetchAll();

// ========== دالة حساب المبلغ الفعلي (مع إصلاح الأقساط المدفوعة) ==========
function getEffectiveAmount($item, $report_ym) {
    // ✅ إذا كان القسط مدفوعاً، نعرض المبلغ الأصلي (كما في صفحة التفاصيل)
    if ($item['is_paid']) {
        return $item['monthly_amount'];
    }
    
    if ($item['type'] == 'djezzy') return $item['monthly_amount'];
    $monthly = $item['monthly_amount'];
    $pay_date = $item['first_early_payment_date'];
    if (!empty($pay_date)) {
        $pay_ym = substr($pay_date, 0, 7);
        if ($pay_ym == $report_ym) {
            return $item['credit_balance'];
        }
        $next_ym = date('Y-m', strtotime($pay_date . ' +1 month'));
        if ($next_ym == $report_ym) {
            $remaining = $monthly - $item['credit_balance'];
            return $remaining < 0 ? 0 : $remaining;
        }
    }
    return $monthly;
}

// ============================================================
// دمج وتجميع البيانات حسب (الموظف + المصدر)
// ============================================================
function groupItems($items, $report_ym) {
    $grouped = [];
    foreach ($items as $it) {
        $key = $it['employee_id'] . '|' . $it['source_id'];
        if (!isset($grouped[$key])) {
            $grouped[$key] = [
                'employee_id' => $it['employee_id'],
                'employee_name' => $it['employee_name'],
                'category' => $it['category'],
                'source_id' => $it['source_id'],
                'source_name' => $it['source_name'],
                'is_loan' => $it['is_loan'],
                'is_paid' => $it['is_paid'],
                'total_amount' => 0,
                'installment_id' => $it['installment_id'],
                'type' => $it['type'],
            ];
        }
        $amount = getEffectiveAmount($it, $report_ym);
        $grouped[$key]['total_amount'] += $amount;
        // إذا كان أي قسط غير مدفوع، نعتبر الصف غير مدفوع
        if (!$it['is_paid']) {
            $grouped[$key]['is_paid'] = 0;
        }
    }
    return array_values($grouped);
}

$grouped_items = groupItems($installments, $report_ym);
usort($grouped_items, fn($a, $b) => strcmp($a['employee_name'], $b['employee_name']));

// فصل حسب الفئة
$permG = array_filter($grouped_items, fn($it) => $it['category'] == 'Permanent');
$contG = array_filter($grouped_items, fn($it) => $it['category'] != 'Permanent');
usort($permG, fn($a, $b) => strcmp($a['employee_name'], $b['employee_name']));
usort($contG, fn($a, $b) => strcmp($a['employee_name'], $b['employee_name']));

$totalPermanent = array_sum(array_column($permG, 'total_amount'));
$totalContract = array_sum(array_column($contG, 'total_amount'));
$grandTotal = $totalPermanent + $totalContract;

$csrf_token = generateCSRFToken();
$unpaid_count = count(array_filter($installments, fn($it) => !$it['is_paid']));

// ============================================================
// وضع الطباعة
// ============================================================
if ($print) {
    ?>
    <!DOCTYPE html>
    <html lang="ar" dir="rtl">
    <head>
        <meta charset="UTF-8">
        <title>تقرير شهري - <?= $month_name_ar . ' ' . $year ?></title>
        <style>
            *{margin:0;padding:0;box-sizing:border-box}
            body{font-family:'Segoe UI',Tahoma,Arial,sans-serif;background:white;padding:20px}
            .print-header{text-align:center;margin-bottom:25px;border-bottom:2px solid #2a5298;padding-bottom:10px}
            .print-header h2{color:#2a5298}
            .section-title{font-size:18px;font-weight:bold;margin:20px 0 10px;border-right:4px solid #2a5298;padding-right:10px}
            table{width:100%;border-collapse:collapse;margin-bottom:20px;font-size:12pt}
            th,td{border:1px solid #999;padding:6px;text-align:center}
            th{background:#2a5298;color:white}
            .total-row{background:#f0f0f0;font-weight:bold}
            .badge-djezzy{background:#6f42c1;color:white;padding:2px 8px;border-radius:12px;font-size:10pt;display:inline-block}
            .djezzy-row{background:#f8f0ff}
            .footer{text-align:center;margin-top:30px;font-size:10px;color:#666}
            @media print{body{margin:0;padding:0}}
        </style>
    </head>
    <body>
        <div class="print-header">
            <h2>مركز التكوين والتعليم المهنيين</h2>
            <h3>الشهيد علي بوسحابة - بكوينين</h3>
            <h4>لجنة الخدمات الاجتماعية</h4>
            <p>التقرير الشهري للاقتطاعات - <?= $month_name_ar . ' ' . $year ?></p>
            <?php if ($show_paid): ?>
                <p style="color:#17a2b8;">(يشمل الأقساط المدفوعة)</p>
            <?php endif; ?>
        </div>

        <!-- الدائمون -->
        <div class="section-title">👔 الموظفون الدائمون</div>
        <table>
            <thead><tr><th>#</th><th>الموظف</th><th>المصدر</th><th>المبلغ (دج)</th><th>النوع</th><th>الحالة</th></tr></thead>
            <tbody>
            <?php if(empty($permG)): ?>
                <tr><td colspan="6" style="text-align:center;">لا توجد بيانات</td></tr>
            <?php else: $i=1; foreach($permG as $it):
                $amount = $it['total_amount'];
                $typeLabel = ($it['source_name'] == 'Djezzy') ? '<span class="badge-djezzy">📱 جيزي</span>' : ($it['is_loan'] ? '💰 سلفة' : '📌 اقتطاع');
                $statusText = $it['is_paid'] ? '✅ مدفوع' : '✅ نشط';
                $statusClass = $it['is_paid'] ? 'status-paid' : 'status-active';
                $rowClass = ($it['source_name'] == 'Djezzy') ? 'djezzy-row' : ($it['is_paid'] ? 'paid-row' : '');
            ?>
            <tr class="<?= $rowClass ?>">
                <td><?= $i++ ?></td>
                <td><?= htmlspecialchars($it['employee_name']) ?></td>
                <td><?= htmlspecialchars($it['source_name']) ?></td>
                <td><?= number_format($amount,2) ?> دج</td>
                <td><?= $typeLabel ?></td>
                <td><span class="badge-status <?= $statusClass ?>"><?= $statusText ?></span></td>
            </tr>
            <?php endforeach; ?>
            <tr class="total-row">
                <td colspan="3"><strong>الإجمالي</strong></td>
                <td colspan="3"><strong><?= number_format($totalPermanent,2) ?> دج</strong></td>
            </tr>
            <?php endif; ?>
            </tbody>
        </table>

        <!-- المتعاقدون -->
        <div style="page-break-before:always;"></div>
        <div class="section-title">👕 الموظفون المتعاقدون</div>
        <table>
            <thead><tr><th>#</th><th>الموظف</th><th>المصدر</th><th>المبلغ (دج)</th><th>النوع</th><th>الحالة</th></tr></thead>
            <tbody>
            <?php if(empty($contG)): ?>
                <tr><td colspan="6" style="text-align:center;">لا توجد بيانات</td></tr>
            <?php else: $i=1; foreach($contG as $it):
                $amount = $it['total_amount'];
                $typeLabel = ($it['source_name'] == 'Djezzy') ? '<span class="badge-djezzy">📱 جيزي</span>' : ($it['is_loan'] ? '💰 سلفة' : '📌 اقتطاع');
                $statusText = $it['is_paid'] ? '✅ مدفوع' : '✅ نشط';
                $statusClass = $it['is_paid'] ? 'status-paid' : 'status-active';
                $rowClass = ($it['source_name'] == 'Djezzy') ? 'djezzy-row' : ($it['is_paid'] ? 'paid-row' : '');
            ?>
            <tr class="<?= $rowClass ?>">
                <td><?= $i++ ?></td>
                <td><?= htmlspecialchars($it['employee_name']) ?></td>
                <td><?= htmlspecialchars($it['source_name']) ?></td>
                <td><?= number_format($amount,2) ?> دج</td>
                <td><?= $typeLabel ?></td>
                <td><span class="badge-status <?= $statusClass ?>"><?= $statusText ?></span></td>
            </tr>
            <?php endforeach; ?>
            <tr class="total-row">
                <td colspan="3"><strong>الإجمالي</strong></td>
                <td colspan="3"><strong><?= number_format($totalContract,2) ?> دج</strong></td>
            </tr>
            <?php endif; ?>
            </tbody>
        </table>

        <div style="margin-top:20px; padding:12px; background:#ff9800; border-radius:8px; text-align:center; font-weight:bold;">
            💰 الإجمالي العام للشهر: <?= number_format($grandTotal,2) ?> دج
        </div>

        <div class="footer">تم إنشاء التقرير بواسطة نظام إدارة الاقتطاعات بتاريخ <?= date('Y-m-d H:i:s') ?></div>
        <script>window.onload = function() { window.print(); };</script>
    </body>
    </html>
    <?php
    exit;
}

// ============================================================
// معالجة POST (تسديد فردي أو كلي)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRFToken();

    $year = (int)($_POST['year'] ?? date('Y'));
    $month = (int)($_POST['month'] ?? date('m'));
    $source_filter = (int)($_POST['source_filter'] ?? 0);
    $employee_filter = (int)($_POST['employee_filter'] ?? 0);
    $show_paid_post = (int)($_POST['show_paid'] ?? 0);

    // تسديد فردي
    if (isset($_POST['pay_single']) && isset($_POST['installment_id'])) {
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("
                SELECT mi.*, d.is_loan, d.id as deduction_id
                FROM monthly_installments mi
                JOIN deductions d ON mi.deduction_id = d.id
                WHERE mi.id = ? AND mi.is_paid = 0
            ");
            $stmt->execute([(int)$_POST['installment_id']]);
            $inst = $stmt->fetch();
            
            if (!$inst) {
                throw new Exception('القسط غير موجود أو تم سداده مسبقاً');
            }
            
            $update = $pdo->prepare("UPDATE monthly_installments SET is_paid = 1, paid_date = datetime('now') WHERE id = ?");
            $update->execute([$inst['id']]);
            
            if ($inst['is_loan']) {
                $amount = $inst['amount'];
                $stmtBudget = $pdo->prepare("
                    UPDATE social_budget 
                    SET remaining_budget = remaining_budget + ?
                    WHERE id = (SELECT id FROM social_budget ORDER BY year DESC LIMIT 1)
                ");
                $stmtBudget->execute([$amount]);
                
                $stmtTrans = $pdo->prepare("
                    INSERT INTO budget_transactions (reference_id, type, amount, description, is_deduct, transaction_date)
                    VALUES (?, 'installment', ?, ?, 0, datetime('now'))
                ");
                $stmtTrans->execute([
                    $inst['deduction_id'],
                    $amount,
                    "استرجاع سلفة (قسط شهر " . getMonthNameArabic($month) . " " . $year . ")"
                ]);
            }
            
            $pdo->commit();
            $_SESSION['toast'] = ['message' => '✅ تم تسديد القسط بنجاح', 'type' => 'success', 'duration' => 3000];
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['toast'] = ['message' => '❌ ' . $e->getMessage(), 'type' => 'error', 'duration' => 5000];
        }
        header("Location: monthly.php?year=$year&month=$month&source_id=$source_filter&employee_id=$employee_filter&show_paid=$show_paid_post");
        exit;
    }

    // تسديد الكل
    if (isset($_POST['pay_all'])) {
        try {
            $pdo->beginTransaction();
            $sql = "SELECT id FROM monthly_installments WHERE year = ? AND month = ? AND is_paid = 0";
            $params = [$year, $month];
            if ($source_filter > 0) { $sql .= " AND source_id = ?"; $params[] = $source_filter; }
            if ($employee_filter > 0) { $sql .= " AND employee_id = ?"; $params[] = $employee_filter; }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $installments_all = $stmt->fetchAll();
            
            if (empty($installments_all)) {
                throw new Exception('لا توجد أقساط غير مدفوعة');
            }
            
            $count = 0;
            foreach ($installments_all as $inst) {
                // نستخدم معالجة كل قسط على حدة (منطق مشابه للفردي)
                $stmt2 = $pdo->prepare("
                    SELECT mi.*, d.is_loan, d.id as deduction_id
                    FROM monthly_installments mi
                    JOIN deductions d ON mi.deduction_id = d.id
                    WHERE mi.id = ?
                ");
                $stmt2->execute([$inst['id']]);
                $data = $stmt2->fetch();
                if ($data) {
                    $update = $pdo->prepare("UPDATE monthly_installments SET is_paid = 1, paid_date = datetime('now') WHERE id = ?");
                    $update->execute([$data['id']]);
                    if ($data['is_loan']) {
                        $stmtBudget = $pdo->prepare("UPDATE social_budget SET remaining_budget = remaining_budget + ? WHERE id = (SELECT id FROM social_budget ORDER BY year DESC LIMIT 1)");
                        $stmtBudget->execute([$data['amount']]);
                        $stmtTrans = $pdo->prepare("INSERT INTO budget_transactions (reference_id, type, amount, description, is_deduct, transaction_date) VALUES (?, 'installment', ?, ?, 0, datetime('now'))");
                        $stmtTrans->execute([$data['deduction_id'], $data['amount'], "استرجاع سلفة (تسديد الكل – شهر " . getMonthNameArabic($month) . " " . $year . ")"]);
                    }
                    $count++;
                }
            }
            $pdo->commit();
            $_SESSION['toast'] = ['message' => "✅ تم تسديد $count قسطاً", 'type' => 'success', 'duration' => 3000];
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['toast'] = ['message' => '❌ ' . $e->getMessage(), 'type' => 'error', 'duration' => 5000];
        }
        header("Location: monthly.php?year=$year&month=$month&source_id=$source_filter&employee_id=$employee_filter&show_paid=$show_paid_post");
        exit;
    }
}

// ============================================================
// العرض العادي
// ============================================================
include '../includes/header.php';
?>
<link rel="stylesheet" href="../assets/css/monthly-report.css">

<?php if (isset($_SESSION['toast'])): ?>
    <div class="toast-container">
        <div class="toast-item toast-<?= $_SESSION['toast']['type'] ?>"><?= $_SESSION['toast']['message'] ?></div>
    </div>
    <script>
        setTimeout(function() {
            document.querySelector('.toast-container').style.display = 'none';
        }, <?= $_SESSION['toast']['duration'] ?? 4000 ?>);
    </script>
    <?php unset($_SESSION['toast']); ?>
<?php endif; ?>

<div class="report-container">
    <div class="report-header">
        <h2>📅 التقرير الشهري للاقتطاعات</h2>
        <h3><?= $month_name_ar . ' ' . $year ?></h3>
        <?php if ($show_paid): ?>
            <p style="color:#cce5ff; margin-top:5px;">(يشمل الأقساط المدفوعة)</p>
        <?php endif; ?>
    </div>
    
    <div class="filters">
        <form method="GET" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center; width:100%;">
            <div class="filter-group"><label>السنة:</label><select name="year">
                <?php for($y=2020; $y<=date('Y')+1; $y++): ?>
                    <option value="<?= $y ?>" <?= $y==$year ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select></div>
            <div class="filter-group"><label>الشهر:</label><select name="month">
                <?php for($m=1; $m<=12; $m++): ?>
                    <option value="<?= $m ?>" <?= $m==$month ? 'selected' : '' ?>><?= getMonthNameArabic($m) ?></option>
                <?php endfor; ?>
            </select></div>
            <div class="filter-group"><label>المصدر:</label><select name="source_id">
                <option value="0">جميع المصادر</option>
                <?php foreach($sources as $src): ?>
                    <option value="<?= $src['id'] ?>" <?= ($source_id==$src['id']) ? 'selected' : '' ?>><?= htmlspecialchars($src['name']) ?></option>
                <?php endforeach; ?>
            </select></div>
            <div class="filter-group"><label>الموظف:</label><select name="employee_id">
                <option value="0">جميع الموظفين</option>
                <?php foreach($employees as $emp): ?>
                    <option value="<?= $emp['id'] ?>" <?= ($employee_id==$emp['id']) ? 'selected' : '' ?>><?= htmlspecialchars($emp['name']) ?></option>
                <?php endforeach; ?>
            </select></div>
            <div class="filter-group" style="background:#e9ecef;">
                <label>عرض المدفوعة:</label>
                <select name="show_paid">
                    <option value="0" <?= $show_paid==0?'selected':'' ?>>إخفاء المدفوعة</option>
                    <option value="1" <?= $show_paid==1?'selected':'' ?>>عرض الكل</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">🔍 عرض</button>
            <a href="?year=<?= $year ?>&month=<?= $month ?>&source_id=<?= $source_id ?>&employee_id=<?= $employee_id ?>&show_paid=<?= $show_paid ?>&print=1" target="_blank" class="btn btn-success">🖨️ طباعة</a>
        </form>
    </div>

    <?php if (empty($installments)): ?>
        <div style="background:#f8d7da; padding:20px; text-align:center;">⚠️ لا توجد بيانات للشهر والفلاتر المحددة</div>
    <?php else: ?>
        <?php if ($unpaid_count > 0 && !$show_paid): ?>
            <button type="button" class="btn-pay-all" onclick="openPayAllModal(<?= $unpaid_count ?>)">
                💰 تسديد الكل (<?= $unpaid_count ?> قسط)
            </button>
        <?php else: ?>
            <button type="button" class="btn-pay-all" style="background:#6c757d; cursor:not-allowed;" disabled>
                <?= $show_paid ? '📋 عرض جميع الأقساط' : '✅ جميع الأقساط مدفوعة' ?>
            </button>
        <?php endif; ?>

        <!-- دالة عرض الجدول (مضمنة هنا) -->
        <?php function renderMonthlyTable($items, $title, $total, $showPayButton = true, $allItems = [], $installments = [], $month_name_ar = '') { ?>
            <div class="section-title"><?= $title ?></div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>الموظف</th>
                        <th>المصدر</th>
                        <th>المبلغ (دج)</th>
                        <th>النوع</th>
                        <th>الحالة</th>
                        <?php if ($showPayButton): ?>
                            <th>تسديد</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($items)): ?>
                    <tr><td colspan="<?= $showPayButton ? 7 : 6 ?>" style="text-align:center;">لا توجد بيانات</td></tr>
                <?php else: $i=1; foreach($items as $it):
                    $amount = $it['total_amount'];
                    $typeLabel = ($it['source_name'] == 'Djezzy') ? '<span class="badge-djezzy">📱 جيزي</span>' : ($it['is_loan'] ? '💰 سلفة' : '📌 اقتطاع');
                    $isPaid = $it['is_paid'];
                    $statusText = $isPaid ? '✅ مدفوع' : '✅ نشط';
                    $statusClass = $isPaid ? 'status-paid' : 'status-active';
                    $rowClass = ($it['source_name'] == 'Djezzy') ? 'djezzy-row' : ($isPaid ? 'paid-row' : '');
                    
                    $hasUnpaid = false;
                    $installment_id_for_pay = 0;
                    if ($showPayButton && $it['source_name'] != 'Djezzy' && !$isPaid) {
                        foreach ($installments as $orig) {
                            if ($orig['employee_id'] == $it['employee_id'] && $orig['source_id'] == $it['source_id'] && $orig['is_paid'] == 0) {
                                $hasUnpaid = true;
                                $installment_id_for_pay = $orig['installment_id'];
                                break;
                            }
                        }
                    }
                    $canPay = ($hasUnpaid && $it['source_name'] != 'Djezzy' && $showPayButton);
                ?>
                    <tr class="<?= $rowClass ?>">
                        <td><?= $i++ ?></td>
                        <td><?= htmlspecialchars($it['employee_name']) ?></td>
                        <td><?= htmlspecialchars($it['source_name']) ?></td>
                        <td><?= number_format($amount, 2) ?> دج</td>
                        <td><?= $typeLabel ?></td>
                        <td><span class="badge-status <?= $statusClass ?>"><?= $statusText ?></span></td>
                        <?php if ($showPayButton): ?>
                            <td>
                                <?php if ($canPay): ?>
                                    <button type="button" class="btn-pay" onclick="openPayModal(<?= $installment_id_for_pay ?>, '<?= htmlspecialchars($it['employee_name']) ?>', '<?= $month_name_ar ?>', '<?= number_format($amount, 2) ?>')">
                                        💰 تسديد
                                    </button>
                                <?php else: ?>
                                    <span class="btn-pay-disabled">✔ تم</span>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td colspan="<?= $showPayButton ? 3 : 3 ?>"><strong>الإجمالي</strong></td>
                    <td colspan="<?= $showPayButton ? 4 : 3 ?>"><strong><?= number_format($total, 2) ?> دج</strong></td>
                </tr>
                <?php endif; ?>
                </tbody>
            </table>
        <?php } ?>

        <?php renderMonthlyTable($permG, '👔 الموظفون الدائمون', $totalPermanent, !$show_paid, [], $installments, $month_name_ar); ?>
        <?php renderMonthlyTable($contG, '👕 الموظفون المتعاقدون', $totalContract, !$show_paid, [], $installments, $month_name_ar); ?>

        <div style="margin-top:20px; padding:12px; background:#ff9800; border-radius:8px; text-align:center; font-weight:bold;">
            💰 الإجمالي العام للشهر: <?= number_format($grandTotal, 2) ?> دج
        </div>
    <?php endif; ?>
</div>

<!-- مودالات التسديد -->
<div id="payModal" class="modal-overlay">
    <div class="modal-box">
        <h3>💰 تأكيد التسديد</h3>
        <p>هل أنت متأكد من تسديد قسط <strong id="modalEmployee"></strong> للشهر <strong id="modalMonth"></strong> بقيمة <strong id="modalAmount"></strong> دج؟</p>
        <form method="POST" id="payForm">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <input type="hidden" name="installment_id" id="modalInstallmentId">
            <input type="hidden" name="year" value="<?= $year ?>">
            <input type="hidden" name="month" value="<?= $month ?>">
            <input type="hidden" name="source_filter" value="<?= $source_id ?>">
            <input type="hidden" name="employee_filter" value="<?= $employee_id ?>">
            <input type="hidden" name="show_paid" value="<?= $show_paid ?>">
            <div class="actions">
                <button type="button" class="btn-cancel-modal" onclick="closePayModal()">إلغاء</button>
                <button type="submit" name="pay_single" class="btn-confirm">💳 تأكيد التسديد</button>
            </div>
        </form>
    </div>
</div>

<div id="payAllModal" class="modal-overlay">
    <div class="modal-box">
        <h3 style="color: #007bff;">💰 تأكيد تسديد الكل</h3>
        <p>سيتم تسديد جميع الأقساط غير المدفوعة للشهر <strong><?= $month_name_ar ?></strong>.</p>
        <p><strong>عدد الأقساط:</strong> <span id="payAllCount">0</span></p>
        <p class="text-muted">سيتم إعادة مبالغ السلف إلى الميزانية تلقائياً.</p>
        <form method="POST" id="payAllForm">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <input type="hidden" name="pay_all" value="1">
            <input type="hidden" name="year" value="<?= $year ?>">
            <input type="hidden" name="month" value="<?= $month ?>">
            <input type="hidden" name="source_filter" value="<?= $source_id ?>">
            <input type="hidden" name="employee_filter" value="<?= $employee_id ?>">
            <input type="hidden" name="show_paid" value="<?= $show_paid ?>">
            <div class="actions">
                <button type="button" class="btn-cancel-modal" onclick="closePayAllModal()">إلغاء</button>
                <button type="submit" class="btn-confirm" style="background:#007bff;">💳 تأكيد الكل</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openPayModal(installmentId, employeeName, month, amount) {
        document.getElementById('modalInstallmentId').value = installmentId;
        document.getElementById('modalEmployee').textContent = employeeName;
        document.getElementById('modalMonth').textContent = month;
        document.getElementById('modalAmount').textContent = amount;
        document.getElementById('payModal').classList.add('active');
    }
    function closePayModal() {
        document.getElementById('payModal').classList.remove('active');
    }
    function openPayAllModal(count) {
        document.getElementById('payAllCount').textContent = count;
        document.getElementById('payAllModal').classList.add('active');
    }
    function closePayAllModal() {
        document.getElementById('payAllModal').classList.remove('active');
    }
    document.getElementById('payModal').addEventListener('click', function(e) {
        if (e.target === this) closePayModal();
    });
    document.getElementById('payAllModal').addEventListener('click', function(e) {
        if (e.target === this) closePayAllModal();
    });
</script>

<?php include '../includes/footer.php'; ?>