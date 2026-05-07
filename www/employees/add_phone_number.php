<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
require_once '../config/database.php';
include '../includes/header.php';

$employee_id = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;
if (!$employee_id) {
    header("Location: list.php");
    exit;
}

$stmt = $pdo->prepare("SELECT name FROM employees WHERE id = ?");
$stmt->execute([$employee_id]);
$employee = $stmt->fetch();
if (!$employee) {
    header("Location: list.php");
    exit;
}

$message = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $phone_number = trim($_POST['phone_number']);
    $monthly_amount = (float)$_POST['monthly_amount'];
    if (empty($phone_number)) {
        $message = "⚠️ رقم الهاتف مطلوب";
    } elseif ($monthly_amount <= 0) {
        $message = "⚠️ القيمة الشهرية يجب أن تكون أكبر من صفر";
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO employee_phone_numbers (employee_id, phone_number, monthly_amount) VALUES (?, ?, ?)");
            $stmt->execute([$employee_id, $phone_number, $monthly_amount]);
            $message = "✅ تم إضافة رقم الهاتف بنجاح";
        } catch (Exception $e) {
            $message = "❌ خطأ: " . $e->getMessage();
        }
    }
}
?>

<style>
    .form-container { max-width: 500px; margin: auto; background: white; padding: 25px; border-radius: 16px; }
    .form-group { margin-bottom: 15px; }
    .form-group label { display: block; font-weight: bold; margin-bottom: 5px; }
    .form-group input, .form-group select { width: 100%; padding: 8px; border-radius: 8px; border: 1px solid #ccc; }
    button { background: #28a745; color: white; padding: 10px; width: 100%; border: none; border-radius: 30px; cursor: pointer; }
    .btn-back { background: #6c757d; color: white; padding: 10px 20px; border-radius: 30px; text-decoration: none; display: inline-block; margin-bottom: 20px; }
</style>

<div class="form-container">
    <a href="phone_numbers.php?employee_id=<?= $employee_id ?>" class="btn-back">🔙 رجوع</a>
    <h2>📱 إضافة رقم هاتف (Djezzy) للموظف: <?= htmlspecialchars($employee['name']) ?></h2>
    <?php if ($message): ?>
        <div style="background:#e9ecef; padding:10px; border-radius:8px;"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <form method="POST">
        <div class="form-group">
            <label>📞 رقم الهاتف</label>
            <input type="text" name="phone_number" placeholder="مثال: 0555123456" required>
        </div>
        <div class="form-group">
            <label>💰 القيمة الشهرية (دج)</label>
            <select name="monthly_amount" required>
                <option value="300">300 دج (شريحة عادية)</option>
                <option value="500">500 دج (شريحة مميزة)</option>
            </select>
        </div>
        <button type="submit">💾 حفظ</button>
    </form>
</div>
<?php include '../includes/footer.php'; ?>