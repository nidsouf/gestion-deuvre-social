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
    $pdo->prepare("UPDATE employee_phone_numbers SET is_active = NOT is_active, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$id]);
    $_SESSION['toast'] = ['message' => 'تم تغيير حالة الرقم بنجاح', 'type' => 'success'];
}
header("Location: phone_numbers.php?employee_id=$employee_id");
exit;
?>