<?php
/**
 * budget/edit.php - تعديل ميزانية موجودة (متوافق مع هيكل قاعدة البيانات)
 */
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';

$error = '';
$budget = null;
$id = isset($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT) : 0;

if (!$id) {
    $_SESSION['toast'] = ['message' => '⚠️ لم يتم تحديد الميزانية', 'type' => 'warning', 'duration' => 3000];
    header("Location: index.php?updated=1");
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
    $error = '❌ خطأ في جلب البيانات: ' . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $budget && empty($error)) {
    requireCSRFToken();
    if (isRateLimited('budget_edit', 5, 3600)) {
        $error = '⚠️ تجاوزت عدد المحاولات المسموحة.';
    } else {
        $year = sanitizeInput($_POST['year'] ?? '');
        $initial = filter_var($_POST['initial_budget'] ?? 0, FILTER_VALIDATE_FLOAT);
        $remaining = filter_var($_POST['remaining_budget'] ?? 0, FILTER_VALIDATE_FLOAT);
        
        if (!validateNumber($year) || $year < 2000 || $year > 2100) {
            $error = '⚠️ السنة غير صالحة';
        } elseif (!$initial || $initial <= 0) {
            $error = '⚠️ الميزانية الأولية يجب أن تكون أكبر من صفر';
        } elseif ($remaining < 0) {
            $error = '⚠️ الميزانية المتبقية لا يمكن أن تكون سالبة';
        } elseif ($remaining > $initial) {
            $error = '⚠️ الميزانية المتبقية لا يمكن أن تكون أكبر من الأولية';
        } else {
            try {
                // التحقق من عدم تكرار السنة
                $stmt = $pdo->prepare("SELECT id FROM social_budget WHERE year = ? AND id != ?");
                $stmt->execute([$year, $id]);
                if ($stmt->fetch()) {
                    $error = "⚠️ السنة {$year} موجودة مسبقاً";
                } else {
                    // حساب المتبقية الجديدة إذا لم يتم تعديلها يدوياً
                    $oldInitial = $budget['initial_budget'];
                    $oldRemaining = $budget['remaining_budget'];
                    $difference = $initial - $oldInitial;
                    $newRemaining = $oldRemaining + $difference;
                    
                    // إذا كان الحقل غير readonly (أي لا توجد صرفيات) نستخدم القيمة المرسلة
                    if ($budget['remaining_budget'] == $budget['initial_budget']) {
                        $newRemaining = $remaining;
                    }
                    
                    // تحديث الميزانية - بدون updated_at (غير موجود في الجدول)
                    $sql = "UPDATE social_budget SET year = ?, initial_budget = ?, remaining_budget = ? WHERE id = ?";
                    $stmt = $pdo->prepare($sql);
                    $result = $stmt->execute([$year, $initial, $newRemaining, $id]);
                    
                    if ($result) {
                        if (function_exists('audit')) {
                            audit('BUDGET_UPDATED', "تم تعديل ميزانية السنة {$budget['year']}");
                        }
                        if (function_exists('addNotification')) {
                            addNotification('تعديل ميزانية', "تم تعديل ميزانية السنة {$year}");
                        }
                        $_SESSION['toast'] = ['message' => '✅ تم تعديل الميزانية بنجاح', 'type' => 'success', 'duration' => 3000];
                        header("Location: index.php");
                        exit;
                    } else {
                        $errorInfo = $stmt->errorInfo();
                        $error = '❌ فشل التعديل: ' . ($errorInfo[2] ?? 'خطأ غير معروف');
                    }
                }
            } catch (PDOException $e) {
                error_log("Budget edit error: " . $e->getMessage());
                $error = '❌ خطأ في قاعدة البيانات: ' . $e->getMessage();
            }
        }
    }
}

$csrf_token = generateCSRFToken();
include '../includes/header.php';
?>

<style>
.form-container { max-width: 500px; margin: 30px auto; background: white; padding: 25px; border-radius: 20px; box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
.form-container h2 { text-align: center; color: #2a5298; }
.info-box { background: #e3f2fd; padding: 12px; border-radius: 10px; margin-bottom: 20px; }
.btn-save { background: #2a5298; color: white; padding: 10px; border: none; border-radius: 30px; cursor: pointer; width: 100%; }
.btn-cancel { background: #6c757d; color: white; padding: 10px; border-radius: 30px; text-align: center; text-decoration: none; display: inline-block; width: 100%; margin-top: 10px; }
.error-message { background: #f8d7da; color: #721c24; padding: 12px; border-radius: 10px; margin-bottom: 20px; }
.warning-note { background: #fff3cd; color: #856404; padding: 10px; border-radius: 8px; font-size: 12px; margin-top: 15px; text-align: center; }
</style>

<div class="form-container">
    <h2>✏️ تعديل الميزانية</h2>
    <?php if ($error): ?>
        <div class="error-message"><?= escape($error) ?></div>
    <?php endif; ?>
    <?php if ($budget): ?>
        <div class="info-box">
            📌 السنة: <strong><?= escape($budget['year']) ?></strong><br>
            💰 الأولية: <strong><?= number_format($budget['initial_budget'], 2) ?> دج</strong><br>
            📉 المتبقية: <strong><?= number_format($budget['remaining_budget'], 2) ?> دج</strong><br>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= escape($csrf_token) ?>">
            <div class="form-group">
                <label>📅 السنة</label>
                <input type="number" name="year" min="2000" max="2100" value="<?= escape($budget['year']) ?>" required>
            </div>
            <div class="form-group">
                <label>💰 الميزانية الأولية (دج)</label>
                <input type="number" name="initial_budget" step="0.01" min="0.01" value="<?= escape($budget['initial_budget']) ?>" required>
            </div>
            <div class="form-group">
                <label>📊 الميزانية المتبقية (دج)</label>
                <input type="number" name="remaining_budget" step="0.01" min="0" value="<?= escape($budget['remaining_budget']) ?>" <?= ($budget['remaining_budget'] != $budget['initial_budget']) ? 'readonly' : '' ?>>
                <?php if ($budget['remaining_budget'] != $budget['initial_budget']): ?>
                    <small>ℹ️ تم تجميد هذا الحقل بسبب وجود صرفيات مسجلة.</small>
                <?php else: ?>
                    <small>ℹ️ يمكنك تعديل الرصيد المتبقي يدوياً.</small>
                <?php endif; ?>
            </div>
            <button type="submit" class="btn-save">💾 حفظ التعديلات</button>
            <a href="index.php" class="btn-cancel">🔙 إلغاء</a>
        </form>
        <div class="warning-note">⚠️ ملاحظة: تغيير الميزانية الأولية سيؤثر تلقائياً على المتبقية (بإضافة الفرق).</div>
    <?php else: ?>
        <div class="error-message">❌ لا توجد بيانات</div>
        <a href="index.php" class="btn-cancel">🔙 العودة</a>
    <?php endif; ?>
</div>

<?php include '../includes/footer.php'; ?>