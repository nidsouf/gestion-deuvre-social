<?php
/**
 * requests/approve.php - الموافقة على الطلب مع تعديل المبلغ والمدة
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
    setToast('⚠️ الطلب غير قابل للموافقة', 'warning');
    header('Location: index.php');
    exit;
}

$grants = $pdo->query("SELECT id, name FROM grants ORDER BY name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRFToken();

    $approvedAmount = (float)$_POST['approved_amount'];
    $approvedMonths = (int)$_POST['approved_months'];
    $decision = trim($_POST['committee_decision']);
    $grantId = isset($_POST['grant_id']) ? (int)$_POST['grant_id'] : null;
    $sourceId = isset($_POST['source_id']) ? (int)$_POST['source_id'] : null;
    $comment = trim($_POST['comment'] ?? '');

    $errors = [];
    if ($approvedAmount <= 0) $errors[] = 'المبلغ الموافق عليه يجب أن يكون موجباً';
    if (($request['request_type'] === 'loan' || $request['request_type'] === 'deduction') && $approvedMonths <= 0) $errors[] = 'عدد الأشهر مطلوب';
    if (empty($decision)) $errors[] = 'قرار اللجنة مطلوب';
    if ($request['request_type'] === 'grant' && $grantId === null) {
        $grantId = $request['grant_id'] ?? null;
        if (!$grantId) $errors[] = 'نوع المنحة مطلوب';
    }

    if (empty($errors)) {
        if (approveRequest($pdo, $id, $_SESSION['user_id'], $approvedAmount, $approvedMonths, $decision, $comment, $grantId, $sourceId)) {
            auditLog($pdo, 'REQUEST_APPROVED', "الموافقة على الطلب رقم $id");
            setToast('✅ تمت الموافقة على الطلب', 'success');
            header('Location: index.php');
            exit;
        } else {
            setToast('❌ حدث خطأ أثناء الموافقة', 'error');
        }
    } else {
        setToast('⚠️ ' . implode(' - ', $errors), 'warning');
    }
}

$pageTitle = 'الموافقة على الطلب';
include '../includes/header.php';
?>
<link rel="stylesheet" href="../assets/css/requests.css">

<div class="container mt-4">
    <h2>✅ الموافقة على الطلب</h2>

    <div class="card mb-4">
        <div class="card-body">
            <p><strong>الموظف:</strong> <?= htmlspecialchars($request['employee_name'] ?? 'غير معروف') ?></p>
            <p><strong>النوع:</strong> <?= ['loan' => 'سلفة', 'grant' => 'منحة', 'deduction' => 'اقتطاع'][$request['request_type']] ?></p>
            <p><strong>العنوان:</strong> <?= htmlspecialchars($request['title']) ?></p>
            <p><strong>المبلغ المطلوب:</strong> <?= number_format($request['requested_amount'], 2) ?> دج</p>
            <p><strong>المدة المطلوبة:</strong> <?= $request['requested_months'] ?> شهر</p>
        </div>
    </div>

    <div class="card">
        <div class="card-header bg-success text-white">
            <h5 class="mb-0">📝 تحديد الموافقة</h5>
        </div>
        <div class="card-body">
            <form method="POST">
                <?= csrfField() ?>

                <?php if ($request['request_type'] === 'grant'): ?>
                <div class="form-group mb-3">
                    <label>نوع المنحة</label>
                    <select name="grant_id" class="form-control" required>
                        <option value="">-- اختر --</option>
                        <?php foreach ($grants as $g): ?>
                            <option value="<?= $g['id'] ?>" <?= ($request['grant_id'] == $g['id']) ? 'selected' : '' ?>><?= htmlspecialchars($g['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="form-group mb-3">
                    <label>المبلغ الموافق عليه (دج)</label>
                    <input type="number" step="0.01" name="approved_amount" class="form-control" value="<?= $request['requested_amount'] ?>" required>
                </div>

                <?php if ($request['request_type'] !== 'grant'): ?>
                <div class="form-group mb-3">
                    <label>عدد الأشهر</label>
                    <input type="number" name="approved_months" class="form-control" value="<?= $request['requested_months'] ?: 1 ?>" min="1" required>
                </div>
                <?php endif; ?>

                <div class="form-group mb-3">
                    <label>قرار اللجنة</label>
                    <input type="text" name="committee_decision" class="form-control" placeholder="مثال: الموافقة على الطلب بكامل المبلغ" required>
                </div>

                <div class="form-group mb-3">
                    <label>تعليق (اختياري)</label>
                    <textarea name="comment" class="form-control" rows="3" placeholder="أي ملاحظات إضافية"></textarea>
                </div>

                <button type="submit" class="btn btn-success">✅ تأكيد الموافقة</button>
                <a href="view.php?id=<?= $id ?>" class="btn btn-secondary">إلغاء</a>
            </form>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>