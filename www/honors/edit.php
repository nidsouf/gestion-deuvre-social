<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
require_once '../config/database.php';
include '../includes/header.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$stmt = $pdo->prepare("SELECT * FROM labor_day_honorees WHERE id = ?");
$stmt->execute([$id]);
$honoree = $stmt->fetch();
if (!$honoree) {
    header("Location: index.php");
    exit;
}

$employees = $pdo->query("SELECT id, name FROM employees ORDER BY name")->fetchAll();
$message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $employee_id = (int)$_POST['employee_id'];
    $year = (int)$_POST['year'];
    $honor_date = $_POST['honor_date'];
    $reason = trim($_POST['reason']);
    $prize_type = trim($_POST['prize_type']);
    $prize_value = (float)$_POST['prize_value'];

    if ($employee_id <= 0 || $year <= 0 || !$honor_date) {
        $message = "⚠️ جميع الحقول المطلوبة يجب أن تكون صحيحة.";
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE labor_day_honorees SET employee_id=?, year=?, honor_date=?, reason=?, prize_type=?, prize_value=? WHERE id=?");
            $stmt->execute([$employee_id, $year, $honor_date, $reason, $prize_type, $prize_value, $id]);
            $message = "✅ تم تحديث التكريم بنجاح.";
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
    .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 8px; border-radius: 8px; border: 1px solid #ccc; }
    button { background: #2a5298; color: white; padding: 10px; width: 100%; border: none; border-radius: 30px; cursor: pointer; }
</style>

<div class="form-container">
    <h2>✏️ تعديل تكريم</h2>
    <?php if ($message): ?>
        <div style="margin-bottom:15px; background:#e9ecef; padding:10px; border-radius:8px;"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <form method="POST">
        <div class="form-group">
            <label>👤 الموظف</label>
            <select name="employee_id" required>
                <?php foreach ($employees as $emp): ?>
                    <option value="<?= $emp['id'] ?>" <?= $emp['id'] == $honoree['employee_id'] ? 'selected' : '' ?>><?= htmlspecialchars($emp['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>📅 سنة التكريم</label>
            <input type="number" name="year" value="<?= $honoree['year'] ?>" required>
        </div>
        <div class="form-group">
            <label>🗓️ تاريخ التكريم</label>
            <input type="date" name="honor_date" value="<?= $honoree['honor_date'] ?>" required>
        </div>
        <div class="form-group">
            <label>🎁 نوع الجائزة</label>
            <input type="text" name="prize_type" value="<?= htmlspecialchars($honoree['prize_type']) ?>">
        </div>
        <div class="form-group">
            <label>💰 قيمة الجائزة (دج)</label>
            <input type="number" step="0.01" name="prize_value" value="<?= $honoree['prize_value'] ?>">
        </div>
        <div class="form-group">
            <label>📝 سبب التكريم (اختياري)</label>
            <textarea name="reason" rows="3"><?= htmlspecialchars($honoree['reason']) ?></textarea>
        </div>
        <button type="submit">💾 تحديث البيانات</button>
    </form>
    <a href="index.php" style="display:block; text-align:center; margin-top:10px;">🔙 إلغاء</a>
</div>

<?php include '../includes/footer.php'; ?>