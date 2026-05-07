<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../login.php"); exit; }
require_once '../config/database.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { header("Location: list.php"); exit; }

// جلب معلومات الاقتطاع
$stmt = $pdo->prepare("SELECT d.*, e.name as employee_name FROM deductions d JOIN employees e ON d.employee_id = e.id WHERE d.id = ?");
$stmt->execute([$id]);
$ded = $stmt->fetch();
if (!$ded) { header("Location: list.php"); exit; }
?>
<!DOCTYPE html>
<html dir="rtl">
<head><meta charset="UTF-8"><title>تأكيد الحذف</title><style>body{font-family:sans-serif;text-align:center;padding:50px;}</style></head>
<body>
<h3>هل أنت متأكد من حذف الاقتطاع الخاص بـ <?= htmlspecialchars($ded['employee_name']) ?>؟</h3>
<a href="delete.php?id=<?= $id ?>" style="background:red;color:white;padding:8px 16px;text-decoration:none;border-radius:5px;">نعم، احذف</a>
<a href="list.php" style="background:gray;color:white;padding:8px 16px;text-decoration:none;border-radius:5px;">إلغاء</a>
</body>
</html>