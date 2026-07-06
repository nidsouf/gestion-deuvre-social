<?php
/**
 * employees/edit.php - تعديل بيانات موظف (محسّن)
 */
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    header("Location: list.php");
    exit;
}

// جلب بيانات الموظف
$stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
$stmt->execute([$id]);
$employee = $stmt->fetch();

if (!$employee) {
    header("Location: list.php");
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // التحقق من CSRF
    requireCSRFToken();
    
    if (isRateLimited('employee_edit', 10, 3600)) {
        $error = '⚠️ لقد تجاوزت عدد المحاولات المسموحة.';
    } else {
        $name = sanitizeInput($_POST['name'] ?? '');
        $category = $_POST['category'] ?? 'Contract';
        $hire_date = $_POST['hire_date'] ?? '';
        
        if (empty($name)) {
            $error = '⚠️ اسم الموظف مطلوب';
        } elseif (!in_array($category, ['Permanent', 'Contract'])) {
            $error = '⚠️ تصنيف غير صحيح';
        } elseif ($hire_date && !validateDate($hire_date)) {
            $error = '⚠️ صيغة تاريخ التوظيف غير صحيحة';
        } else {
            try {
                $account_number = trim($_POST['account_number'] ?? '');
                $stmt = $pdo->prepare("UPDATE employees SET name = ?, category = ?, account_number = ? WHERE id = ?");
                $stmt->execute([$name, $category, $account_number, $id]);
                
                audit('EMPLOYEE_EDITED', "Edited employee: $name (ID: $id)");
                addNotification('تحديث موظف', "تم تحديث بيانات الموظف $name بنجاح", null, 'success');
                
                $_SESSION['toast'] = ['message' => '✅ تم تحديث الموظف بنجاح', 'type' => 'success', 'duration' => 3000];
                header("Location: list.php");
                exit;
            } catch (PDOException $e) {
                error_log("Employee edit error: " . $e->getMessage());
                $error = '❌ حدث خطأ في قاعدة البيانات';
            }
        }
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
    .btn-save { width: 100%; background: #ffc107; color: #000; padding: 12px; border: none; border-radius: 30px; font-weight: bold; cursor: pointer; }
    .btn-cancel { display: block; text-align: center; margin-top: 15px; color: #6c757d; text-decoration: none; }
    .error-message { background: #f8d7da; color: #721c24; padding: 12px; border-radius: 10px; margin-bottom: 20px; }
</style>

<div class="form-container">
    <h2>✏️ تعديل موظف</h2>
    <?php if ($error): ?>
        <div class="error-message"><?= escape($error) ?></div>
    <?php endif; ?>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= escape($csrf_token) ?>">
        
        <div class="form-group">
            <label>👤 اسم الموظف</label>
            <input type="text" name="name" value="<?= escape($employee['name']) ?>" required>
        </div>
        
        <div class="form-group">
            <label>📌 التصنيف</label>
            <select name="category">
                <option value="Permanent" <?= $employee['category'] == 'Permanent' ? 'selected' : '' ?>>دائم</option>
                <option value="Contract" <?= $employee['category'] == 'Contract' ? 'selected' : '' ?>>متعاقد</option>
            </select>
        </div>
        
        <div class="form-group">
            <label>📅 تاريخ التوظيف</label>
            <input type="date" name="hire_date" value="<?= escape($employee['hire_date']) ?>">
            <small>يُستخدم لحساب أوراق العمرة</small>
        </div>
        
        <div class="form-group">
            <label>رقم الحساب (بنكي / اجتماعي)</label>
            <input type="text" name="account_number" value="<?= escape($emp['account_number'] ?? '') ?>" placeholder="مثال: 12345-6789">
        </div>

        <button type="submit" class="btn-save">💾 تحديث البيانات</button>
        <a href="list.php" class="btn-cancel">🔙 إلغاء</a>
    </form>
</div>

<?php include '../includes/footer.php'; ?>