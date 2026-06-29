<?php
/**
 * logout.php - تسجيل الخروج وتدمير الجلسة
 */
session_start();

// تسجيل عملية الخروج في سجل التدقيق
require_once 'config/database.php';
require_once 'includes/security.php';
require_once 'includes/functions.php';

if (isset($_SESSION['user_id'])) {
    audit('LOGOUT', "User {$_SESSION['username']} logged out");
}

// تدمير الجلسة
destroySession();

// إعادة التوجيه إلى صفحة تسجيل الدخول
header("Location: login.php");
exit;
?>