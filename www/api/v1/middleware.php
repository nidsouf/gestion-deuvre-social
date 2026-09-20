<?php
/**
 * api/v1/middleware.php - التحقق من التوكن
 */

require_once __DIR__ . '/config.php';

if (!function_exists('apiRequireAuth')) {
    /**
     * التحقق من التوكن — يُرجع بيانات المستخدم
     * أو يوقف التنفيذ برسالة 401
     */
    function apiRequireAuth() {
        global $pdo;
        
        $token = getBearerToken();
        
        if (!$token) {
            apiError('يجب تسجيل الدخول', 401, 'NO_TOKEN');
        }
        
        try {
            $stmt = $pdo->prepare("
                SELECT t.*, u.username, u.role, u.employee_id 
                FROM api_tokens t
                JOIN users u ON t.user_id = u.id
                WHERE t.token = ?
                  AND t.is_revoked = 0
                  AND t.expires_at > datetime('now')
                LIMIT 1
            ");
            $stmt->execute([$token]);
            $auth = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$auth) {
                apiError('التوكن غير صالح أو منتهي', 401, 'INVALID_TOKEN');
            }
            
            // تحديث آخر استخدام
            $pdo->prepare("UPDATE api_tokens SET last_used_at = datetime('now') WHERE id = ?")
                ->execute([$auth['id']]);
            
            return [
                'user_id' => (int)$auth['user_id'],
                'username' => $auth['username'],
                'role' => $auth['role'],
                'employee_id' => $auth['employee_id'],
                'token_id' => (int)$auth['id'],
            ];
            
        } catch (Exception $e) {
            apiError('خطأ في المصادقة: ' . $e->getMessage(), 500, 'AUTH_ERROR');
        }
    }
}

if (!function_exists('getBearerToken')) {
    function getBearerToken() {
        $headers = [];
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
        }
        
        $auth = $headers['Authorization'] ?? $headers['authorization'] ?? null;
        if ($auth && preg_match('/Bearer\s+(.+)/i', $auth, $m)) {
            return trim($m[1]);
        }
        
        // احتياطي: من GET أو POST
        return $_GET['token'] ?? $_POST['token'] ?? null;
    }
}

if (!function_exists('apiRequireRole')) {
    /**
     * التحقق من الدور
     */
    function apiRequireRole($auth, $allowedRoles) {
        if (!in_array($auth['role'], (array)$allowedRoles)) {
            apiError('غير مصرح لك بهذا الإجراء', 403, 'FORBIDDEN');
        }
    }
}