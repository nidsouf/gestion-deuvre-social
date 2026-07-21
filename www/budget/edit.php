<?php
/**
 * budget/edit.php - تعديل ميزانية موجودة
 */
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';
require_once '../includes/budget_helpers.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$budget = null;
$error = '';

if ($id) {
    $stmt = $pdo->prepare("SELECT * FROM social_budget WHERE id = ?");
    $stmt->execute([$id]);
    $budget = $stmt->fetch();
}
if (!$budget) {
    setToast('⚠️ الميزانية غير موجودة', 'warning');
    redirectBudget('index.php');
}

$stats = getBudgetStats($pdo, $budget['year']);
$hasExpenses = ($stats['total_expenses'] > 0 || $stats['refunds'] > 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRFToken();
    $year = (int)$_POST['year'];
    $initial = (float)$_POST['initial_budget'];
    $remaining = (float)$_POST['remaining_budget'];

    if ($year < 2000 || $year > 2100 || $initial <= 0 || $remaining < 0) {
        $error = '⚠️ بيانات غير صالحة';
    } elseif ($remaining > $initial) {
        $error = '⚠️ الميزانية المتبقية لا يمكن أن تكون أكبر من الأولية';
    } else {
        // التحقق من عدم تكرار السنة
        $stmt = $pdo->prepare("SELECT id FROM social_budget WHERE year = ? AND id != ?");
        $stmt->execute([$year, $id]);
        if ($stmt->fetch()) {
            $error = "⚠️ السنة $year موجودة مسبقاً";
        } else {
            // إذا كان هناك صرفيات، نمنع تعديل المتبقية يدوياً
            if ($hasExpenses && $remaining != $budget['remaining_budget']) {
                $error = '⚠️ لا يمكن تعديل الرصيد المتبقي يدوياً لأن هناك صرفيات مسجلة. استخدم زر "إعادة تعيين" أو أضف استرجاعاً.';
            } else {
                try {
                    $newRemaining = $remaining;
                    // إذا لم تتغير الأولية، نستخدم القيمة المرسلة
                    // إذا تغيرت الأولية، نضبط المتبقية = old_remaining + (initial - old_initial)
                    if ($initial != $budget['initial_budget']) {
                        $newRemaining = $budget['remaining_budget'] + ($initial - $budget['initial_budget']);
                        if ($newRemaining < 0) $newRemaining = 0;
                    }
                    $stmt = $pdo->prepare("UPDATE social_budget SET year = ?, initial_budget = ?, remaining_budget = ? WHERE id = ?");
                    $stmt->execute([$year, $initial, $newRemaining, $id]);
                    audit('BUDGET_UPDATED', "تم تعديل ميزانية السنة {$budget['year']}");
                    setToast('✅ تم تعديل الميزانية بنجاح', 'success');
                    redirectBudget('index.php');
                } catch (PDOException $e) {
                    error_log("Budget edit error: " . $e->getMessage());
                    $error = '❌ حدث خطأ في قاعدة البيانات';
                }
            }
        }
    }
}

$csrf_token = generateCSRFToken();
include '../includes/header.php';
?>
<link rel="stylesheet" href="../assets/css/budget.css">
<div style="max-width:500px; margin:30px auto; background:white; padding:25px; border-radius:20px; box-shadow:0 5px 15px rgba(0,0,0,0.1);">
    <h2 style="text-align:center; color:#2a5298;">✏️ تعديل الميزانية</h2>
    <?php if ($error): ?>
        <div style="background:#f8d7da; color:#721c24; padding:12px; border-radius:10px; margin-bottom:20px;"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <div style="background:#e3f2fd; padding:10px; border-radius:10px; margin-bottom:20px;">
        <strong>📌 السنة الحالية:</strong> <?= $budget['year'] ?><br>
        <strong>💰 الأولية:</strong> <?= formatCurrency($budget['initial_budget']) ?><br>
        <strong>📉 المتبقية:</strong> <?= formatCurrency($budget['remaining_budget']) ?>
    </div>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        <div class="form-group">
            <label>📅 السنة</label>
            <input type="number" name="year" min="2000" max="2100" value="<?= $budget['year'] ?>" required>
        </div>
        <div class="form-group">
            <label>💰 الميزانية الأولية (دج)</label>
            <input type="number" name="initial_budget" step="0.01" min="0.01" value="<?= $budget['initial_budget'] ?>" required>
        </div>
        <div class="form-group">
            <label>📊 الميزانية المتبقية (دج)</label>
            <input type="number" name="remaining_budget" step="0.01" min="0" value="<?= $budget['remaining_budget'] ?>" <?= $hasExpenses ? 'readonly' : '' ?>>
            <?php if ($hasExpenses): ?>
                <small style="color:#856404;">⚠️ لا يمكن تعديل الرصيد المتبقي يدوياً بسبب وجود صرفيات.</small>
            <?php else: ?>
                <small>يمكنك تعديل الرصيد المتبقي يدوياً.</small>
            <?php endif; ?>
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%;">💾 حفظ</button>
        <a href="index.php" class="btn btn-secondary" style="width:100%; margin-top:10px; text-align:center; display:block;">🔙 إلغاء</a>
    </form>
</div>
<?php include '../includes/footer.php'; ?>