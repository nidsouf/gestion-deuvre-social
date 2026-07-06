<?php
ob_start();
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';
error_reporting(E_ALL);
ini_set('display_errors', 1);

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
    
    // التحقق من صحة المدخلات
    if ($total_amount <= 0 || $total_months <= 0 || !$start_date) {
        $_SESSION['toast'] = ['message' => 'يرجى ملء جميع الحقول المطلوبة بشكل صحيح', 'type' => 'error', 'duration' => 3000];
        header("Location: add.php");
        exit;
    }
    
    // إذا كانت سلفة ولم يتم تحديد تاريخ صرف، نضع تاريخ اليوم
    if ($is_loan && empty($grant_date)) {
        $grant_date = date('Y-m-d');
    }
    
    $monthly_amount = round($total_amount / $total_months, 2);
    $end_date = date('Y-m-d', strtotime("+".($total_months - 1)." months", strtotime($start_date)));
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO deductions (employee_id, source_id, monthly_amount, total_months, start_date, end_date, is_loan, grant_date, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$employee_id, $source_id, $monthly_amount, $total_months, $start_date, $end_date, $is_loan, $grant_date, $notes]);
        $new_id = $pdo->lastInsertId();
        
        regenerateMonthlyInstallments($new_id, false);
        
        $_SESSION['toast'] = ['message' => '✅ تم إضافة الاقتطاع بنجاح', 'type' => 'success', 'duration' => 3000];
        header("Location: list.php");
        exit;
    } catch (Exception $e) {
        $_SESSION['toast'] = ['message' => '❌ خطأ: ' . $e->getMessage(), 'type' => 'error', 'duration' => 5000];
        header("Location: add.php");
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
    <h2>➕ إضافة اقتطاع جديد</h2>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
        
        <div class="form-group">
            <label>الموظف</label>
            <select name="employee_id" required>
                <option value="">اختر الموظف</option>
                <?php foreach($employees as $emp): ?>
                    <option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['name']) ?> (<?= $emp['category'] == 'Permanent' ? 'دائم' : 'متعاقد' ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="form-group">
            <label>المصدر</label>
            <select name="source_id" required>
                <option value="">اختر المصدر</option>
                <?php foreach($sources as $src): ?>
                    <option value="<?= $src['id'] ?>"><?= htmlspecialchars($src['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="form-group">
            <label>المبلغ الكلي (دج)</label>
            <input type="number" step="0.01" name="total_amount" required placeholder="مثال: 100000.00">
        </div>
        
        <div class="form-group">
            <label>عدد الأشهر (الأقساط)</label>
            <input type="number" name="total_months" required placeholder="مثال: 10">
        </div>
        
        <div class="form-group">
            <label>تاريخ بداية الاقتطاع</label>
            <input type="date" name="start_date" required>
        </div>
        
        <div class="form-group">
            <label>
                <input type="checkbox" name="is_loan" value="1" id="is_loan_checkbox" onchange="toggleLoanFields()"> سلفة (قرض)
            </label>
        </div>
        
        <div id="loan_fields" class="loan-fields" style="display: none;">
            <div class="form-group">
                <label>📅 تاريخ الصرف</label>
                <input type="date" name="grant_date" value="<?= date('Y-m-d') ?>">
                <small class="text-muted">تاريخ منح السلفة (سيظهر في المحضر)</small>
            </div>
        </div>
        
        <div class="form-group">
            <label>ملاحظات</label>
            <textarea name="notes" rows="3"></textarea>
        </div>
        
        <div style="display: flex; gap: 10px; justify-content: space-between;">
            <button type="submit" class="btn-primary">💾 حفظ</button>
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