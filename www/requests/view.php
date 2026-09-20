<?php
/**
 * requests/view.php - عرض تفاصيل الطلب
 */
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';
require_once '../includes/requests_helpers.php';

// ============================================================
// 1. التحقق من وجود الطلب
// ============================================================
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    setToast('⚠️ رقم الطلب غير صالح', 'warning');
    header('Location: index.php');
    exit;
}

$request = getRequestDetails($pdo, $id);
if (!$request) {
    setToast('⚠️ الطلب غير موجود', 'warning');
    header('Location: index.php');
    exit;
}

// ============================================================
// 2. التحقق من الصلاحيات
// ============================================================
$userRole = $_SESSION['role'] ?? 'employee';
$canEdit = in_array($userRole, ['admin', 'manager', 'committee']);
$isOwner = ($request['employee_id'] == ($_SESSION['employee_id'] ?? 0));

if (!$canEdit && !$isOwner) {
    setToast('⚠️ غير مصرح لك بمشاهدة هذا الطلب', 'warning');
    header('Location: index.php');
    exit;
}

// ============================================================
// 3. جلب التعليقات
// ============================================================
$comments = getRequestComments($pdo, $id);

// ============================================================
// 4. عرض الصفحة
// ============================================================
$pageTitle = 'تفاصيل الطلب';
include '../includes/header.php';
?>

<link rel="stylesheet" href="../assets/css/requests.css">

<div class="container mt-4">
    <!-- رأس الصفحة مع الأزرار -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>📄 تفاصيل الطلب #<?= $request['id'] ?></h2>
        <div>
            <a href="index.php" class="btn btn-secondary">⬅️ العودة</a>

            <?php if ($canEdit && $request['status'] === 'pending'): ?>
                <a href="review.php?id=<?= $id ?>" class="btn btn-warning">🔍 دراسة الطلب</a>
            <?php endif; ?>

            <?php if ($canEdit && ($request['status'] === 'pending' || $request['status'] === 'reviewing')): ?>
                <a href="approve.php?id=<?= $id ?>" class="btn btn-success">✅ موافقة</a>
                <a href="reject.php?id=<?= $id ?>" class="btn btn-danger">❌ رفض</a>
            <?php endif; ?>

            <?php if ($canEdit && $request['status'] === 'approved' && !$request['executed']): ?>
                <a href="execute.php?id=<?= $id ?>" class="btn btn-primary">⚡ تنفيذ</a>
            <?php endif; ?>

            <?php if (($isOwner || $canEdit) && $request['status'] === 'pending'): ?>
                <a href="cancel.php?id=<?= $id ?>" class="btn btn-danger">🗑️ إلغاء الطلب</a>
            <?php endif; ?>
        </div>
    </div>

    <!-- بطاقة معلومات الطلب -->
    <div class="card mb-4">
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <p><strong>الموظف:</strong> 
                        <?= htmlspecialchars($request['employee_name'] ?? 'غير معروف') ?>
                        <?php if ($request['employee_id']): ?>
                            <span class="text-muted">(ID: <?= $request['employee_id'] ?>)</span>
                        <?php endif; ?>
                    </p>
                    <p><strong>رقم الحساب:</strong> <?= htmlspecialchars($request['account_number'] ?? '—') ?></p>
                    <p><strong>النوع:</strong> 
                        <?= ['loan' => 'سلفة', 'grant' => 'منحة', 'deduction' => 'اقتطاع'][$request['request_type']] ?? $request['request_type'] ?>
                    </p>
                    <?php if ($request['request_type'] === 'grant' && $request['grant_name']): ?>
                        <p><strong>نوع المنحة:</strong> <?= htmlspecialchars($request['grant_name']) ?></p>
                    <?php endif; ?>
                    <?php if ($request['source_name']): ?>
                        <p><strong>المصدر:</strong> <?= htmlspecialchars($request['source_name']) ?></p>
                    <?php endif; ?>
                    <p><strong>المصدر (الوارد من):</strong> <?= $request['source'] ?? 'manual' ?></p>
                </div>
                <div class="col-md-6">
                    <p><strong>العنوان:</strong> <?= htmlspecialchars($request['title']) ?></p>
                    <p><strong>المبلغ المطلوب:</strong> <?= number_format($request['requested_amount'], 2) ?> دج</p>
                    <p><strong>عدد الأشهر:</strong> <?= $request['requested_months'] ?: '—' ?></p>
                    <p><strong>تاريخ الطلب:</strong> <?= safeFormatDate($request['requested_date']) ?></p>
                    <p><strong>الحالة:</strong> <span class="status-badge status-<?= $request['status'] ?>"><?= $request['status'] ?></span></p>
                    <?php if ($request['executed']): ?>
                        <p><strong>✅ تم التنفيذ</strong> (المرجع: <?= $request['reference_id'] ?>)</p>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($request['description']): ?>
                <div class="mt-3">
                    <strong>الوصف:</strong>
                    <p class="text-muted"><?= nl2br(htmlspecialchars($request['description'])) ?></p>
                </div>
            <?php endif; ?>

            <?php if ($request['status'] === 'approved'): ?>
                <div class="mt-3 bg-success text-white p-3 rounded">
                    <p><strong>المبلغ الموافق عليه:</strong> <?= number_format($request['approved_amount'], 2) ?> دج</p>
                    <p><strong>المدة الموافق عليها:</strong> <?= $request['approved_months'] ?> شهر</p>
                    <p><strong>قرار اللجنة:</strong> <?= htmlspecialchars($request['committee_decision'] ?? '') ?></p>
                    <p><strong>تمت الموافقة بواسطة:</strong> <?= htmlspecialchars($request['approved_by_name'] ?? '') ?> بتاريخ <?= safeFormatDate($request['approved_at']) ?></p>
                </div>
            <?php endif; ?>
            <?php if ($request['status'] === 'rejected'): ?>
                <div class="mt-3 bg-danger text-white p-3 rounded">
                    <p><strong>سبب الرفض:</strong> <?= htmlspecialchars($request['rejection_reason']) ?></p>
                    <p><strong>تم الرفض بواسطة:</strong> <?= htmlspecialchars($request['reviewed_by_name'] ?? '') ?> بتاريخ <?= safeFormatDate($request['reviewed_at']) ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- التعليقات -->
    <h4>💬 التعليقات</h4>
    <div class="card mb-4">
        <div class="card-body">
            <?php if (empty($comments)): ?>
                <p class="text-muted">لا توجد تعليقات</p>
            <?php else: ?>
                <?php foreach ($comments as $c): ?>
                    <div class="comment-item">
                        <strong><?= htmlspecialchars($c['user_name'] ?? 'نظام') ?></strong>
                        <small class="text-muted"><?= safeFormatDate($c['created_at']) ?></small>
                        <p><?= nl2br(htmlspecialchars($c['comment'])) ?></p>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- إضافة تعليق جديد -->
    <?php if ($canEdit || $isOwner): ?>
    <div class="card">
        <div class="card-header bg-light">
            <h5 class="mb-0">✏️ إضافة تعليق</h5>
        </div>
        <div class="card-body">
            <form method="POST" action="add_comment.php">
                <?= csrfField() ?>
                <input type="hidden" name="request_id" value="<?= $id ?>">
                <div class="form-group">
                    <textarea name="comment" class="form-control" rows="3" placeholder="أضف تعليقك هنا..." required></textarea>
                </div>
                <button type="submit" class="btn btn-primary mt-2">💬 إرسال</button>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include '../includes/footer.php'; ?>