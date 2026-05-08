<?php
session_start();

// إذا كان المستخدم مسجلاً بالفعل، أعده للصفحة الرئيسية
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

// ========== REQUIREMENTS ==========
require_once 'config/database.php';
require_once 'includes/security.php';

// ========== INITIALIZE SECURITY ==========
setSessionSecurityHeaders();

$error = '';
$timeout_message = '';

// التحقق من انتهاء الجلسة
if (isset($_GET['timeout']) && $_GET['timeout'] == 1) {
    $timeout_message = "انتهت الجلسة بسبب عدم النشاط لمدة 10 دقيقة. يرجى تسجيل الدخول مرة أخرى.";
}

// ========== CSRF TOKEN GENERATION ==========
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ========== FORM SUBMISSION HANDLING ==========
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // 1. Check Rate Limit (محاولات الدخول المتكررة)
    if (!checkRateLimit('login', 5, 300)) {
        $error = "⚠️ تم تجاوز عدد محاولات الدخول. يرجى المحاولة بعد 5 دقائق.";
    }
    // 2. Verify CSRF Token
    elseif (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = "❌ طلب غير صالح (CSRF Token Invalid)";
        logSecurityAction('login_attempt_csrf_fail', 'authentication', 0, 'CSRF token mismatch');
    }
    else {
        // 3. Validate and Sanitize Input
        $username = validateInput($_POST['username'] ?? '', 'string', ['min' => 3, 'max' => 50]);
        $password = $_POST['password'] ?? '';
        
        if (!$username) {
            $error = "⚠️ اسم المستخدم غير صحيح (يجب أن يكون 3-50 حرف)";
        } elseif (strlen($password) < 6 || strlen($password) > 255) {
            $error = "⚠️ كلمة المرور غير صحيحة";
        } else {
            try {
                // 4. Query Database with Prepared Statement
                $stmt = $pdo->prepare("SELECT id, username, password, role, is_active FROM users WHERE username = ?");
                $stmt->execute([$username]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // 5. Verify User and Password
                if ($user && $user['is_active'] == 1 && password_verify($password, $user['password'])) {
                    // Login successful
                    
                    // Regenerate session ID (prevent Session Fixation)
                    regenerateSession();
                    
                    // Store user info in session
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['login_time'] = time();
                    $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
                    $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN';
                    
                    // Reset rate limit after successful login
                    resetRateLimit('login');
                    
                    // Log successful login
                    logSecurityAction('login_success', 'authentication', $user['id'], 
                        'User logged in successfully');
                    
                    // Redirect to dashboard
                    header("Location: index.php");
                    exit;
                } else {
                    // Login failed
                    $error = "❌ اسم المستخدم أو كلمة المرور غير صحيحة";
                    
                    // Log failed attempt
                    logSecurityAction('login_failed', 'authentication', 0, 
                        "Failed login attempt for username: $username");
                }
            } catch (PDOException $e) {
                $error = "❌ حدث خطأ في النظام. يرجى المحاولة لاحقاً.";
                error_log("Login Error: " . $e->getMessage());
                logSecurityAction('login_error', 'authentication', 0, 'Database error during login');
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>تسجيل الدخول - نظام إدارة الاقتطاعات</title>
    
    <!-- Security Headers -->
    <meta http-equiv="Content-Security-Policy" content="default-src 'self'; style-src 'unsafe-inline'">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Tajawal', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #1a3a2a, #0d2a1a);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        
        .login-container {
            background: white;
            border-radius: 32px;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);
            width: 100%;
            max-width: 450px;
            padding: 40px 35px;
            text-align: center;
            transition: transform 0.3s ease;
        }
        
        .login-container:hover {
            transform: translateY(-5px);
        }
        
        .logo {
            margin-bottom: 20px;
        }
        
        .logo img {
            max-width: 100px;
            height: auto;
            border-radius: 20px;
        }
        
        .logo h2 {
            font-size: 22px;
            color: #1b5e20;
            margin-top: 15px;
            margin-bottom: 5px;
        }
        
        .logo h3 {
            font-size: 16px;
            color: #2e7d32;
            font-weight: normal;
            margin-bottom: 5px;
        }
        
        .logo p {
            font-size: 14px;
            color: #ff8f00;
            font-weight: bold;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid #eee;
        }
        
        .form-group {
            margin-bottom: 20px;
            text-align: right;
        }
        
        .form-group label {
            display: block;
            font-weight: bold;
            margin-bottom: 8px;
            color: #333;
        }
        
        .form-group input {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 28px;
            font-size: 14px;
            transition: 0.3s;
            font-family: inherit;
            background: #f9f9f9;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #2e7d32;
            background: white;
            box-shadow: 0 0 0 3px rgba(46,125,50,0.1);
        }
        
        .form-group input:invalid:not(:placeholder-shown) {
            border-color: #dc3545;
        }
        
        button {
            background: linear-gradient(135deg, #2e7d32, #1b5e20);
            color: white;
            border: none;
            padding: 12px;
            width: 100%;
            border-radius: 28px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: 0.3s;
            margin-top: 10px;
        }
        
        button:hover {
            transform: scale(1.02);
            background: linear-gradient(135deg, #1b5e20, #0d3b12);
        }
        
        button:active {
            transform: scale(0.98);
        }
        
        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 12px 15px;
            border-radius: 28px;
            margin-bottom: 20px;
            font-size: 13px;
            border: 1px solid #f5c6cb;
            animation: slideDown 0.3s ease;
        }
        
        .warning {
            background: #fff3cd;
            color: #856404;
            padding: 12px 15px;
            border-radius: 28px;
            margin-bottom: 20px;
            font-size: 13px;
            border-right: 4px solid #ffc107;
            text-align: right;
            animation: slideDown 0.3s ease;
        }
        
        .footer {
            margin-top: 25px;
            font-size: 11px;
            color: #888;
            text-align: center;
        }
        
        hr {
            margin: 20px 0;
            border: none;
            border-top: 1px solid #eee;
        }
        
        .security-info {
            font-size: 11px;
            color: #999;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #eee;
        }
        
        .security-info span {
            display: block;
            margin: 3px 0;
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @media (max-width: 480px) {
            .login-container {
                padding: 30px 20px;
                border-radius: 20px;
            }
            
            .logo h2 {
                font-size: 18px;
            }
            
            .logo h3 {
                font-size: 14px;
            }
        }
    </style>
</head>
<body>
<div class="login-container">
    <div class="logo">
        <img src="assets/images/logo.png" alt="شعار المركز" onerror="this.style.display='none'">
        <h2>مركز التكوين والتعليم المهنيين</h2>
        <h3>الشهيد علي بوسحابة - بكوينين</h3>
        <p>لجنة الخدمات الاجتماعية</p>
    </div>

    <?php if ($timeout_message): ?>
        <div class="warning">
            ⏱️ <?= htmlspecialchars($timeout_message) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="error">⚠️ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="login.php" novalidate>
        <!-- CSRF Token -->
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        
        <!-- Username -->
        <div class="form-group">
            <label for="username">👤 اسم المستخدم</label>
            <input 
                type="text" 
                id="username"
                name="username" 
                required 
                autofocus 
                minlength="3"
                maxlength="50"
                placeholder="أدخل اسم المستخدم"
                aria-label="اسم المستخدم">
        </div>
        
        <!-- Password -->
        <div class="form-group">
            <label for="password">🔒 كلمة المرور</label>
            <input 
                type="password" 
                id="password"
                name="password" 
                required 
                minlength="6"
                maxlength="255"
                placeholder="أدخل كلمة المرور"
                aria-label="كلمة المرور">
        </div>
        
        <!-- Submit Button -->
        <button type="submit" aria-label="تسجيل الدخول">دخول</button>
    </form>
    
    <hr>
    
    <div class="footer">
        <strong>نظام إدارة الاقتطاعات والمنح الاجتماعية</strong><br>
        إنجاز شـوقي نيـد<br>
        <div class="security-info">
            <span>🔒 جميع البيانات محمية بالتشفير</span>
            <span>✅ هذا الموقع آمن وموثوق</span>
        </div>
    </div>
</div>

<!-- Optional: Show remaining attempts message -->
<?php if (isset($_POST['username']) && !$error): ?>
    <!-- No error, don't show anything extra -->
<?php elseif (isset($_POST['username']) && $error): 
    $remaining = getRateLimitRemaining('login');
    if ($remaining > 0 && $remaining < 5):
?>
    <script>
        console.warn('محاولات دخول متبقية: <?= $remaining ?>');
    </script>
<?php endif; endif; ?>

</body>
</html>
