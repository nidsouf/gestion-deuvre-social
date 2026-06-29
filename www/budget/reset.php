<?php
/**
 * budget/reset.php - إعادة تعيين الميزانية المتبقية إلى قيمتها الأولية
 */
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';

$id = isset($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT) : 0;

if (!$id) {
    $_SESSION['toast'] = ['message' => '⚠️ لم يتم تحديد الميزانية', 'type' => 'warning', 'duration' => 3000];
    header("Location: index.php");
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM social_budget WHERE id = ?");
    $stmt->execute([$id]);
    $budget = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$budget) {
        $_SESSION['toast'] = ['message' => '⚠️ الميزانية غير موجودة', 'type' => 'warning', 'duration' => 3000];
        header("Location: index.php");
        exit;
    }
} catch (PDOException $e) {
    error_log("Budget fetch error: " . $e->getMessage());
    $_SESSION['toast'] = ['message' => '❌ حدث خطأ في قاعدة البيانات', 'type' => 'error', 'duration' => 3000];
    header("Location: index.php");
    exit;
}

if ($budget['remaining_budget'] == $budget['initial_budget']) {
    $_SESSION['toast'] = ['message' => 'ℹ️ الميزانية المتبقية تساوي بالفعل الميزانية الأولية', 'type' => 'info', 'duration' => 3000];
    header("Location: index.php");
    exit;
}

$confirmed = isset($_GET['confirm']) && $_GET['confirm'] === 'yes';

if ($confirmed) {
    requireCSRFToken();
    
    if (isRateLimited('budget_reset', 3, 3600)) {
        $_SESSION['toast'] = ['message' => '⚠️ لقد تجاوزت عدد المحاولات المسموحة', 'type' => 'warning', 'duration' => 3000];
        header("Location: index.php");
        exit;
    }
    
    try {
        $result = db_transaction(function($pdo) use ($id, $budget) {
            $stmt = $pdo->prepare("UPDATE social_budget SET remaining_budget = initial_budget, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            return $stmt->execute([$id]);
        });
        
        if ($result) {
            audit('BUDGET_RESET', "تم إعادة تعيين ميزانية السنة {$budget['year']} من " . number_format($budget['remaining_budget'], 2) . " دج إلى " . number_format($budget['initial_budget'], 2) . " دج");
            addNotification('إعادة تعيين ميزانية', "تم إعادة تعيين ميزانية السنة {$budget['year']} إلى قيمتها الأولية", null, 'warning');
            $_SESSION['toast'] = ['message' => '✅ تم إعادة تعيين الميزانية المتبقية بنجاح', 'type' => 'success', 'duration' => 4000];
        } else {
            throw new Exception('فشل تحديث قاعدة البيانات');
        }
    } catch (Exception $e) {
        error_log("Budget reset error: " . $e->getMessage());
        $_SESSION['toast'] = ['message' => '❌ فشل إعادة تعيين الميزانية', 'type' => 'error', 'duration' => 3000];
    }
    
    header("Location: index.php");
    exit;
}

$csrf_token = generateCSRFToken();
include '../includes/header.php';
?>

<style>
.reset-container {
    max-width: 500px;
    margin: 50px auto;
    background: white;
    padding: 30px;
    border-radius: 20px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.15);
    text-align: center;
}
.reset-container .warning-icon { font-size: 64px; margin-bottom: 20px; }
.reset-container h2 { color: #dc3545; margin-bottom: 20px; }
.reset-container .budget-info { background: #f8f9fa; padding: 15px; border-radius: 12px; margin: 20px 0; text-align: right; }
.reset-container .budget-info .old-value { color: #dc3545; font-weight: bold; font-size: 18px; }
.reset-container .budget-info .new-value { color: #28a745; font-weight: bold; font-size: 18px; }
.reset-container .warning-message { background: #fff3cd; color: #856404; padding: 12px; border-radius: 10px; margin: 20px 0; font-size: 14px; }
.button-group { display: flex; gap: 15px; margin-top: 25px; }
.btn-confirm { flex: 1; background: #dc3545; color: white; padding: 12px; border: none; border-radius: 30px; font-weight: bold; cursor: pointer; }
.btn-confirm:hover { background: #c82333; transform: translateY(-2px); }
.btn-cancel { flex: 1; background: #6c757d; color: white; padding: 12px; border: none; border-radius: 30px; text-decoration: none; text-align: center; }
.btn-cancel:hover { background: #5a6268; transform: translateY(-2px); }
hr { margin: 20px 0; border-top: 1px solid #ddd; }
</style>

<div class="reset-container">
    <div class="warning-icon">⚠️</div>
    <h2>إعادة تعيين الميزانية</h2>
    
    <div class="budget-info">
        <p><strong>📅 السنة:</strong> <?= escape($budget['year']) ?></p>
        <p><strong>💰 الميزانية الأولية:</strong> <?= number_format($budget['initial_budget'], 2) ?> دج</p>
        <p><strong>📉 الميزانية المتبقية حالياً:</strong> <span class="old-value"><?= number_format($budget['remaining_budget'], 2) ?> دج</span></p>
        <hr>
        <p><strong>🔄 بعد إعادة التعيين:</strong> <span class="new-value"><?= number_format($budget['initial_budget'], 2) ?> دج</span></p>
    </div>
    
    <div class="warning-message">⚠️ <strong>تنبيه:</strong> إعادة التعيين ستعيد الميزانية المتبقية إلى قيمتها الأولية. هذا الإجراء لا يمكن التراجع عنه.</div>
    
    <form method="GET" action="">
        <input type="hidden" name="id" value="<?= escape($id) ?>">
        <input type="hidden" name="confirm" value="yes">
        <input type="hidden" name="csrf_token" value="<?= escape($csrf_token) ?>">
        <div class="button-group">
            <button type="submit" class="btn-confirm" onclick="return confirm('هل أنت متأكد من إعادة تعيين الميزانية؟')">✅ تأكيد إعادة التعيين</button>
            <a href="index.php" class="btn-cancel">🔙 إلغاء</a>
        </div>
    </form>
</div>

<?php include '../includes/footer.php'; ?>