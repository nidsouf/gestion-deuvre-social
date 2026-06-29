<?php
ob_start();
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRFToken();
    
    $employee_id   = (int)$_POST['employee_id'];
    $source_id     = (int)$_POST['source_id'];
    $total_amount  = (float)$_POST['total_amount'];      // المبلغ الكلي
    $total_months  = (int)$_POST['total_months'];        // عدد الأشهر
    $start_date    = $_POST['start_date'];
    $is_loan       = isset($_POST['is_loan']) ? 1 : 0;
    $notes         = trim($_POST['notes'] ?? '');
    
    // التحقق من صحة المدخلات
    if ($total_amount <= 0 || $total_months <= 0 || !$start_date) {
        $_SESSION['toast'] = ['message' => 'يرجى ملء جميع الحقول المطلوبة بشكل صحيح', 'type' => 'error', 'duration' => 3000];
        header("Location: add.php");
        exit;
    }
    
    // حساب المبلغ الشهري وتاريخ النهاية
    $monthly_amount = $total_amount / $total_months;
    // يمكن تقريب المبلغ الشهري إلى منزلتين عشريتين (اختياري)
    $monthly_amount = round($monthly_amount, 2);
    // حساب تاريخ النهاية: إضافة (total_months - 1) شهر إلى تاريخ البداية
    $end_date = date('Y-m-d', strtotime("+".($total_months - 1)." months", strtotime($start_date)));
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO deductions (employee_id, source_id, monthly_amount, total_months, start_date, end_date, is_loan, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$employee_id, $source_id, $monthly_amount, $total_months, $start_date, $end_date, $is_loan, $notes]);
        $new_id = $pdo->lastInsertId();
        
        // توليد الأقساط الشهرية
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
                <input type="checkbox" name="is_loan" value="1"> سلفة (قرض)
            </label>
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

<?php include '../includes/footer.php'; ?>