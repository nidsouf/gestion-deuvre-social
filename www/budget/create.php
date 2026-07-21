<?php
/**
 * budget/create.php - إضافة ميزانية جديدة
 */
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';
require_once '../includes/budget_helpers.php';

$error = '';
$csrf_token = generateCSRFToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRFToken();
    if (isRateLimited('budget_create', 5, 3600)) {
        $error = '⚠️ لقد تجاوزت عدد المحاولات المسموحة.';
    } else {
        $year = (int)$_POST['year'];
        $initial = (float)$_POST['initial_budget'];

        if ($year < 2000 || $year > 2100) {
            $error = '⚠️ السنة غير صالحة';
        } elseif ($initial <= 0) {
            $error = '⚠️ الميزانية يجب أن تكون أكبر من صفر';
        } else {
            $stmt = $pdo->prepare("SELECT id FROM social_budget WHERE year = ?");
            $stmt->execute([$year]);
            if ($stmt->fetch()) {
                $error = "⚠️ السنة $year موجودة مسبقاً";
            } else {
                try {
                    $stmt = $pdo->prepare("INSERT INTO social_budget (year, initial_budget, remaining_budget) VALUES (?, ?, ?)");
                    $stmt->execute([$year, $initial, $initial]);
                    audit('BUDGET_CREATED', "تم إضافة ميزانية السنة $year بقيمة $initial دج");
                    setToast("✅ تم إضافة ميزانية سنة $year بنجاح", 'success');
                    redirectBudget('index.php');
                } catch (PDOException $e) {
                    error_log("Budget create error: " . $e->getMessage());
                    $error = '❌ حدث خطأ في قاعدة البيانات';
                }
            }
        }
    }
}

include '../includes/header.php';
?>
<link rel="stylesheet" href="../assets/css/budget.css">
<div style="max-width:500px; margin:30px auto; background:white; padding:25px; border-radius:20px; box-shadow:0 5px 15px rgba(0,0,0,0.1);">
    <h2 style="text-align:center; color:#2a5298;">➕ إضافة ميزانية جديدة</h2>
    <?php if ($error): ?>
        <div style="background:#f8d7da; color:#721c24; padding:12px; border-radius:10px; margin-bottom:20px;"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        <div class="form-group">
            <label>📅 السنة</label>
            <input type="number" name="year" min="2000" max="2100" value="<?= date('Y')+1 ?>" required>
        </div>
        <div class="form-group">
            <label>💰 الميزانية الأولية (دج)</label>
            <input type="number" name="initial_budget" step="0.01" min="0.01" required>
        </div>
        <button type="submit" class="btn btn-success" style="width:100%; margin-top:10px;">💾 حفظ</button>
        <a href="index.php" class="btn btn-secondary" style="width:100%; margin-top:10px; text-align:center; display:block;">🔙 إلغاء</a>
    </form>
</div>
<?php include '../includes/footer.php'; ?>