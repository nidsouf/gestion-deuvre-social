<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/security.php';
require_once '../includes/common_helpers.php';
require_once '../includes/monthly_helpers.php';
require_once '../includes/monthly_table.php';
require_once '../includes/monthly_payment.php';

// ============================================================
// جلب المعاملات من GET
// ============================================================
$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : date('m');
$source_id = isset($_GET['source_id']) ? (int)$_GET['source_id'] : 0;
$employee_id = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;
$show_djezzy = isset($_GET['show_djezzy']) ? (int)$_GET['show_djezzy'] : 1;
$print = isset($_GET['print']) && $_GET['print'] == '1';

$month_name_ar = getMonthNameArabic($month);
$report_ym = sprintf("%04d-%02d", $year, $month);

$sources = $pdo->query("SELECT id, name FROM sources ORDER BY name")->fetchAll();
$employees = $pdo->query("SELECT id, name, category FROM employees ORDER BY name")->fetchAll();

// ========== 1. الاقتطاعات العادية ==========
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
if ($source_id > 0) { $sql .= " AND mi.source_id = :source_id"; $params[':source_id'] = $source_id; }
if ($employee_id > 0) { $sql .= " AND mi.employee_id = :employee_id"; $params[':employee_id'] = $employee_id; }
$sql .= " ORDER BY e.name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$installments = $stmt->fetchAll();

// ========== 2. اقتطاعات جيزي ==========
$djezzy_items = [];
if ($show_djezzy) {
    $sql_dj = "SELECT 
                epn.monthly_amount,
                e.id as employee_id,
                e.name as employee_name,
                e.category,
                'Djezzy' as source_name,
                0 as is_loan,
                0 as credit_balance,
                NULL as first_early_payment_date,
                'djezzy' as type
            FROM employee_phone_numbers epn
            JOIN employees e ON epn.employee_id = e.id
            WHERE epn.is_active = 1";
    if ($employee_id > 0) $sql_dj .= " AND e.id = $employee_id";
    $sql_dj .= " ORDER BY e.name ASC";
    $djezzy_items = $pdo->query($sql_dj)->fetchAll();
}

// ========== 3. دمج البيانات ==========
$all_items = array_merge($installments, $djezzy_items);
usort($all_items, fn($a,$b)=>strcmp($a['employee_name'], $b['employee_name']));

// ========== 4. تجميع البيانات ==========
$grouped_items = groupItems($all_items, $report_ym);
sortByName($grouped_items);

// ========== 5. فصل حسب الفئة ==========
$permG = filterByCategory($grouped_items, 'Permanent');
$contG = filterByCategory($grouped_items, 'Permanent', true);
sortByName($permG);
sortByName($contG);

// ========== 6. حساب الإجماليات ==========
$totalPermanent = totalAmount($permG);
$totalContract = totalAmount($contG);
$grandTotal = $totalPermanent + $totalContract;
$totalDjezzy = totalAmount(array_filter($grouped_items, fn($it) => $it['source_name'] == 'Djezzy'));

// حساب مجموع السلف والاقتطاعات
$totals = calculateTotals($grouped_items);
$totalLoans = $totals['loans'];
$totalDeductions = $totals['deductions'];

$csrf_token = generateCSRFToken();

// عدد الأقساط غير المدفوعة (للتسديد الكل)
$unpaid_count = 0;
foreach ($installments as $it) {
    if ($it['is_paid'] == 0) $unpaid_count++;
}

