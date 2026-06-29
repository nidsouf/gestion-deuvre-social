<?php
/**
 * employees/add.php - إضافة موظف جديد (محسّن)
 */
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';
include '../includes/header.php';

$error = '';
$name = '';
$category = 'Contract';
$hire_date = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // التحقق من CSRF
    requireCSRFToken();
    
    // التحقق من Rate Limiting
    if (isRateLimited('employee_add', 10, 3600)) {
        $error = '⚠️ لقد تجاوزت عدد المحاولات المسموحة. الرجاء المحاولة لاحقاً.';
    } else {
        // تنظيف المدخلات
        $name = sanitizeInput($_POST['name'] ?? '');
        $category = $_POST['category'] ?? 'Contract';
        $hire_date = $_POST['hire_date'] ?? '';
        
        // التحقق من صحة البيانات
        if (empty($name)) {
            $error = '⚠️ اسم الموظف مطلوب';
        } elseif (!in_array($category, ['Permanent', 'Contract'])) {
            $error = '⚠️ تصنيف غير صحيح';
        } elseif ($hire_date && !validateDate($hire_date)) {
            $error = '⚠️ صيغة تاريخ التوظيف غير صحيحة (YYYY-MM-DD)';
        } else {
            try {
                // إضافة الموظف
                $stmt = $pdo->prepare("INSERT INTO employees (name, category, hire_date) VALUES (?, ?, ?)");
                $stmt->execute([$name, $category, $hire_date ?: null]);
                $new_id = $pdo->lastInsertId();
                
                // تسجيل العملية
                audit('EMPLOYEE_ADDED', "Added employee: $name (ID: $new_id)");
                addNotification('موظف جديد', "تم إضافة الموظف $name بنجاح", null, 'success');
                
                $_SESSION['toast'] = [
                    'message' => '✅ تم إضافة الموظف بنجاح',
                    'type' => 'success',
                    'duration' => 3000
                ];
                header("Location: list.php");
                exit;
            } catch (PDOException $e) {
                error_log("Employee add error: " . $e->getMessage());
                $error = '❌ حدث خطأ في قاعدة البيانات. الرجاء المحاولة مرة أخرى.';
            }
        }
    }
}

// توليد CSRF token
$csrf_token = generateCSRFToken();
?>

<style>
    .form-container {
        max-width: 500px;
        margin: 30px auto;
        background: white;
        padding: 25px;
        border-radius: 20px;
        box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    }
    .form-group { margin-bottom: 20px; }
    .form-group label { display: block; font-weight: bold; margin-bottom: 8px; }
    .form-group input, .form-group select {
        width: 100%;
        padding: 10px;
        border: 1px solid #ddd;
        border-radius: 12px;
    }
    .btn-save {
        width: 100%;
        background: #28a745;
        color: white;
        padding: 12px;
        border: none;
        border-radius: 30px;
        font-weight: bold;
        cursor: pointer;
    }
    .error-message {
        background: #f8d7da;
        color: #721c24;
        padding: 12px;
        border-radius: 10px;
        margin-bottom: 20px;
    }
</style>

<div class="form-container">
    <h2>➕ إضافة موظف جديد</h2>
    
    <?php if ($error): ?>
        <div class="error-message"><?= escape($error) ?></div>
    <?php endif; ?>
    
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= escape($csrf_token) ?>">
        
        <div class="form-group">
            <label>👤 اسم الموظف</label>
            <input type="text" name="name" value="<?= escape($name) ?>" required>
        </div>
        
        <div class="form-group">
            <label>📌 التصنيف</label>
            <select name="category">
                <option value="Permanent" <?= $category == 'Permanent' ? 'selected' : '' ?>>دائم</option>
                <option value="Contract" <?= $category == 'Contract' ? 'selected' : '' ?>>متعاقد</option>
            </select>
        </div>
        
        <div class="form-group">
            <label>📅 تاريخ التوظيف</label>
            <input type="date" name="hire_date" value="<?= escape($hire_date) ?>">
            <small>يُستخدم لحساب أوراق العمرة</small>
        </div>
        
        <button type="submit" class="btn-save">💾 حفظ</button>
    </form>
</div>

<?php include '../includes/footer.php'; ?>