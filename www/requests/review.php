<?php
/**
 * requests/review.php - دراسة الطلب (نقل إلى حالة المراجعة)
 * الصلاحية: فقط المدير، المدير العام، أو اللجنة
 * الحالة المسموح بها: pending فقط
 */
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';
require_once '../includes/requests_helpers.php';

// ============================================================
// 1. التحقق من الصلاحية
// ============================================================
$userRole = $_SESSION['role'] ?? 'employee';
if (!in_array($userRole, ['admin', 'manager', 'committee'])) {
    setToast('⚠️ غير مصرح لك بهذه الصفحة', 'warning');
    header('Location: index.php');
    exit;
}

// ============================================================
// 2. التحقق من وجود الطلب
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
// 3. التحقق من أن الحالة مسموحة (pending فقط)
// ============================================================
if ($request['status'] !== 'pending') {
    setToast('⚠️ الطلب غير قابل للدراسة (الحالة الحالية: ' . $request['status'] . ')', 'warning');
    header('Location: view.php?id=' . $id);
    exit;
}

// ============================================================
// 4. معالجة POST
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRFToken();

    $comment = trim($_POST['comment'] ?? '');

    if (empty($comment)) {
        setToast('⚠️ يجب إضافة تعليق أثناء الدراسة', 'warning');
    } else {
        try {
            $pdo->beginTransaction();

            // تحديث الحالة إلى reviewing
            $stmt = $pdo->prepare("
                UPDATE requests 
                SET status = 'reviewing', 
                    reviewed_by = ?, 
                    reviewed_at = datetime('now'),
                    updated_at = datetime('now')
                WHERE id = ?
            ");
            $stmt->execute([$_SESSION['user_id'], $id]);

            // إضافة تعليق
            $stmt = $pdo->prepare("
                INSERT INTO request_comments (request_id, user_id, comment)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$id, $_SESSION['user_id'], $comment]);

            $pdo->commit();

            auditLog($pdo, 'REQUEST_REVIEW', "دراسة الطلب رقم $id");
            setToast('✅ تم تحويل الطلب إلى قيد الدراسة', 'success');
            header('Location: view.php?id=' . $id);
            exit;

        } catch (Exception $e) {
            $pdo->rollBack();
            setToast('❌ حدث خطأ: ' . $e->getMessage(), 'error');
        }
    }
}

// ============================================================
// 5. عرض الصفحة
// ============================================================
$pageTitle = 'دراسة الطلب';
include '../includes/header.php';
?>

<link rel="stylesheet" href="../assets/css/requests.css">

<div class="container mt-4">
    <h2>🔍 دراسة الطلب</h2>

    <!-- معلومات الطلب -->
    <div class="card mb-4">
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <p><strong>الموظف:</strong> <?= htmlspecialchars($request['employee_name'] ?? 'غير معروف') ?></p>
                    <p><strong>رقم الحساب:</strong> <?= htmlspecialchars($request['account_number'] ?? '—') ?></p>
                    <p><strong>النوع:</strong> <?= ['loan' => 'سلفة', 'grant' => 'منحة', 'deduction' => 'اقتطاع'][$request['request_type']] ?></p>
                </div>
                <div class="col-md-6">
                    <p><strong>العنوان:</strong> <?= htmlspecialchars($request['title']) ?></p>
                    <p><strong>المبلغ المطلوب:</strong> <?= number_format($request['requested_amount'], 2) ?> دج</p>
                    <p><strong>عدد الأشهر:</strong> <?= $request['requested_months'] ?: '—' ?></p>
                    <p><strong>الحالة الحالية:</strong> <span class="status-badge status-<?= $request['status'] ?>"><?= $request['status'] ?></span></p>
                </div>
            </div>
            <?php if ($request['description']): ?>
                <div class="mt-3">
                    <strong>الوصف:</strong>
                    <p class="text-muted"><?= nl2br(htmlspecialchars($request['description'])) ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- نموذج الدراسة -->
    <div class="card">
        <div class="card-header bg-info text-white">
            <h5 class="mb-0">📝 تعليق الدراسة</h5>
        </div>
        <div class="card-body">
            <form method="POST">
                <?= csrfField() ?>
                <div class="form-group mb-3">
                    <label for="comment">ملاحظات الدراسة</label>
                    <textarea name="comment" id="comment" class="form-control" rows="4" placeholder="اكتب ملاحظاتك حول الطلب..." required></textarea>
                </div>
                <button type="submit" class="btn btn-info">🔍 تحويل إلى قيد الدراسة</button>
                <a href="view.php?id=<?= $id ?>" class="btn btn-secondary">إلغاء</a>
            </form>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>