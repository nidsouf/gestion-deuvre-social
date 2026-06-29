<?php
require_once 'config/database.php';

$username = 'admin2';
$new_password = 'admin123';
$hashed = password_hash($new_password, PASSWORD_DEFAULT);

// تحديث كلمة المرور
$stmt = $pdo->prepare("UPDATE users SET password = ? WHERE username = ?");
$stmt->execute([$hashed, $username]);

// التحقق من التحديث
$stmt = $pdo->prepare("SELECT password FROM users WHERE username = ?");
$stmt->execute([$username]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if ($row && password_verify($new_password, $row['password'])) {
    echo "✅ تم تحديث كلمة المرور بنجاح للمستخدم <strong>$username</strong><br>";
    echo "🔑 كلمة المرور الجديدة: <strong>$new_password</strong><br>";
    echo "يمكنك الآن <a href='login.php'>تسجيل الدخول</a>.";
} else {
    echo "❌ فشل التحديث. تأكد من اتصال قاعدة البيانات.";
}
?>