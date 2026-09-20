<?php
/**
 * requests/reject.php - رفض الطلب مع سبب
 */
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';
require_once '../includes/requests_helpers.php';

if (!in_array($_SESSION['role'], ['admin', 'manager', 'committee'])) {
    setToast('⚠️ غير مصرح لك بهذه الصفحة', 'warning');
    header('Location: index.php');
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    header('Location: index.php');
    exit;
}

$request = getRequestDetails($pdo, $id);
if (!$request || in_array($request['status'], ['approved', 'rejected', 'cancelled'])) {
    setToast('⚠️ الطلب غير قابل للرفض', 'warning');
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRFToken();

    $reason = trim($_POST['rejection_reason']);
    $comment = trim($_POST['comment'] ?? '');

    if (empty($reason)) {
        setToast('⚠️ سبب الرفض مطلوب', 'warning');
    } elseif (rejectRequest($pdo, $id, $_SESSION['user_id'], $reason, $comment)) {
        auditLog($pdo, 'REQUEST_REJECTED', "رفض الطلب رقم $id - السبب: $reason");
        setToast('✅ تم رفض الطلب', 'success');
        header('Location: index.php');
        exit;
    } else {
        setToast('❌ حدث خطأ أثناء الرفض', 'error');
    }
}

$pageTitle = 'رفض الطلب';
include '../includes/header.php';
?>
<link rel="stylesheet" href="../assets/css/requests.css">

<div class="container mt-4">
    <h2>❌ رفض الطلب</h2>

    <div class="card mb-4">
        <div class="card-body">
            <p><strong>الموظف:</strong> <?= htmlspecialchars($request['employee_name'] ?? 'غير معروف') ?></p>
            <p><strong>العنوان:</strong> <?= htmlspecialchars($request['title']) ?></p>
            <p><strong>المبلغ المطلوب:</strong> <?= number_format($request['requested_amount'], 2) ?> دج</p>
        </div>
    </div>

    <div class="card">
        <div class="card-header bg-danger text-white">
            <h5 class="mb-0">📝 تحديد سبب الرفض</h5>
        </div>
        <div class="card-body">
            <form method="POST">
                <?= csrfField() ?>
                <div class="form-group mb-3">
                    <label>سبب الرفض</label>
                    <input type="text" name="rejection_reason" class="form-control" placeholder="سبب الرفض" required>
                </div>
                <div class="form-group mb-3">
                    <label>تعليق (اختياري)</label>
                    <textarea name="comment" class="form-control" rows="3" placeholder="أي ملاحظات إضافية"></textarea>
                </div>
                <button type="submit" class="btn btn-danger">❌ تأكيد الرفض</button>
                <a href="view.php?id=<?= $id ?>" class="btn btn-secondary">إلغاء</a>
            </form>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>