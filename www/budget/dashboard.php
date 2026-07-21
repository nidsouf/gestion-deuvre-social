<?php
/**
 * budget/dashboard.php - لوحة تحكم الميزانية (محسّنة)
 * مع زر إعادة حساب الميزانية
 */
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';
require_once '../includes/budget_helpers.php'; // ← إضافة هذا السطر

$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
$stats = getBudgetStats($pdo, $year);
$transactions = getBudgetTransactions($pdo, ['year' => $year, 'limit' => 10]);
$years = getBudgetYears($pdo);

$csrf_token = generateCSRFToken();

include '../includes/header.php';
?>
<link rel="stylesheet" href="../assets/css/budget.css">

<div class="budget-container">
    <div class="budget-header">
        <h2>📊 لوحة تحكم الميزانية - سنة <?= $year ?></h2>
    </div>

    <!-- اختيار السنة + زر إعادة الحساب -->
    <div style="margin-bottom:20px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
        <form method="GET" style="display:flex; gap:10px; align-items:center;">
            <label for="year">السنة:</label>
            <select name="year" id="year">
                <?php foreach ($years as $y): ?>
                    <option value="<?= $y ?>" <?= $y == $year ? 'selected' : '' ?>><?= $y ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary">عرض</button>
        </form>
        
        <!-- زر إعادة حساب الميزانية -->
        <form method="POST" action="recalculate.php" style="display:inline;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="year" value="<?= $year ?>">
            <button type="submit" class="btn btn-warning" onclick="return confirm('⚠️ هل أنت متأكد من إعادة حساب الميزانية؟ سيتم تحديث الرصيد المتبقي من المعاملات المسجلة.')">
                🔄 إعادة حساب الميزانية
            </button>
        </form>
        
        <a href="index.php" class="btn btn-secondary" style="margin-right:auto;">⚙️ إدارة الميزانية</a>
    </div>

    <!-- بطاقات الإحصائيات -->
    <div class="stats-grid">
        <div class="stat-card remaining">
            <div class="stat-label">✅ الميزانية المتبقية</div>
            <div class="stat-value" style="color: <?= $stats['remaining'] >= 0 ? '#17a2b8' : '#dc3545' ?>;">
                <?= formatCurrency($stats['remaining']) ?>
            </div>
        </div>
        <div class="stat-card expenses">
            <div class="stat-label">💸 إجمالي الصرف</div>
            <div class="stat-value"><?= formatCurrency($stats['total_expenses']) ?></div>
        </div>
        <div class="stat-card refunds">
            <div class="stat-label">🔄 استرجاعات السلف</div>
            <div class="stat-value"><?= formatCurrency($stats['total_refunds']) ?></div>
        </div>
        <div class="stat-card loans">
            <div class="stat-label">💰 السلف</div>
            <div class="stat-value"><?= formatCurrency($stats['total_loans']) ?></div>
        </div>
        <div class="stat-card grants">
            <div class="stat-label">🎁 المنح الفعلية</div>
            <div class="stat-value"><?= formatCurrency($stats['total_grants']) ?></div>
        </div>
        <div class="stat-card installments">
            <div class="stat-label">🔄 الأقساط المردودة</div>
            <div class="stat-value"><?= formatCurrency($stats['total_installments']) ?></div>
        </div>
    </div>

    <!-- شريط التقدم -->
    <div class="progress-section">
        <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
            <span>الميزانية المستخدمة</span>
            <span><?= $stats['spent_percent'] ?>%</span>
        </div>
        <div class="progress-bar-container">
            <div class="progress-bar-fill" style="width: <?= $stats['spent_percent'] ?>%;">
                <?= $stats['spent_percent'] ?>%
            </div>
        </div>
        <div style="margin-top:10px; color:#666; font-size:14px;">
            الميزانية الأولية: <?= formatCurrency($stats['initial']) ?>
        </div>
        <div style="margin-top:5px; font-size:13px; color:#888;">
            <small>ℹ️ تم تحديث الميزانية من <?= count($transactions) ?> معاملة</small>
        </div>
    </div>

    <!-- رسوم بيانية بسيطة -->
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;">
        <div class="chart-container">
            <h4>📊 توزيع المصروفات حسب النوع</h4>
            <div>
                <div><span style="background:#2a5298; display:inline-block; width:20px; height:20px;"></span> سلف: <?= formatCurrency($stats['total_loans']) ?></div>
                <div><span style="background:#28a745; display:inline-block; width:20px; height:20px;"></span> منح: <?= formatCurrency($stats['total_grants']) ?></div>
                <div><span style="background:#6c757d; display:inline-block; width:20px; height:20px;"></span> أقساط: <?= formatCurrency($stats['total_installments']) ?></div>
            </div>
        </div>
        <div class="chart-container">
            <h4>📊 الصرف vs الاسترجاع</h4>
            <div>
                <div><span style="background:#dc3545; display:inline-block; width:20px; height:20px;"></span> صرف: <?= formatCurrency($stats['total_expenses']) ?></div>
                <div><span style="background:#ffc107; display:inline-block; width:20px; height:20px;"></span> استرجاع: <?= formatCurrency($stats['total_refunds']) ?></div>
                <div><span style="background:#17a2b8; display:inline-block; width:20px; height:20px;"></span> صافي: <?= formatCurrency($stats['total_expenses'] - $stats['total_refunds']) ?></div>
            </div>
        </div>
    </div>

    <!-- آخر المعاملات -->
    <h3 style="margin:30px 0 15px;">🕒 آخر المعاملات</h3>
    <table class="data-table">
        <thead>
            <tr><th>التاريخ</th><th>النوع</th><th>الوصف</th><th>المبلغ (دج)</th><th>اتجاه</th></tr>
        </thead>
        <tbody>
            <?php if (empty($transactions)): ?>
                <tr><td colspan="5" style="text-align:center;">لا توجد معاملات</td></tr>
            <?php else: ?>
                <?php foreach ($transactions as $t): ?>
                <tr>
                    <td><?= date('d/m/Y H:i', strtotime($t['transaction_date'])) ?></td>
                    <td><?= $t['type_label'] ?></td>
                    <td><?= htmlspecialchars($t['description'] ?? '') ?></td>
                    <td class="<?= $t['is_deduct'] ? 'debit' : 'credit' ?>">
                        <?= $t['is_deduct'] ? '-' : '+' ?> <?= formatCurrency($t['amount']) ?>
                    </td>
                    <td><?= $t['direction'] ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <div style="margin-top:20px; text-align:center;">
        <a href="report.php?year=<?= $year ?>" class="btn btn-primary">📄 عرض التقرير الكامل</a>
        <a href="index.php" class="btn btn-secondary">⚙️ إدارة الميزانية</a>
    </div>
</div>

<?php include '../includes/footer.php'; ?>