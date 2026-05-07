<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

require_once '../config/database.php';

// ========== معالجة POST قبل أي ناتج ==========
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // التحقق من وجود البيانات
    if (empty($_POST['name']) || empty($_POST['amount'])) {
        $_SESSION['toast'] = ['message' => 'يرجى ملء جميع الحقول المطلوبة', 'type' => 'warning', 'duration' => 4000];
        header("Location: add.php");
        exit;
    }

    $name = trim($_POST['name']);
    $amount = (float)$_POST['amount'];

    if ($amount <= 0) {
        $_SESSION['toast'] = ['message' => 'قيمة المنحة يجب أن تكون أكبر من صفر', 'type' => 'error', 'duration' => 4000];
        header("Location: add.php");
        exit;
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO grants (name, amount) VALUES (?, ?)");
        $stmt->execute([$name, $amount]);

        $_SESSION['toast'] = ['message' => '✅ تم إضافة المنحة بنجاح', 'type' => 'success', 'duration' => 3000];
        header("Location: list.php");
        exit;
    } catch (Exception $e) {
        $_SESSION['toast'] = ['message' => '❌ حدث خطأ أثناء الإضافة: ' . $e->getMessage(), 'type' => 'error', 'duration' => 5000];
        header("Location: add.php");
        exit;
    }
}

// ========== بعد المعالجة، نبدأ عرض الصفحة ==========
include '../includes/header.php';
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
    label {
        display: block;
        margin-top: 15px;
        font-weight: bold;
    }
    input {
        width: 100%;
        padding: 8px;
        margin-top: 5px;
        border-radius: 12px;
        border: 1px solid #ccc;
    }
    button {
        margin-top: 20px;
        background: #2a5298;
        color: white;
        padding: 10px;
        width: 100%;
        border: none;
        border-radius: 30px;
        font-weight: bold;
        cursor: pointer;
    }
    .btn-cancel {
        display: block;
        text-align: center;
        margin-top: 10px;
        background: #6c757d;
        color: white;
        text-decoration: none;
        padding: 10px;
        border-radius: 30px;
    }
</style>

<div class="form-container">
    <h2>➕ إضافة منحة جديدة</h2>
    <form method="POST">
        <label>🏷️ اسم المنحة</label>
        <input type="text" name="name" required>

        <label>💰 القيمة (دج)</label>
        <input type="number" step="0.01" name="amount" required>

        <button type="submit">💾 حفظ المنحة</button>
        <a href="list.php" class="btn-cancel">🔙 إلغاء</a>
    </form>
</div>

<?php include '../includes/footer.php'; ?>