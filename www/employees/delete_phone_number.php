<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
require_once '../config/database.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$employee_id = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;
if ($id > 0) {
    $pdo->prepare("DELETE FROM employee_phone_numbers WHERE id = ?")->execute([$id]);
    $_SESSION['toast'] = ['message' => 'تم حذف الرقم بنجاح', 'type' => 'success'];
}
header("Location: phone_numbers.php?employee_id=$employee_id");
exit;
?>