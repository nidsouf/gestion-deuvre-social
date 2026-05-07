<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

require_once '../config/database.php';

// ========== معالجة POST قبل أي ناتج ==========
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = trim($_POST['name']);
    $category = $_POST['category'];
    $hire_date = !empty($_POST['hire_date']) ? $_POST['hire_date'] : null;
    
    $stmt = $pdo->prepare("INSERT INTO employees (name, category, hire_date) VALUES (?, ?, ?)");
    $stmt->execute([$name, $category, $hire_date]);
    
    $_SESSION['message'] = "✅ تم إضافة الموظف بنجاح";
    header("Location: list.php");
    exit;
}

// ========== بعد المعالجة، نبدأ عرض الصفحة ==========
include '../includes/header.php';
?>

<style>
    .form-container {
        background: white;
        max-width: 500px;
        margin: 30px auto;
        padding: 30px;
        border-radius: 20px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    }
    .form-container h2 {
        text-align: center;
        margin-bottom: 20px;
        color: #1b5e20;
    }
    .form-group {
        margin-bottom: 20px;
    }
    .form-group label {
        display: block;
        font-weight: bold;
        margin-bottom: 8px;
    }
    .form-group input, .form-group select {
        width: 100%;
        padding: 10px;
        border: 1px solid #ccc;
        border-radius: 12px;
        font-size: 14px;
    }
    .btn-save {
        background: linear-gradient(135deg, #2e7d32, #1b5e20);
        color: white;
        border: none;
        padding: 10px 20px;
        border-radius: 12px;
        font-weight: bold;
        cursor: pointer;
        width: 100%;
        transition: 0.3s;
    }
    .btn-save:hover {
        transform: scale(1.02);
        background: #1b5e20;
    }
    .btn-cancel {
        background: #6c757d;
        color: white;
        border: none;
        padding: 10px 20px;
        border-radius: 12px;
        font-weight: bold;
        text-align: center;
        display: inline-block;
        width: 100%;
        text-decoration: none;
        margin-top: 10px;
    }
    small {
        display: block;
        color: #6c757d;
        font-size: 12px;
        margin-top: 5px;
    }
</style>

<div class="form-container">
    <h2>➕ إضافة موظف جديد</h2>
    <form method="POST">
        <div class="form-group">
            <label>👤 اسم الموظف</label>
            <input type="text" name="name" required>
        </div>
        <div class="form-group">
            <label>📌 التصنيف</label>
            <select name="category">
                <option value="Permanent">دائم</option>
                <option value="Contract">متعاقد</option>
            </select>
        </div>
        <div class="form-group">
            <label>📅 تاريخ التوظيف</label>
            <input type="date" name="hire_date">
            <small>يُستخدم لحساب أوراق العمرة والمنح. يفضل إدخاله بدقة.</small>
        </div>
        <button type="submit" class="btn-save">💾 حفظ</button>
        <a href="list.php" class="btn-cancel">🔙 إلغاء</a>
    </form>
</div>

<?php include '../includes/footer.php'; ?>