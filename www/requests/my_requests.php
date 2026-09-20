<?php
/**
 * requests/my_requests.php - عرض طلبات الموظف الحالي
 */
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';
require_once '../includes/requests_helpers.php';

if (!isset($_SESSION['employee_id'])) {
    setToast('⚠️ لا يوجد موظف مرتبط بحسابك', 'warning');
    header('Location: index.php');
    exit;
}

$employeeId = $_SESSION['employee_id'];

$filters = [
    'status' => $_GET['status'] ?? 'all',
    'type' => $_GET['type'] ?? 'all',
    'employee_id' => $employeeId
];

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

$requests = getRequestsList($pdo, $filters, $perPage, $offset);
$total = countRequests($pdo, $filters);
$totalPages = ceil($total / $perPage);

$pageTitle = 'طلباتي';
include '../includes/header.php';
?>
<link rel="stylesheet" href="../assets/css/requests.css">

<div class="container mt-4">
    <h2>📋 طلباتي</h2>

    <div class="filter-section mb-3">
        <form method="GET" class="filter-form">
            <div class="filter-group">
                <label>الحالة</label>
                <select name="status">
                    <option value="all">الكل</option>
                    <option value="pending" <?= $filters['status'] === 'pending' ? 'selected' : '' ?>>قيد الانتظار</option>
                    <option value="reviewing" <?= $filters['status'] === 'reviewing' ? 'selected' : '' ?>>قيد الدراسة</option>
                    <option value="approved" <?= $filters['status'] === 'approved' ? 'selected' : '' ?>>موافق</option>
                    <option value="rejected" <?= $filters['status'] === 'rejected' ? 'selected' : '' ?>>مرفوض</option>
                    <option value="cancelled" <?= $filters['status'] === 'cancelled' ? 'selected' : '' ?>>ملغي</option>
                </select>
            </div>
            <div class="filter-group">
                <label>النوع</label>
                <select name="type">
                    <option value="all">الكل</option>
                    <option value="loan" <?= $filters['type'] === 'loan' ? 'selected' : '' ?>>سلفة</option>
                    <option value="grant" <?= $filters['type'] === 'grant' ? 'selected' : '' ?>>منحة</option>
                    <option value="deduction" <?= $filters['type'] === 'deduction' ? 'selected' : '' ?>>اقتطاع</option>
                </select>
            </div>
            <button type="submit" class="btn-filter">🔍 بحث</button>
            <a href="my_requests.php" class="btn-reset">🗑️ إعادة تعيين</a>
        </form>
    </div>

    <div class="table-responsive">
        <table class="requests-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>النوع</th>
                    <th>العنوان</th>
                    <th>المبلغ</th>
                    <th>الحالة</th>
                    <th>تاريخ الطلب</th>
                    <th>الإجراءات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($requests)): ?>
                    <tr><td colspan="7" class="text-center py-4">لا توجد طلبات</td></tr>
                <?php else: 
                    $i = $offset + 1;
                    foreach ($requests as $r):
                ?>
                    <tr>
                        <td><?= $i++ ?></td>
                        <td>
                            <span class="badge-type <?= $r['request_type'] ?>">
                                <?= ['loan' => 'سلفة', 'grant' => 'منحة', 'deduction' => 'اقتطاع'][$r['request_type']] ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($r['title']) ?></td>
                        <td><?= number_format($r['requested_amount'], 2) ?> دج</td>
                        <td><span class="status-badge status-<?= $r['status'] ?>"><?= $r['status'] ?></span></td>
                        <td><?= safeFormatDate($r['requested_date']) ?></td>
                        <td>
                            <a href="view.php?id=<?= $r['id'] ?>" class="btn-sm btn-view">📄 عرض</a>
                            <?php if ($r['status'] === 'pending'): ?>
                                <a href="cancel.php?id=<?= $r['id'] ?>" class="btn-sm btn-danger">🗑️ إلغاء</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
        <nav class="mt-4">
            <ul class="pagination justify-content-center">
                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                    <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(array_merge($filters, ['page' => $p])) ?>"><?= $p ?></a>
                    </li>
                <?php endfor; ?>
            </ul>
        </nav>
    <?php endif; ?>
</div>

<?php include '../includes/footer.php'; ?>