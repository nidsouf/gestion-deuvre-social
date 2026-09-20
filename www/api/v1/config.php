<?php
/**
 * api/v1/config.php - إعدادات API الأساسية
 */

// منع عرض الأخطاء في الاستجابة (تُسجَّل فقط)
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// ============================================================
// CORS - للسماح بالوصول من التطبيق أو المتصفح
// ============================================================
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Max-Age: 86400');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ============================================================
// رأس JSON
// ============================================================
header('Content-Type: application/json; charset=utf-8');

// ============================================================
// تحميل قاعدة البيانات
// ============================================================
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

// ============================================================
// دوال مساعدة للـ API
// ============================================================

if (!function_exists('apiResponse')) {
    /**
     * إرسال استجابة JSON
     */
    function apiResponse($data = null, $status = 200, $message = null, $extra = []) {
        http_response_code($status);
        $response = [
            'success' => $status >= 200 && $status < 300,
            'timestamp' => date('c'),
        ];
        
        if ($message !== null) $response['message'] = $message;
        if ($data !== null) $response['data'] = $data;
        
        foreach ($extra as $k => $v) $response[$k] = $v;
        
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
}

if (!function_exists('apiError')) {
    /**
     * إرسال خطأ
     */
    function apiError($message, $status = 400, $code = null) {
        apiResponse(null, $status, $message, $code ? ['error_code' => $code] : []);
    }
}

if (!function_exists('apiInput')) {
    /**
     * قراءة JSON من الطلب
     */
    function apiInput() {
        static $input = null;
        if ($input === null) {
            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true) ?: [];
            
            // دمج مع POST
            if (!empty($_POST)) {
                $input = array_merge($input, $_POST);
            }
        }
        return $input;
    }
}

if (!function_exists('apiGet')) {
    /**
     * قراءة قيمة من الطلب (JSON أو GET أو POST)
     */
    function apiGet($key, $default = null) {
        $input = apiInput();
        if (isset($input[$key])) return $input[$key];
        if (isset($_GET[$key])) return $_GET[$key];
        if (isset($_POST[$key])) return $_POST[$key];
        return $default;
    }
}

if (!function_exists('apiPaginate')) {
    /**
     * حساب Pagination
     */
    function apiPaginate($total, $page, $perPage) {
        $totalPages = $perPage > 0 ? (int)ceil($total / $perPage) : 1;
        return [
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => (int)$total,
            'total_pages' => $totalPages,
            'has_next' => $page < $totalPages,
            'has_prev' => $page > 1,
        ];
    }
}

if (!function_exists('apiLog')) {
    /**
     * تسجيل عمليات API
     */
    function apiLog($action, $details = null, $userId = null) {
        $logDir = __DIR__ . '/../../logs/';
        if (!is_dir($logDir)) mkdir($logDir, 0777, true);
        $line = sprintf(
            "[%s] [API] user=%s action=%s ip=%s details=%s\n",
            date('Y-m-d H:i:s'),
            $userId ?? 'guest',
            $action,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $details ?? ''
        );
        file_put_contents($logDir . 'api.log', $line, FILE_APPEND | LOCK_EX);
    }
}

// ============================================================
// Rate Limiting
// ============================================================
if (!function_exists('checkRateLimit')) {
    function checkRateLimit($endpoint, $maxRequests = 60, $windowSeconds = 60) {
        global $pdo;
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        
        try {
            // تنظيف السجلات القديمة
            $pdo->prepare("DELETE FROM api_rate_limits WHERE window_start < datetime('now', '-1 hour')")->execute();
            
            // البحث عن السجل الحالي
            $stmt = $pdo->prepare("
                SELECT * FROM api_rate_limits 
                WHERE ip_address = ? AND endpoint = ?
            ");
            $stmt->execute([$ip, $endpoint]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$row) {
                $pdo->prepare("
                    INSERT INTO api_rate_limits (ip_address, endpoint, request_count, window_start)
                    VALUES (?, ?, 1, datetime('now'))
                ")->execute([$ip, $endpoint]);
                return true;
            }
            
            $windowStart = strtotime($row['window_start']);
            $elapsed = time() - $windowStart;
            
            if ($elapsed > $windowSeconds) {
                // نافذة جديدة
                $pdo->prepare("
                    UPDATE api_rate_limits 
                    SET request_count = 1, window_start = datetime('now')
                    WHERE id = ?
                ")->execute([$row['id']]);
                return true;
            }
            
            if ($row['request_count'] >= $maxRequests) {
                $retryAfter = $windowSeconds - $elapsed;
                header("Retry-After: $retryAfter");
                apiError("تجاوزت الحد المسموح به. أعد المحاولة بعد {$retryAfter} ثانية.", 429, 'RATE_LIMIT');
            }
            
            $pdo->prepare("UPDATE api_rate_limits SET request_count = request_count + 1 WHERE id = ?")
                ->execute([$row['id']]);
            return true;
            
        } catch (Exception $e) {
            return true; // اسمح بالمرور عند فشل Rate Limiter
        }
    }
}