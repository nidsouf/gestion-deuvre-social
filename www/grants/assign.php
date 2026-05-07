<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
require_once '../config/database.php';
include '../includes/header.php';

$message = '';
$employees = $pdo->query("SELECT id, name FROM employees ORDER BY name")->fetchAll();
$grants = $pdo->query("SELECT id, name, amount FROM grants ORDER BY name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $employee_id = (int)$_POST['employee_id'];
    $grant_id = (int)$_POST['grant_id'];
    $grant_date = $_POST['grant_date'];
    $notes = trim($_POST['notes']);

    if ($employee_id <= 0 || $grant_id <= 0 || empty($grant_date)) {
        $message = "⚠️ جميع الحقول المطلوبة يجب أن تكون صحيحة.";
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO employee_grants (employee_id, grant_id, grant_date, notes) VALUES (?, ?, ?, ?)");
            $stmt->execute([$employee_id, $grant_id, $grant_date, $notes]);
            $message = "✅ تم توزيع المنحة بنجاح.";
        } catch (Exception $e) {
            $message = "❌ خطأ: " . $e->getMessage();
        }
    }
}
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
    .form-group {
        margin-bottom: 20px;
    }
    .form-group label {
        display: block;
        font-weight: bold;
        margin-bottom: 5px;
    }
    .form-group select, .form-group input, .form-group textarea {
        width: 100%;
        padding: 8px;
        border-radius: 8px;
        border: 1px solid #ccc;
    }
    button {
        background: #28a745;
        color: white;
        padding: 10px;
        width: 100%;
        border: none;
        border-radius: 30px;
        cursor: pointer;
        font-weight: bold;
    }
    /* زر العودة بإطار أزرق شفاف */
    .btn-back {
        display: inline-block;
        background: transparent;
        border: 2px solid #2a5298;
        color: #2a5298;
        padding: 8px 16px;
        border-radius: 30px;
        text-decoration: none;
        margin-bottom: 20px;
        font-size: 14px;
        font-weight: 500;
        transition: all 0.3s ease;
    }
    .btn-back:hover {
        background: #2a5298;
        color: white;
        transform: translateY(-2px);
    }
</style>

<div class="form-container">
    <a href="list.php" class="btn-back">🔙 العودة إلى قائمة المنح</a>
    <h2>🎁 منح موظف</h2>
    <?php if ($message): ?>
        <div style="background:#e9ecef; padding:10px; border-radius:8px; margin-bottom:15px;"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <form method="POST">
        <div class="form-group">
            <label>👤 الموظف</label>
            <select name="employee_id" required>
                <option value="">اختر الموظف</option>
                <?php foreach ($employees as $emp): ?>
                    <option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>🎁 نوع المنحة</label>
            <select name="grant_id" required>
                <option value="">اختر المنحة</option>
                <?php foreach ($grants as $g): ?>
                    <option value="<?= $g['id'] ?>"><?= htmlspecialchars($g['name']) ?> (<?= number_format($g['amount'], 2) ?> دج)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>📅 تاريخ المنح</label>
            <input type="date" name="grant_date" value="<?= date('Y-m-d') ?>" required>
        </div>
        <div class="form-group">
            <label>📝 ملاحظات (اختياري)</label>
            <textarea name="notes" rows="3"></textarea>
        </div>
        <button type="submit">💾 توزيع المنحة</button>
    </form>
</div>

<?php include '../includes/footer.php'; ?>