<?php
ob_start(); // منع أي مخرجات مبكرة
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';

// ========== معالجة POST ==========
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (empty($_POST['source_id']) || empty($_POST['cheque_number']) || empty($_POST['cheque_date']) || empty($_POST['amount'])) {
        $_SESSION['toast'] = ['message' => 'يرجى ملء جميع الحقول المطلوبة', 'type' => 'warning', 'duration' => 4000];
        header("Location: add.php");
        exit;
    }

    $source_id      = (int)$_POST['source_id'];
    $cheque_number  = trim($_POST['cheque_number']);
    $cheque_date_raw = trim($_POST['cheque_date']);
    $amount         = (float)$_POST['amount'];
    $quarter        = isset($_POST['quarter']) ? (int)$_POST['quarter'] : null;
    $notes          = trim($_POST['notes'] ?? '');

    if ($amount <= 0) {
        $_SESSION['toast'] = ['message' => 'المبلغ يجب أن يكون أكبر من صفر', 'type' => 'error', 'duration' => 4000];
        header("Location: add.php");
        exit;
    }

    $cheque_date = date('Y-m-d', strtotime($cheque_date_raw));
    if (!$cheque_date || $cheque_date === '1970-01-01') {
        $_SESSION['toast'] = ['message' => 'تاريخ الشيك غير صالح', 'type' => 'error', 'duration' => 4000];
        header("Location: add.php");
        exit;
    }

    $is_saadine = ($source_id === 1); // حسب هيكل البيانات لديك
    if ($is_saadine && ($quarter < 1 || $quarter > 4)) {
        $_SESSION['toast'] = ['message' => 'يجب اختيار رقم الربع (1-4) لمصدر سعدين', 'type' => 'warning', 'duration' => 4000];
        header("Location: add.php");
        exit;
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO source_payments (source_id, cheque_number, cheque_date, amount, quarter, notes) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$source_id, $cheque_number, $cheque_date, $amount, $quarter, $notes]);

        $_SESSION['toast'] = ['message' => '✅ تم إضافة الشيك بنجاح', 'type' => 'success', 'duration' => 3000];
        header("Location: list.php");
        exit;
    } catch (Exception $e) {
        $_SESSION['toast'] = ['message' => '❌ حدث خطأ: ' . $e->getMessage(), 'type' => 'error', 'duration' => 5000];
        header("Location: add.php");
        exit;
    }
}

// جلب المصادر للقائمة المنسدلة
$sources = $pdo->query("SELECT id, name FROM sources ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
include '../includes/header.php';
?>

<div class="section" style="max-width: 600px; margin: 0 auto;">
    <div class="section-header">
        <h3>➕ إضافة شيك / قيد دفع جديد</h3>
        <a href="list.php" class="btn-sm">🔙 العودة للقائمة</a>
    </div>

    <form method="POST" style="background: white; padding: 25px; border-radius: 20px; box-shadow: 0 0 10px rgba(0,0,0,0.05);">
        <div class="form-group">
            <label>📁 المصدر <span style="color:red;">*</span></label>
            <select name="source_id" id="source_id" required class="form-control">
                <option value="">-- اختر المصدر --</option>
                <?php foreach($sources as $src): ?>
                    <option value="<?= $src['id'] ?>"><?= htmlspecialchars($src['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label>🧾 رقم الشيك <span style="color:red;">*</span></label>
            <input type="text" name="cheque_number" required class="form-control">
        </div>

        <div class="form-group">
            <label>📅 تاريخ الشيك <span style="color:red;">*</span></label>
            <input type="date" name="cheque_date" required class="form-control">
        </div>

        <div class="form-group">
            <label>💰 القيمة (دج) <span style="color:red;">*</span></label>
            <input type="number" step="0.01" name="amount" required class="form-control">
        </div>

        <div class="form-group" id="quarter_group" style="display: none;">
            <label>📆 رقم الربع (نظام الثلاثي) <span style="color:red;">*</span></label>
            <select name="quarter" id="quarter" class="form-control">
                <option value="">-- اختر الربع --</option>
                <option value="1">الربع الأول</option>
                <option value="2">الربع الثاني</option>
                <option value="3">الربع الثالث</option>
                <option value="4">الربع الرابع</option>
            </select>
        </div>

        <div class="form-group">
            <label>📝 ملاحظات</label>
            <textarea name="notes" rows="3" class="form-control"></textarea>
        </div>

        <button type="submit" class="btn btn-primary" style="width:100%;">💾 حفظ الشيك</button>
    </form>
</div>

<script>
    document.getElementById('source_id').addEventListener('change', function() {
        const selectedId = parseInt(this.value);
        const quarterGroup = document.getElementById('quarter_group');
        const quarterSelect = document.getElementById('quarter');
        if (selectedId === 1) {  // مصدر سعدين
            quarterGroup.style.display = 'block';
            quarterSelect.required = true;
        } else {
            quarterGroup.style.display = 'none';
            quarterSelect.required = false;
            quarterSelect.value = '';
        }
    });
</script>

<?php
ob_end_flush();
include '../includes/footer.php';
?>