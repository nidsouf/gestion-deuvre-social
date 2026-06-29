<?php
/**
 * delete_employee_grant.php - حذف منحة موظف مع صفحة تأكيد
 */
session_start();
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// ========== POST: تنفيذ الحذف (يأتي من نموذج التأكيد) ==========
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    requireCSRFToken();
    
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $refund = isset($_POST['refund']) ? $_POST['refund'] : 'no';
    
    if (!$id) {
        $_SESSION['toast'] = ['message' => '⚠️ معرف غير صالح', 'type' => 'warning', 'duration' => 3000];
        header("Location: employee_list.php");
        exit;
    }
    
    // جلب بيانات المنحة
    $stmt = $pdo->prepare("
        SELECT eg.*, e.name as employee_name, g.name as grant_name, g.amount as default_amount
        FROM employee_grants eg
        JOIN employees e ON eg.employee_id = e.id
        JOIN grants g ON eg.grant_id = g.id
        WHERE eg.id = ?
    ");
    $stmt->execute([$id]);
    $grant = $stmt->fetch();
    
    if (!$grant) {
        $_SESSION['toast'] = ['message' => '⚠️ المنحة غير موجودة', 'type' => 'warning', 'duration' => 3000];
        header("Location: employee_list.php");
        exit;
    }
    
    $grant_amount = ($grant['amount'] > 0) ? $grant['amount'] : $grant['default_amount'];
    
    try {
        $pdo->beginTransaction();
        
        // حذف المنحة
        $delete = $pdo->prepare("DELETE FROM employee_grants WHERE id = ?");
        $delete->execute([$id]);
        
        if ($delete->rowCount() == 0) {
            throw new Exception("المنحة غير موجودة أو تم حذفها مسبقاً");
        }
        
        // تسجيل في سجل التدقيق
        if (function_exists('audit')) {
            audit('EMPLOYEE_GRANT_DELETED', "حذف منحة {$grant['grant_name']} بقيمة {$grant_amount} دج للموظف {$grant['employee_name']}");
        }
        
        // استرجاع المبلغ للميزانية إذا اختار المستخدم
        if ($refund == 'yes') {
            $currentYear = date('Y');
            $stmtBudget = $pdo->prepare("SELECT id, remaining_budget FROM social_budget WHERE year = ? ORDER BY id DESC LIMIT 1");
            $stmtBudget->execute([$currentYear]);
            $budget = $stmtBudget->fetch();
            
            if ($budget) {
                $newRemaining = $budget['remaining_budget'] + $grant_amount;
                $updateBudget = $pdo->prepare("UPDATE social_budget SET remaining_budget = ? WHERE id = ?");
                $updateBudget->execute([$newRemaining, $budget['id']]);
                
                $msg = "✅ تم حذف المنحة، وتم استرجاع " . number_format($grant_amount, 2) . " دج للميزانية.";
                $type = 'success';
            } else {
                // إنشاء ميزانية جديدة
                $insertBudget = $pdo->prepare("INSERT INTO social_budget (year, initial_budget, remaining_budget) VALUES (?, 0, ?)");
                $insertBudget->execute([$currentYear, $grant_amount]);
                $msg = "✅ تم حذف المنحة، وتم إنشاء ميزانية جديدة واسترجاع " . number_format($grant_amount, 2) . " دج.";
                $type = 'success';
            }
        } else {
            $msg = "✅ تم حذف المنحة (لم يتم استرجاع المبلغ).";
            $type = 'info';
        }
        
        $pdo->commit();
        
        if (function_exists('addNotification')) {
            addNotification('حذف منحة', "تم حذف منحة {$grant['grant_name']} للموظف {$grant['employee_name']}", null, $type);
        }
        
        $_SESSION['toast'] = ['message' => $msg, 'type' => $type, 'duration' => 4000];
        header("Location: employee_list.php");
        exit;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Delete grant error: " . $e->getMessage());
        $_SESSION['toast'] = ['message' => '❌ خطأ: ' . $e->getMessage(), 'type' => 'error', 'duration' => 5000];
        header("Location: employee_list.php");
        exit;
    }
}

// ========== GET: عرض نموذج التأكيد (يأتي من زر الحذف) ==========
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    $_SESSION['toast'] = ['message' => '⚠️ معرف غير صالح', 'type' => 'warning', 'duration' => 3000];
    header("Location: employee_list.php");
    exit;
}

// جلب بيانات المنحة
$stmt = $pdo->prepare("
    SELECT eg.*, e.name as employee_name, g.name as grant_name, g.amount as default_amount
    FROM employee_grants eg
    JOIN employees e ON eg.employee_id = e.id
    JOIN grants g ON eg.grant_id = g.id
    WHERE eg.id = ?
");
$stmt->execute([$id]);
$grant = $stmt->fetch();

if (!$grant) {
    $_SESSION['toast'] = ['message' => '⚠️ المنحة غير موجودة', 'type' => 'warning', 'duration' => 3000];
    header("Location: employee_list.php");
    exit;
}

$grant_amount = ($grant['amount'] > 0) ? $grant['amount'] : $grant['default_amount'];
$csrf_token = generateCSRFToken();
include '../includes/header.php';
?>

<style>
    .confirm-box {
        max-width: 500px;
        margin: 50px auto;
        background: white;
        padding: 30px;
        border-radius: 20px;
        box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        text-align: center;
        direction: rtl;
    }
    .confirm-box h3 { color: #dc3545; }
    .grant-details { background: #f8f9fa; padding: 15px; border-radius: 10px; margin: 15px 0; text-align: right; }
    button { padding: 10px 25px; margin: 10px 5px; border: none; border-radius: 30px; cursor: pointer; font-weight: bold; }
    .btn-refund { background: #28a745; color: white; }
    .btn-keep { background: #dc3545; color: white; }
    .btn-cancel { background: #6c757d; color: white; }
</style>

<div class="confirm-box">
    <h3>⚠️ تأكيد حذف المنحة</h3>
    
    <div class="grant-details">
        <p><strong>الموظف:</strong> <?= htmlspecialchars($grant['employee_name']) ?></p>
        <p><strong>نوع المنحة:</strong> <?= htmlspecialchars($grant['grant_name']) ?></p>
        <p><strong>القيمة:</strong> <?= number_format($grant_amount, 2) ?> دج</p>
        <p><strong>التاريخ:</strong> <?= date('d/m/Y', strtotime($grant['grant_date'])) ?></p>
    </div>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
        <input type="hidden" name="id" value="<?= $id ?>">
        
        <p style="color:#666; font-size:14px;">اختر كيفية التعامل مع المبلغ:</p>
        
        <button type="submit" name="refund" value="yes" class="btn-refund">🔄 حذف واسترجاع المبلغ للميزانية</button>
        <button type="submit" name="refund" value="no" class="btn-keep">🗑️ حذف بدون استرجاع</button>
        <br>
        <button type="button" onclick="window.location.href='employee_list.php'" class="btn-cancel">🔙 إلغاء</button>
    </form>
</div>

<?php include '../includes/footer.php'; ?>