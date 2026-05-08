<?php
/**
 * ======================================================
 * نظام الأمان الشامل - Security Module
 * ======================================================
 * - حماية CSRF (Cross-Site Request Forgery)
 * - التحقق من صحة المدخلات (Input Validation)
 * - حماية من XSS (Cross-Site Scripting)
 * - Rate Limiting
 * - معالجة الأخطاء الآمنة
 */

// ========== CSRF Token Management ==========

/**
 * إنشاء أو الحصول على CSRF Token
 * @return string
 */
function getCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * التحقق من صحة CSRF Token
 * @param string $token
 * @return bool
 */
function verifyCSRFToken($token) {
    if (!isset($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * إعادة توليد CSRF Token بعد تسجيل الدخول
 */
function regenerateCSRFToken() {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}

// ========== Input Validation ==========

/**
 * التحقق من نوع البيانات والقيم المسموحة
 * @param mixed $input
 * @param string $type
 * @param array $options
 * @return mixed|false
 */
function validateInput($input, $type = 'string', $options = []) {
    $input = trim($input ?? '');
    
    switch ($type) {
        case 'string':
            $maxLength = $options['max'] ?? 255;
            $minLength = $options['min'] ?? 1;
            if (strlen($input) < $minLength || strlen($input) > $maxLength) {
                return false;
            }
            return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
            
        case 'email':
            if (!filter_var($input, FILTER_VALIDATE_EMAIL)) {
                return false;
            }
            return strtolower($input);
            
        case 'integer':
            $int = filter_var($input, FILTER_VALIDATE_INT);
            if ($int === false) return false;
            $min = $options['min'] ?? PHP_INT_MIN;
            $max = $options['max'] ?? PHP_INT_MAX;
            if ($int < $min || $int > $max) return false;
            return $int;
            
        case 'float':
            $float = filter_var($input, FILTER_VALIDATE_FLOAT);
            if ($float === false) return false;
            $min = $options['min'] ?? -PHP_FLOAT_MAX;
            $max = $options['max'] ?? PHP_FLOAT_MAX;
            if ($float < $min || $float > $max) return false;
            return $float;
            
        case 'date':
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $input)) {
                return false;
            }
            // التحقق من أن التاريخ صحيح
            $date = DateTime::createFromFormat('Y-m-d', $input);
            return ($date && $date->format('Y-m-d') === $input) ? $input : false;
            
        case 'phone':
            // رقم هاتف (أرقام وفواصل فقط)
            if (!preg_match('/^[\d\-\+\s\(\)]{6,20}$/', $input)) {
                return false;
            }
            return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
            
        case 'url':
            if (!filter_var($input, FILTER_VALIDATE_URL)) {
                return false;
            }
            return $input;
            
        case 'enum':
            $allowed = $options['values'] ?? [];
            return in_array($input, $allowed, true) ? $input : false;
            
        case 'arabic':
            // للنصوص العربية
            if (!preg_match('/^[\p{L}\p{N}\s\-\.,!؟]+$/u', $input)) {
                return false;
            }
            $maxLength = $options['max'] ?? 500;
            if (strlen($input) > $maxLength) return false;
            return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
            
        default:
            return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * التحقق من مجموعة من المدخلات
 * @param array $inputs
 * @param array $validationRules
 * @return array|false
 */
function validateMultiple($inputs, $validationRules) {
    $validated = [];
    $errors = [];
    
    foreach ($validationRules as $field => $rules) {
        $value = $inputs[$field] ?? null;
        
        // Check if required
        if (($rules['required'] ?? false) && empty($value)) {
            $errors[$field] = $rules['error_message'] ?? "الحقل {$field} مطلوب";
            continue;
        }
        
        // Skip validation if not required and empty
        if (empty($value) && !($rules['required'] ?? false)) {
            $validated[$field] = null;
            continue;
        }
        
        // Validate based on type
        $validated[$field] = validateInput(
            $value,
            $rules['type'] ?? 'string',
            $rules['options'] ?? []
        );
        
        if ($validated[$field] === false) {
            $errors[$field] = $rules['error_message'] ?? "القيمة المدخلة غير صحيحة للحقل {$field}";
        }
    }
    
    return empty($errors) ? $validated : false;
}

// ========== XSS Protection ==========

/**
 * تنظيف HTML مع السماح بعلامات محدودة
 * @param string $html
 * @return string
 */
function sanitizeHTML($html) {
    $allowed = '<p><br><strong><b><em><i><u><h1><h2><h3><h4><h5><h6><ul><ol><li><a><table><tr><td><th><div><span>';
    return strip_tags($html, $allowed);
}

/**
 * تنظيف النص من الأحرف الخطرة
 * @param string $text
 * @return string
 */
function sanitizeText($text) {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

/**
 * الحصول على البيانات من GET/POST مع الحماية
 * @param string $key
 * @param string $type
 * @param mixed $default
 * @return mixed
 */
function getSafeInput($key, $type = 'string', $default = null) {
    $value = $_POST[$key] ?? $_GET[$key] ?? $default;
    
    if ($value === null || $value === $default) {
        return $default;
    }
    
    return validateInput($value, $type);
}

// ========== Rate Limiting ==========

const RATE_LIMIT_KEY_PREFIX = 'ratelimit_';
const RATE_LIMIT_MAX_ATTEMPTS = 5;
const RATE_LIMIT_WINDOW = 300; // 5 دقائق

/**
 * التحقق من حد الطلبات
 * @param string $identifier
 * @param int $maxAttempts
 * @param int $window
 * @return bool
 */
function checkRateLimit($identifier, $maxAttempts = RATE_LIMIT_MAX_ATTEMPTS, $window = RATE_LIMIT_WINDOW) {
    $key = RATE_LIMIT_KEY_PREFIX . $identifier;
    
    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = [
            'attempts' => 0,
            'first_attempt' => time()
        ];
    }
    
    $rateData = $_SESSION[$key];
    $elapsed = time() - $rateData['first_attempt'];
    
    // إعادة تعيين إذا انتهت فترة الانتظار
    if ($elapsed > $window) {
        $_SESSION[$key] = [
            'attempts' => 1,
            'first_attempt' => time()
        ];
        return true;
    }
    
    // التحقق من عدد المحاولات
    if ($rateData['attempts'] >= $maxAttempts) {
        return false;
    }
    
    // زيادة عدد المحاولات
    $_SESSION[$key]['attempts']++;
    return true;
}

/**
 * إعادة تعيين حد الطلبات
 * @param string $identifier
 */
function resetRateLimit($identifier) {
    $key = RATE_LIMIT_KEY_PREFIX . $identifier;
    unset($_SESSION[$key]);
}

/**
 * الحصول على عدد محاولات حد الطلبات المتبقية
 * @param string $identifier
 * @return int
 */
function getRateLimitRemaining($identifier) {
    $key = RATE_LIMIT_KEY_PREFIX . $identifier;
    
    if (!isset($_SESSION[$key])) {
        return RATE_LIMIT_MAX_ATTEMPTS;
    }
    
    $rateData = $_SESSION[$key];
    $elapsed = time() - $rateData['first_attempt'];
    
    if ($elapsed > RATE_LIMIT_WINDOW) {
        return RATE_LIMIT_MAX_ATTEMPTS;
    }
    
    return max(0, RATE_LIMIT_MAX_ATTEMPTS - $rateData['attempts']);
}

// ========== Prepared Statements Helper ==========

/**
 * تنفيذ استعلام مع معالجة الأخطاء
 * @param PDO $pdo
 * @param string $sql
 * @param array $params
 * @return PDOStatement|false
 */
function executeSafeQuery($pdo, $sql, $params = []) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    } catch (PDOException $e) {
        error_log("Database Error: " . $e->getMessage());
        return false;
    }
}

// ========== Session Security ==========

/**
 * تحديث معرف الجلسة (منع Session Fixation)
 */
function regenerateSession() {
    session_regenerate_id(true);
    // الحفاظ على CSRF token
    if (isset($_SESSION['csrf_token'])) {
        $csrf = $_SESSION['csrf_token'];
        session_regenerate_id(true);
        $_SESSION['csrf_token'] = $csrf;
    }
}

/**
 * تعيين خصائص أمان الجلسة
 */
function setSessionSecurityHeaders() {
    // منع الوصول للـ cookie من JavaScript
    ini_set('session.cookie_httponly', '1');
    
    // نقل الـ cookie فقط عبر HTTPS (اختياري - حسب البيئة)
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        ini_set('session.cookie_secure', '1');
    }
    
    // تعيين SameSite attribute
    ini_set('session.cookie_samesite', 'Strict');
}

