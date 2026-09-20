<?php
/**
 * requests/google_requests.php - عرض طلبات Google Forms
 */
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';
require_once '../includes/requests_helpers.php';

if (!in_array($_SESSION['role'] ?? '', ['admin', 'manager', 'committee'])) {
    setToast('⚠️ غير مصرح لك بهذه الصفحة', 'warning');
    header('Location: index.php');
    exit;
}

$filters = [
    'status' => $_GET['status'] ?? 'all',
    'type' => $_GET['type'] ?? 'all',
    'source' => 'google_form',
    'search' => $_GET['search'] ?? ''
];

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

$requests = getRequestsList($pdo, $filters, $perPage, $offset);
$total = countRequests($pdo, $filters);
$totalPages = ceil($total / $perPage);

$stats = [
    'total' => $total,
    'pending' => 0,
    'reviewing' => 0,
    'approved' => 0,
    'rejected' => 0
];
foreach ($requests as $r) {
    if (isset($stats[$r['status']])) {
        $stats[$r['status']]++;
    }
}

$pageTitle = 'طلبات Google Forms';
include '../includes/header.php';
?>

<link rel="stylesheet" href="../assets/css/requests.css">

<div class="container mt-4">
    <h2>📋 طلبات Google Forms</h2>

    <div class="stats-grid">
        <div class="stat-card total">
            <div class="stat-label">📊 الإجمالي</div>
            <div class="stat-value"><?= $stats['total'] ?></div>
        </div>
        <div class="stat-card pending">
            <div class="stat-label">⏳ قيد الانتظار</div>
            <div class="stat-value"><?= $stats['pending'] ?></div>
        </div>
        <div class="stat-card reviewing">
            <div class="stat-label">🔍 قيد الدراسة</div>
            <div class="stat-value"><?= $stats['reviewing'] ?></div>
        </div>
        <div class="stat-card approved">
            <div class="stat-label">✅ موافق</div>
            <div class="stat-value"><?= $stats['approved'] ?></div>
        </div>
        <div class="stat-card rejected">
            <div class="stat-label">❌ مرفوض</div>
            <div class="stat-value"><?= $stats['rejected'] ?></div>
        </div>
    </div>

    <div class="filter-section">
        <form method="GET" class="filter-form">
            <div class="filter-group">
                <label>الحالة</label>
                <select name="status">
                    <option value="all">الكل</option>
                    <option value="pending" <?= $filters['status'] === 'pending' ? 'selected' : '' ?>>قيد الانتظار</option>
                    <option value="reviewing" <?= $filters['status'] === 'reviewing' ? 'selected' : '' ?>>قيد الدراسة</option>
                    <option value="approved" <?= $filters['status'] === 'approved' ? 'selected' : '' ?>>موافق</option>
                    <option value="rejected" <?= $filters['status'] === 'rejected' ? 'selected' : '' ?>>مرفوض</option>
                </select>
            </div>
            <div class="filter-group">
                <label>النوع</label>
                <select name="type">
                    <option value="all">الكل</option>
                    <option value="grant" <?= $filters['type'] === 'grant' ? 'selected' : '' ?>>منحة</option>
                    <option value="loan" <?= $filters['type'] === 'loan' ? 'selected' : '' ?>>سلفة</option>
                    <option value="deduction" <?= $filters['type'] === 'deduction' ? 'selected' : '' ?>>اقتطاع</option>
                </select>
            </div>
            <div class="filter-group">
                <label>بحث</label>
                <input type="text" name="search" placeholder="اسم الموظف" value="<?= htmlspecialchars($filters['search']) ?>">
            </div>
            <button type="submit" class="btn-filter">🔍 بحث</button>
            <a href="google_requests.php" class="btn-reset">🗑️ إعادة تعيين</a>
        </form>
    </div>

    <div class="table-responsive">
        <table class="requests-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>الموظف</th>
                    <th>النوع</th>
                    <th>العنوان</th>
                    <th>المبلغ</th>
                    <th>الحالة</th>
                    <th>التاريخ</th>
                    <th>الإجراءات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($requests)): ?>
                    <tr><td colspan="8" class="text-center py-4">لا توجد طلبات من Google Forms</td></tr>
                <?php else: 
                    $i = $offset + 1;
                    foreach ($requests as $r):
                ?>
                    <tr>
                        <td><?= $i++ ?></td>
                        <td>
                            <?= htmlspecialchars($r['employee_name'] ?? 'غير معروف') ?>
                            <?php if ($r['employee_id']): ?>
                                <br><small class="text-muted">ID: <?= $r['employee_id'] ?></small>
                            <?php endif; ?>
                        </td>
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
                            <div class="action-buttons">
                                <a href="view.php?id=<?= $r['id'] ?>" class="btn-sm btn-view">📄 عرض</a>
                                <?php if (in_array($_SESSION['role'] ?? '', ['admin', 'manager', 'committee'])): ?>
                                    <?php if ($r['status'] === 'pending' || $r['status'] === 'reviewing'): ?>
                                        <a href="review.php?id=<?= $r['id'] ?>" class="btn-sm btn-review">🔍 دراسة</a>
                                    <?php endif; ?>
                                    <?php if ($r['status'] === 'approved' && !$r['executed']): ?>
                                        <a href="execute.php?id=<?= $r['id'] ?>" class="btn-sm btn-execute">⚡ تنفيذ</a>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
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