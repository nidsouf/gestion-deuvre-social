<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
require_once '../config/database.php';
require_once '../includes/functions.php';

// ========== معالجة POST قبل أي ناتج ==========
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // التحقق من وجود البيانات الأساسية
    if (empty($_POST['employee_id']) || empty($_POST['source_id']) || empty($_POST['total_amount']) || empty($_POST['total_months']) || empty($_POST['start_date'])) {
        $_SESSION['toast'] = ['message' => 'يرجى ملء جميع الحقول المطلوبة', 'type' => 'warning', 'duration' => 4000];
        header("Location: add.php");
        exit;
    }

    $employee_id = $_POST['employee_id'];
    $source_id = $_POST['source_id'];
    $total_amount = floatval($_POST['total_amount']);
    $total_months = intval($_POST['total_months']);
    $start_date = $_POST['start_date'];
    $grant_date = !empty($_POST['grant_date']) ? $_POST['grant_date'] : $start_date;
    $is_loan = isset($_POST['is_loan']) ? 1 : 0;

    // التحقق من صحة البيانات
    if ($total_amount <= 0 || $total_months <= 0) {
        $_SESSION['toast'] = ['message' => 'المبلغ وعدد الأشهر يجب أن يكونا أكبر من صفر', 'type' => 'error', 'duration' => 4000];
        header("Location: add.php");
        exit;
    }

    $monthly_amount = $total_amount / $total_months;
    
    // ========== حساب تاريخ النهاية بدقة باستخدام DateInterval ==========
    $startDateTime = new DateTime($start_date);
    $endDateTime = clone $startDateTime;
    $monthsToAdd = $total_months - 1;
    if ($monthsToAdd > 0) {
        $interval = new DateInterval('P' . $monthsToAdd . 'M');
        $endDateTime->add($interval);
    }
    // إذا أردت أن يكون تاريخ النهاية هو اليوم الأخير من ذلك الشهر (اختياري)
    // $endDateTime->modify('last day of this month');
    $end_date = $endDateTime->format('Y-m-d');

    // بدء المعاملة (transaction) للتأكد من سلامة البيانات
    $pdo->beginTransaction();
    try {
        // إضافة الاقتطاع مع grant_date
        $stmt = $pdo->prepare("INSERT INTO deductions (employee_id, source_id, monthly_amount, total_months, start_date, end_date, is_loan, grant_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$employee_id, $source_id, $monthly_amount, $total_months, $start_date, $end_date, $is_loan, $grant_date]);
        $deduction_id = $pdo->lastInsertId();

        // لو السلفة: خصم من الميزانية وتسجيل المعاملة
        if ($is_loan) {
            updateBudget($total_amount, 'deduct');
            $pdo->prepare("
                INSERT INTO budget_transactions (amount, type, reference_id, description, is_deduct)
                VALUES (?, 'loan', ?, 'سلفة جديدة', 1)
            ")->execute([$total_amount, $deduction_id]);
        }

        $pdo->commit();
        $_SESSION['toast'] = ['message' => 'تم إضافة الاقتطاع بنجاح', 'type' => 'success', 'duration' => 3000];
        header("Location: list.php");
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['toast'] = ['message' => 'حدث خطأ أثناء حفظ الاقتطاع: ' . $e->getMessage(), 'type' => 'error', 'duration' => 5000];
        header("Location: add.php");
        exit;
    }
}

// ========== بعد المعالجة، نبدأ عرض الصفحة ==========
include '../includes/header.php';

$employees = $pdo->query("SELECT id, name FROM employees ORDER BY name")->fetchAll();
$sources = $pdo->query("SELECT id, name FROM sources ORDER BY name")->fetchAll();
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
    input, select {
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
    .checkbox-group {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-top: 15px;
    }
</style>

<div class="form-container">
    <h2>➕ إضافة اقتطاع جديد</h2>
    <form method="POST">
        <label>👤 الموظف</label>
        <select name="employee_id" required>
            <option value="">اختر الموظف</option>
            <?php foreach($employees as $emp): ?>
                <option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['name']) ?></option>
            <?php endforeach; ?>
        </select>

        <label>📁 المصدر</label>
        <select name="source_id" required>
            <option value="">اختر المصدر</option>
            <?php foreach($sources as $src): ?>
                <option value="<?= $src['id'] ?>"><?= htmlspecialchars($src['name']) ?></option>
            <?php endforeach; ?>
        </select>

        <label>💰 المبلغ الكلي (دج)</label>
        <input type="number" step="0.01" name="total_amount" required>

        <label>📊 عدد الأقساط (شهور)</label>
        <input type="number" name="total_months" required>

        <label>📅 تاريخ بداية الاقتطاع</label>
        <input type="date" name="start_date" required>

        <label>📅 تاريخ صرف السلفة (تاريخ منحها للموظف)</label>
        <input type="date" name="grant_date" value="<?= date('Y-m-d') ?>">
        <small>يستخدم هذا التاريخ في المحضر لتحديد شهر الصرف (للسلف فقط)</small>

        <div class="checkbox-group">
            <input type="checkbox" name="is_loan" id="is_loan">
            <label for="is_loan">🔁 سلفة (تُرد للميزانية)</label>
        </div>

        <button type="submit">💾 حفظ الاقتطاع</button>
    </form>
</div>

<?php include '../includes/footer.php'; ?>