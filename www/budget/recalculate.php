<?php
/**
 * budget/recalculate.php - إعادة حساب الميزانية المتبقية
 */
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';

$year = isset($_POST['year']) ? (int)$_POST['year'] : date('Y');

// التحقق من CSRF
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    $_SESSION['toast'] = ['message' => '⚠️ خطأ في التحقق الأمني', 'type' => 'warning', 'duration' => 3000];
    header("Location: dashboard.php?year=$year");
    exit;
}

// إعادة حساب الميزانية
if (recalculateBudget($pdo, $year)) {
    // جلب القيمة الجديدة لعرضها في الرسالة
    $stmt = $pdo->prepare("SELECT remaining_budget FROM social_budget WHERE year = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$year]);
    $newRemaining = $stmt->fetchColumn();
    
    $_SESSION['toast'] = [
        'message' => '✅ تم إعادة حساب ميزانية سنة ' . $year . ' بنجاح (المتبقية: ' . number_format($newRemaining, 2) . ' دج)',
        'type' => 'success',
        'duration' => 4000
    ];
} else {
    $_SESSION['toast'] = [
        'message' => '⚠️ لا توجد ميزانية مسجلة لسنة ' . $year . ' أو حدث خطأ',
        'type' => 'warning',
        'duration' => 3000
    ];
}

header("Location: dashboard.php?year=$year");
exit;