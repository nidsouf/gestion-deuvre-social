<?php
session_start();
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$error = '';
$timeout_message = '';

// التحقق من وجود طلب انتهاء الجلسة
if (isset($_GET['timeout']) && $_GET['timeout'] == 1) {
    $timeout_message = "انتهت الجلسة بسبب عدم النشاط لمدة 10 دقيقة. يرجى تسجيل الدخول مرة أخرى.";
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    require_once 'config/database.php';
    $username = $_POST['username'];
    $password = $_POST['password'];
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        header("Location: index.php");
        exit;
    } else {
        $error = "اسم المستخدم أو كلمة المرور غير صحيحة";
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تسجيل الدخول - نظام إدارة الاقتطاعات</title>
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
        }
        .form-group input:focus {
            outline: none;
            border-color: #2e7d32;
            box-shadow: 0 0 0 3px rgba(46,125,50,0.1);
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
        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 10px;
            border-radius: 28px;
            margin-bottom: 20px;
            font-size: 13px;
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

    <form method="POST">
        <div class="form-group">
            <label>👤 اسم المستخدم</label>
            <input type="text" name="username" required autofocus>
        </div>
        <div class="form-group">
            <label>🔒 كلمة المرور</label>
            <input type="password" name="password" required>
        </div>
        <button type="submit">دخول</button>
    </form>
    <hr>
    <div class="footer">
        نظام إدارة الاقتطاعات والمنح الاجتماعية<br>
        إنجاز شـوقي نيـد
    </div>
</div>
</body>
</html>