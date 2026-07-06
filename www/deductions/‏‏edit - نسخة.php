<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

require_once '../config/database.php';

$id = $_GET['id'] ?? 0;
$stmt = $pdo->prepare("SELECT * FROM deductions WHERE id = ?");
$stmt->execute([$id]);
$row = $stmt->fetch();

if (!$row) {
    $_SESSION['toast'] = ['message' => 'الاقتطاع غير موجود', 'type' => 'error', 'duration' => 3000];
    header("Location: list.php");
    exit;
}

// ========== معالجة POST قبل أي ناتج ==========
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // التحقق من صحة البيانات
    if (empty($_POST['employee_id']) || empty($_POST['source_id']) || empty($_POST['monthly_amount']) || empty($_POST['total_months']) || empty($_POST['start_date'])) {
        $_SESSION['toast'] = ['message' => 'يرجى ملء جميع الحقول المطلوبة', 'type' => 'warning', 'duration' => 4000];
        header("Location: edit.php?id=$id");
        exit;
    }

    $employee_id = $_POST['employee_id'];
    $source_id = $_POST['source_id'];
    $monthly_amount = floatval($_POST['monthly_amount']);
    $total_months = intval($_POST['total_months']);
    $start_date = $_POST['start_date'];
    $grant_date = !empty($_POST['grant_date']) ? $_POST['grant_date'] : $start_date;
    
    if ($monthly_amount <= 0 || $total_months <= 0) {
        $_SESSION['toast'] = ['message' => 'المبلغ الشهري وعدد الأشهر يجب أن يكونا أكبر من صفر', 'type' => 'error', 'duration' => 4000];
        header("Location: edit.php?id=$id");
        exit;
    }

    // ========== حساب تاريخ النهاية بدقة باستخدام DateTime ==========
    $startDateTime = new DateTime($start_date);
    $endDateTime = clone $startDateTime;
    $endDateTime->modify('+' . ($total_months - 1) . ' months');
    $end_date = $endDateTime->format('Y-m-d');

    try {
        // تحديث البيانات مع إضافة grant_date
        $update = $pdo->prepare("UPDATE deductions SET employee_id=?, source_id=?, monthly_amount=?, total_months=?, start_date=?, end_date=?, grant_date=? WHERE id=?");
        $update->execute([$employee_id, $source_id, $monthly_amount, $total_months, $start_date, $end_date, $grant_date, $id]);

        $_SESSION['toast'] = ['message' => '✅ تم تعديل الاقتطاع بنجاح', 'type' => 'success', 'duration' => 3000];
        header("Location: list.php");
        exit;
    } catch (Exception $e) {
        $_SESSION['toast'] = ['message' => 'حدث خطأ أثناء التعديل: ' . $e->getMessage(), 'type' => 'error', 'duration' => 5000];
        header("Location: edit.php?id=$id");
        exit;
    }
}

// ========== جلب البيانات اللازمة للواجهة ==========
$employees = $pdo->query("SELECT id, name FROM employees ORDER BY name")->fetchAll();
$sources = $pdo->query("SELECT id, name FROM sources ORDER BY name")->fetchAll();

// ========== بعد المعالجة، نبدأ عرض الصفحة ==========
include '../includes/header.php';
?>

<style>
    .form-container {
        max-width: 600px;
        margin: 30px auto;
        background: white;
        padding: 25px;
        border-radius: 20px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    }
    .form-container h2 {
        text-align: center;
        margin-bottom: 20px;
        color: #e65100;
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
    .btn-update {
        background: linear-gradient(135deg, #ff9800, #e65100);
        color: white;
        border: none;
        padding: 10px 20px;
        border-radius: 12px;
        font-weight: bold;
        cursor: pointer;
        width: 100%;
        transition: 0.3s;
    }
    .btn-update:hover {
        transform: scale(1.02);
        background: #e65100;
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
</style>

<div class="form-container">
    <h2>✏️ تعديل الاقتطاع</h2>
    <form method="POST">
        <div class="form-group">
            <label>👤 الموظف</label>
            <select name="employee_id" required>
                <?php foreach($employees as $emp): ?>
                    <option value="<?= $emp['id'] ?>" <?= $emp['id'] == $row['employee_id'] ? 'selected' : '' ?>><?= htmlspecialchars($emp['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label>📁 المصدر</label>
            <select name="source_id" required>
                <?php foreach($sources as $src): ?>
                    <option value="<?= $src['id'] ?>" <?= $src['id'] == $row['source_id'] ? 'selected' : '' ?>><?= htmlspecialchars($src['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label>💰 المبلغ الشهري (دج)</label>
            <input type="number" step="0.01" name="monthly_amount" value="<?= htmlspecialchars($row['monthly_amount']) ?>" required>
        </div>

        <div class="form-group">
            <label>📊 عدد الأشهر</label>
            <input type="number" name="total_months" value="<?= htmlspecialchars($row['total_months']) ?>" required>
        </div>

        <div class="form-group">
            <label>📅 تاريخ بداية الاقتطاع</label>
            <input type="date" name="start_date" value="<?= htmlspecialchars($row['start_date']) ?>" required>
        </div>
        
        <div class="form-group">
            <label>📅 تاريخ صرف السلفة (تاريخ منحها للموظف)</label>
            <input type="date" name="grant_date" value="<?= htmlspecialchars($row['grant_date'] ?? date('Y-m-d')) ?>">
            <small>يستخدم هذا التاريخ في المحضر لتحديد شهر الصرف (للسلف فقط)</small>
        </div>

        <button type="submit" class="btn-update">💾 تحديث البيانات</button>
        <a href="list.php" class="btn-cancel">🔙 إلغاء</a>
    </form>
</div>

<?php include '../includes/footer.php'; ?>