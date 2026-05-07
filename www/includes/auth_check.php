<?php
/**
 * auth_check.php
 * التحقق من مصادقة المستخدم وانتهاء الجلسة (Session Timeout)
 * يجب تضمين هذا الملف في بداية كل صفحة محمية قبل أي مخرجات
 */

// التأكد من بدء الجلسة إذا لم تكن قد بدأت
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// =============================================
// 1. التحقق من وجود مستخدم مسجل الدخول
// =============================================
if (!isset($_SESSION['user_id'])) {
    // إعادة التوجيه إلى صفحة تسجيل الدخول مع رسالة (اختياري)
    $_SESSION['toast'] = [
        'message' => 'يرجى تسجيل الدخول أولاً',
        'type' => 'warning',
        'duration' => 3000
    ];
    header("Location: ../login.php");
    exit;
}

// =============================================
// 2. التحقق من انتهاء الجلسة بسبب الخمول (20 دقيقة)
// =============================================
$timeout = 20 * 60; // 20 دقيقة بالثواني

if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout)) {
    // انتهت الجلسة: إتلاف البيانات وإعادة التوجيه مع إشارة timeout
    session_unset();
    session_destroy();
    
    // يمكن إضافة رسالة toast في جلسة جديدة (لن تبقى لأننا دمرناها، لذا نستخدم GET)
    header("Location: ../login.php?timeout=1");
    exit;
}

// تحديث آخر نشاط للمستخدم
$_SESSION['last_activity'] = time();

// (اختياري) تجديد معرف الجلسة لمنع هجمات fixation
if (!isset($_SESSION['regenerated'])) {
    session_regenerate_id(true);
    $_SESSION['regenerated'] = true;
}
?>