// ========== Logging & Audit Trail ==========

/**
 * تسجيل العمليات الحساسة
 * @param string $action
 * @param string $entity
 * @param int $entityId
 * @param string $details
 * @param int $userId
 */
function logSecurityAction($action, $entity, $entityId, $details = '', $userId = null) {
    global $pdo;
    
    if ($userId === null) {
        $userId = $_SESSION['user_id'] ?? null;
    }
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO security_audit_log (user_id, action, entity, entity_id, details, ip_address, user_agent, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now'))
        ");
        
        $stmt->execute([
            $userId,
            $action,
            $entity,
            $entityId,
            $details,
            $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN',
            $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN'
        ]);
    } catch (PDOException $e) {
        error_log("Audit Log Error: " . $e->getMessage());
    }
}

// ========== Password Security ==========

/**
 * التحقق من قوة كلمة المرور
 * @param string $password
 * @return array
 */
function checkPasswordStrength($password) {
    $strength = 0;
    $feedback = [];
    
    if (strlen($password) >= 8) $strength++;
    else $feedback[] = "يجب أن تكون كلمة المرور 8 أحرف على الأقل";
    
    if (preg_match('/[a-z]/', $password)) $strength++;
    else $feedback[] = "أضف حروفاً صغيرة";
    
    if (preg_match('/[A-Z]/', $password)) $strength++;
    else $feedback[] = "أضف حروفاً كبيرة";
    
    if (preg_match('/[0-9]/', $password)) $strength++;
    else $feedback[] = "أضف أرقاماً";
    
    if (preg_match('/[!@#$%^&*]/', $password)) $strength++;
    else $feedback[] = "أضف رموزاً خاصة (!@#$%^&*)";
    
    return [
        'strength' => $strength,
        'level' => $strength <= 1 ? 'ضعيفة' : ($strength <= 2 ? 'متوسطة' : ($strength <= 3 ? 'جيدة' : 'قوية جداً')),
        'feedback' => $feedback
    ];
}

/**
 * توليد كلمة مرور قوية
 * @param int $length
 * @return string
 */
function generateStrongPassword($length = 12) {
    $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $lowercase = 'abcdefghijklmnopqrstuvwxyz';
    $numbers = '0123456789';
    $symbols = '!@#$%^&*';
    
    $all = $uppercase . $lowercase . $numbers . $symbols;
    $password = '';
    
    // تأكد من وجود أنواع مختلفة من الأحرف
    $password .= $uppercase[random_int(0, strlen($uppercase) - 1)];
    $password .= $lowercase[random_int(0, strlen($lowercase) - 1)];
    $password .= $numbers[random_int(0, strlen($numbers) - 1)];
    $password .= $symbols[random_int(0, strlen($symbols) - 1)];
    
    // ملء الباقي عشوائياً
    for ($i = 4; $i < $length; $i++) {
        $password .= $all[random_int(0, strlen($all) - 1)];
    }
    
    // خلط الأحرف
    $password = str_shuffle($password);
    
    return $password;
}

// ========== Initialize Security ==========
setSessionSecurityHeaders();
?>
