<?php
/**
 * reports/monthly.php - التقرير الشهري للاقتطاعات
 * يعرض الأقساط غير المسددة (بما فيها المؤجلة) مع إمكانية التسديد
 * يدعم اقتطاعات الهواتف (source_id = 999) بدون زر تسديد
 */
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/security.php';
require_once '../includes/monthly_helpers.php';

if (!function_exists('getMonthNameArabic')) {
    function getMonthNameArabic($month) {
        $months = [1=>'جانفي',2=>'فيفري',3=>'مارس',4=>'أفريل',5=>'ماي',6=>'جوان',7=>'جويلية',8=>'أوت',9=>'سبتمبر',10=>'أكتوبر',11=>'نوفمبر',12=>'ديسمبر'];
        return $months[(int)$month] ?? '';
    }
}

// ============================================================
// جلب البيانات
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

// ========== استعلام الأقساط (مع دعم الهواتف) ==========
// الجزء الأول: أقساط الهواتف (من employee_phone_numbers)
$sql_phones = "
    SELECT 
        NULL as installment_id,
        ep.monthly_amount,
        0 as is_paid,
        0 as is_postponed,
        e.id as employee_id,
        e.name as employee_name,
        e.category,
        'هاتف' as source_name,
        999 as source_id,
        0 as is_loan,
        0 as credit_balance,
        NULL as first_early_payment_date,
        'phone' as type
    FROM employee_phone_numbers ep
    JOIN employees e ON ep.employee_id = e.id
    WHERE ep.is_active = 1
";

// الجزء الثاني: الأقساط العادية من monthly_installments
$sql_regular = "
    SELECT 
        mi.id as installment_id,
        mi.amount as monthly_amount,
        mi.is_paid,
        mi.is_postponed,
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
";

// دمج الاستعلامين مع UNION
$sql = $sql_phones . " UNION ALL " . $sql_regular;
$params = [':year' => $year, ':month' => $month];

// إذا لم نطلب عرض المدفوعة، نستبعدها (تنطبق على الأقساط العادية فقط)
if (!$show_paid) {
    // نضيف شرط is_paid = 0 فقط للجزء الثاني (الاقتطاعات العادية)
    // ولكن UNION لا يسمح بشرط جزئي بسهولة، نستخدم استعلامين منفصلين ثم ندمج
    // الحل الأسهل: نستخدم استعلامين ثم ندمج يدوياً
    $sql = $sql_phones . " UNION ALL " . str_replace('WHERE mi.year = :year AND mi.month = :month', 'WHERE mi.year = :year AND mi.month = :month AND mi.is_paid = 0', $sql_regular);
    // نعدل الـ params لتناسب
}

if ($source_id > 0) { 
    $sql .= " AND source_id = :source_id"; 
    $params[':source_id'] = $source_id; 
}
if ($employee_id > 0) { 
    $sql .= " AND employee_id = :employee_id"; 
    $params[':employee_id'] = $employee_id; 
}
$sql .= " ORDER BY employee_name ASC";

$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->execute();
$installments = $stmt->fetchAll();

// ========== دالة حساب المبلغ الفعلي ==========
function getEffectiveAmount($item, $report_ym) {
    if ($item['is_paid']) {
        return $item['monthly_amount'];
    }
    if ($item['type'] == 'djezzy' || $item['type'] == 'phone') {
        return $item['monthly_amount'];
    }
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

// ========== تجميع البيانات حسب الموظف+المصدر ==========
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
                'is_postponed' => $it['is_postponed'] ?? 0,
                'total_amount' => 0,
                'installment_id' => $it['installment_id'],
                'type' => $it['type'],
            ];
        }
        $amount = getEffectiveAmount($it, $report_ym);
        $grouped[$key]['total_amount'] += $amount;
        if (!$it['is_paid']) {
            $grouped[$key]['is_paid'] = 0;
        }
        if (!empty($it['is_postponed'])) {
            $grouped[$key]['is_postponed'] = 1;
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

// ========== دالة عرض الجدول ==========
function renderMonthlyTable($items, $title, $total, $showPayButton = true, $installments = [], $month_name_ar = '') {
    ?>
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
            $isPhone = ($it['source_name'] == 'هاتف' || $it['type'] == 'phone');
            $typeLabel = $isPhone 
                ? '<span class="badge-phone">📱 هاتف</span>' 
                : (($it['source_name'] == 'Djezzy') 
                    ? '<span class="badge-djezzy">📱 جيزي</span>' 
                    : ($it['is_loan'] ? '💰 سلفة' : '📌 اقتطاع'));
            
            if ($it['is_paid']) {
                $statusText = '✅ مدفوع';
                $statusClass = 'status-paid';
            } elseif ($it['is_postponed']) {
                $statusText = '⏰ مؤجل';
                $statusClass = 'status-postponed';
            } else {
                $statusText = '✅ نشط';
                $statusClass = 'status-active';
            }
            
            $rowClass = ($isPhone || $it['source_name'] == 'Djezzy') ? 'djezzy-row' : ($it['is_paid'] ? 'paid-row' : '');
            
            $hasUnpaid = false;
            $installment_id_for_pay = 0;
            if ($showPayButton && !$it['is_paid'] && !$isPhone) {
                foreach ($installments as $orig) {
                    if ($orig['employee_id'] == $it['employee_id'] && $orig['source_id'] == $it['source_id'] && $orig['is_paid'] == 0) {
                        $hasUnpaid = true;
                        $installment_id_for_pay = $orig['installment_id'];
                        break;
                    }
                }
            }
            // ❌ الهواتف لا تحتوي على زر تسديد
            $canPay = ($hasUnpaid && $showPayButton && !$isPhone && $it['source_name'] != 'Djezzy');
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
    <?php
}

// ============================================================
// وضع الطباعة (تضمين ملف منفصل)
// ============================================================
if ($print) {
    // تعيين المتغيرات المطلوبة للطباعة
    $year = $year;
    $month = $month;
    $month_name_ar = $month_name_ar;
    $show_paid = $show_paid;
    $permG = $permG;
    $contG = $contG;
    $totalPermanent = $totalPermanent;
    $totalContract = $totalContract;
    $grandTotal = $grandTotal;
    
    include __DIR__ . '/monthly_print.php';
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

        <?php renderMonthlyTable($permG, '👔 الموظفون الدائمون', $totalPermanent, !$show_paid, $installments, $month_name_ar); ?>
        <?php renderMonthlyTable($contG, '👕 الموظفون المتعاقدون', $totalContract, !$show_paid, $installments, $month_name_ar); ?>

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