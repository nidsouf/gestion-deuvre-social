<?php
ob_start();
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$stmt = $pdo->prepare("SELECT * FROM deductions WHERE id = ?");
$stmt->execute([$id]);
$ded = $stmt->fetch();

if (!$ded) {
    $_SESSION['toast'] = ['message' => 'الاقتطاع غير موجود', 'type' => 'error', 'duration' => 3000];
    header("Location: list.php");
    exit;
}

// حساب المبلغ الكلي الحالي
$total_amount = $ded['monthly_amount'] * $ded['total_months'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRFToken();
    
    $employee_id   = (int)$_POST['employee_id'];
    $source_id     = (int)$_POST['source_id'];
    $total_amount  = (float)$_POST['total_amount'];
    $total_months  = (int)$_POST['total_months'];
    $start_date    = $_POST['start_date'];
    $is_loan       = isset($_POST['is_loan']) ? 1 : 0;
    $notes         = trim($_POST['notes'] ?? '');
    
    if ($total_amount <= 0 || $total_months <= 0 || !$start_date) {
        $_SESSION['toast'] = ['message' => 'يرجى ملء جميع الحقول المطلوبة', 'type' => 'error', 'duration' => 3000];
        header("Location: edit.php?id=$id");
        exit;
    }
    
    $monthly_amount = round($total_amount / $total_months, 2);
    $end_date = date('Y-m-d', strtotime("+".($total_months - 1)." months", strtotime($start_date)));
    
    try {
        $update = $pdo->prepare("
            UPDATE deductions 
            SET employee_id = ?, source_id = ?, monthly_amount = ?, total_months = ?, 
                start_date = ?, end_date = ?, is_loan = ?, notes = ?
            WHERE id = ?
        ");
        $update->execute([$employee_id, $source_id, $monthly_amount, $total_months, $start_date, $end_date, $is_loan, $notes, $id]);
        
        regenerateMonthlyInstallments($id, true);
        
        $_SESSION['toast'] = ['message' => '✅ تم التعديل بنجاح', 'type' => 'success', 'duration' => 3000];
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
</style>

<div class="form-container">
    <h2>✏️ تعديل الاقتطاع</h2>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
        
        <div class="form-group">
            <label>الموظف</label>
            <select name="employee_id" required>
                <?php foreach($employees as $emp): ?>
                    <option value="<?= $emp['id'] ?>" <?= $emp['id'] == $ded['employee_id'] ? 'selected' : '' ?>><?= htmlspecialchars($emp['name']) ?></option>
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
            <input type="number" step="0.01" name="total_amount" value="<?= htmlspecialchars($total_amount) ?>" required>
        </div>
        
        <div class="form-group">
            <label>عدد الأشهر (الأقساط)</label>
            <input type="number" name="total_months" value="<?= htmlspecialchars($ded['total_months']) ?>" required>
        </div>
        
        <div class="form-group">
            <label>تاريخ بداية الاقتطاع</label>
            <input type="date" name="start_date" value="<?= htmlspecialchars($ded['start_date']) ?>" required>
        </div>
        
        <div class="form-group">
            <label>
                <input type="checkbox" name="is_loan" value="1" <?= $ded['is_loan'] ? 'checked' : '' ?>> سلفة (قرض)
            </label>
        </div>
        
        <div class="form-group">
            <label>ملاحظات</label>
            <textarea name="notes" rows="3"><?= htmlspecialchars($ded['notes'] ?? '') ?></textarea>
        </div>
        
        <div style="display: flex; gap: 10px; justify-content: space-between;">
            <button type="submit" class="btn-primary">💾 حفظ التعديلات</button>
            <a href="list.php" class="btn-secondary">🔙 إلغاء</a>
        </div>
    </form>
</div>

<?php include '../includes/footer.php'; ?>