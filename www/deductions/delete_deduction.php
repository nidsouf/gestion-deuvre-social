<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

require_once '../config/database.php';
require_once '../includes/functions.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$confirm = isset($_GET['confirm']) ? $_GET['confirm'] : '';

if ($id <= 0) {
    $_SESSION['toast'] = ['message' => 'معرف غير صحيح', 'type' => 'error'];
    header("Location: list.php");
    exit;
}

// جلب بيانات الاقتطاع
$stmt = $pdo->prepare("SELECT d.*, e.name as employee_name FROM deductions d JOIN employees e ON d.employee_id = e.id WHERE d.id = ?");
$stmt->execute([$id]);
$ded = $stmt->fetch();

if (!$ded) {
    $_SESSION['toast'] = ['message' => 'الاقتطاع غير موجود', 'type' => 'error'];
    header("Location: list.php");
    exit;
}

$is_loan = $ded['is_loan'];
$total = $ded['monthly_amount'] * $ded['total_months'];

// إذا كان الاقتطاع عادياً -> احذف فوراً (بدون تأكيد إضافي)
if (!$is_loan) {
    try {
        $pdo->prepare("DELETE FROM deductions WHERE id = ?")->execute([$id]);
        $_SESSION['toast'] = ['message' => 'تم حذف الاقتطاع بنجاح', 'type' => 'success'];
    } catch (Exception $e) {
        $_SESSION['toast'] = ['message' => 'خطأ: ' . $e->getMessage(), 'type' => 'error'];
    }
    header("Location: list.php");
    exit;
}

// ========== من هنا نتعامل مع السلفة ==========
// إذا لم يتم تأكيد الحذف بعد، نعرض نموذج التأكيد
if ($confirm !== 'yes') {
    ?>
    <!DOCTYPE html>
    <html dir="rtl">
    <head>
        <meta charset="UTF-8">
        <title>تأكيد حذف السلفة</title>
        <style>
            body { font-family: 'Segoe UI', sans-serif; background: #f0f2f5; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
            .confirm-box { background: white; padding: 30px; border-radius: 20px; text-align: center; width: 400px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); }
            .btn { display: inline-block; padding: 10px 20px; margin: 10px; border-radius: 30px; text-decoration: none; font-weight: bold; }
            .btn-danger { background: #dc3545; color: white; }
            .btn-secondary { background: #6c757d; color: white; }
        </style>
    </head>
    <body>
    <div class="confirm-box">
        <h3>⚠️ تأكيد حذف السلفة</h3>
        <p><strong>الموظف:</strong> <?= htmlspecialchars($ded['employee_name']) ?></p>
        <p><strong>المبلغ الكلي:</strong> <?= number_format($total, 2) ?> دج</p>
        <p>هل أنت متأكد من حذف هذه السلفة؟</p>
        <a href="?id=<?= $id ?>&confirm=yes" class="btn btn-danger">نعم، احذف</a>
        <a href="list.php" class="btn btn-secondary">إلغاء</a>
    </div>
    </body>
    </html>
    <?php
    exit;
}

// بعد التأكيد (confirm=yes)، نعرض خيارات استرجاع المبلغ أو لا
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

// عرض خيارات استرجاع المبلغ
?>
<!DOCTYPE html>
<html dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>خيارات حذف السلفة</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f0f2f5; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .confirm-box { background: white; padding: 30px; border-radius: 20px; text-align: center; width: 400px; }
        button, .btn-cancel { padding: 10px 20px; margin: 10px; border-radius: 30px; cursor: pointer; font-weight: bold; border: none; }
        .btn-refund { background: #28a745; color: white; }
        .btn-keep { background: #dc3545; color: white; }
        .btn-cancel { background: #6c757d; color: white; text-decoration: none; display: inline-block; }
    </style>
</head>
<body>
<div class="confirm-box">
    <h3>⚠️ حذف السلفة</h3>
    <p><strong>الموظف:</strong> <?= htmlspecialchars($ded['employee_name']) ?></p>
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