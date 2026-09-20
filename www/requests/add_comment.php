<?php
/**
 * requests/add_comment.php - إضافة تعليق
 */
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';
require_once '../includes/requests_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

requireCSRFToken();

$requestId = (int)$_POST['request_id'];
$comment = trim($_POST['comment']);

if (empty($comment)) {
    setToast('⚠️ التعليق مطلوب', 'warning');
    header("Location: view.php?id=$requestId");
    exit;
}

$request = getRequestDetails($pdo, $requestId);
if (!$request) {
    setToast('⚠️ الطلب غير موجود', 'warning');
    header('Location: index.php');
    exit;
}

$canEdit = in_array($_SESSION['role'], ['admin', 'manager', 'committee']);
$isOwner = ($request['employee_id'] == ($_SESSION['employee_id'] ?? 0));
if (!$canEdit && !$isOwner) {
    setToast('⚠️ غير مصرح لك بإضافة تعليق', 'warning');
    header("Location: view.php?id=$requestId");
    exit;
}

$stmt = $pdo->prepare("INSERT INTO request_comments (request_id, user_id, comment) VALUES (?, ?, ?)");
$stmt->execute([$requestId, $_SESSION['user_id'], $comment]);

auditLog($pdo, 'REQUEST_COMMENT', "تعليق على الطلب رقم $requestId");
setToast('✅ تم إضافة التعليق', 'success');
header("Location: view.php?id=$requestId");
exit;