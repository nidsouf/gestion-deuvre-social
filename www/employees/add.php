<?php
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';
require_once '../includes/common_helpers.php';

$error = '';
$name = '';
$category = 'Contract';
$hire_date = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRFToken();
    if (isRateLimited('employee_add', 10, 3600)) {
        $error = '⚠️ لقد تجاوزت عدد المحاولات المسموحة.';
    } else {
        $name = sanitizeInput($_POST['name'] ?? '');
        $category = $_POST['category'] ?? 'Contract';
        $hire_date = $_POST['hire_date'] ?? '';
        $account_number = trim($_POST['account_number'] ?? '');

        if (empty($name)) {
            $error = '⚠️ اسم الموظف مطلوب';
        } elseif (!in_array($category, ['Permanent', 'Contract'])) {
            $error = '⚠️ تصنيف غير صحيح';
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO employees (name, category, hire_date, account_number) VALUES (?, ?, ?, ?)");
                $stmt->execute([$name, $category, $hire_date ?: null, $account_number]);
                setToast('✅ تم إضافة الموظف بنجاح', 'success');
                redirectTo('list.php');
            } catch (Exception $e) {
                error_log("Employee add error: " . $e->getMessage());
                $error = '❌ حدث خطأ في قاعدة البيانات';
            }
        }
    }
}

$csrf_token = generateCSRFToken();
include '../includes/header.php';
?>
<link rel="stylesheet" href="../assets/css/employees.css">

<div class="form-container" style="max-width:500px; margin:30px auto; background:white; padding:25px; border-radius:20px; box-shadow:0 5px 15px rgba(0,0,0,0.1);">
    <h2>➕ إضافة موظف جديد</h2>
    <?php if ($error): ?>
        <div style="background:#f8d7da; color:#721c24; padding:12px; border-radius:10px; margin-bottom:20px;"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        <div class="form-group" style="margin-bottom:15px;">
            <label style="display:block; font-weight:bold; margin-bottom:5px;">👤 اسم الموظف</label>
            <input type="text" name="name" value="<?= htmlspecialchars($name) ?>" required style="width:100%; padding:10px; border-radius:12px; border:1px solid #ddd;">
        </div>
        <div class="form-group" style="margin-bottom:15px;">
            <label style="display:block; font-weight:bold; margin-bottom:5px;">📌 التصنيف</label>
            <select name="category" style="width:100%; padding:10px; border-radius:12px; border:1px solid #ddd;">
                <option value="Permanent" <?= $category == 'Permanent' ? 'selected' : '' ?>>دائم</option>
                <option value="Contract" <?= $category == 'Contract' ? 'selected' : '' ?>>متعاقد</option>
            </select>
        </div>
        <div class="form-group" style="margin-bottom:15px;">
            <label style="display:block; font-weight:bold; margin-bottom:5px;">📅 تاريخ التوظيف</label>
            <input type="date" name="hire_date" value="<?= htmlspecialchars($hire_date) ?>" style="width:100%; padding:10px; border-radius:12px; border:1px solid #ddd;">
        </div>
        <div class="form-group" style="margin-bottom:15px;">
            <label style="display:block; font-weight:bold; margin-bottom:5px;">رقم الحساب</label>
            <input type="text" name="account_number" placeholder="مثال: 12345-6789" style="width:100%; padding:10px; border-radius:12px; border:1px solid #ddd;">
        </div>
        <button type="submit" class="btn-save" style="width:100%; background:#28a745; color:white; padding:12px; border:none; border-radius:30px; font-weight:bold; cursor:pointer;">💾 حفظ</button>
        <a href="list.php" style="display:block; text-align:center; margin-top:10px; background:#6c757d; color:white; padding:10px; border-radius:30px; text-decoration:none;">🔙 إلغاء</a>
    </form>
</div>

<?php include '../includes/footer.php'; ?>