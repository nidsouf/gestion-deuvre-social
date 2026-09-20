<?php
/**
 * requests/execute.php - تنفيذ الطلب (متوافق مع PHP Desktop)
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
if (!$request || $request['status'] !== 'approved' || $request['executed'] == 1) {
    setToast('⚠️ الطلب غير قابل للتنفيذ', 'warning');
    header('Location: index.php');
    exit;
}

$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm'])) {
    requireCSRFToken();
    $result = executeRequest($pdo, $id, $_SESSION['user_id']);
    if ($result['success']) {
        setToast('✅ ' . $result['message'], 'success');
        header('Location: view.php?id=' . $id);
        exit;
    } else {
        $errorMessage = $result['message'];
    }
}

$pageTitle = 'تنفيذ الطلب';
include '../includes/header.php';
?>
<link rel="stylesheet" href="../assets/css/requests.css">

<div class="container mt-4">
    <div class="card">
        <div class="card-header bg-success text-white">
            <h4 class="mb-0">⚡ تنفيذ الطلب</h4>
        </div>
        <div class="card-body">
            <?php if (!empty($errorMessage)): ?>
                <div class="alert alert-danger">
                    <strong>❌ خطأ في التنفيذ:</strong><br>
                    <?= htmlspecialchars($errorMessage) ?>
                </div>
            <?php endif; ?>

            <h5 class="text-center mb-3">هل أنت متأكد من تنفيذ الطلب التالي؟</h5>
            
            <div class="alert alert-info">
                <p><strong>الموظف:</strong> <?= htmlspecialchars($request['employee_name'] ?? 'غير معروف') ?></p>
                <p><strong>النوع:</strong> <?= ['loan' => 'سلفة', 'grant' => 'منحة', 'deduction' => 'اقتطاع'][$request['request_type']] ?></p>
                <p><strong>المبلغ الموافق عليه:</strong> <?= number_format($request['approved_amount'], 2) ?> دج</p>
                <p><strong>المدة:</strong> <?= $request['approved_months'] ?> شهر</p>
                <?php if ($request['request_type'] === 'grant'): ?>
                    <p><strong>نوع المنحة:</strong> <?= htmlspecialchars($request['grant_name'] ?? 'غير محدد') ?></p>
                <?php else: ?>
                    <p><strong>المصدر:</strong> <?= htmlspecialchars($request['source_name'] ?? 'غير محدد') ?></p>
                <?php endif; ?>
            </div>

            <div class="text-center mt-4">
                <button type="button" class="btn btn-success btn-lg" data-bs-toggle="modal" data-bs-target="#confirmExecuteModal">
                    ✅ نعم، تنفيذ
                </button>
                <a href="view.php?id=<?= $id ?>" class="btn btn-secondary btn-lg">إلغاء</a>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================
     Modal تأكيد التنفيذ (بديل confirm())
============================================================ -->
<div class="modal fade" id="confirmExecuteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">⚠️ تأكيد التنفيذ</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2">هل أنت متأكد من تنفيذ هذا الطلب؟</p>
                <div class="alert alert-warning mb-0">
                    <small>
                        ⚠️ سيتم إنشاء 
                        <strong><?= $request['request_type'] === 'grant' ? 'منحة جديدة' : ($request['request_type'] === 'loan' ? 'سلفة جديدة' : 'اقتطاع جديد') ?></strong>
                        بمبلغ <strong><?= number_format($request['approved_amount'], 2) ?> دج</strong>
                        وخصمه من الميزانية.
                        <br>هذه العملية <strong>لا يمكن التراجع عنها</strong>.
                    </small>
                </div>
            </div>
            <div class="modal-footer">
                <form method="POST" style="display:inline;">
                    <?= csrfField() ?>
                    <input type="hidden" name="confirm" value="1">
                    <button type="submit" class="btn btn-success">✅ نعم، تنفيذ</button>
                </form>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>