<?php
/**
 * budget/reset.php - إعادة تعيين الميزانية المتبقية إلى قيمتها الأولية
 */
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';
require_once '../includes/budget_helpers.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$budget = null;
if ($id) {
    $stmt = $pdo->prepare("SELECT * FROM social_budget WHERE id = ?");
    $stmt->execute([$id]);
    $budget = $stmt->fetch();
}
if (!$budget) {
    setToast('⚠️ الميزانية غير موجودة', 'warning');
    redirectBudget('index.php');
}

if ($budget['remaining_budget'] == $budget['initial_budget']) {
    setToast('ℹ️ الميزانية المتبقية تساوي بالفعل الميزانية الأولية', 'info');
    redirectBudget('index.php');
}

if (isset($_GET['confirm']) && $_GET['confirm'] === 'yes') {
    requireCSRFToken();
    if (isRateLimited('budget_reset', 3, 3600)) {
        setToast('⚠️ لقد تجاوزت عدد المحاولات المسموحة', 'warning');
        redirectBudget('index.php');
    }
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("UPDATE social_budget SET remaining_budget = initial_budget WHERE id = ?");
        $stmt->execute([$id]);
        $pdo->commit();
        audit('BUDGET_RESET', "تم إعادة تعيين ميزانية السنة {$budget['year']}");
        setToast('✅ تم إعادة تعيين الميزانية المتبقية بنجاح', 'success');
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Budget reset error: " . $e->getMessage());
        setToast('❌ فشل إعادة تعيين الميزانية', 'error');
    }
    redirectBudget('index.php');
}

$csrf_token = generateCSRFToken();
include '../includes/header.php';
?>
<link rel="stylesheet" href="../assets/css/budget.css">
<div style="max-width:500px; margin:50px auto; background:white; padding:30px; border-radius:20px; box-shadow:0 10px 30px rgba(0,0,0,0.15); text-align:center;">
    <div style="font-size:64px; margin-bottom:20px;">⚠️</div>
    <h2 style="color:#dc3545;">إعادة تعيين الميزانية</h2>
    <div style="background:#f8f9fa; padding:15px; border-radius:12px; margin:20px 0; text-align:right;">
        <p><strong>📅 السنة:</strong> <?= $budget['year'] ?></p>
        <p><strong>💰 الميزانية الأولية:</strong> <?= formatCurrency($budget['initial_budget']) ?></p>
        <p><strong>📉 الميزانية المتبقية حالياً:</strong> <span style="color:#dc3545; font-weight:bold;"><?= formatCurrency($budget['remaining_budget']) ?></span></p>
        <hr>
        <p><strong>🔄 بعد إعادة التعيين:</strong> <span style="color:#28a745; font-weight:bold;"><?= formatCurrency($budget['initial_budget']) ?></span></p>
    </div>
    <div style="background:#fff3cd; color:#856404; padding:12px; border-radius:10px; margin:20px 0; font-size:14px;">
        ⚠️ <strong>تنبيه:</strong> إعادة التعيين ستعيد الميزانية المتبقية إلى قيمتها الأولية. هذا الإجراء لا يمكن التراجع عنه.
    </div>
    <form method="GET" action="">
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="confirm" value="yes">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        <div style="display:flex; gap:15px; margin-top:25px;">
            <button type="submit" class="btn btn-danger" style="flex:1; padding:12px; border-radius:30px; font-weight:bold;">✅ تأكيد إعادة التعيين</button>
            <a href="index.php" class="btn btn-secondary" style="flex:1; padding:12px; border-radius:30px; text-decoration:none; text-align:center;">🔙 إلغاء</a>
        </div>
    </form>
</div>
<?php include '../includes/footer.php'; ?>