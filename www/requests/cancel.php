<?php
/**
 * requests/cancel.php - إلغاء الطلب
 */
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';
require_once '../includes/requests_helpers.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    header('Location: index.php');
    exit;
}

$request = getRequestDetails($pdo, $id);
if (!$request) {
    setToast('⚠️ الطلب غير موجود', 'warning');
    header('Location: index.php');
    exit;
}

$canCancel = ($request['employee_id'] == ($_SESSION['employee_id'] ?? 0)) || 
             in_array($_SESSION['role'], ['admin', 'manager', 'committee']);

if (!$canCancel) {
    setToast('⚠️ غير مصرح لك بإلغاء هذا الطلب', 'warning');
    header('Location: index.php');
    exit;
}

if ($request['status'] !== 'pending') {
    setToast('⚠️ الطلب غير قابل للإلغاء (الحالة: ' . $request['status'] . ')', 'warning');
    header('Location: view.php?id=' . $id);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRFToken();
    $reason = trim($_POST['reason'] ?? 'إلغاء من قبل المستخدم');

    if (cancelRequest($pdo, $id, $_SESSION['user_id'], $reason)) {
        auditLog($pdo, 'REQUEST_CANCELLED', "إلغاء الطلب رقم $id - السبب: $reason");
        setToast('✅ تم إلغاء الطلب بنجاح', 'success');
        header('Location: index.php');
        exit;
    } else {
        setToast('❌ حدث خطأ أثناء إلغاء الطلب', 'error');
    }
}

$pageTitle = 'إلغاء الطلب';
include '../includes/header.php';
?>
<link rel="stylesheet" href="../assets/css/requests.css">

<div class="container mt-4">
    <div class="card">
        <div class="card-header bg-danger text-white">
            <h4 class="mb-0">🗑️ إلغاء الطلب</h4>
        </div>
        <div class="card-body text-center">
            <h5>هل أنت متأكد من إلغاء الطلب التالي؟</h5>
            <div class="alert alert-info">
                <p><strong>الموظف:</strong> <?= htmlspecialchars($request['employee_name'] ?? 'غير معروف') ?></p>
                <p><strong>العنوان:</strong> <?= htmlspecialchars($request['title']) ?></p>
                <p><strong>المبلغ:</strong> <?= number_format($request['requested_amount'], 2) ?> دج</p>
            </div>
            <form method="POST">
                <?= csrfField() ?>
                <div class="form-group mb-3">
                    <label>سبب الإلغاء (اختياري)</label>
                    <input type="text" name="reason" class="form-control" placeholder="سبب الإلغاء" value="إلغاء من قبل المستخدم">
                </div>
                <button type="submit" class="btn btn-danger">🗑️ تأكيد الإلغاء</button>
                <a href="view.php?id=<?= $id ?>" class="btn btn-secondary">إلغاء</a>
            </form>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>