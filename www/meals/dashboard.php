<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
require_once '../config/database.php';
require_once '../includes/functions.php';

$month = date('m');
$year = date('Y');

// ========== الإحصائيات من meal_records (المصدر الرئيسي) ==========

// 1. إجمالي المستفيدين الذين لديهم وجبات هذا الشهر
$stmtBeneficiaries = $pdo->prepare("
    SELECT COUNT(DISTINCT employee_id) 
    FROM meal_records 
    WHERE year = ? AND month = ? AND meal_count > 0
");
$stmtBeneficiaries->execute([$year, $month]);
$totalBeneficiaries = $stmtBeneficiaries->fetchColumn() ?: 0;

// 2. إجمالي الوجبات هذا الشهر
$stmtMeals = $pdo->prepare("
    SELECT SUM(meal_count) 
    FROM meal_records 
    WHERE year = ? AND month = ?
");
$stmtMeals->execute([$year, $month]);
$totalMealsMonth = $stmtMeals->fetchColumn() ?: 0;

// 3. إجمالي المبلغ هذا الشهر
$stmtAmount = $pdo->prepare("
    SELECT SUM(total_amount) 
    FROM meal_records 
    WHERE year = ? AND month = ?
");
$stmtAmount->execute([$year, $month]);
$totalAmountMonth = $stmtAmount->fetchColumn() ?: 0;

// 4. إجمالي منح الوجبات (من meal_installments)
$stmtGrants = $pdo->prepare("
    SELECT SUM(grant_amount) 
    FROM meal_installments 
    WHERE year = ? AND month = ? AND is_processed = 1
");
$stmtGrants->execute([$year, $month]);
$totalGrantsMonth = $stmtGrants->fetchColumn() ?: 0;

// 5. عدد الموظفين النشطين (الإجمالي)
$stmtEmployees = $pdo->query("SELECT COUNT(*) FROM employees");
$totalEmployees = $stmtEmployees->fetchColumn() ?: 0;

include '../includes/header.php';
?>

<style>
    .dashboard-container { max-width: 1200px; margin: 0 auto; padding: 20px; }
    .dashboard-header { background: linear-gradient(135deg, #1e3c72, #2a5298); color: white; padding: 20px; border-radius: 15px; margin-bottom: 25px; text-align: center; }
    .dashboard-header h2 { margin: 0; }
    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
    .stat-card { background: white; border-radius: 20px; padding: 20px; text-align: center; box-shadow: 0 2px 10px rgba(0,0,0,0.05); transition: transform 0.2s; }
    .stat-card:hover { transform: translateY(-3px); }
    .stat-card .number { font-size: 32px; font-weight: 700; }
    .stat-card .label { color: #666; font-size: 14px; margin-top: 5px; }
    .stat-card .icon { font-size: 28px; margin-bottom: 10px; }
    .stat-card.primary .number { color: #2a5298; }
    .stat-card.success .number { color: #28a745; }
    .stat-card.warning .number { color: #fd7e14; }
    .stat-card.info .number { color: #17a2b8; }
    .stat-card.danger .number { color: #dc3545; }
    .quick-actions { display: flex; gap: 15px; flex-wrap: wrap; justify-content: center; margin-top: 20px; }
    .quick-actions a { padding: 10px 25px; border-radius: 30px; text-decoration: none; color: white; font-weight: bold; }
    .btn-import { background: #28a745; }
    .btn-import:hover { background: #218838; }
    .btn-report { background: #17a2b8; }
    .btn-report:hover { background: #138496; }
    .btn-generate { background: #fd7e14; }
    .btn-generate:hover { background: #e36209; }
    .btn-export { background: #6c757d; }
    .btn-export:hover { background: #5a6268; }
    /* تحسينات التصميم المتجاوب */
@media (max-width: 768px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    .quick-actions {
        flex-direction: column;
        align-items: stretch;
    }
    .quick-actions a {
        text-align: center;
    }
}
@media (max-width: 480px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="dashboard-container">
    <div class="dashboard-header">
        <h2>🍽️ لوحة تحكم وجبات المطعم</h2>
        <p style="margin: 5px 0 0; opacity: 0.8;">الشهر الحالي: <?= getMonthNameArabic($month) . ' ' . $year ?></p>
    </div>

    <!-- الإحصائيات -->
    <div class="stats-grid">
        <div class="stat-card primary">
            <div class="icon">👥</div>
            <div class="number"><?= number_format($totalBeneficiaries) ?></div>
            <div class="label">المستفيدين هذا الشهر</div>
        </div>
        
        <div class="stat-card success">
            <div class="icon">🍽️</div>
            <div class="number"><?= number_format($totalMealsMonth) ?></div>
            <div class="label">إجمالي الوجبات</div>
        </div>
        
        <div class="stat-card info">
            <div class="icon">💰</div>
            <div class="number"><?= number_format($totalAmountMonth, 2) ?> دج</div>
            <div class="label">قيمة الوجبات</div>
        </div>
        
        <div class="stat-card warning">
            <div class="icon">🎁</div>
            <div class="number"><?= number_format($totalGrantsMonth, 2) ?> دج</div>
            <div class="label">منح الوجبات</div>
        </div>
    </div>

    <!-- الإجراءات السريعة -->
    <div class="quick-actions">
        <a href="import_monthly.php" class="btn-import">📥 استيراد تقرير شهري</a>
        <a href="report.php" class="btn-report">📊 عرض التقرير</a>
        <a href="generate_grant.php?month=<?= $month ?>&year=<?= $year ?>" class="btn-generate" onclick="return confirm('⚠️ توليد منح الوجبات لهذا الشهر؟')">🎁 توليد المنحة</a>
        <a href="export_manager.php" class="btn-export">📤 تصدير البيانات</a>
    </div>

    <?php if ($totalMealsMonth == 0): ?>
        <div style="background: #fff3cd; padding: 15px; border-radius: 10px; margin-top: 20px; text-align: center;">
            ⚠️ لا توجد وجبات مسجلة لهذا الشهر. قم باستيراد تقرير CSV من تطبيق المطعم.
        </div>
    <?php endif; ?>
</div>

<?php include '../includes/footer.php'; ?>