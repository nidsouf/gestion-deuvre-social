<?php
session_start();
header('Content-Type: application/json');
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'غير مصرح']);
    exit;
}

$user_id = $_SESSION['user_id'];

// تحديث واحد
if (isset($_GET['mark_read'])) {
    $id = (int)$_GET['mark_read'];
    $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND (user_id IS NULL OR user_id = ?)")->execute([$id, $user_id]);
    echo json_encode(['success' => true]);
    exit;
}

// تحديث الكل
if (isset($_GET['mark_all_read'])) {
    $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id IS NULL OR user_id = ?")->execute([$user_id]);
    echo json_encode(['success' => true]);
    exit;
}
// باقي الكود لجلب الإشعارات (اختياري)
?>