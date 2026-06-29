<?php
session_start();
header('Content-Type: application/json');
require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'غير مصرح']);
    exit;
}

$user_id = $_SESSION['user_id'];

// معالجة طلب تحديث مقروء
if (isset($_GET['mark_read'])) {
    $id = (int)$_GET['mark_read'];
    $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND (user_id IS NULL OR user_id = ?)")->execute([$id, $user_id]);
    echo json_encode(['success' => true]);
    exit;
}

if (isset($_GET['mark_all_read'])) {
    $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id IS NULL OR user_id = ?")->execute([$user_id]);
    echo json_encode(['success' => true]);
    exit;
}

// جلب الإشعارات
$stmt = $pdo->prepare("SELECT id, title, message, type, is_read, created_at FROM notifications WHERE user_id IS NULL OR user_id = ? ORDER BY created_at DESC LIMIT 20");
$stmt->execute([$user_id]);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['notifications' => $notifications]);
?>