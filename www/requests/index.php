<?php
/**
 * requests/index.php - لوحة تحكم طلبات الموظفين
 */
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';
require_once '../includes/requests_helpers.php';

$stats = getRequestsStats($pdo);

$filters = [
    'status' => $_GET['status'] ?? 'all',
    'type' => $_GET['type'] ?? 'all',
    'source' => $_GET['source'] ?? 'all',
    'search' => $_GET['search'] ?? '',
    'from_date' => $_GET['from_date'] ?? '',
    'to_date' => $_GET['to_date'] ?? '',
    'employee_id' => $_GET['employee_id'] ?? 0
];

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 20;
$offset = ($page - 1) * $perPage;

$requests = getRequestsList($pdo, $filters, $perPage, $offset);
$total = countRequests($pdo, $filters);
$totalPages = ceil($total / $perPage);

$employees = $pdo->query("SELECT id, name FROM employees ORDER BY name")->fetchAll();

$pageTitle = 'إدارة الطلبات';
include '../includes/header.php';
?>
<link rel="stylesheet" href="../assets/css/requests.css">

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>📋 إدارة طلبات الموظفين</h2>
        <a href="add.php" class="btn btn-primary">➕ تقديم طلب جديد</a>
    </div>

    <!-- بطاقات الإحصائيات -->
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
            <div class="stat-label">✅ تمت الموافقة</div>
            <div class="stat-value"><?= $stats['approved'] ?></div>
        </div>
        <div class="stat-card rejected">
            <div class="stat-label">❌ مرفوض</div>
            <div class="stat-value"><?= $stats['rejected'] ?></div>
        </div>
        <div class="stat-card cancelled">
            <div class="stat-label">🗑️ ملغي</div>
            <div class="stat-value"><?= $stats['cancelled'] ?></div>
        </div>
    </div>

    <!-- الفلاتر -->
    <div class="filter-section">
        <form method="GET" class="filter-form">
            <div class="filter-group">
                <label>الحالة</label>
                <select name="status">
                    <option value="all">الكل</option>
                    <option value="pending" <?= $filters['status'] === 'pending' ? 'selected' : '' ?>>قيد الانتظار</option>
                    <option value="reviewing" <?= $filters['status'] === 'reviewing' ? 'selected' : '' ?>>قيد الدراسة</option>
                    <option value="approved" <?= $filters['status'] === 'approved' ? 'selected' : '' ?>>تمت الموافقة</option>
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
            <div class="filter-group">
                <label>المصدر</label>
                <select name="source">
                    <option value="all">الكل</option>
                    <option value="manual" <?= $filters['source'] === 'manual' ? 'selected' : '' ?>>يدوي</option>
                    <option value="google_form" <?= $filters['source'] === 'google_form' ? 'selected' : '' ?>>Google Forms</option>
                </select>
            </div>
            <div class="filter-group">
                <label>الموظف</label>
                <select name="employee_id">
                    <option value="0">جميع الموظفين</option>
                    <?php foreach ($employees as $emp): ?>
                        <option value="<?= $emp['id'] ?>" <?= $filters['employee_id'] == $emp['id'] ? 'selected' : '' ?>><?= htmlspecialchars($emp['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>بحث</label>
                <input type="text" name="search" placeholder="اسم الموظف أو عنوان الطلب" value="<?= htmlspecialchars($filters['search']) ?>">
            </div>
            <button type="submit" class="btn-filter">🔍 بحث</button>
            <a href="index.php" class="btn-reset">🗑️ إعادة تعيين</a>
        </form>
    </div>

    <!-- الجدول -->
    <div class="table-responsive">
        <table class="requests-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>الموظف</th>
                    <th>النوع</th>
                    <th>العنوان</th>
                    <th>المبلغ المطلوب</th>
                    <th>الحالة</th>
                    <th>المصدر</th>
                    <th>تاريخ الطلب</th>
                    <th>الإجراءات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($requests)): ?>
                    <tr><td colspan="9" class="text-center py-4">لا توجد طلبات</td></tr>
                <?php else: 
                    $i = $offset + 1;
                    foreach ($requests as $r):
                        $statusClass = [
                            'pending' => 'status-pending',
                            'reviewing' => 'status-reviewing',
                            'approved' => 'status-approved',
                            'rejected' => 'status-rejected',
                            'cancelled' => 'status-cancelled'
                        ][$r['status']] ?? '';
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
                        <td><span class="status-badge <?= $statusClass ?>"><?= $r['status'] ?></span></td>
                        <td><span class="badge badge-light"><?= $r['source'] ?></span></td>
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
                                    <?php if ($r['status'] === 'pending'): ?>
                                        <a href="cancel.php?id=<?= $r['id'] ?>" class="btn-sm btn-danger">🗑️ إلغاء</a>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if (($r['employee_id'] == ($_SESSION['employee_id'] ?? 0)) && $r['status'] === 'pending'): ?>
                                    <a href="cancel.php?id=<?= $r['id'] ?>" class="btn-sm btn-danger">🗑️ إلغاء</a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- باجيناشن -->
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