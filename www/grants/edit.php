<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

require_once '../config/database.php';

$id = $_GET['id'] ?? 0;

$stmt = $pdo->prepare("SELECT * FROM grants WHERE id = ?");
$stmt->execute([$id]);
$grant = $stmt->fetch();

if (!$grant) {
    $_SESSION['toast'] = ['message' => 'المنحة غير موجودة', 'type' => 'error', 'duration' => 3000];
    header("Location: list.php");
    exit;
}

// ========== معالجة POST قبل أي ناتج ==========
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // التحقق من صحة المدخلات
    if (empty($_POST['name']) || empty($_POST['amount'])) {
        $_SESSION['toast'] = ['message' => 'يرجى ملء جميع الحقول المطلوبة', 'type' => 'warning', 'duration' => 4000];
        header("Location: edit.php?id=$id");
        exit;
    }

    $name = trim($_POST['name']);
    $amount = floatval($_POST['amount']);

    if ($amount <= 0) {
        $_SESSION['toast'] = ['message' => 'قيمة المنحة يجب أن تكون أكبر من صفر', 'type' => 'error', 'duration' => 4000];
        header("Location: edit.php?id=$id");
        exit;
    }

    try {
        $update = $pdo->prepare("UPDATE grants SET name = ?, amount = ? WHERE id = ?");
        $update->execute([$name, $amount, $id]);

        $_SESSION['toast'] = ['message' => '✅ تم تعديل المنحة بنجاح', 'type' => 'success', 'duration' => 3000];
        header("Location: list.php");
        exit;
    } catch (Exception $e) {
        $_SESSION['toast'] = ['message' => '❌ حدث خطأ أثناء التعديل: ' . $e->getMessage(), 'type' => 'error', 'duration' => 5000];
        header("Location: edit.php?id=$id");
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
    <h2>✏️ تعديل المنحة</h2>
    <form method="POST">
        <label>🏷️ اسم المنحة</label>
        <input type="text" name="name" value="<?= htmlspecialchars($grant['name']) ?>" required>

        <label>💰 القيمة (دج)</label>
        <input type="number" step="0.01" name="amount" value="<?= htmlspecialchars($grant['amount']) ?>" required>

        <button type="submit">💾 حفظ التعديلات</button>
        <a href="list.php" class="btn-cancel">🔙 إلغاء</a>
    </form>
</div>

<?php include '../includes/footer.php'; ?>