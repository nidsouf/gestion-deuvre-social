<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

$id = $_GET['id'] ?? 0;

if (!$id) {
    header("Location: employee_list.php");
    exit;
}

// جلب بيانات المنحة
$stmt = $pdo->prepare("
    SELECT eg.*, e.name as employee_name, g.name as grant_name, g.amount
    FROM employee_grants eg
    JOIN employees e ON eg.employee_id = e.id
    JOIN grants g ON eg.grant_id = g.id
    WHERE eg.id = ?
");
$stmt->execute([$id]);
$grant = $stmt->fetch();

if (!$grant) {
    $_SESSION['toast'] = ['message' => 'منحة الموظف غير موجودة', 'type' => 'error', 'duration' => 3000];
    header("Location: employee_list.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $refund = $_POST['refund'] ?? 'no';

    try {
        $pdo->beginTransaction();

        // حذف منحة الموظف
        $pdo->prepare("DELETE FROM employee_grants WHERE id = ?")->execute([$id]);

        if ($refund == 'yes') {
            // استرجاع المبلغ للميزانية (أحدث سجل)
            $pdo->prepare("
                UPDATE social_budget 
                SET remaining_budget = remaining_budget + ? 
                WHERE id = (SELECT MAX(id) FROM social_budget)
            ")->execute([$grant['amount']]);
            
            // حذف المعاملة من سجل الميزانية
            $pdo->prepare("DELETE FROM budget_transactions WHERE type = 'grant' AND reference_id = ?")->execute([$id]);
            $msg = "✅ تم حذف المنحة، وتم استرجاع " . number_format($grant['amount'], 2) . " دج للميزانية.";
            $type = 'success';
        } else {
            $msg = "✅ تم حذف المنحة، لكن المبلغ لم يُسترجَع (كأنها صرفت فعلاً).";
            $type = 'info';
        }

        $pdo->commit();
        $_SESSION['toast'] = ['message' => $msg, 'type' => $type, 'duration' => 4000];
        header("Location: employee_list.php");
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['toast'] = ['message' => '❌ حدث خطأ أثناء الحذف: ' . $e->getMessage(), 'type' => 'error', 'duration' => 5000];
        header("Location: employee_list.php");
        exit;
    }
}

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
    }
    button {
        padding: 10px 20px;
        margin: 10px;
        border: none;
        border-radius: 30px;
        cursor: pointer;
        font-weight: bold;
    }
    .btn-refund {
        background: #28a745;
        color: white;
    }
    .btn-keep {
        background: #dc3545;
        color: white;
    }
    .btn-cancel {
        background: #6c757d;
        color: white;
    }
</style>

<div class="confirm-box">
    <h3>⚠️ حذف منحة الموظف: <?= htmlspecialchars($grant['employee_name']) ?></h3>
    <p><strong>المنحة:</strong> <?= htmlspecialchars($grant['grant_name']) ?></p>
    <p><strong>القيمة:</strong> <?= number_format($grant['amount'], 2) ?> دج</p>
    <form method="POST">
        <button type="submit" name="refund" value="yes" class="btn-refund">🔄 حذف واسترجاع المبلغ للميزانية</button>
        <button type="submit" name="refund" value="no" class="btn-keep">🗑️ حذف مع الاحتفاظ بالخصم (صرفت فعلاً)</button>
        <br>
        <button type="button" onclick="window.location.href='employee_list.php'" class="btn-cancel">إلغاء</button>
    </form>
</div>

<?php include '../includes/footer.php'; ?>