// ============================================================
// 7. وضع الطباعة (مع التجميع)
// ============================================================
if ($print) {
    // نفس منطق الطباعة السابق باستخدام الدوال المساعدة
    // اختصار: نستخدم نفس المتغيرات المجمعة
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
        </div>

        <!-- الدائمون -->
        <div class="section-title">👔 الموظفون الدائمون</div>
        <table>
            <thead><tr><th>#</th><th>الموظف</th><th>المصدر</th><th>المبلغ (دج)</th><th>النوع</th></tr></thead>
            <tbody>
            <?php if(empty($permG)): ?>
                <tr><td colspan="5" style="text-align:center;">لا توجد بيانات</td></tr>
            <?php else: $i=1; foreach($permG as $it):
                $amount = $it['total_amount'];
                $typeLabel = ($it['source_name'] == 'Djezzy') ? '<span class="badge-djezzy">📱 جيزي</span>' : ($it['is_loan'] ? '💰 سلفة' : '📌 اقتطاع');
                $rowClass = ($it['source_name'] == 'Djezzy') ? 'djezzy-row' : '';
            ?>
            <tr class="<?= $rowClass ?>">
                <td><?= $i++ ?></td>
                <td><?= htmlspecialchars($it['employee_name']) ?></td>
                <td><?= htmlspecialchars($it['source_name']) ?></td>
                <td><?= formatAmount($amount) ?></td>
                <td><?= $typeLabel ?></td>
            </tr>
            <?php endforeach; ?>
            <tr class="total-row">
                <td colspan="3"><strong>الإجمالي</strong></td>
                <td colspan="2"><strong><?= formatAmount($totalPermanent) ?></strong></td>
            </tr>
            <?php endif; ?>
            </tbody>
        </table>

        <!-- المتعاقدون -->
        <div style="page-break-before:always;"></div>
        <div class="section-title">👕 الموظفون المتعاقدون</div>
        <table>
            <thead><tr><th>#</th><th>الموظف</th><th>المصدر</th><th>المبلغ (دج)</th><th>النوع</th></tr></thead>
            <tbody>
            <?php if(empty($contG)): ?>
                <tr><td colspan="5" style="text-align:center;">لا توجد بيانات</td></tr>
            <?php else: $i=1; foreach($contG as $it):
                $amount = $it['total_amount'];
                $typeLabel = ($it['source_name'] == 'Djezzy') ? '<span class="badge-djezzy">📱 جيزي</span>' : ($it['is_loan'] ? '💰 سلفة' : '📌 اقتطاع');
                $rowClass = ($it['source_name'] == 'Djezzy') ? 'djezzy-row' : '';
            ?>
            <tr class="<?= $rowClass ?>">
                <td><?= $i++ ?></td>
                <td><?= htmlspecialchars($it['employee_name']) ?></td>
                <td><?= htmlspecialchars($it['source_name']) ?></td>
                <td><?= formatAmount($amount) ?></td>
                <td><?= $typeLabel ?></td>
            </tr>
            <?php endforeach; ?>
            <tr class="total-row">
                <td colspan="3"><strong>الإجمالي</strong></td>
                <td colspan="2"><strong><?= formatAmount($totalContract) ?></strong></td>
            </tr>
            <?php endif; ?>
            </tbody>
        </table>

        <div style="margin-top:20px; padding:12px; background:#ff9800; border-radius:8px; text-align:center; font-weight:bold;">
            💰 الإجمالي العام للشهر: <?= formatAmount($grandTotal) ?><br>
            💰 مجموع السلف: <?= formatAmount($totalLoans) ?><br>
            💰 مجموع الاقتطاعات (بدون سلف): <?= formatAmount($totalDeductions) ?>
            <?php if ($totalDjezzy > 0): ?>
                <br><span style="font-size:12px;">📱 يشمل جيزي: <?= formatAmount($totalDjezzy) ?></span>
            <?php endif; ?>
        </div>

        <div class="footer">تم إنشاء التقرير بواسطة نظام إدارة الاقتطاعات بتاريخ <?= date('Y-m-d H:i:s') ?></div>
        <script>window.onload = function() { window.print(); };</script>
    </body>
    </html>
    <?php
    exit;
}

