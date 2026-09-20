<?php
/**
 * api/v1/notifications.php - الإشعارات
 * 
 * GET  /api/v1/notifications.php         → القائمة
 * POST /api/v1/notifications.php         → تحديد كمقروء
 *   body: { "action": "mark_read", "id": 5 }
 *   body: { "action": "mark_all_read" }
 */

require_once __DIR__ . '/middleware.php';
$auth = apiRequireAuth();

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        $limit = min(50, max(1, (int)apiGet('limit', 20)));
        
        $stmt = $pdo->prepare("
            SELECT id, title, message, type, is_read, created_at
            FROM notifications
            WHERE user_id IS NULL OR user_id = ?
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$auth['user_id'], $limit]);
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // عدد غير المقروء
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM notifications
            WHERE (user_id IS NULL OR user_id = ?) AND is_read = 0
        ");
        $stmt->execute([$auth['user_id']]);
        $unreadCount = (int)$stmt->fetchColumn();
        
        apiResponse([
            'items' => $notifications,
            'unread_count' => $unreadCount,
        ]);
    }
    
    elseif ($method === 'POST') {
        $action = apiGet('action', '');
        $notifId = (int)apiGet('id', 0);
        
        if ($action === 'mark_read' && $notifId > 0) {
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND (user_id IS NULL OR user_id = ?)");
            $stmt->execute([$notifId, $auth['user_id']]);
            apiResponse(['affected' => $stmt->rowCount()], 200, 'تم التحديد');
        }
        
        if ($action === 'mark_all_read') {
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE (user_id IS NULL OR user_id = ?) AND is_read = 0");
            $stmt->execute([$auth['user_id']]);
            apiResponse(['affected' => $stmt->rowCount()], 200, 'تم تحديد الكل');
        }
        
        apiError('إجراء غير معروف', 400);
    }
    
    else {
        apiError('طريقة غير مدعومة', 405);
    }
    
} catch (Exception $e) {
    apiError('خطأ: ' . $e->getMessage(), 500);
}