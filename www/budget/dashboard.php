<?php
session_start();
require_once '../includes/auth_check.php';
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
require_once '../config/database.php';
require_once '../includes/functions.php';
include '../includes/header.php';

// التأكد من وجود $pdo
global $pdo;

// إنشاء السنة القادمة تلقائيًا إذا لزم الأمر (تم التعليق مؤقتًا للتأكد)
// autoCreateNextYearBudget();

// جلب الميزانية الحالية
$budget = $pdo->query("SELECT * FROM social_budget ORDER BY year DESC LIMIT 1")->fetch();
$remaining = $budget ? $budget['remaining_budget'] : 0;
$initial = $budget ? $budget['initial_budget'] : 0;

// إجمالي المصروفات (حيث is_deduct = 1) في السنة الحالية - معدل لـ SQLite
$totalDeduct = $pdo->query("
    SELECT COALESCE(SUM(amount),0) 
    FROM budget_transactions 
    WHERE is_deduct = 1 
    AND strftime('%Y', transaction_date) = strftime('%Y', 'now')
")->fetchColumn();
$spent = $totalDeduct;
$spentPercent = $initial ? round(($spent / $initial) * 100, 1) : 0;
$year = date('Y');

// إشعار تلقائي عند انخفاض الميزانية عن 100,000 دج
if ($remaining < 100000) {
    // نتحقق إذا كان هذا الإشعار قد أضيف بالفعل اليوم (لتجنب التكرار)
    $today = date('Y-m-d');
    $stmt = $pdo->prepare("
        SELECT id FROM notifications 
        WHERE user_id = ? AND type = 'warning' 
        AND date(created_at) = date(?) 
        AND message LIKE '%الميزانية%'
    ");
    $stmt->execute([$_SESSION['user_id'], $today]);
    $exists = $stmt->fetch();
    if (!$exists) {
        addNotification($_SESSION['user_id'], "⚠️ الميزانية أقل من 100,000 دج! (المتبقي: " . number_format($remaining, 2) . " دج)", "warning", "budget/index.php");
    }
}

// إحصائيات السنة الحالية (معدلة لـ SQLite)
$totalGrants = $pdo->query("
    SELECT COALESCE(SUM(amount),0) 
    FROM budget_transactions 
    WHERE type='grant' 
    AND strftime('%Y', transaction_date) = strftime('%Y', 'now')
")->fetchColumn();

$totalLoans = $pdo->query("
    SELECT COALESCE(SUM(amount),0) 
    FROM budget_transactions 
    WHERE type='loan' 
    AND strftime('%Y', transaction_date) = strftime('%Y', 'now')
")->fetchColumn();

$totalInstallments = $pdo->query("
    SELECT COALESCE(SUM(amount),0) 
    FROM budget_transactions 
    WHERE type='installment' 
    AND strftime('%Y', transaction_date) = strftime('%Y', 'now')
")->fetchColumn();

// آخر 5 عمليات
$transactions = $pdo->query("SELECT * FROM budget_transactions ORDER BY transaction_date DESC LIMIT 5")->fetchAll();

// تفاصيل الأقساط المردودة (معدلة لـ SQLite)
$installmentsDetails = $pdo->query("
    SELECT 
        d.id,
        d.monthly_amount,
        d.total_months,
        d.start_date,
        d.end_date,
        e.name as employee_name,
        COALESCE(SUM(CASE WHEN bt.type = 'installment' AND strftime('%Y', bt.transaction_date) = strftime('%Y', 'now') THEN bt.amount ELSE 0 END), 0) as paid_amount,
        COALESCE(SUM(CASE WHEN bt.type = 'installment' AND strftime('%Y', bt.transaction_date) = strftime('%Y', 'now') THEN 1 ELSE 0 END), 0) as paid_months
    FROM deductions d
    JOIN employees e ON d.employee_id = e.id
    LEFT JOIN budget_transactions bt ON bt.reference_id = d.id AND bt.type = 'installment'
    WHERE d.is_loan = 1 
      AND strftime('%Y', d.start_date) <= strftime('%Y', 'now') 
      AND strftime('%Y', d.end_date) >= strftime('%Y', 'now')
    GROUP BY d.id
    ORDER BY e.name ASC, d.start_date ASC
")->fetchAll();
?>

<style>
    .dashboard { display: flex; flex-wrap: wrap; gap: 20px; margin-bottom: 30px; }
    .card { flex: 1; background: white; padding: 20px; border-radius: 20px; text-align: center; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
    .card .value { font-size: 28px; font-weight: bold; }
    .progress-bar { background: #e9ecef; border-radius: 30px; height: 20px; margin: 15px 0; overflow: hidden; }
    .progress-fill { background: #28a745; width: 0%; height: 100%; border-radius: 30px; }
    .progress-fill.warning { background: #dc3545; }
    table { width: 100%; border-collapse: collapse; background: white; margin-top: 20px; border-radius: 16px; overflow: hidden; }
    th, td { padding: 12px; text-align: center; border-bottom: 1px solid #ddd; }
    th { background: #2a5298; color: white; }
    .badge-grant { background: #dc3545; color: white; padding: 4px 12px; border-radius: 20px; display: inline-block; }
    .badge-loan { background: #fd7e14; color: white; padding: 4px 12px; border-radius: 20px; display: inline-block; }
    .badge-installment { background: #28a745; color: white; padding: 4px 12px; border-radius: 20px; display: inline-block; }
    .btn { display: inline-block; padding: 8px 16px; background: #2a5298; color: white; border-radius: 20px; text-decoration: none; margin-top: 15px; }
</style>

<h2>📊 لوحة تحكم الميزانية - سنة <?= $year ?></h2>

<div class="dashboard">
    <div class="card">
        <h3>💰 الميزانية المتبقية</h3>
        <div class="value"><?= number_format($remaining, 2) ?> دج</div>
    </div>
    <div class="card">
        <h3>📉 إجمالي الصرف</h3>
        <div class="value"><?= number_format($spent, 2) ?> دج</div>
        <div class="progress-bar">
            <div class="progress-fill <?= $spentPercent > 80 ? 'warning' : '' ?>" style="width: <?= $spentPercent ?>%;"></div>
        </div>
        <div><?= $spentPercent ?>% من الميزانية</div>
    </div>
    <div class="card">
        <h3>🎁 المنح</h3>
        <div class="value"><?= number_format($totalGrants, 2) ?> دج</div>
    </div>
    <div class="card">
        <h3>💰 السلف</h3>
        <div class="value"><?= number_format($totalLoans, 2) ?> دج</div>
    </div>
    <div class="card">
        <h3>🔄 الأقساط المردودة</h3>
        <div class="value"><?= number_format($totalInstallments, 2) ?> دج</div>
    </div>
</div>

<h3>📋 آخر العمليات</h3>
<table>
    <thead>
        <tr><th>التاريخ</th><th>النوع</th><th>الوصف</th><th>المبلغ</th></tr>
    </thead>
    <tbody>
        <?php foreach($transactions as $t): 
            $typeClass = $t['type'] == 'grant' ? 'badge-grant' : ($t['type'] == 'loan' ? 'badge-loan' : 'badge-installment');
            $typeName = $t['type'] == 'grant' ? 'منحة' : ($t['type'] == 'loan' ? 'سلفة' : 'قسط مردود');
        ?>
        <tr>
            <td><?= date('d/m/Y H:i', strtotime($t['transaction_date'])) ?></span></small></td>
            <td><span class="<?= $typeClass ?>"><?= $typeName ?></span></span></small></td>
            <td><?= htmlspecialchars($t['description']) ?> </span></small></td>
            <td><?= number_format($t['amount'], 2) ?> دج</span></small></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<h3>📊 تفاصيل الأقساط المردودة</h3>
<table>
    <thead>
        <tr>
            <th>الموظف</th>
            <th>المبلغ الشهري</th>
            <th>إجمالي الأقساط</th>
            <th>الأقساط المردودة</th>
            <th>المتبقي</th>
            <th>تاريخ البداية</th>
            <th>تاريخ النهاية</th>
         </tr>
    </thead>
    <tbody>
        <?php foreach($installmentsDetails as $row): 
            $remainingMonths = $row['total_months'] - $row['paid_months'];
            $remainingAmount = $remainingMonths * $row['monthly_amount'];
        ?>
        <tr>
            <td><?= htmlspecialchars($row['employee_name']) ?> </span></small></td>
            <td><?= number_format($row['monthly_amount'], 2) ?> دج</span></small></td>
            <td><?= $row['total_months'] ?> قسط</span></small></td>
            <td><?= $row['paid_months'] ?> قسط</span></small></td>
            <td><strong><?= number_format($remainingAmount, 2) ?> دج</strong></span></small></td>
            <td><?= date('d/m/Y', strtotime($row['start_date'])) ?> </span></small></td>
            <td><?= date('d/m/Y', strtotime($row['end_date'])) ?> </span></small></td>
         </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<a href="index.php" class="btn">🔙 العودة للميزانية</a>

<?php include '../includes/footer.php'; ?>