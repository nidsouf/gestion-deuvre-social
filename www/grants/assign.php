<?php
/**
 * grants/assign.php - توزيع منحة على موظف (محسّن)
 */
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';

$employees = $pdo->query("SELECT id, name FROM employees ORDER BY name")->fetchAll();
$grants = $pdo->query("SELECT id, name, amount FROM grants ORDER BY name")->fetchAll();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRFToken();
    
    if (isRateLimited('grant_assign', 20, 3600)) {
        $error = '⚠️ لقد تجاوزت عدد المحاولات المسموحة.';
    } else {
        $employee_id = (int)$_POST['employee_id'];
        $grant_id = (int)$_POST['grant_id'];
        $grant_date = sanitizeInput($_POST['grant_date'] ?? '');
        $notes = sanitizeInput($_POST['notes'] ?? '');
        
        if ($employee_id <= 0 || $grant_id <= 0 || empty($grant_date)) {
            $error = '⚠️ جميع الحقول المطلوبة يجب أن تكون صحيحة';
        } elseif (!validateDate($grant_date)) {
            $error = '⚠️ صيغة تاريخ المنح غير صحيحة';
        } else {
            try {
                $pdo->beginTransaction();
                
                $stmt = $pdo->prepare("INSERT INTO employee_grants (employee_id, grant_id, grant_date, notes) VALUES (?, ?, ?, ?)");
                $stmt->execute([$employee_id, $grant_id, $grant_date, $notes]);
                $grant_id_inserted = $pdo->lastInsertId();
                
                // جلب اسم الموظف وقيمة المنحة للتسجيل
                $emp_name = $pdo->prepare("SELECT name FROM employees WHERE id = ?");
                $emp_name->execute([$employee_id]);
                $employee_name = $emp_name->fetchColumn();
                
                $grant_info = $pdo->prepare("SELECT name, amount FROM grants WHERE id = ?");
                $grant_info->execute([$grant_id]);
                $grant_data = $grant_info->fetch();
                
                audit('GRANT_ASSIGNED', "Grant '{$grant_data['name']}' assigned to $employee_name");
                addNotification('منحة جديدة', "تم توزيع منحة {$grant_data['name']} بقيمة " . number_format($grant_data['amount'], 2) . " دج للموظف $employee_name", null, 'success');
                
                $pdo->commit();
                
                $_SESSION['toast'] = ['message' => '✅ تم توزيع المنحة بنجاح', 'type' => 'success', 'duration' => 3000];
                header("Location: employee_list.php");
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Grant assign error: " . $e->getMessage());
                $error = '❌ حدث خطأ أثناء توزيع المنحة';
            }
        }
    }
}

$csrf_token = generateCSRFToken();
include '../includes/header.php';
?>

<style>
    .form-container { max-width: 500px; margin: 30px auto; background: white; padding: 25px; border-radius: 20px; box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
    .form-group { margin-bottom: 20px; }
    .form-group label { display: block; font-weight: bold; margin-bottom: 8px; }
    .form-group select, .form-group input, .form-group textarea { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 12px; }
    .btn-save { width: 100%; background: #28a745; color: white; padding: 12px; border: none; border-radius: 30px; font-weight: bold; cursor: pointer; }
    .btn-back { display: inline-block; margin-bottom: 20px; background: #6c757d; color: white; padding: 8px 16px; border-radius: 30px; text-decoration: none; }
    .error-message { background: #f8d7da; color: #721c24; padding: 12px; border-radius: 10px; margin-bottom: 20px; }
</style>

<div class="form-container">
    <a href="list.php" class="btn-back">🔙 العودة إلى قائمة المنح</a>
    <h2>🎁 منح موظف</h2>
    
    <?php if ($error): ?>
        <div class="error-message"><?= escape($error) ?></div>
    <?php endif; ?>
    
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= escape($csrf_token) ?>">
        
        <div class="form-group">
            <label>👤 الموظف</label>
            <select name="employee_id" required>
                <option value="">اختر الموظف</option>
                <?php foreach ($employees as $emp): ?>
                    <option value="<?= $emp['id'] ?>"><?= escape($emp['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="form-group">
            <label>🎁 نوع المنحة</label>
            <select name="grant_id" required>
                <option value="">اختر المنحة</option>
                <?php foreach ($grants as $g): ?>
                    <option value="<?= $g['id'] ?>"><?= escape($g['name']) ?> (<?= number_format($g['amount'], 2) ?> دج)</option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="form-group">
            <label>📅 تاريخ المنح</label>
            <input type="date" name="grant_date" value="<?= date('Y-m-d') ?>" required>
        </div>
        
        <div class="form-group">
            <label>📝 ملاحظات (اختياري)</label>
            <textarea name="notes" rows="3"></textarea>
        </div>
        
        <button type="submit" class="btn-save">💾 توزيع المنحة</button>
    </form>
</div>

<?php include '../includes/footer.php'; ?>