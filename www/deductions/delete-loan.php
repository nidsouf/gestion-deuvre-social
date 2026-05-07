<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

require_once '../config/database.php';
require_once '../includes/functions.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// جلب بيانات السلفة
$stmt = $pdo->prepare("SELECT d.*, e.name as employee_name FROM deductions d JOIN employees e ON d.employee_id = e.id WHERE d.id = ? AND d.is_loan = 1");
$stmt->execute([$id]);
$loan = $stmt->fetch();

if (!$loan) {
    $_SESSION['toast'] = ['message' => 'السلفة غير موجودة', 'type' => 'error'];
    header("Location: list.php");
    exit;
}

$total = $loan['monthly_amount'] * $loan['total_months'];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $refund = $_POST['refund'] ?? 'no';

    // حذف السلفة
    $pdo->prepare("DELETE FROM deductions WHERE id = ?")->execute([$id]);
    // حذف الأقساط المرتبطة
    $pdo->prepare("DELETE FROM budget_transactions WHERE reference_id = ? AND type = 'installment'")->execute([$id]);

    if ($refund == 'yes') {
        // استرجاع المبلغ للميزانية
        updateBudget($total, 'add');
        $pdo->prepare("INSERT INTO budget_transactions (amount, type, reference_id, description, is_deduct) VALUES (?, 'loan', ?, 'استرجاع سلفة بعد الحذف', 0)")->execute([$total, $id]);
        $_SESSION['toast'] = ['message' => 'تم حذف السلفة واسترجاع ' . number_format($total, 2) . ' دج للميزانية', 'type' => 'success'];
    } else {
        $pdo->prepare("DELETE FROM budget_transactions WHERE reference_id = ? AND type = 'loan'")->execute([$id]);
        $_SESSION['toast'] = ['message' => 'تم حذف السلفة مع الاحتفاظ بالخصم', 'type' => 'success'];
    }
    header("Location: list.php");
    exit;
}
?>
<!DOCTYPE html>
<html dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>حذف سلفة</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f0f2f5; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .confirm-box { background: white; padding: 30px; border-radius: 20px; text-align: center; width: 400px; }
        button { padding: 10px 20px; margin: 10px; border-radius: 30px; cursor: pointer; font-weight: bold; border: none; }
        .btn-refund { background: #28a745; color: white; }
        .btn-keep { background: #dc3545; color: white; }
        .btn-cancel { background: #6c757d; color: white; text-decoration: none; display: inline-block; padding: 10px 20px; border-radius: 30px; }
    </style>
</head>
<body>
<div class="confirm-box">
    <h3>⚠️ حذف السلفة</h3>
    <p><strong>الموظف:</strong> <?= htmlspecialchars($loan['employee_name']) ?></p>
    <p><strong>المبلغ الكلي:</strong> <?= number_format($total, 2) ?> دج</p>
    <form method="POST">
        <button type="submit" name="refund" value="yes" class="btn-refund">🔄 حذف واسترجاع المبلغ للميزانية</button>
        <button type="submit" name="refund" value="no" class="btn-keep">🗑️ حذف مع الاحتفاظ بالخصم</button>
        <br>
        <a href="list.php" class="btn-cancel">إلغاء</a>
    </form>
</div>
</body>
</html>