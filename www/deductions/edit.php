<?php
ob_start();
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';
error_reporting(E_ALL);
ini_set('display_errors', 1);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    $_SESSION['toast'] = ['message' => 'اقتطاع غير صالح', 'type' => 'error', 'duration' => 3000];
    header("Location: list.php");
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM deductions WHERE id = ?");
$stmt->execute([$id]);
$ded = $stmt->fetch();
if (!$ded) {
    $_SESSION['toast'] = ['message' => 'الاقتطاع غير موجود', 'type' => 'error', 'duration' => 3000];
    header("Location: list.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRFToken();
    
    $employee_id   = (int)$_POST['employee_id'];
    $source_id     = (int)$_POST['source_id'];
    $total_amount  = (float)$_POST['total_amount'];
    $total_months  = (int)$_POST['total_months'];
    $start_date    = $_POST['start_date'];
    $is_loan       = isset($_POST['is_loan']) ? 1 : 0;
    $grant_date    = !empty($_POST['grant_date']) ? $_POST['grant_date'] : null;
    $notes         = trim($_POST['notes'] ?? '');
    
    if ($total_amount <= 0 || $total_months <= 0 || !$start_date) {
        $_SESSION['toast'] = ['message' => 'يرجى ملء جميع الحقول المطلوبة', 'type' => 'error', 'duration' => 3000];
        header("Location: edit.php?id=$id");
        exit;
    }
    
    if ($is_loan && empty($grant_date)) {
        $grant_date = date('Y-m-d');
    }
    
    $monthly_amount = round($total_amount / $total_months, 2);
    $end_date = date('Y-m-d', strtotime("+".($total_months - 1)." months", strtotime($start_date)));
    
    try {
        $stmt = $pdo->prepare("
            UPDATE deductions 
            SET employee_id = ?, source_id = ?, monthly_amount = ?, total_months = ?, start_date = ?, end_date = ?, is_loan = ?, grant_date = ?, notes = ?
            WHERE id = ?
        ");
        $stmt->execute([$employee_id, $source_id, $monthly_amount, $total_months, $start_date, $end_date, $is_loan, $grant_date, $notes, $id]);
        
        regenerateMonthlyInstallments($id, true);
        
        $_SESSION['toast'] = ['message' => '✅ تم تحديث الاقتطاع بنجاح', 'type' => 'success', 'duration' => 3000];
        header("Location: list.php");
        exit;
    } catch (Exception $e) {
        $_SESSION['toast'] = ['message' => '❌ خطأ: ' . $e->getMessage(), 'type' => 'error', 'duration' => 5000];
        header("Location: edit.php?id=$id");
        exit;
    }
}

$employees = $pdo->query("SELECT id, name, category FROM employees ORDER BY name")->fetchAll();
$sources = $pdo->query("SELECT id, name FROM sources ORDER BY name")->fetchAll();
$csrf_token = generateCSRFToken();
include '../includes/header.php';
?>

<style>
    .form-container { max-width: 600px; margin: 20px auto; background: white; padding: 25px; border-radius: 20px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
    .form-group { margin-bottom: 15px; }
    .form-group label { display: block; font-weight: bold; margin-bottom: 5px; }
    .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 8px; border-radius: 10px; border: 1px solid #ccc; }
    .btn-primary { background: #2a5298; color: white; padding: 10px 20px; border-radius: 30px; border: none; cursor: pointer; }
    .btn-secondary { background: #6c757d; color: white; padding: 10px 20px; border-radius: 30px; text-decoration: none; display: inline-block; text-align: center; }
    .loan-fields { background: #f8f9fa; padding: 15px; border-radius: 10px; margin-top: 10px; }
</style>

<div class="form-container">
    <h2>✏️ تعديل الاقتطاع</h2>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
        
        <div class="form-group">
            <label>الموظف</label>
            <select name="employee_id" required>
                <?php foreach($employees as $emp): ?>
                    <option value="<?= $emp['id'] ?>" <?= $emp['id'] == $ded['employee_id'] ? 'selected' : '' ?>><?= htmlspecialchars($emp['name']) ?> (<?= $emp['category'] == 'Permanent' ? 'دائم' : 'متعاقد' ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="form-group">
            <label>المصدر</label>
            <select name="source_id" required>
                <?php foreach($sources as $src): ?>
                    <option value="<?= $src['id'] ?>" <?= $src['id'] == $ded['source_id'] ? 'selected' : '' ?>><?= htmlspecialchars($src['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="form-group">
            <label>المبلغ الكلي (دج)</label>
            <input type="number" step="0.01" name="total_amount" value="<?= $ded['monthly_amount'] * $ded['total_months'] ?>" required>
        </div>
        
        <div class="form-group">
            <label>عدد الأشهر (الأقساط)</label>
            <input type="number" name="total_months" value="<?= $ded['total_months'] ?>" required>
        </div>
        
        <div class="form-group">
            <label>تاريخ بداية الاقتطاع</label>
            <input type="date" name="start_date" value="<?= $ded['start_date'] ?>" required>
        </div>
        
        <div class="form-group">
            <label>
                <input type="checkbox" name="is_loan" value="1" <?= $ded['is_loan'] ? 'checked' : '' ?> id="is_loan_checkbox" onchange="toggleLoanFields()"> سلفة (قرض)
            </label>
        </div>
        
        <div id="loan_fields" class="loan-fields" style="<?= $ded['is_loan'] ? 'display:block;' : 'display:none;' ?>">
            <div class="form-group">
                <label>📅 تاريخ الصرف</label>
                <input type="date" name="grant_date" value="<?= $ded['grant_date'] ?? date('Y-m-d') ?>">
                <small class="text-muted">تاريخ منح السلفة (سيظهر في المحضر)</small>
            </div>
        </div>
        
        <div class="form-group">
            <label>ملاحظات</label>
            <textarea name="notes" rows="3"><?= htmlspecialchars($ded['notes'] ?? '') ?></textarea>
        </div>
        
        <div style="display: flex; gap: 10px; justify-content: space-between;">
            <button type="submit" class="btn-primary">💾 تحديث</button>
            <a href="list.php" class="btn-secondary">إلغاء</a>
        </div>
    </form>
</div>

<script>
    function toggleLoanFields() {
        var checkbox = document.getElementById('is_loan_checkbox');
        var fields = document.getElementById('loan_fields');
        fields.style.display = checkbox.checked ? 'block' : 'none';
    }
</script>

<?php include '../includes/footer.php'; ?>