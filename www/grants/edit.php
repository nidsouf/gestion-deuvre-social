<?php
/**
 * grants/edit.php - تعديل نوع منحة
 */
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    $_SESSION['toast'] = ['message' => 'معرف غير صالح', 'type' => 'error', 'duration' => 3000];
    header("Location: list.php");
    exit;
}

// جلب بيانات المنحة
$stmt = $pdo->prepare("SELECT * FROM grants WHERE id = ?");
$stmt->execute([$id]);
$grant = $stmt->fetch();
if (!$grant) {
    $_SESSION['toast'] = ['message' => 'المنحة غير موجودة', 'type' => 'error', 'duration' => 3000];
    header("Location: list.php");
    exit;
}

// ========== معالجة POST ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRFToken();
    
    $name = sanitizeInput($_POST['name'] ?? '');
    $calculation_type = $_POST['calculation_type'] ?? 'fixed';
    $amount = (float)$_POST['amount'];
    $percentage_value = (float)$_POST['percentage_value'];
    $max_amount = (float)$_POST['max_amount'];
    $update_existing = isset($_POST['update_existing']) ? 1 : 0;
    
    $errors = [];
    if (empty($name)) $errors[] = 'اسم المنحة مطلوب';
    
    if ($calculation_type == 'fixed') {
        if ($amount <= 0) $errors[] = 'المبلغ يجب أن يكون موجباً';
        $percentage_value = 0;
        $max_amount = 0;
    } else {
        if ($percentage_value <= 0 || $percentage_value > 100) $errors[] = 'النسبة المئوية يجب أن تكون بين 1 و 100';
        if ($max_amount < 0) $errors[] = 'الحد الأقصى يجب أن يكون موجباً';
        $amount = 0;
    }
    
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // تحديث المنحة مع تحديث updated_at
            $stmt = $pdo->prepare("
                UPDATE grants 
                SET name = ?, amount = ?, calculation_type = ?, percentage_value = ?, max_amount = ?, updated_at = datetime('now')
                WHERE id = ?
            ");
            $stmt->execute([$name, $amount, $calculation_type, $percentage_value, $max_amount, $id]);
            
            // تحديث المنح الموزعة إذا طلب ذلك
            if ($update_existing) {
                if ($calculation_type == 'fixed') {
                    $updateStmt = $pdo->prepare("UPDATE employee_grants SET amount = ? WHERE grant_id = ?");
                    $updateStmt->execute([$amount, $id]);
                } else {
                    $updateStmt = $pdo->prepare("
                        UPDATE employee_grants 
                        SET amount = LEAST((invoice_amount * ? / 100), ?)
                        WHERE grant_id = ? AND invoice_amount > 0
                    ");
                    $updateStmt->execute([$percentage_value, $max_amount, $id]);
                }
                addNotification('تحديث المنح', "تم تحديث قيم المنح لـ '$name'", null, 'info');
            }
            
            $pdo->commit();
            $_SESSION['toast'] = ['message' => '✅ تم تحديث المنحة بنجاح', 'type' => 'success', 'duration' => 3000];
            header("Location: list.php");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Grant edit error: " . $e->getMessage());
            $_SESSION['toast'] = ['message' => '❌ حدث خطأ: ' . $e->getMessage(), 'type' => 'error', 'duration' => 5000];
            header("Location: edit.php?id=$id");
            exit;
        }
    } else {
        $_SESSION['toast'] = ['message' => '⚠️ ' . implode(' - ', $errors), 'type' => 'warning', 'duration' => 4000];
        header("Location: edit.php?id=$id");
        exit;
    }
}

$csrf_token = generateCSRFToken();
include '../includes/header.php';
?>

<style>
    .form-container { max-width: 500px; margin: 30px auto; background: white; padding: 25px; border-radius: 20px; box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
    .form-group { margin-bottom: 20px; }
    .form-group label { display: block; font-weight: bold; margin-bottom: 8px; }
    .form-group input, .form-group select { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 12px; }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
    .btn-save { width: 100%; background: #2a5298; color: white; padding: 12px; border: none; border-radius: 30px; font-weight: bold; cursor: pointer; }
    .btn-save:hover { background: #1e3c72; }
    .btn-cancel { display: block; text-align: center; margin-top: 10px; background: #6c757d; color: white; text-decoration: none; padding: 10px; border-radius: 30px; }
    .help-text { font-size: 12px; color: #888; margin-top: 5px; }
    .checkbox-group { display: flex; align-items: center; gap: 10px; }
    .checkbox-group input { width: auto; }
</style>

<div class="form-container">
    <h2>✏️ تعديل منحة: <?= escape($grant['name']) ?></h2>
    <form method="POST" id="editForm" novalidate>
        <input type="hidden" name="csrf_token" value="<?= escape($csrf_token) ?>">
        
        <div class="form-group">
            <label>اسم المنحة</label>
            <input type="text" name="name" value="<?= escape($grant['name']) ?>" required>
        </div>
        
        <div class="form-group">
            <label>نوع الحساب</label>
            <select name="calculation_type" id="calcType" onchange="toggleFields()" required>
                <option value="fixed" <?= $grant['calculation_type'] == 'fixed' ? 'selected' : '' ?>>💰 مبلغ ثابت</option>
                <option value="percentage" <?= $grant['calculation_type'] == 'percentage' ? 'selected' : '' ?>>📊 نسبة مئوية</option>
            </select>
        </div>
        
        <div id="fixedFields" <?= $grant['calculation_type'] == 'fixed' ? '' : 'style="display:none;"' ?>>
            <div class="form-group">
                <label>المبلغ (دج)</label>
                <input type="number" step="0.01" name="amount" value="<?= $grant['amount'] ?>">
            </div>
        </div>
        
        <div id="percentageFields" <?= $grant['calculation_type'] == 'percentage' ? '' : 'style="display:none;"' ?>>
            <div class="form-row">
                <div class="form-group">
                    <label>النسبة المئوية (%)</label>
                    <input type="number" step="0.1" name="percentage_value" value="<?= $grant['percentage_value'] ?>">
                </div>
                <div class="form-group">
                    <label>الحد الأقصى (دج)</label>
                    <input type="number" step="0.01" name="max_amount" value="<?= $grant['max_amount'] ?>">
                </div>
            </div>
        </div>
        
        <div class="form-group checkbox-group">
            <input type="checkbox" name="update_existing" id="update_existing" value="1">
            <label for="update_existing">🔄 تحديث قيم المنح الموزعة سابقاً لهذا النوع</label>
        </div>
        <div class="help-text">إذا اخترت هذا الخيار، سيتم تحديث قيم جميع المنح الموزعة من هذا النوع بالقيمة الجديدة.</div>
        
        <button type="submit" class="btn-save">💾 حفظ التعديلات</button>
        <a href="list.php" class="btn-cancel">🔙 إلغاء</a>
    </form>
</div>

<script>
    function toggleFields() {
        const type = document.getElementById('calcType').value;
        document.getElementById('fixedFields').style.display = type === 'fixed' ? 'block' : 'none';
        document.getElementById('percentageFields').style.display = type === 'percentage' ? 'block' : 'none';
    }
</script>

<?php include '../includes/footer.php'; ?>