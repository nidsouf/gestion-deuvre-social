<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

require_once '../config/database.php';

$id = $_GET['id'] ?? 0;
$stmt = $pdo->prepare("SELECT * FROM grants WHERE id = ?");
$stmt->execute([$id]);
$g = $stmt->fetch();

if (!$g) {
    $_SESSION['toast'] = ['message' => 'المنحة غير موجودة', 'type' => 'error', 'duration' => 3000];
    header("Location: list.php");
    exit;
}

// ========== معالجة POST بعد التأكيد ==========
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'];

    try {
        if ($action == 'keep') {
            // حذف المنحة مع الاحتفاظ بالخصم (لأنها صرفت فعلاً)
            $pdo->prepare("DELETE FROM employee_grants WHERE grant_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM grants WHERE id = ?")->execute([$id]);

            $_SESSION['toast'] = ['message' => '✅ تم حذف المنحة، وبقي الخصم في الميزانية (لأنها صرفت فعلاً).', 'type' => 'success', 'duration' => 4000];
        } elseif ($action == 'refund') {
            // حذف المنحة واسترجاع المبلغ للميزانية
            $pdo->prepare("DELETE FROM budget_transactions WHERE type = 'grant' AND reference_id IN (SELECT id FROM employee_grants WHERE grant_id = ?)")->execute([$id]);
            $pdo->prepare("DELETE FROM employee_grants WHERE grant_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM grants WHERE id = ?")->execute([$id]);

            // تحديث الميزانية: إضافة المبلغ مرة أخرى (أحدث سجل)
            $pdo->prepare("
                UPDATE social_budget 
                SET remaining_budget = remaining_budget + ? 
                WHERE id = (SELECT MAX(id) FROM social_budget)
            ")->execute([$g['amount']]);

            $_SESSION['toast'] = ['message' => '✅ تم حذف المنحة واسترجاع المبلغ ' . number_format($g['amount'], 2) . ' دج إلى الميزانية.', 'type' => 'success', 'duration' => 4000];
        } else {
            $_SESSION['toast'] = ['message' => 'إجراء غير صالح', 'type' => 'warning', 'duration' => 3000];
        }
        header("Location: list.php");
        exit;
    } catch (Exception $e) {
        $_SESSION['toast'] = ['message' => '❌ حدث خطأ أثناء حذف المنحة: ' . $e->getMessage(), 'type' => 'error', 'duration' => 5000];
        header("Location: list.php");
        exit;
    }
}

// ========== عرض صفحة التأكيد ==========
?>
<!DOCTYPE html>
<html dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>حذف المنحة</title>
    <style>
        body {
            font-family: 'Segoe UI', sans-serif;
            background: #f0f2f5;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }
        .confirm-box {
            background: white;
            padding: 30px;
            border-radius: 20px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            text-align: center;
            width: 400px;
        }
        button {
            padding: 10px 20px;
            margin: 10px;
            border: none;
            border-radius: 30px;
            cursor: pointer;
            font-weight: bold;
        }
        .btn-keep {
            background: #dc3545;
            color: white;
        }
        .btn-refund {
            background: #28a745;
            color: white;
        }
        .btn-cancel {
            background: #6c757d;
            color: white;
        }
    </style>
</head>
<body>
<div class="confirm-box">
    <h3>⚠️ حذف المنحة: <?= htmlspecialchars($g['name']) ?></h3>
    <p>القيمة: <?= number_format($g['amount'], 2) ?> دج</p>
    <form method="POST">
        <input type="hidden" name="action" value="keep">
        <button type="submit" class="btn-keep">🗑️ حذف مع الاحتفاظ بالخصم (صرفت فعلاً)</button>
    </form>
    <form method="POST">
        <input type="hidden" name="action" value="refund">
        <button type="submit" class="btn-refund">🔄 حذف واسترجاع المبلغ للميزانية</button>
    </form>
    <button type="button" onclick="window.location.href='list.php'" class="btn-cancel">إلغاء</button>
</div>
</body>
</html>