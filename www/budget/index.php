<?php
/**
 * budget/index.php - إدارة الميزانية الاجتماعية (مع أزرار تعديل وإعادة تعيين)
 */
ob_start(); // ← أضف هذا السطر
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';

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

include '../includes/header.php';

// جلب بيانات الميزانية
$budgets = $pdo->query("SELECT * FROM social_budget ORDER BY year DESC")->fetchAll();
$lastBudget = $pdo->query("SELECT * FROM social_budget ORDER BY year DESC LIMIT 1")->fetch();

// تصحيح الاستعلام: استخدام g.amount بدلاً من eg.amount
$totalGrants = $pdo->query("SELECT COALESCE(SUM(g.amount), 0) FROM employee_grants eg JOIN grants g ON eg.grant_id = g.id")->fetchColumn();

$csrf_token = generateCSRFToken();
?>

<style>
    .stats-grid { display: flex; gap: 20px; margin-bottom: 30px; flex-wrap: wrap; }
    .stat-card { background: white; border-radius: 20px; padding: 20px; text-align: center; flex: 1; min-width: 200px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
    .stat-card .number { font-size: 28px; font-weight: 700; }
    .btn-add { background: #28a745; color: white; padding: 8px 20px; border-radius: 30px; text-decoration: none; display: inline-block; margin-bottom: 20px; transition: 0.3s; }
    .btn-add:hover { background: #218838; transform: translateY(-2px); }
    .btn-edit { background: #ffc107; color: #000; padding: 4px 12px; border-radius: 20px; text-decoration: none; margin: 0 3px; display: inline-block; font-size: 13px; }
    .btn-edit:hover { background: #e0a800; }
    .btn-reset { background: #fd7e14; color: white; padding: 4px 12px; border-radius: 20px; text-decoration: none; margin: 0 3px; display: inline-block; font-size: 13px; }
    .btn-reset:hover { background: #e36209; }
    .data-table { width: 100%; border-collapse: collapse; background: white; border-radius: 15px; overflow: hidden; }
    .data-table th, .data-table td { border: 1px solid #ddd; padding: 12px 10px; text-align: center; }
    .data-table th { background: #2a5298; color: white; }
    .action-buttons { display: flex; justify-content: center; gap: 8px; flex-wrap: wrap; }
    .note { font-size: 12px; color: #6c757d; margin-top: 20px; text-align: center; }
</style>

<div style="max-width: 1200px; margin: 0 auto; padding: 0 15px;">
    <h2 style="margin-bottom: 20px;">🏛️ إدارة الميزانية الاجتماعية</h2>
    <?php if (isset($_GET['updated'])): ?>
        <div style="background: #d4edda; color: #155724; padding: 12px; border-radius: 10px; margin-bottom: 20px; text-align: center;">
        ✅ تم تعديل الميزانية بنجاح!
        </div>
    <?php endif; ?>
    <div class="stats-grid">
        <div class="stat-card"><div>💰 الميزانية الحالية</div><div class="number"><?= number_format($lastBudget['remaining_budget'] ?? 0, 2) ?> دج</div></div>
        <div class="stat-card"><div>📊 إجمالي المنح الممنوحة</div><div class="number"><?= number_format($totalGrants, 2) ?> دج</div></div>
        <div class="stat-card"><div>📅 آخر سنة مسجلة</div><div class="number"><?= $lastBudget['year'] ?? '—' ?></div></div>
    </div>
    
    <a href="create.php" class="btn-add">➕ إضافة ميزانية جديدة</a>
    
    <div style="overflow-x: auto;">
        <table class="data-table">
            <thead>
                <tr><th>السنة</th><th>الميزانية الأولية (دج)</th><th>الميزانية المتبقية (دج)</th><th>آخر تحديث</th><th>الإجراءات</th></tr>
            </thead>
            <tbody>
                <?php if (empty($budgets)): ?>
                    <tr><td colspan="5" style="text-align:center;">لا توجد بيانات ميزانية</td></tr>
                <?php else: ?>
                    <?php foreach ($budgets as $b): ?>
                        <tr>
                            <td><strong><?= escape($b['year']) ?></strong></td>
                            <td><?= number_format($b['initial_budget'], 2) ?> دج</td>
                            <td><?= number_format($b['remaining_budget'], 2) ?> دج</td>
                            <td><?= date('d/m/Y', strtotime($b['last_updated'] ?? $b['created_at'] ?? 'now')) ?></td>
                            <td class="action-buttons">
                                <a href="edit.php?id=<?= $b['id'] ?>" class="btn-edit" title="تعديل">✏️ تعديل</a>
                                <?php if ($b['remaining_budget'] != $b['initial_budget']): ?>
                                    <a href="reset.php?id=<?= $b['id'] ?>" class="btn-reset" title="إعادة تعيين المتبقية" onclick="return confirm('⚠️ هل أنت متأكد؟ سيتم إعادة تعيين الرصيد المتبقي إلى <?= number_format($b['initial_budget'], 2) ?> دج.')">🔄 إعادة تعيين</a>
                                <?php else: ?>
                                    <span class="btn-reset" style="background:#ccc; cursor:not-allowed;">🔒 متساوية</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="note">ℹ️ زر "إعادة تعيين" يظهر فقط عندما تكون الميزانية المتبقية أقل من الأولية.</div>
</div>

<?php
ob_end_flush();
include '../includes/footer.php';
?>