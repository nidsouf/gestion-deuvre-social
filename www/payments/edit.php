<?php
ob_start();
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = $pdo->prepare("SELECT * FROM source_payments WHERE id = ?");
$stmt->execute([$id]);
$payment = $stmt->fetch();

if (!$payment) {
    $_SESSION['toast'] = ['message' => 'الشيك غير موجود', 'type' => 'error', 'duration' => 3000];
    header("Location: list.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
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
        $_SESSION['toast'] = ['message' => '❌ حدث خطأ: ' . $e->getMessage(), 'type' => 'error', 'duration' => 5000];
        header("Location: edit.php?id=$id");
        exit;
    }
}

$sources = $pdo->query("SELECT id, name FROM sources ORDER BY name")->fetchAll();
include '../includes/header.php';
?>

<div style="max-width: 600px; margin: 30px auto; background: white; padding: 25px; border-radius: 20px;">
    <h2>✏️ تعديل شيك</h2>
    <form method="POST">
        <div class="form-group">
            <label>📁 المصدر</label>
            <select name="source_id" required class="form-control">
                <option value="">اختر المصدر</option>
                <?php foreach($sources as $src): ?>
                    <option value="<?= $src['id'] ?>" <?= $src['id'] == $payment['source_id'] ? 'selected' : '' ?>><?= htmlspecialchars($src['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label>🔢 رقم الشيك</label>
            <input type="text" name="cheque_number" value="<?= htmlspecialchars($payment['cheque_number']) ?>" required class="form-control">
        </div>

        <div class="form-group">
            <label>📅 التاريخ</label>
            <input type="date" name="cheque_date" value="<?= $payment['cheque_date'] ?>" required class="form-control">
        </div>

        <div class="form-group">
            <label>💰 المبلغ (دج)</label>
            <input type="number" step="0.01" name="amount" value="<?= $payment['amount'] ?>" required class="form-control">
        </div>

        <div class="form-group">
            <label>📝 ملاحظات</label>
            <textarea name="notes" rows="3" class="form-control"><?= htmlspecialchars($payment['notes']) ?></textarea>
        </div>

        <button type="submit" class="btn btn-primary" style="width:100%;">💾 حفظ التعديلات</button>
        <a href="list.php" class="btn-sm" style="display:block; text-align:center; margin-top:15px;">🔙 إلغاء</a>
    </form>
</div>

<?php
ob_end_flush();
include '../includes/footer.php';
?>