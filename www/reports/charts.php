<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../config/database.php';
require_once '../includes/functions.php';
include '../includes/header.php';

$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');

// إحصائيات عامة
$totalEmployees = $pdo->query("SELECT COUNT(*) FROM employees")->fetchColumn();
$totalDeductions = $pdo->query("SELECT COUNT(*) FROM deductions")->fetchColumn();
$totalAmount = $pdo->query("SELECT SUM(monthly_amount * total_months) FROM deductions")->fetchColumn();

// أعلى 5 موظفين
$topEmployees = $pdo->query("
    SELECT e.name, SUM(d.monthly_amount * d.total_months) as total
    FROM deductions d
    JOIN employees e ON d.employee_id = e.id
    GROUP BY e.id
    ORDER BY total DESC
    LIMIT 5
")->fetchAll();

// النسبة المئوية حسب المصدر
$sourceStats = $pdo->query("
    SELECT 
        s.name as source_name,
        COALESCE(SUM(d.monthly_amount * d.total_months), 0) as total
    FROM sources s
    LEFT JOIN deductions d ON d.source_id = s.id
    GROUP BY s.id
    ORDER BY total DESC
")->fetchAll();

$totalAllSources = array_sum(array_column($sourceStats, 'total'));

// إجمالي المنح
$totalGrants = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM grants")->fetchColumn();

// إجمالي السلف
$totalLoans = $pdo->query("SELECT COALESCE(SUM(monthly_amount * total_months), 0) FROM deductions WHERE is_loan = 1")->fetchColumn();
?>

<style>
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }
    .stat-card {
        background: white;
        border-radius: 20px;
        padding: 20px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    }
    .stat-card h3 {
        color: #1e3c72;
        margin-bottom: 15px;
        border-bottom: 2px solid #e0e0e0;
        padding-bottom: 8px;
    }
    .progress-bar {
        background: #e9ecef;
        border-radius: 30px;
        height: 20px;
        overflow: hidden;
        margin-top: 8px;
    }
    .progress-fill {
        background: #2a5298;
        height: 100%;
        border-radius: 30px;
        color: white;
        font-size: 11px;
        line-height: 20px;
        padding-right: 8px;
        text-align: right;
    }
    .stat-number {
        font-size: 24px;
        font-weight: bold;
        color: #2e7d32;
    }
    .top-employees-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 15px;
    }
    .top-employees-table th, .top-employees-table td {
        padding: 10px;
        text-align: center;
        border-bottom: 1px solid #ddd;
    }
    .top-employees-table th {
        background: #2a5298;
        color: white;
    }
    .top-employees-table td {
        background-color: #f8f9fa;
        color: #1e3c72;
        font-weight: bold;
    }
    .top-employees-table tr:hover td {
        background-color: #e9ecef;
    }
    .source-name {
        font-size: 16px;
        font-weight: bold;
        color: #1b5e20;
    }
    .source-percent {
        font-size: 14px;
        color: #2a5298;
    }
</style>

<h2>📊 الإحصائيات والتحليلات</h2>

<div class="stats-grid">
    <div class="stat-card">
        <h3>👥 إجمالي الموظفين</h3>
        <div class="stat-number"><?= $totalEmployees ?></div>
    </div>
    <div class="stat-card">
        <h3>📋 إجمالي الاقتطاعات</h3>
        <div class="stat-number"><?= $totalDeductions ?></div>
    </div>
    <div class="stat-card">
        <h3>💰 إجمالي المبالغ</h3>
        <div class="stat-number"><?= number_format($totalAmount, 2) ?> دج</div>
    </div>
    <div class="stat-card">
        <h3>🎁 إجمالي المنح</h3>
        <div class="stat-number"><?= number_format($totalGrants, 2) ?> دج</div>
    </div>
    <div class="stat-card">
        <h3>💰 إجمالي السلف</h3>
        <div class="stat-number"><?= number_format($totalLoans, 2) ?> دج</div>
    </div>
</div>

<!-- النسبة المئوية حسب المصدر -->
<div class="stat-card" style="margin-bottom: 30px;">
    <h3>📁 النسبة المئوية حسب المصدر</h3>
    <?php foreach($sourceStats as $src): 
        $percentage = $totalAllSources > 0 ? round(($src['total'] / $totalAllSources) * 100, 1) : 0;
    ?>
        <div style="margin-bottom: 25px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px;">
                <span class="source-name">📌 <?= htmlspecialchars($src['source_name']) ?></span>
                <span class="source-percent"><?= number_format($src['total'], 2) ?> دج (<?= $percentage ?>%)</span>
            </div>
            <div class="progress-bar">
                <div class="progress-fill" style="width: <?= $percentage ?>%;">
                    <?= $percentage > 10 ? $percentage . '%' : '' ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- أعلى 5 موظفين -->
<div class="stat-card">
    <h3>🏆 أعلى 5 موظفين اقتطاعاً</h3>
    <table class="top-employees-table">
        <thead>
            <tr><th>#</th><th>الموظف</th><th>الإجمالي</th></tr>
        </thead>
        <tbody>
            <?php $i = 1; foreach($topEmployees as $emp): ?>
            <tr>
                <td><?= $i++ ?></td>
                <td><?= htmlspecialchars($emp['name']) ?></td>
                <td><?= number_format($emp['total'], 2) ?> دج</span></small></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php include '../includes/footer.php'; ?>