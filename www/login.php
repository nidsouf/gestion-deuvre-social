<?php
session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/security.php';

// إعادة التوجيه إذا كان المستخدم مسجلاً دخولاً مسبقاً
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // التحقق من CSRF
    requireCSRFToken();
    
    // التحقق من Rate Limiting
    if (isRateLimited('login', 5, 300)) {
        $error = 'لقد تجاوزت عدد المحاولات المسموحة. الرجاء المحاولة بعد 5 دقائق.';
    } else {
        $username = sanitizeInput($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        
        if (empty($username) || empty($password)) {
            $error = 'الرجاء إدخال اسم المستخدم وكلمة المرور';
        } else {
            $stmt = $pdo->prepare("SELECT id, username, password, role FROM users WHERE username = ? AND is_active = 1");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user) {
    error_log("User found. Hash: " . $user['password']);
    $verify = password_verify($password, $user['password']);
    error_log("Password verify result: " . ($verify ? 'true' : 'false'));
}
            if ($user && password_verify($password, $user['password'])) {
                // تسجيل دخول ناجح - إعادة تعيين حد المحاولات
                resetRateLimit('login');
                setSessionData($user['id'], $user['username']);
                
                // تسجيل في سجل التدقيق
                auditLog($pdo, 'LOGIN_SUCCESS', "User {$username} logged in successfully");
                
                header("Location: index.php");
                exit;
            } else {
                $error = 'اسم المستخدم أو كلمة المرور غير صحيحة';
                auditLog($pdo, 'LOGIN_FAILED', "Failed login attempt for username: {$username}");
            }
        }
    }
}

// توليد رمز CSRF جديد للصفحة
$csrf_token = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تسجيل الدخول - نظام الاقتطاعات</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        .login-container {
            background: white;
            border-radius: 20px;
            padding: 40px;
            width: 400px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.2);
        }
        .login-container h2 {
            text-align: center;
            margin-bottom: 30px;
            color: #333;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            color: #555;
        }
        .form-group input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 10px;
            font-size: 16px;
            transition: 0.3s;
        }
        .form-group input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102,126,234,0.2);
        }
        button {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: 0.3s;
        }
        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
        }
        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 12px;
            border-radius: 10px;
            margin-bottom: 20px;
            text-align: center;
        }
        .info {
            text-align: center;
            margin-top: 20px;
            color: #888;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <h2>🏛️ نظام إدارة الاقتطاعات والمنح</h2>
        <h3 style="text-align:center; margin-bottom:20px; color:#666;">تسجيل الدخول</h3>
        
        <?php if ($error): ?>
            <div class="error"><?= escape($error) ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= escape($csrf_token) ?>">
            <div class="form-group">
                <label>👤 اسم المستخدم</label>
                <input type="text" name="username" required>
            </div>
            <div class="form-group">
                <label>🔒 كلمة المرور</label>
                <input type="password" name="password" required>
            </div>
            <button type="submit">🚀 دخول</button>
        </form>
        <div class="info">
            نظام إدارة الاقتطاعات والمنح الاجتماعية<br>
            لجنة الخدمات الاجتماعية
        </div>
    </div>
</body>
</html>