<?php
ob_start();
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    $_SESSION['toast'] = ['message' => 'عملية غير صالحة', 'type' => 'error', 'duration' => 3000];
    header("Location: list.php");
    exit;
}

$stmt = $pdo->prepare("
    SELECT ep.*, d.employee_id, d.end_date, d.total_months, d.is_loan, d.credit_balance
    FROM early_payments ep
    JOIN deductions d ON ep.deduction_id = d.id
    WHERE ep.id = ? AND ep.is_reversed = 0
");
$stmt->execute([$id]);
$payment = $stmt->fetch();
if (!$payment) {
    $_SESSION['toast'] = ['message' => 'التسديد غير موجود أو ملغى مسبقاً', 'type' => 'error', 'duration' => 3000];
    header("Location: list.php");
    exit;
}

if (!$payment['is_loan']) {
    $_SESSION['toast'] = ['message' => 'هذا الاقتطاع ليس سلفة، لا يمكن إلغاء التسديد', 'type' => 'error', 'duration' => 3000];
    header("Location: list.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $csrf_token = generateCSRFToken();
    include '../includes/header.php';
    ?>
    <div style="max-width:500px; margin:40px auto; background:white; padding:25px; border-radius:20px; box-shadow:0 5px 15px rgba(0,0,0,0.1);">
        <h2>⚠️ تأكيد إلغاء التسديد المقدم</h2>
        <p><strong>عدد الأشهر المدفوعة مقدمًا:</strong> <?= $payment['months_paid'] ?></p>
        <p><strong>المبلغ المدفوع:</strong> <?= number_format($payment['amount'], 2) ?> دج</p>
        <p><strong>تاريخ التسديد:</strong> <?= date('d/m/Y H:i', strtotime($payment['payment_date'])) ?></p>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <button type="submit" style="background:#dc3545; color:white; border:none; padding:10px 20px; border-radius:30px; cursor:pointer; font-weight:bold; width:100%;">
                🗑️ تأكيد الإلغاء
            </button>
            <a href="list.php" style="display:block; text-align:center; margin-top:10px; background:#6c757d; color:white; padding:10px; border-radius:30px; text-decoration:none;">
                🔙 إلغاء
            </a>
        </form>
    </div>
    <?php
    include '../includes/footer.php';
    exit;
}

requireCSRFToken();
try {
    $pdo->beginTransaction();
    
    // ========== 1. استعادة البيانات الأصلية للاقتطاع ==========
    $updateDed = $pdo->prepare("UPDATE deductions SET end_date = ?, total_months = ? WHERE id = ?");
    $updateDed->execute([$payment['original_end_date'], $payment['original_total_months'], $payment['deduction_id']]);
    
    // ========== 2. إعادة ضبط الرصيد الدائن إلى 0 ==========
    $resetCredit = $pdo->prepare("UPDATE deductions SET credit_balance = 0 WHERE id = ?");
    $resetCredit->execute([$payment['deduction_id']]);
    
    // ========== 3. حذف الأقساط المكررة ==========
    $deleteInst = $pdo->prepare("DELETE FROM monthly_installments WHERE deduction_id = ? AND is_paid = 0 AND is_postponed = 0");
    $deleteInst->execute([$payment['deduction_id']]);
    
    // ========== 4. إعادة توليد الأقساط ==========
    regenerateMonthlyInstallments($payment['deduction_id'], false);
    
    // ========== 5. تحديث الدفعة المقدمة إلى ملغاة ==========
    $updateEarly = $pdo->prepare("UPDATE early_payments SET is_reversed = 1, reversed_date = datetime('now') WHERE id = ?");
    $updateEarly->execute([$id]);
    
    // ========== 6. إلغاء العملية من budget_transactions (إضافة سجل عكسي) ==========
    // is_deduct = 1 لأن إلغاء الاسترجاع = خصم من الميزانية
    $stmtTrans = $pdo->prepare("
        INSERT INTO budget_transactions (reference_id, type, amount, description, is_deduct, transaction_date)
        VALUES (?, 'installment', ?, ?, 1, datetime('now'))
    ");
    $stmtTrans->execute([
        $payment['deduction_id'],
        $payment['amount'],
        "إلغاء استرجاع سلفة - سلفة رقم " . $payment['deduction_id']
    ]);
    
    // ========== 7. تحديث الميزانية (مرة واحدة فقط) ==========
    // is_deduct = 1 يعني خصم من الميزانية (عكس الاسترجاع)
    updateBudgetAfterTransaction($pdo, date('Y'), $payment['amount'], 1);
    
    // ========== 8. تدقيق وإشعارات ==========
    if (function_exists('audit')) {
        audit('UNDO_EARLY_PAYMENT', "إلغاء تسديد {$payment['amount']} دج للسلفة {$payment['deduction_id']}");
    }
    if (function_exists('addNotification')) {
        addNotification('إلغاء تسديد مقدم', "تم إلغاء تسديد {$payment['amount']} دج من السلفة رقم {$payment['deduction_id']}");
    }
    
    $pdo->commit();
    $_SESSION['toast'] = ['message' => '✅ تم إلغاء التسديد المقدم وعودة الأقساط إلى الوضع الطبيعي', 'type' => 'success', 'duration' => 4000];
    
} catch (Exception $e) {
    $pdo->rollBack();
    $_SESSION['toast'] = ['message' => '❌ خطأ: ' . $e->getMessage(), 'type' => 'error', 'duration' => 5000];
}

header("Location: list.php");
exit;