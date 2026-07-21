<?php
ob_start();
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';
require_once '../includes/common_helpers.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $source_id = (int)$_POST['source_id'];
    $cheque_number = trim($_POST['cheque_number']);
    $cheque_date = $_POST['cheque_date'];
    $amount = (float)$_POST['amount'];
    $quarter = isset($_POST['quarter']) ? (int)$_POST['quarter'] : null;
    $notes = trim($_POST['notes'] ?? '');

    if ($amount <= 0 || !$source_id || !$cheque_number || !$cheque_date) {
        setToast('يرجى ملء جميع الحقول المطلوبة', 'warning');
        redirectTo('add.php');
    }

    $cheque_date = date('Y-m-d', strtotime($cheque_date));
    if ($cheque_date === '1970-01-01') { setToast('تاريخ الشيك غير صالح', 'error'); redirectTo('add.php'); }

    if ($source_id === 1 && ($quarter < 1 || $quarter > 4)) {
        setToast('يجب اختيار رقم الربع (1-4) لمصدر سعدين', 'warning');
        redirectTo('add.php');
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO source_payments (source_id, cheque_number, cheque_date, amount, quarter, notes) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$source_id, $cheque_number, $cheque_date, $amount, $quarter, $notes]);
        setToast('✅ تم إضافة الشيك بنجاح', 'success');
        redirectTo('list.php');
    } catch (Exception $e) {
        setToast('❌ حدث خطأ: ' . $e->getMessage(), 'error');
        redirectTo('add.php');
    }
}

$sources = $pdo->query("SELECT id, name FROM sources ORDER BY name")->fetchAll();
include '../includes/header.php';
?>
<link rel="stylesheet" href="../assets/css/payments.css">

<div class="payments-container" style="max-width:600px; margin:0 auto;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
        <h3>➕ إضافة شيك جديد</h3>
        <a href="list.php" class="btn-sm" style="background:#6c757d; color:white; padding:6px 16px; border-radius:20px; text-decoration:none;">🔙 العودة</a>
    </div>

    <form method="POST" style="background:white; padding:25px; border-radius:20px; box-shadow:0 2px 10px rgba(0,0,0,0.05);">
        <div class="form-group" style="margin-bottom:15px;">
            <label style="display:block; font-weight:bold; margin-bottom:5px;">📁 المصدر <span style="color:red;">*</span></label>
            <select name="source_id" id="source_id" required style="width:100%; padding:10px; border-radius:12px; border:1px solid #ddd;">
                <option value="">-- اختر المصدر --</option>
                <?php foreach($sources as $src): ?>
                    <option value="<?= $src['id'] ?>"><?= htmlspecialchars($src['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group" style="margin-bottom:15px;">
            <label style="display:block; font-weight:bold; margin-bottom:5px;">🧾 رقم الشيك <span style="color:red;">*</span></label>
            <input type="text" name="cheque_number" required style="width:100%; padding:10px; border-radius:12px; border:1px solid #ddd;">
        </div>

        <div class="form-group" style="margin-bottom:15px;">
            <label style="display:block; font-weight:bold; margin-bottom:5px;">📅 تاريخ الشيك <span style="color:red;">*</span></label>
            <input type="date" name="cheque_date" required style="width:100%; padding:10px; border-radius:12px; border:1px solid #ddd;">
        </div>

        <div class="form-group" style="margin-bottom:15px;">
            <label style="display:block; font-weight:bold; margin-bottom:5px;">💰 القيمة (دج) <span style="color:red;">*</span></label>
            <input type="number" step="0.01" name="amount" required style="width:100%; padding:10px; border-radius:12px; border:1px solid #ddd;">
        </div>

        <div class="form-group" id="quarter_group" style="margin-bottom:15px; display:none;">
            <label style="display:block; font-weight:bold; margin-bottom:5px;">📆 رقم الربع <span style="color:red;">*</span></label>
            <select name="quarter" id="quarter" style="width:100%; padding:10px; border-radius:12px; border:1px solid #ddd;">
                <option value="">-- اختر الربع --</option>
                <option value="1">الربع الأول</option>
                <option value="2">الربع الثاني</option>
                <option value="3">الربع الثالث</option>
                <option value="4">الربع الرابع</option>
            </select>
        </div>

        <div class="form-group" style="margin-bottom:15px;">
            <label style="display:block; font-weight:bold; margin-bottom:5px;">📝 ملاحظات</label>
            <textarea name="notes" rows="3" style="width:100%; padding:10px; border-radius:12px; border:1px solid #ddd;"></textarea>
        </div>

        <button type="submit" style="width:100%; background:#28a745; color:white; padding:12px; border:none; border-radius:30px; font-weight:bold; cursor:pointer;">💾 حفظ الشيك</button>
    </form>
</div>

<script>
document.getElementById('source_id').addEventListener('change', function() {
    const quarterGroup = document.getElementById('quarter_group');
    const quarterSelect = document.getElementById('quarter');
    if (parseInt(this.value) === 1) {
        quarterGroup.style.display = 'block';
        quarterSelect.required = true;
    } else {
        quarterGroup.style.display = 'none';
        quarterSelect.required = false;
        quarterSelect.value = '';
    }
});
</script>

<?php include '../includes/footer.php'; ?>