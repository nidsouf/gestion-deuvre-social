<?php
/**
 * api/v1/auth.php - تسجيل الدخول / الخروج
 * 
 * POST /api/v1/auth.php?action=login
 *   body: { "username": "...", "password": "...", "device_name": "..." }
 * 
 * POST /api/v1/auth.php?action=logout
 *   header: Authorization: Bearer <token>
 * 
 * POST /api/v1/auth.php?action=refresh
 *   header: Authorization: Bearer <token>
 */

require_once __DIR__ . '/config.php';

$action = $_GET['action'] ?? 'login';

try {
    // ============================================================
    // تسجيل الدخول
    // ============================================================
    if ($action === 'login') {
        checkRateLimit('auth_login', 10, 300); // 10 محاولات كل 5 دقائق
        
        $username = trim(apiGet('username', ''));
        $password = apiGet('password', '');
        $deviceName = trim(apiGet('device_name', 'Unknown Device'));
        $deviceType = trim(apiGet('device_type', 'mobile'));
        
        if (empty($username) || empty($password)) {
            apiError('اسم المستخدم وكلمة المرور مطلوبان', 400, 'MISSING_CREDENTIALS');
        }
        
        // البحث عن المستخدم
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            apiError('اسم المستخدم أو كلمة المرور غير صحيحة', 401, 'INVALID_CREDENTIALS');
        }
        
        // التحقق من كلمة المرور (يدعم password_verify + MD5 القديم)
        $validPassword = false;
        if (password_verify($password, $user['password'])) {
            $validPassword = true;
        } elseif (md5($password) === $user['password']) {
            // ترقية تلقائية لـ Argon2id
            $newHash = password_hash($password, PASSWORD_ARGON2ID);
            $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")
                ->execute([$newHash, $user['id']]);
            $validPassword = true;
        }
        
        if (!$validPassword) {
            apiLog('LOGIN_FAILED', "user=$username", $user['id']);
            apiError('اسم المستخدم أو كلمة المرور غير صحيحة', 401, 'INVALID_CREDENTIALS');
        }
        
        // إنشاء توكن
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));
        
        $stmt = $pdo->prepare("
            INSERT INTO api_tokens (
                user_id, token, device_name, device_type, 
                ip_address, user_agent, expires_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $user['id'],
            $token,
            $deviceName,
            $deviceType,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
            $expiresAt,
        ]);
        
        apiLog('LOGIN_SUCCESS', "user=$username", $user['id']);
        
        apiResponse([
            'token' => $token,
            'expires_at' => $expiresAt,
            'user' => [
                'id' => (int)$user['id'],
                'username' => $user['username'],
                'role' => $user['role'],
                'employee_id' => $user['employee_id'] ?? null,
            ],
        ], 200, 'تم تسجيل الدخول بنجاح');
    }
    
    // ============================================================
    // تسجيل الخروج
    // ============================================================
    elseif ($action === 'logout') {
        require_once __DIR__ . '/middleware.php';
        $auth = apiRequireAuth();
        
        $pdo->prepare("UPDATE api_tokens SET is_revoked = 1 WHERE id = ?")
            ->execute([$auth['token_id']]);
        
        apiLog('LOGOUT', null, $auth['user_id']);
        apiResponse(null, 200, 'تم تسجيل الخروج');
    }
    
    // ============================================================
    // تجديد التوكن
    // ============================================================
    elseif ($action === 'refresh') {
        require_once __DIR__ . '/middleware.php';
        $auth = apiRequireAuth();
        
        $newToken = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));
        
        $pdo->beginTransaction();
        try {
            // إلغاء التوكن القديم
            $pdo->prepare("UPDATE api_tokens SET is_revoked = 1 WHERE id = ?")
                ->execute([$auth['token_id']]);
            
            // إصدار توكن جديد
            $stmt = $pdo->prepare("
                INSERT INTO api_tokens (
                    user_id, token, device_name, device_type,
                    ip_address, user_agent, expires_at
                ) VALUES (?, ?, 'Refreshed', 'mobile', ?, ?, ?)
            ");
            $stmt->execute([
                $auth['user_id'],
                $newToken,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null,
                $expiresAt,
            ]);
            
            $pdo->commit();
            apiResponse(['token' => $newToken, 'expires_at' => $expiresAt], 200, 'تم تجديد التوكن');
        } catch (Exception $e) {
            $pdo->rollBack();
            apiError('فشل تجديد التوكن', 500);
        }
    }
    
    else {
        apiError('إجراء غير معروف', 400);
    }
    
} catch (Exception $e) {
    apiLog('AUTH_ERROR', $e->getMessage());
    apiError('حدث خطأ داخلي', 500);
}