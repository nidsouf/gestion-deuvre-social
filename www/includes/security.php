<?php
/**
 * security.php - مكتبة الأمان الشاملة للنظام
 * توفر: CSRF, XSS Protection, Input Validation, Rate Limiting, Session Security
 */

// بدء الجلسة إذا لم تكن قد بدأت
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// في أعلى security.php، بعد session_start()
if (ob_get_level()) {
    ob_end_clean(); // تنظيف أي مخرجات عالقة
}
ob_start(); // بدء التخزين المؤقت
// =============================================
// 1. CSRF Protection (الحماية من هجمات التزوير)
// =============================================

/**
 * إنشاء وتخزين رمز CSRF جديد
 * @return string رمز CSRF
 */
function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * عرض حقل CSRF مخفي في النماذج
 */
function csrfField() {
    $token = generateCSRFToken();
    echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
}

/**
 * التحقق من صحة رمز CSRF
 * @param string|null $token الرمز المرسل (إذا كان null، يتم أخذه من POST)
 * @return bool صحة الرمز
 */
function verifyCSRFToken($token = null) {
    if ($token === null) {
        $token = $_POST['csrf_token'] ?? '';
    }
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        error_log("CSRF verification failed at " . $_SERVER['REQUEST_URI']);
        return false;
    }
    return true;
}

/**
 * التحقق من CSRF وإنهاء الطلب إذا فشل
 */
function requireCSRFToken() {
    if (!verifyCSRFToken()) {
        die('<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="text-align:center;padding:50px;"><h2>⚠️ فشل التحقق الأمني</h2><p>الرجاء تحديث الصفحة والمحاولة مرة أخرى.</p><button onclick="history.back()">العودة</button></body></html>');
    }
}

// =============================================
// 2. XSS Protection (غير مستخدمة - محذوفة لتجنب التكرار)
// الدوال escape, escapeWithBreaks, escapeAttr موجودة في functions.php
// =============================================

// =============================================
// 3. Input Validation (التحقق من صحة المدخلات)
// =============================================

/**
 * التحقق من صحة البريد الإلكتروني
 * @param string $email البريد الإلكتروني
 * @return bool صحة البريد
 */
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * التحقق من صحة الرقم (عدد صحيح أو عشري)
 * @param mixed $number الرقم
 * @return bool هل هو رقم صحيح
 */
function validateNumber($number) {
    return is_numeric($number);
}

/**
 * ❌ تمت إزالة دالة validateDate() - موجودة في functions.php
 */

/**
 * التحقق من أن القيمة موجبة
 * @param float $value القيمة
 * @return bool هل هي موجبة
 */
function validatePositive($value) {
    return is_numeric($value) && $value > 0;
}

/**
 * ❌ تمت إزالة دالة sanitizeInput() - موجودة في functions.php
 */

// =============================================
// 4. Rate Limiting (تحديد عدد المحاولات)
// =============================================

/**
 * فحص حد المحاولات لعنوان IP معين
 * @param string $action نوع العملية (login, api, etc)
 * @param int $limit الحد الأقصى للمحاولات
 * @param int $window الفترة الزمنية بالثواني
 * @return bool هل تم تجاوز الحد
 */
function isRateLimited($action = 'default', $limit = 5, $window = 300) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = "rate_limit_{$action}_{$ip}";
    
    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = ['count' => 1, 'first_attempt' => time()];
        return false;
    }
    
    $data = $_SESSION[$key];
    if (time() - $data['first_attempt'] > $window) {
        $_SESSION[$key] = ['count' => 1, 'first_attempt' => time()];
        return false;
    }
    
    if ($data['count'] >= $limit) {
        return true;
    }
    
    $_SESSION[$key]['count']++;
    return false;
}

/**
 * إعادة تعيين عدد المحاولات
 * @param string $action نوع العملية
 */
function resetRateLimit($action = 'default') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = "rate_limit_{$action}_{$ip}";
    unset($_SESSION[$key]);
}

// =============================================
// 5. Session Security (أمان الجلسات)
// =============================================

/**
 * تجديد معرف الجلسة (بعد تسجيل الدخول)
 */
function regenerateSession() {
    session_regenerate_id(true);
}

