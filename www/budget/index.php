<?php
/**
 * budget/index.php - إدارة الميزانية الاجتماعية (قائمة السنوات)
 */
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';
require_once '../includes/budget_helpers.php';

// عرض رسائل التنبيه
if (isset($_SESSION['toast'])) {
    $toast = $_SESSION['toast'];
    echo "<script>
        document.addEventListener('DOMContentLoaded', function() {
            toastr.options = {
                'closeButton': true,
                'progressBar': true,
                'positionClass': 'toast-top-left',
                'timeOut': " . ($toast['duration'] ?? 3000) . ",
                'rtl': true
            };
            toastr.{$toast['type']}('{$toast['message']}');
        });
    </script>";
    unset($_SESSION['toast']);
}

$budgets = getBudgetSummary($pdo);
$lastBudget = $pdo->query("SELECT * FROM social_budget ORDER BY year DESC LIMIT 1")->fetch();
$totalGrants = $pdo->query("SELECT COALESCE(SUM(g.amount), 0) FROM employee_grants eg JOIN grants g ON eg.grant_id = g.id")->fetchColumn();

$csrf_token = generateCSRFToken();
include '../includes/header.php';
?>
<link rel="stylesheet" href="../assets/css/budget.css">

<div style="max-width:1200px; margin:0 auto; padding:0 15px;">
    <h2 style="margin-bottom:20px;">🏛️ إدارة الميزانية الاجتماعية</h2>

    <div class="stats-grid">
        <div class="stat-card remaining">
            <div class="stat-label">💰 الميزانية الحالية</div>
            <div class="stat-value"><?= formatCurrency($lastBudget['remaining_budget'] ?? 0) ?></div>
        </div>
        <div class="stat-card grants">
            <div class="stat-label">📊 إجمالي المنح الممنوحة</div>
            <div class="stat-value"><?= formatCurrency($totalGrants) ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">📅 آخر سنة مسجلة</div>
            <div class="stat-value"><?= $lastBudget['year'] ?? '—' ?></div>
        </div>
    </div>

    <a href="create.php" class="btn btn-success" style="margin-bottom:20px;">➕ إضافة ميزانية جديدة</a>

    <div style="overflow-x:auto;">
        <table class="data-table">
            <thead>
                <tr><th>السنة</th><th>الميزانية الأولية</th><th>الميزانية المتبقية</th><th>إجمالي الصرف</th><th>الاسترجاعات</th><th>الإجراءات</th></tr>
            </thead>
            <tbody>
                <?php if (empty($budgets)): ?>
                    <tr><td colspan="6" style="text-align:center;">لا توجد ميزانية مسجلة</td></tr>
                <?php else: ?>
                    <?php foreach ($budgets as $b): ?>
                        <tr>
                            <td><strong><?= escape($b['year']) ?></strong></td>
                            <td><?= formatCurrency($b['initial_budget']) ?></td>
                            <td><?= formatCurrency($b['remaining_budget']) ?></td>
                            <td><?= formatCurrency($b['total_expenses'] ?? 0) ?></td>
                            <td><?= formatCurrency($b['total_refunds'] ?? 0) ?></td>
                            <td class="action-buttons" style="display:flex; gap:8px; justify-content:center;">
                                <a href="edit.php?id=<?= $b['id'] ?>" class="btn btn-warning btn-sm">✏️ تعديل</a>
                                <?php if ($b['remaining_budget'] != $b['initial_budget']): ?>
                                    <a href="reset.php?id=<?= $b['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('⚠️ هل أنت متأكد؟ سيتم إعادة تعيين الرصيد المتبقي إلى <?= number_format($b['initial_budget'], 2) ?> دج.')">🔄 إعادة تعيين</a>
                                <?php else: ?>
                                    <span class="btn btn-sm" style="background:#ccc; cursor:not-allowed;">🔒 متساوية</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div style="margin-top:15px; color:#6c757d; font-size:13px;">ℹ️ زر "إعادة تعيين" يظهر فقط عندما تكون الميزانية المتبقية أقل من الأولية.</div>
</div>
<?php include '../includes/footer.php'; ?>