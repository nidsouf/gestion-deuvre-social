<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
require_once '../config/database.php';
include '../includes/header.php';

$message = '';
$employees = $pdo->query("SELECT id, name, category FROM employees ORDER BY name")->fetchAll();

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
            $stmt = $pdo->prepare("INSERT INTO labor_day_honorees (employee_id, year, honor_date, reason, prize_type, prize_value) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$employee_id, $year, $honor_date, $reason, $prize_type, $prize_value]);
            $message = "✅ تم إضافة الموظف إلى قائمة المكرمين بنجاح.";
            // إعادة تعيين النموذج أو التوجيه حسب رغبتك، لكن الأفضل البقاء مع رسالة
            // header("Location: index.php"); // إذا أردت التوجيه التلقائي
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
    .btn-save { background: #28a745; color: white; padding: 10px 20px; border: none; border-radius: 30px; cursor: pointer; font-size: 16px; }
    .btn-cancel { background: #6c757d; color: white; padding: 10px 20px; border: none; border-radius: 30px; cursor: pointer; font-size: 16px; text-decoration: none; display: inline-block; text-align: center; }
    .actions { display: flex; gap: 10px; margin-top: 20px; }
</style>

<div class="form-container">
    <h2>🎖️ تكريم موظف جديد</h2>
    <?php if ($message): ?>
        <div style="background:#e9ecef; padding:10px; border-radius:8px; margin-bottom:15px;"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <form method="POST">
        <div class="form-group">
            <label>👤 الموظف</label>
            <select name="employee_id" required>
                <option value="">-- اختر الموظف --</option>
                <?php foreach ($employees as $emp): ?>
                    <option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['name']) ?> (<?= $emp['category'] == 'Permanent' ? 'دائم' : 'متعاقد' ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>📅 سنة التكريم</label>
            <input type="number" name="year" value="<?= date('Y') ?>" required>
        </div>
        <div class="form-group">
            <label>🗓️ تاريخ التكريم</label>
            <input type="date" name="honor_date" value="<?= date('Y-m-d') ?>" required>
        </div>
        <div class="form-group">
            <label>🎁 نوع الجائزة</label>
            <input type="text" name="prize_type" placeholder="مثال: شهادة تقدير، جائزة مالية، هدية عينية" value="شهادة تقدير">
        </div>
        <div class="form-group">
            <label>💰 قيمة الجائزة (دج)</label>
            <input type="number" step="0.01" name="prize_value" value="0">
        </div>
        <div class="form-group">
            <label>📝 سبب التكريم (اختياري)</label>
            <textarea name="reason" rows="3" placeholder="مثال: تفوق في العمل، كفاءة عالية، خدمة متميزة"></textarea>
        </div>
        <div class="actions">
            <button type="submit" class="btn-save">💾 حفظ التكريم</button>
            <a href="index.php" class="btn-cancel">🔙 رجوع</a>
        </div>
    </form>
</div>

<?php include '../includes/footer.php'; ?>