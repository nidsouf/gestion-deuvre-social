<?php
/**
 * grants/list.php - قائمة أنواع المنح (محسّن)
 */
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';
include '../includes/header.php';

// معالجة إضافة نوع منحة جديد
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_grant'])) {
    requireCSRFToken();
    
    $name = sanitizeInput($_POST['name'] ?? '');
    $amount = (float)$_POST['amount'];
    
    if (empty($name)) {
        $_SESSION['toast'] = ['message' => '⚠️ اسم المنحة مطلوب', 'type' => 'warning', 'duration' => 3000];
    } elseif ($amount <= 0) {
        $_SESSION['toast'] = ['message' => '⚠️ المبلغ يجب أن يكون موجباً', 'type' => 'warning', 'duration' => 3000];
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO grants (name, amount) VALUES (?, ?)");
            $stmt->execute([$name, $amount]);
            audit('GRANT_TYPE_ADDED', "Added grant type: $name");
            addNotification('نوع منحة جديد', "تم إضافة نوع منحة جديدة: $name", null, 'success');
            $_SESSION['toast'] = ['message' => '✅ تم إضافة نوع المنحة بنجاح', 'type' => 'success', 'duration' => 3000];
        } catch (Exception $e) {
            error_log("Grant type add error: " . $e->getMessage());
            $_SESSION['toast'] = ['message' => '❌ حدث خطأ', 'type' => 'error', 'duration' => 3000];
        }
    }
    header("Location: list.php");
    exit;
}

// جلب قائمة أنواع المنح
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
    .form-group input { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 12px; }
    .btn-add { background: #28a745; color: white; padding: 8px 20px; border: none; border-radius: 30px; cursor: pointer; }
    .data-table { width: 100%; border-collapse: collapse; background: white; }
    .data-table th, .data-table td { border: 1px solid #ddd; padding: 10px; text-align: center; }
    .data-table th { background: #2a5298; color: white; }
    .btn-edit { background: #ffc107; color: #000; padding: 4px 12px; border-radius: 20px; text-decoration: none; }
    .btn-delete { background: #dc3545; color: white; padding: 4px 12px; border-radius: 20px; text-decoration: none; }
    .action-buttons { display: flex; gap: 10px; justify-content: center; }
</style>

<div style="max-width: 1000px; margin: 0 auto;">
    <h2 style="margin-bottom: 20px;">🎁 أنواع المنح الاجتماعية</h2>
    
    <div class="stats-grid">
        <div class="stat-card grants"><div>🎁 إجمالي أنواع المنح</div><div class="number"><?= $totalGrants ?></div></div>
        <div class="stat-card amount"><div>💰 إجمالي القيم</div><div class="number"><?= number_format($totalAmount, 2) ?> دج</div></div>
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
                <label>المبلغ (دج)</label>
                <input type="number" step="0.01" name="amount" required>
            </div>
            <button type="submit" name="add_grant" class="btn-add">💾 إضافة</button>
        </form>
    </div>
    
    <div style="overflow-x: auto;">
        <table class="data-table">
            <thead><tr><th>#</th><th>اسم المنحة</th><th>المبلغ (دج)</th><th>الإجراءات</th></tr></thead>
            <tbody>
                <?php if (empty($grants)): ?>
                    <tr><td colspan="4" style="text-align:center;">لا توجد أنواع منح</td></tr>
                <?php else: ?>
                    <?php $i=1; foreach ($grants as $g): ?>
                        <tr>
                            <td><?= $i++ ?></td>
                            <td><?= escape($g['name']) ?></td>
                            <td><?= number_format($g['amount'], 2) ?> دج</td>
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

<?php include '../includes/footer.php'; ?>