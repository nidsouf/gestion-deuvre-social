<?php
/**
 * grants/list.php - قائمة أنواع المنح (مع دعم النسبة المئوية)
 */
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';

// ========== معالجة POST ==========
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_grant'])) {
    requireCSRFToken();
    
    $name = sanitizeInput($_POST['name'] ?? '');
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
    } else { // percentage
        if ($percentage_value <= 0 || $percentage_value > 100) $errors[] = 'النسبة المئوية يجب أن تكون بين 1 و 100';
        if ($max_amount < 0) $errors[] = 'الحد الأقصى يجب أن يكون موجباً';
        $amount = 0; // لا نستخدم المبلغ الثابت
    }
    
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO grants (name, amount, calculation_type, percentage_value, max_amount) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$name, $amount, $calculation_type, $percentage_value, $max_amount]);
            audit('GRANT_TYPE_ADDED', "Added grant type: $name ($calculation_type)");
            addNotification('نوع منحة جديد', "تم إضافة نوع منحة جديدة: $name", null, 'success');
            $_SESSION['toast'] = ['message' => '✅ تم إضافة نوع المنحة بنجاح', 'type' => 'success', 'duration' => 3000];
        } catch (Exception $e) {
            error_log("Grant type add error: " . $e->getMessage());
            $_SESSION['toast'] = ['message' => '❌ حدث خطأ', 'type' => 'error', 'duration' => 3000];
        }
    } else {
        $_SESSION['toast'] = ['message' => '⚠️ ' . implode(' - ', $errors), 'type' => 'warning', 'duration' => 4000];
    }
    header("Location: list.php");
    exit;
}

// ========== جلب البيانات ==========
include '../includes/header.php';

$grants = $pdo->query("SELECT * FROM grants ORDER BY name")->fetchAll();
$totalGrants = count($grants);
$totalAmount = array_sum(array_column($grants, 'amount'));

$csrf_token = generateCSRFToken();
?>

<style>
    .stats-grid { display: flex; gap: 20px; margin-bottom: 30px; }
    .stat-card { background: white; border-radius: 20px; padding: 20px; text-align: center; flex: 1; border-bottom: 3px solid; }
    .stat-card.grants { border-bottom-color: #9c27b0; }
    .stat-card.amount { border-bottom-color: #ff9800; }
    .stat-card .number { font-size: 28px; font-weight: 700; }
    .form-card { background: #f8f9fa; padding: 20px; border-radius: 20px; margin-bottom: 30px; }
    .form-group { margin-bottom: 15px; }
    .form-group label { display: block; font-weight: bold; margin-bottom: 8px; }
    .form-group input, .form-group select { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 12px; }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
    .btn-add { background: #28a745; color: white; padding: 8px 20px; border: none; border-radius: 30px; cursor: pointer; }
    .data-table { width: 100%; border-collapse: collapse; background: white; }
    .data-table th, .data-table td { border: 1px solid #ddd; padding: 10px; text-align: center; }
    .data-table th { background: #2a5298; color: white; }
    .badge { padding: 3px 10px; border-radius: 12px; font-size: 12px; display: inline-block; }
    .badge-fixed { background: #28a745; color: white; }
    .badge-percentage { background: #ff9800; color: white; }
    .btn-edit { background: #ffc107; color: #000; padding: 4px 12px; border-radius: 20px; text-decoration: none; }
    .btn-delete { background: #dc3545; color: white; padding: 4px 12px; border-radius: 20px; text-decoration: none; }
    .action-buttons { display: flex; gap: 10px; justify-content: center; }
    .help-text { font-size: 12px; color: #888; margin-top: 5px; }
</style>

<div style="max-width: 1100px; margin: 0 auto;">
    <h2 style="margin-bottom: 20px;">🎁 أنواع المنح الاجتماعية</h2>
    
    <div class="stats-grid">
        <div class="stat-card grants"><div>🎁 إجمالي أنواع المنح</div><div class="number"><?= $totalGrants ?></div></div>
        <div class="stat-card amount"><div>💰 إجمالي القيم الثابتة</div><div class="number"><?= number_format($totalAmount, 2) ?> دج</div></div>
    </div>
    
    <div class="form-card">
        <h3>➕ إضافة نوع منحة جديد</h3>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= escape($csrf_token) ?>">
            
            <div class="form-group">
                <label>اسم المنحة</label>
                <input type="text" name="name" required>
            </div>
            
            <div class="form-group">
                <label>نوع الحساب</label>
                <select name="calculation_type" id="calcType" onchange="toggleFields()" required>
                    <option value="fixed">💰 مبلغ ثابت</option>
                    <option value="percentage">📊 نسبة مئوية من الفاتورة</option>
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
            
            <button type="submit" name="add_grant" class="btn-add">💾 إضافة</button>
        </form>
    </div>
    
    <div style="overflow-x: auto;">
        <table class="data-table">
            <thead><tr><th>#</th><th>اسم المنحة</th><th>نوع الحساب</th><th>القيمة</th><th>الحد الأقصى</th><th>الإجراءات</th></tr></thead>
            <tbody>
                <?php if (empty($grants)): ?>
                    <tr><td colspan="6" style="text-align:center;">لا توجد أنواع منح</td></tr>
                <?php else: ?>
                    <?php $i=1; foreach ($grants as $g): ?>
                        <tr>
                            <td><?= $i++ ?></td>
                            <td><?= escape($g['name']) ?></td>
                            <td>
                                <?php if ($g['calculation_type'] == 'fixed'): ?>
                                    <span class="badge badge-fixed">💰 ثابت</span>
                                <?php else: ?>
                                    <span class="badge badge-percentage">📊 نسبة مئوية</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($g['calculation_type'] == 'fixed'): ?>
                                    <?= number_format($g['amount'], 2) ?> دج
                                <?php else: ?>
                                    <?= $g['percentage_value'] ?>%
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($g['calculation_type'] == 'percentage' && $g['max_amount'] > 0): ?>
                                    <?= number_format($g['max_amount'], 2) ?> دج
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td class="action-buttons">
                                <a href="edit.php?id=<?= $g['id'] ?>" class="btn-edit">✏️ تعديل</a>
                                <a href="delete.php?id=<?= $g['id'] ?>" class="btn-delete" onclick="return confirm('حذف هذه المنحة؟')">🗑️ حذف</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    function toggleFields() {
        const type = document.getElementById('calcType').value;
        document.getElementById('fixedFields').style.display = type === 'fixed' ? 'block' : 'none';
        document.getElementById('percentageFields').style.display = type === 'percentage' ? 'block' : 'none';
    }
    // استدعاء أولي
    toggleFields();
</script>

<?php include '../includes/footer.php'; ?>