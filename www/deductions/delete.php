<?php
session_start();
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id']) || !isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
    $_SESSION['toast'] = ['message' => 'طلب غير مصرح به', 'type' => 'error', 'duration' => 3000];
    header("Location: list.php");
    exit;
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if (!$id) {
    $_SESSION['toast'] = ['message' => 'معرف غير صالح', 'type' => 'error', 'duration' => 3000];
    header("Location: list.php");
    exit;
}

try {
    $pdo->prepare("DELETE FROM monthly_installments WHERE deduction_id = ?")->execute([$id]);
    $stmt = $pdo->prepare("DELETE FROM deductions WHERE id = ?");
    $stmt->execute([$id]);
    
    $_SESSION['toast'] = ['message' => '✅ تم حذف الاقتطاع بنجاح', 'type' => 'success', 'duration' => 3000];
} catch (Exception $e) {
    $_SESSION['toast'] = ['message' => '❌ خطأ: ' . $e->getMessage(), 'type' => 'error', 'duration' => 5000];
}

header("Location: list.php");
exit;
?>