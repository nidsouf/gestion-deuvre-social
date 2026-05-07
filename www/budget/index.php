<?php
ob_start();
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

error_reporting(0);

require_once '../config/database.php';
require_once '../includes/functions.php';

$selectedYear = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');

// جلب الميزانية للسنة المحددة
$stmt = $pdo->prepare("SELECT * FROM social_budget WHERE year = ?");
$stmt->execute([$selectedYear]);
$budget = $stmt->fetch();

if (!$budget) {
    $remaining = 0;
    $initial = 0;
} else {
    $remaining = $budget['remaining_budget'];
    $initial = $budget['initial_budget'];
}

// إجمالي الصرف (حيث is_deduct = 1)
$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM budget_transactions WHERE is_deduct = 1 AND strftime('%Y', transaction_date) = ?");
$stmt->execute([$selectedYear]);
$totalDeduct = $stmt->fetchColumn();

// إجمالي المنح
$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM budget_transactions WHERE type = 'grant' AND strftime('%Y', transaction_date) = ?");
$stmt->execute([$selectedYear]);
$totalGrants = $stmt->fetchColumn();

// إجمالي السلف (الخصم عند صرف السلفة)
$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM budget_transactions WHERE type = 'loan' AND is_deduct = 1 AND strftime('%Y', transaction_date) = ?");
$stmt->execute([$selectedYear]);
$totalLoans = $stmt->fetchColumn();

// إجمالي الأقساط المردودة (حيث type = 'installment' و is_deduct = 0)
$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM budget_transactions WHERE type = 'installment' AND is_deduct = 0 AND strftime('%Y', transaction_date) = ?");
$stmt->execute([$selectedYear]);
$totalInstallments = $stmt->fetchColumn();

ob_end_clean();
include '../includes/header.php';
?>

<style>
    .stats-grid { display: flex; gap: 20px; margin-bottom: 30px; flex-wrap: wrap; }
    .card { background: white; padding: 20px; border-radius: 20px; flex: 1; text-align: center; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
    .card .value { font-size: 28px; font-weight: bold; color: #2a5298; }
    .filters { background: #f0f2f5; padding: 15px; border-radius: 12px; margin-bottom: 20px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
    .btn-sm { background: #2a5298; color: white; padding: 8px 15px; border-radius: 8px; text-decoration: none; border: none; cursor: pointer; }
</style>

<div style="max-width: 1200px; margin: 0 auto;">
    <h2 style="margin-bottom: 20px;">إدارة الميزانية - سنة <?= $selectedYear ?></h2>

    <div class="filters">
        <form method="GET" style="display: flex; gap: 10px; margin:0; padding:0;">
            <select name="year">
                <?php for($y = 2020; $y <= date('Y') + 1; $y++): ?>
                    <option value="<?= $y ?>" <?= $y == $selectedYear ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
            <button type="submit" class="btn-sm">عرض</button>
            <a href="create.php" class="btn-sm" style="background: #28a745;">إضافة ميزانية جديدة</a>
            <a href="report.php" class="btn-sm">تقرير الميزانية</a>
            <a href="simulation.php" class="btn-sm">محاكاة الميزانية</a>
        </form>
    </div>

    <div class="stats-grid">
        <div class="card">
            <h3>الميزانية المتبقية</h3>
            <div class="value"><?= number_format($remaining, 2) ?> دج</div>
        </div>
        <div class="card">
            <h3>إجمالي الصرف</h3>
            <div class="value"><?= number_format($totalDeduct, 2) ?> دج</div>
        </div>
        <div class="card">
            <h3>المنح</h3>
            <div class="value"><?= number_format($totalGrants, 2) ?> دج</div>
        </div>
        <div class="card">
            <h3>السلف</h3>
            <div class="value"><?= number_format($totalLoans, 2) ?> دج</div>
        </div>
        <div class="card">
            <h3>الأقساط المردودة</h3>
            <div class="value"><?= number_format($totalInstallments, 2) ?> دج</div>
        </div>
    </div>

    <div style="background: #e3f2fd; padding: 15px; border-radius: 12px; margin-top: 20px;">
        <p><strong>ملاحظة:</strong> الميزانية المتبقية = الميزانية الأولية - (المنح + السلف) + الأقساط المردودة</p>
        <p>الميزانية الأولية: <?= number_format($initial, 2) ?> دج</p>
    </div>
</div>

<?php include '../includes/footer.php'; ?>