/**
 * التحقق من صحة الجلسة (IP, User Agent)
 * @return bool هل الجلسة صالحة
 */
function validateSession() {
    if (!isset($_SESSION['user_id'])) {
        return false;
    }
    
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    if (isset($_SESSION['ip_address']) && $_SESSION['ip_address'] !== $ip) {
        error_log("Session IP mismatch for user {$_SESSION['user_id']}");
        return false;
    }
    
    if (isset($_SESSION['user_agent']) && $_SESSION['user_agent'] !== $ua) {
        error_log("Session User Agent mismatch for user {$_SESSION['user_id']}");
        return false;
    }
    
    return true;
}

/**
 * تعيين بيانات الجلسة عند تسجيل الدخول
 * @param int $user_id معرف المستخدم
 * @param string $username اسم المستخدم
 */
function setSessionData($user_id, $username) {
    $_SESSION['user_id'] = $user_id;
    $_SESSION['username'] = $username;
    $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'] ?? '';
    $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $_SESSION['login_time'] = time();
    regenerateSession();
}

/**
 * تدمير الجلسة وتسجيل الخروج
 */
function destroySession() {
    $_SESSION = array();
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}

// =============================================
// 6. Password Security (أمان كلمات المرور)
// =============================================

/**
 * تشفير كلمة المرور باستخدام Argon2id
 * @param string $password كلمة المرور
 * @return string التشفير
 */
function hashPassword($password) {
    return password_hash($password, PASSWORD_ARGON2ID, [
        'memory_cost' => 65536,
        'time_cost' => 4,
        'threads' => 3
    ]);
}

/**
 * التحقق من كلمة المرور
 * @param string $password كلمة المرور المدخلة
 * @param string $hash التشفير المخزن
 * @return bool صحة كلمة المرور
 */
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

/**
 * فحص قوة كلمة المرور
 * @param string $password كلمة المرور
 * @return array النتيجة (score من 0-4، ورسالة)
 */
function checkPasswordStrength($password) {
    $score = 0;
    $messages = [];
    
    if (strlen($password) < 8) {
        $messages[] = 'كلمة المرور قصيرة جداً (8 أحرف على الأقل)';
    } else {
        $score++;
    }
    
    if (preg_match('/[A-Z]/', $password)) $score++;
    if (preg_match('/[a-z]/', $password)) $score++;
    if (preg_match('/[0-9]/', $password)) $score++;
    if (preg_match('/[^A-Za-z0-9]/', $password)) $score++;
    
    if ($score >= 4) {
        $messages[] = 'قوية جداً';
    } elseif ($score >= 3) {
        $messages[] = 'قوية';
    } elseif ($score >= 2) {
        $messages[] = 'متوسطة';
    } else {
        $messages[] = 'ضعيفة';
    }
    
    return ['score' => $score, 'message' => implode(', ', $messages)];
}

// =============================================
// 7. Audit Log (تسجيل العمليات الأمنية)
// =============================================

/**
 * تسجيل عملية في سجل التدقيق
 * @param PDO $pdo اتصال قاعدة البيانات
 * @param string $action نوع العملية
 * @param string|null $details تفاصيل إضافية
 */
function auditLog($pdo, $action, $details = null) {
    $user_id = $_SESSION['user_id'] ?? null;
    $username = $_SESSION['username'] ?? 'guest';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO audit_log (user_id, username, action, details, ip_address, user_agent, created_at)
            VALUES (?, ?, ?, ?, ?, ?, datetime('now'))
        ");
        $stmt->execute([$user_id, $username, $action, $details, $ip, $user_agent]);
    } catch (Exception $e) {
        error_log("Audit log failed: " . $e->getMessage());
    }
}

// =============================================
// 8. Security Headers (رؤوس الأمان)
// =============================================

/**
 * إرسال رؤوس الأمان للمتصفح
 */
function sendSecurityHeaders() {
    header("X-Frame-Options: DENY");
    header("X-Content-Type-Options: nosniff");
    header("X-XSS-Protection: 1; mode=block");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline'; font-src 'self' https://cdnjs.cloudflare.com;");
}
ob_end_flush();
?>