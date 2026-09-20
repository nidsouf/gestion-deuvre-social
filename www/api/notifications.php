<?php
/**
 * api/notifications.php - API الإشعارات (POST + CSRF)
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'غير مصرح']);
    exit;
}

$userId = $_SESSION['user_id'];

// ============================================================
// POST: تحديد كمقروء
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRFToken();
    
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    
    try {
        if ($action === 'mark_read' && $id > 0) {
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND (user_id IS NULL OR user_id = ?)");
            $stmt->execute([$id, $userId]);
            echo json_encode(['success' => true, 'affected' => $stmt->rowCount()]);
            exit;
        }
        
        if ($action === 'mark_all_read') {
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE (user_id IS NULL OR user_id = ?) AND is_read = 0");
            $stmt->execute([$userId]);
            echo json_encode(['success' => true, 'affected' => $stmt->rowCount()]);
            exit;
        }
        
        if ($action === 'get_unread_count') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE (user_id IS NULL OR user_id = ?) AND is_read = 0");
            $stmt->execute([$userId]);
            echo json_encode(['success' => true, 'count' => (int)$stmt->fetchColumn()]);
            exit;
        }
        
        http_response_code(400);
        echo json_encode(['error' => 'إجراء غير معروف']);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ============================================================
// GET: جلب الإشعارات
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $limit = min(50, max(1, (int)($_GET['limit'] ?? 10)));
    $stmt = $pdo->prepare("
        SELECT id, title, message, type, is_read, created_at 
        FROM notifications 
        WHERE user_id IS NULL OR user_id = ? 
        ORDER BY created_at DESC 
        LIMIT $limit
    ");
    $stmt->execute([$userId]);
    echo json_encode(['success' => true, 'notifications' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);