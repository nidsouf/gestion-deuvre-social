<?php
require_once 'config/database.php';

$username = 'admin2';
$password = 'admin123';

$stmt = $pdo->prepare("SELECT password FROM users WHERE username = ?");
$stmt->execute([$username]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if ($row) {
    if (password_verify($password, $row['password'])) {
        echo "✅ كلمة المرور صحيحة (password_verify نجحت)";
    } else {
        echo "❌ كلمة المرور غير صحيحة (password_verify فشلت)";
    }
} else {
    echo "❌ المستخدم غير موجود";
}
?>