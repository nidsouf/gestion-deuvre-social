<?php
ob_start();
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    $_SESSION['toast'] = ['message' => 'اقتطاع غير صالح', 'type' => 'error', 'duration' => 3000];
    header("Location: list.php");
    exit;
}

$stmt = $pdo->prepare("SELECT d.*, e.name as employee_name FROM deductions d JOIN employees e ON d.employee_id = e.id WHERE d.id = ?");
$stmt->execute([$id]);
$ded = $stmt->fetch();
if (!$ded) {
    $_SESSION['toast'] = ['message' => 'الاقتطاع غير موجود', 'type' => 'error', 'duration' => 3000];
    header("Location: list.php");
    exit;
}

if (!$ded['is_loan']) {
    $_SESSION['toast'] = ['message' => '⚠️ هذا الاقتطاع ليس سلفة، لا يمكن تسديد مقدم.', 'type' => 'error', 'duration' => 3000];
    header("Location: list.php");
    exit;
}

$today = new DateTime();
$end = new DateTime($ded['end_date']);
$remaining_months = ($end->diff($today)->m) + ($end->diff($today)->y * 12);
if ($remaining_months < 0) $remaining_months = 0;

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRFToken();
    $amount = (float)$_POST['amount'];
    if ($amount <= 0) {
        $error = 'المبلغ يجب أن يكون أكبر من صفر';
    } else {
        $monthly = $ded['monthly_amount'];
        $new_credit = $ded['credit_balance'] + $amount;
        $months_to_deduct = floor($new_credit / $monthly);
        $remaining_credit = $new_credit - ($months_to_deduct * $monthly);
        
        $new_total_months = $ded['total_months'] - $months_to_deduct;
        if ($new_total_months < 0) $new_total_months = 0;
        $new_end_date = date('Y-m-d', strtotime("-$months_to_deduct months", strtotime($ded['end_date'])));
        
        try {
            $pdo->beginTransaction();
            
            // ========== 1. تحديث الاقتطاع ==========
            $update = $pdo->prepare("
                UPDATE deductions 
                SET credit_balance = ?, total_months = ?, end_date = ? 
                WHERE id = ?
            ");
            $update->execute([$remaining_credit, $new_total_months, $new_end_date, $id]);
            
            // ========== 2. إعادة توليد الأقساط لتوزيع الرصيد الدائن ==========
            regenerateMonthlyInstallments($id, true);
            
            // ========== 3. تسجيل الدفعة المقدمة ==========
            $stmtEarly = $pdo->prepare("
                INSERT INTO early_payments (deduction_id, months_paid, amount, original_end_date, original_total_months, transaction_id)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmtEarly->execute([
                $id, 
                $months_to_deduct, 
                $amount, 
                $ded['end_date'], 
                $ded['total_months'], 
                $pdo->lastInsertId()
            ]);
            
            // ========== 4. تسجيل العملية في budget_transactions ==========
            // is_deduct = 0 لأن تسديد مقدم = استرجاع (إضافة إلى الميزانية)
            $stmtTrans = $pdo->prepare("
                INSERT INTO budget_transactions (reference_id, type, amount, description, is_deduct, transaction_date)
                VALUES (?, 'installment', ?, ?, 0, datetime('now'))
            ");
            $stmtTrans->execute([
                $id,
                $amount,
                "استرجاع سلفة (تسديد مقدم) - سلفة رقم $id - الموظف: " . $ded['employee_name']
            ]);
            
            // ========== 5. تحديث الميزانية (مرة واحدة فقط) ==========
            // is_deduct = 0 يعني إضافة إلى الميزانية (استرجاع)
            updateBudgetAfterTransaction($pdo, date('Y'), $amount, 0);
            
            // ========== 6. تدقيق وإشعارات ==========
            if (function_exists('audit')) {
                audit('EARLY_PAYMENT', "تسديد {$amount} دج للسلفة {$id} (خصم {$months_to_deduct} شهر)");
            }
            if (function_exists('addNotification')) {
                addNotification('تسديد مقدم', "تم تسديد {$amount} دج من سلفة الموظف {$ded['employee_name']}");
            }
            
            $pdo->commit();
            $_SESSION['toast'] = [
                'message' => "✅ تم تسديد {$amount} دج بنجاح. تم خصم {$months_to_deduct} شهر.",
                'type' => 'success',
                'duration' => 4000
            ];
            header("Location: list.php");
            exit;
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'خطأ: ' . $e->getMessage();
        }
    }
}

$csrf_token = generateCSRFToken();
include '../includes/header.php';
?>

<style>
    .form-container { max-width: 500px; margin: 40px auto; background: white; padding: 25px; border-radius: 20px; box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
    .form-group { margin-bottom: 20px; }
    .form-group label { display: block; font-weight: bold; margin-bottom: 8px; }
    .form-group input { width: 100%; padding: 10px; border-radius: 12px; border: 1px solid #ccc; }
    .btn-save { width: 100%; background: #28a745; color: white; padding: 12px; border: none; border-radius: 30px; font-weight: bold; cursor: pointer; }
    .btn-save:hover { background: #218838; }
    .btn-cancel { display: block; text-align: center; margin-top: 10px; background: #6c757d; color: white; text-decoration: none; padding: 10px; border-radius: 30px; }
    .error-message { background: #f8d7da; color: #721c24; padding: 12px; border-radius: 10px; margin-bottom: 20px; }
</style>

<div class="form-container">
    <h2>💰 تسديد مقدم لسلفة</h2>
    <p><strong>الموظف:</strong> <?= htmlspecialchars($ded['employee_name']) ?></p>
    <p><strong>المبلغ الشهري:</strong> <?= number_format($ded['monthly_amount'], 2) ?> دج</p>
    <p><strong>الأشهر المتبقية:</strong> <?= $remaining_months ?> شهر</p>
    <p><strong>الرصيد الدائن الحالي:</strong> <?= number_format($ded['credit_balance'], 2) ?> دج</p>
    
    <?php if ($error): ?>
        <div class="error-message"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= escape($csrf_token) ?>">
        <div class="form-group">
            <label>المبلغ المراد تسديده (دج):</label>
            <input type="number" name="amount" step="0.01" min="0" required>
        </div>
        <button type="submit" class="btn-save">💳 تأكيد التسديد</button>
        <a href="list.php" class="btn-cancel">🔙 إلغاء</a>
    </form>
</div>

<?php include '../includes/footer.php'; ?>