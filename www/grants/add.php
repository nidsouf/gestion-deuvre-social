<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';

// ========== معالجة POST قبل أي ناتج ==========
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    requireCSRFToken();
    
    $name = trim($_POST['name'] ?? '');
    $calculation_type = $_POST['calculation_type'] ?? 'fixed';
    $amount = (float)$_POST['amount'];
    $percentage_value = (float)$_POST['percentage_value'];
    $max_amount = (float)$_POST['max_amount'];
    
    $errors = [];
    if (empty($name)) $errors[] = 'اسم المنحة مطلوب';
    
    if ($calculation_type == 'fixed') {
        if ($amount <= 0) $errors[] = 'المبلغ يجب أن يكون موجباً';
        $percentage_value = 0;
        $max_amount = 0;
    } else {
        if ($percentage_value <= 0 || $percentage_value > 100) $errors[] = 'النسبة المئوية يجب أن تكون بين 1 و 100';
        if ($max_amount < 0) $errors[] = 'الحد الأقصى يجب أن يكون موجباً';
        $amount = 0;
    }
    
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO grants (name, amount, calculation_type, percentage_value, max_amount, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, datetime('now'), datetime('now'))
            ");
            $stmt->execute([$name, $amount, $calculation_type, $percentage_value, $max_amount]);
            
            audit('GRANT_TYPE_ADDED', "Added grant type: $name");
            addNotification('نوع منحة جديد', "تم إضافة نوع منحة جديدة: $name", null, 'success');
            
            $_SESSION['toast'] = ['message' => '✅ تم إضافة المنحة بنجاح', 'type' => 'success', 'duration' => 3000];
            header("Location: list.php");
            exit;
        } catch (Exception $e) {
            error_log("Grant add error: " . $e->getMessage());
            $_SESSION['toast'] = ['message' => '❌ حدث خطأ: ' . $e->getMessage(), 'type' => 'error', 'duration' => 5000];
            header("Location: add.php");
            exit;
        }
    } else {
        $_SESSION['toast'] = ['message' => '⚠️ ' . implode(' - ', $errors), 'type' => 'warning', 'duration' => 4000];
        header("Location: add.php");
        exit;
    }
}

// ========== بعد المعالجة، نبدأ عرض الصفحة ==========
include '../includes/header.php';
$csrf_token = generateCSRFToken();
?>

<style>
    .form-container { max-width: 500px; margin: 30px auto; background: white; padding: 25px; border-radius: 20px; box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
    .form-group { margin-bottom: 20px; }
    .form-group label { display: block; font-weight: bold; margin-bottom: 8px; }
    .form-group input, .form-group select { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 12px; }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
    .btn-save { width: 100%; background: #28a745; color: white; padding: 12px; border: none; border-radius: 30px; font-weight: bold; cursor: pointer; }
    .btn-cancel { display: block; text-align: center; margin-top: 10px; background: #6c757d; color: white; text-decoration: none; padding: 10px; border-radius: 30px; }
    .help-text { font-size: 12px; color: #888; margin-top: 5px; }
</style>

<div class="form-container">
    <h2>➕ إضافة منحة جديدة</h2>
    <form method="POST" novalidate>
        <input type="hidden" name="csrf_token" value="<?= escape($csrf_token) ?>">
        
        <div class="form-group">
            <label>اسم المنحة</label>
            <input type="text" name="name" required>
        </div>
        
        <div class="form-group">
            <label>نوع الحساب</label>
            <select name="calculation_type" id="calcType" onchange="toggleFields()" required>
                <option value="fixed">💰 مبلغ ثابت</option>
                <option value="percentage">📊 نسبة مئوية</option>
            </select>
        </div>
        
        <div id="fixedFields">
            <div class="form-group">
                <label>المبلغ (دج)</label>
                <input type="number" step="0.01" name="amount" value="0">
            </div>
        </div>
        
        <div id="percentageFields" style="display:none;">
            <div class="form-row">
                <div class="form-group">
                    <label>النسبة المئوية (%)</label>
                    <input type="number" step="0.1" name="percentage_value" value="30" min="1" max="100">
                    <div class="help-text">مثال: 30 = 30% من قيمة الفاتورة</div>
                </div>
                <div class="form-group">
                    <label>الحد الأقصى (دج)</label>
                    <input type="number" step="0.01" name="max_amount" value="25000">
                    <div class="help-text">أقصى مبلغ يمكن صرفه. 0 = بدون حد</div>
                </div>
            </div>
        </div>
        
        <button type="submit" class="btn-save">💾 حفظ المنحة</button>
        <a href="list.php" class="btn-cancel">🔙 إلغاء</a>
    </form>
</div>

<script>
    function toggleFields() {
        const type = document.getElementById('calcType').value;
        document.getElementById('fixedFields').style.display = type === 'fixed' ? 'block' : 'none';
        document.getElementById('percentageFields').style.display = type === 'percentage' ? 'block' : 'none';
    }
</script>

<?php include '../includes/footer.php'; ?>