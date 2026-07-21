<?php
/**
 * budget/report.php - تقرير الميزانية المفصل
 */
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';
require_once '../includes/budget_helpers.php';

$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
$type = isset($_GET['type']) ? $_GET['type'] : 'all';
$transactions = getBudgetTransactions($pdo, ['year' => $year, 'type' => $type, 'limit' => 1000]);
$years = getBudgetYears($pdo);

// حساب الإجماليات
$totalDebit = 0;
$totalCredit = 0;
foreach ($transactions as $t) {
    if ($t['is_deduct']) {
        $totalDebit += $t['amount'];
    } else {
        $totalCredit += $t['amount'];
    }
}
$net = $totalCredit - $totalDebit;

include '../includes/header.php';
?>
<link rel="stylesheet" href="../assets/css/budget.css">
<div class="budget-container">
    <h2>📊 تقرير الميزانية - سنة <?= $year ?></h2>

    <div class="filters">
        <form method="GET" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
            <div class="filter-group">
                <label>السنة</label>
                <select name="year">
                    <?php foreach ($years as $y): ?>
                        <option value="<?= $y ?>" <?= $y == $year ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>النوع</label>
                <select name="type">
                    <option value="all" <?= $type == 'all' ? 'selected' : '' ?>>الكل</option>
                    <option value="grant" <?= $type == 'grant' ? 'selected' : '' ?>>منح</option>
                    <option value="loan" <?= $type == 'loan' ? 'selected' : '' ?>>سلف</option>
                    <option value="installment" <?= $type == 'installment' ? 'selected' : '' ?>>أقساط</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">🔍 عرض</button>
            <a href="report.php?year=<?= $year ?>&type=<?= $type ?>&print=1" target="_blank" class="btn btn-success">🖨️ طباعة</a>
        </form>
    </div>

    <!-- ملخص سريع -->
    <div style="display:flex; gap:20px; flex-wrap:wrap; margin-bottom:20px;">
        <div style="background:#f8f9fa; padding:15px; border-radius:10px; flex:1;">
            <strong>إجمالي الخصم (الصرف):</strong> <?= formatCurrency($totalDebit) ?>
        </div>
        <div style="background:#f8f9fa; padding:15px; border-radius:10px; flex:1;">
            <strong>إجمالي الإضافة (الاسترجاعات):</strong> <?= formatCurrency($totalCredit) ?>
        </div>
        <div style="background:#e3f2fd; padding:15px; border-radius:10px; flex:1;">
            <strong>صافي الميزانية:</strong> <?= formatCurrency($net) ?>
        </div>
    </div>

    <!-- الجدول -->
    <table class="data-table">
        <thead>
            <tr><th>#</th><th>التاريخ</th><th>النوع</th><th>الوصف</th><th>المبلغ (دج)</th><th>اتجاه</th></tr>
        </thead>
        <tbody>
            <?php if (empty($transactions)): ?>
                <tr><td colspan="6" style="text-align:center;">لا توجد معاملات</td></tr>
            <?php else: $i=1; foreach ($transactions as $t): ?>
                <tr>
                    <td><?= $i++ ?></td>
                    <td><?= date('d/m/Y H:i', strtotime($t['transaction_date'])) ?></td>
                    <td><?= $t['type_label'] ?></td>
                    <td><?= htmlspecialchars($t['description'] ?? '') ?></td>
                    <td class="<?= $t['is_deduct'] ? 'debit' : 'credit' ?>"><?= $t['is_deduct'] ? '-' : '+' ?> <?= formatCurrency($t['amount']) ?></td>
                    <td><?= $t['direction'] ?></td>
                </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <div style="margin-top:20px;">
        <a href="dashboard.php?year=<?= $year ?>" class="btn btn-primary">🔙 العودة للوحة التحكم</a>
    </div>
</div>
<?php include '../includes/footer.php'; ?>