// ============================================================
// 8. معالجة POST (تسديد فردي أو كلي)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRFToken();

    $year = (int)($_POST['year'] ?? date('Y'));
    $month = (int)($_POST['month'] ?? date('m'));
    $source_filter = (int)($_POST['source_filter'] ?? 0);
    $employee_filter = (int)($_POST['employee_filter'] ?? 0);
    $show_djezzy = (int)($_POST['show_djezzy'] ?? 1);

    // تسديد فردي
    if (isset($_POST['pay_single']) && isset($_POST['installment_id'])) {
        try {
            $pdo->beginTransaction();
            processPayment($pdo, (int)$_POST['installment_id'], $month, $year);
            $pdo->commit();
            setToast('✅ تم تسديد القسط بنجاح', 'success');
        } catch (Exception $e) {
            $pdo->rollBack();
            setToast('❌ ' . $e->getMessage(), 'error');
        }
        redirectTo('monthly.php', [
            'year' => $year,
            'month' => $month,
            'source_id' => $source_filter,
            'employee_id' => $employee_filter,
            'show_djezzy' => $show_djezzy
        ]);
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
                processPayment($pdo, $inst['id'], $month, $year);
                $count++;
            }
            $pdo->commit();
            setToast("✅ تم تسديد $count قسطاً", 'success');
        } catch (Exception $e) {
            $pdo->rollBack();
            setToast('❌ ' . $e->getMessage(), 'error');
        }
        redirectTo('monthly.php', [
            'year' => $year,
            'month' => $month,
            'source_id' => $source_filter,
            'employee_id' => $employee_filter,
            'show_djezzy' => $show_djezzy
        ]);
        exit;
    }
}

// ============================================================
// 9. العرض العادي (باستخدام الدوال المساعدة)
// ============================================================
include '../includes/header.php';
?>
<link rel="stylesheet" href="../assets/css/monthly-report.css">

<?php if (hasToast()) { $toast = getToast(); ?>
    <div class="toast-container">
        <div class="toast-item toast-<?= $toast['type'] ?>"><?= $toast['message'] ?></div>
    </div>
    <script>
        setTimeout(function() {
            document.querySelector('.toast-container').style.display = 'none';
        }, <?= $toast['duration'] ?? 4000 ?>);
    </script>
<?php } ?>

<div class="report-container">
    <div class="report-header">
        <h2>📅 التقرير الشهري للاقتطاعات</h2>
        <h3><?= $month_name_ar . ' ' . $year ?></h3>
    </div>
    
    <!-- الفلاتر -->
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
                <label>📱 جيزي:</label>
                <select name="show_djezzy">
                    <option value="1" <?= $show_djezzy == 1 ? 'selected' : '' ?>>إظهار</option>
                    <option value="0" <?= $show_djezzy == 0 ? 'selected' : '' ?>>إخفاء</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">🔍 عرض</button>
            <a href="?year=<?= $year ?>&month=<?= $month ?>&source_id=<?= $source_id ?>&employee_id=<?= $employee_id ?>&show_djezzy=<?= $show_djezzy ?>&print=1" target="_blank" class="btn btn-success">🖨️ طباعة</a>
        </form>
    </div>

    <?php if (empty($installments) && empty($djezzy_items)): ?>
        <div style="background:#f8d7da; padding:20px; text-align:center;">⚠️ لا توجد بيانات للشهر والفلاتر المحددة</div>
    <?php else: ?>
        <?php if ($unpaid_count > 0): ?>
            <button type="button" class="btn-pay-all" onclick="openPayAllModal(<?= $unpaid_count ?>)">
                💰 تسديد الكل (<?= $unpaid_count ?> قسط)
            </button>
        <?php else: ?>
            <button type="button" class="btn-pay-all" style="background:#6c757d; cursor:not-allowed;" disabled>
                ✅ جميع الأقساط مدفوعة
            </button>
        <?php endif; ?>

        <?php renderMonthlyTable($permG, '👔 الموظفون الدائمون', $totalPermanent, true, $all_items, $installments, $month_name_ar); ?>
        <?php renderMonthlyTable($contG, '👕 الموظفون المتعاقدون', $totalContract, true, $all_items, $installments, $month_name_ar); ?>

        <div style="margin-top:20px; padding:12px; background:#ff9800; border-radius:8px; text-align:center; font-weight:bold;">
            💰 الإجمالي العام للشهر: <?= formatAmount($grandTotal) ?><br>
            💰 مجموع السلف: <?= formatAmount($totalLoans) ?><br>
            💰 مجموع الاقتطاعات (بدون سلف): <?= formatAmount($totalDeductions) ?>
            <?php if ($totalDjezzy > 0): ?>
                <br><span style="font-size:14px;">📱 يشمل جيزي: <?= formatAmount($totalDjezzy) ?></span>
            <?php endif; ?>
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
            <input type="hidden" name="show_djezzy" value="<?= $show_djezzy ?>">
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
            <input type="hidden" name="show_djezzy" value="<?= $show_djezzy ?>">
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