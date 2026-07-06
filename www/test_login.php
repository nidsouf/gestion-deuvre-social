<?php
require_once 'config/database.php';
require_once 'includes/security.php';

$username = 'admin2';
$password = 'admin123';

$stmt = $pdo->prepare("SELECT id, username, password, role FROM users WHERE username = ? AND is_active = 1");
$stmt->execute([$username]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user) {
    echo "<pre>";
    echo "User found: " . print_r($user, true);
    $verify = password_verify($password, $user['password']);
    echo "Password verify result: " . ($verify ? 'TRUE ✅' : 'FALSE ❌') . "\n";
    if ($verify) {
        echo "✅ يمكن تسجيل الدخول بنجاح.";
    } else {
        echo "❌ كلمة المرور غير صحيحة.";
    }
    echo "</pre>";
} else {
    echo "❌ المستخدم غير موجود أو غير نشط.";
}
?>