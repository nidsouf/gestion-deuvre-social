<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

require_once '../config/database.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// جلب بيانات الشيك
$stmt = $pdo->prepare("SELECT * FROM source_payments WHERE id = ?");
$stmt->execute([$id]);
$payment = $stmt->fetch();

if (!$payment) {
    $_SESSION['toast'] = ['message' => 'الشيك غير موجود', 'type' => 'error', 'duration' => 3000];
    header("Location: list.php");
    exit;
}

// معالجة التحديث
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // التحقق من المدخلات
    if (empty($_POST['source_id']) || empty($_POST['cheque_number']) || empty($_POST['cheque_date']) || empty($_POST['amount'])) {
        $_SESSION['toast'] = ['message' => 'يرجى ملء جميع الحقول المطلوبة', 'type' => 'warning', 'duration' => 4000];
        header("Location: edit.php?id=$id");
        exit;
    }

    $source_id = (int)$_POST['source_id'];
    $cheque_number = trim($_POST['cheque_number']);
    $cheque_date = $_POST['cheque_date'];
    $amount = (float)$_POST['amount'];
    $notes = trim($_POST['notes'] ?? '');

    if ($amount <= 0) {
        $_SESSION['toast'] = ['message' => 'المبلغ يجب أن يكون أكبر من صفر', 'type' => 'error', 'duration' => 4000];
        header("Location: edit.php?id=$id");
        exit;
    }

    try {
        $update = $pdo->prepare("UPDATE source_payments SET source_id = ?, cheque_number = ?, cheque_date = ?, amount = ?, notes = ? WHERE id = ?");
        $update->execute([$source_id, $cheque_number, $cheque_date, $amount, $notes, $id]);

        $_SESSION['toast'] = ['message' => '✅ تم تعديل الشيك بنجاح', 'type' => 'success', 'duration' => 3000];
        header("Location: list.php");
        exit;
    } catch (Exception $e) {
        $_SESSION['toast'] = ['message' => '❌ حدث خطأ أثناء التعديل: ' . $e->getMessage(), 'type' => 'error', 'duration' => 5000];
        header("Location: edit.php?id=$id");
        exit;
    }
}

// جلب قائمة المصادر
$sources = $pdo->query("SELECT id, name FROM sources ORDER BY name")->fetchAll();

include '../includes/header.php';
?>

<style>
    .form-container {
        max-width: 600px;
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
    input, select, textarea {
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
    <h2>✏️ تعديل شيك</h2>
    <form method="POST">
        <label>📁 المصدر</label>
        <select name="source_id" required>
            <option value="">اختر المصدر</option>
            <?php foreach($sources as $src): ?>
                <option value="<?= $src['id'] ?>" <?= $src['id'] == $payment['source_id'] ? 'selected' : '' ?>><?= htmlspecialchars($src['name']) ?></option>
            <?php endforeach; ?>
        </select>

        <label>🔢 رقم الشيك</label>
        <input type="text" name="cheque_number" value="<?= htmlspecialchars($payment['cheque_number']) ?>" required>

        <label>📅 التاريخ</label>
        <input type="date" name="cheque_date" value="<?= $payment['cheque_date'] ?>" required>

        <label>💰 المبلغ (دج)</label>
        <input type="number" step="0.01" name="amount" value="<?= $payment['amount'] ?>" required>

        <label>📝 ملاحظات</label>
        <textarea name="notes" rows="3"><?= htmlspecialchars($payment['notes']) ?></textarea>

        <button type="submit">💾 حفظ التعديلات</button>
        <a href="list.php" class="btn-cancel">🔙 إلغاء</a>
    </form>
</div>

<?php include '../includes/footer.php'; ?>