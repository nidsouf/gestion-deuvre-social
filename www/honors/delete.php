<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
require_once '../config/database.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    $_SESSION['toast'] = ['message' => 'معرف غير صحيح', 'type' => 'error'];
    header("Location: index.php");
    exit;
}

try {
    $stmt = $pdo->prepare("DELETE FROM labor_day_honorees WHERE id = ?");
    $stmt->execute([$id]);
    $_SESSION['toast'] = ['message' => '✅ تم حذف التكريم بنجاح', 'type' => 'success'];
} catch (Exception $e) {
    $_SESSION['toast'] = ['message' => '❌ خطأ: ' . $e->getMessage(), 'type' => 'error'];
}
header("Location: index.php");
exit;
?>