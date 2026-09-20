<?php
/**
 * employees/phone_numbers/index.php - قائمة أرقام الهواتف
 */

// ============================================================
// 1. بدء الجلسة والتحقق من الصلاحية
// ============================================================
session_start();
require_once '../../includes/auth_check.php';

// ============================================================
// 2. الاتصال بقاعدة البيانات والدوال المساعدة
// ============================================================
require_once '../../config/database.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';
require_once '../../includes/employee_phone_helpers.php';

// ============================================================
// 3. التحقق من الصلاحية (إضافي للتأكيد)
// ============================================================
if (!in_array($_SESSION['role'] ?? '', ['admin', 'manager', 'committee'])) {
    setToast('⚠️ غير مصرح لك بهذه الصفحة', 'warning');
    header('Location: ../index.php');
    exit;
}

// ============================================================
// 4. جلب البيانات والفلاتر
// ============================================================
$filters = [
    'employee_id' => isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0,
    'search' => isset($_GET['search']) ? trim($_GET['search']) : '',
    'is_active' => isset($_GET['is_active']) && $_GET['is_active'] !== '' ? (int)$_GET['is_active'] : ''
];

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 30;
$offset = ($page - 1) * $perPage;

$phones = getEmployeePhoneNumbers($pdo, $filters, $perPage, $offset);
$total = countEmployeePhoneNumbers($pdo, $filters);
$totalPages = ceil($total / $perPage);
$stats = getEmployeePhoneStats($pdo);

$employees = $pdo->query("SELECT id, name FROM employees ORDER BY name")->fetchAll();

// ============================================================
// 5. تضمين الهيدر
// ============================================================
$pageTitle = 'إدارة أرقام الهواتف';
require_once '../../includes/header.php';
?>

<!-- ✅ إصلاح المسار النسبي للـ CSS الخاص بالهيدر -->
<link rel="stylesheet" href="/assets/css/header.css">
<link rel="stylesheet" href="../../assets/css/employee_phones.css">

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>📱 إدارة أرقام الهواتف</h2>
        <a href="add.php" class="btn btn-primary">➕ إضافة رقم جديد</a>
        <a href="generate_installments.php" class="btn btn-info">📅 توليد أقساط الهواتف</a>
        <!-- تم إزالة زر توليد الهواتف نهائياً -->
    </div>

    <!-- بطاقات الإحصائيات -->
    <div class="stats-grid">
        <div class="stat-card total">
            <div class="stat-label">📊 الإجمالي</div>
            <div class="stat-value"><?= $stats['total'] ?></div>
        </div>
        <div class="stat-card active">
            <div class="stat-label">✅ نشط</div>
            <div class="stat-value"><?= $stats['active'] ?></div>
        </div>
        <div class="stat-card inactive">
            <div class="stat-label">⏸️ غير نشط</div>
            <div class="stat-value"><?= $stats['inactive'] ?></div>
        </div>
        <div class="stat-card amount">
            <div class="stat-label">💰 إجمالي الاقتطاع الشهري</div>
            <div class="stat-value"><?= number_format($stats['total_monthly'], 2) ?> دج</div>
        </div>
    </div>

    <!-- الفلاتر -->
    <div class="filter-section">
        <form method="GET" class="filter-form">
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
                <label>الحالة</label>
                <select name="is_active">
                    <option value="">الكل</option>
                    <option value="1" <?= $filters['is_active'] === '1' ? 'selected' : '' ?>>نشط</option>
                    <option value="0" <?= $filters['is_active'] === '0' ? 'selected' : '' ?>>غير نشط</option>
                </select>
            </div>
            <div class="filter-group">
                <label>بحث</label>
                <input type="text" name="search" placeholder="رقم الهاتف أو اسم الموظف" value="<?= htmlspecialchars($filters['search']) ?>">
            </div>
            <button type="submit" class="btn-filter">🔍 بحث</button>
            <a href="index.php" class="btn-reset">🗑️ إعادة تعيين</a>
        </form>
    </div>

    <!-- الجدول -->
    <div class="table-responsive">
        <table class="phones-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>الموظف</th>
                    <th>رقم الهاتف</th>
                    <th>المبلغ الشهري (دج)</th>
                    <th>الحالة</th>
                    <th>تاريخ الإضافة</th>
                    <th>الإجراءات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($phones)): ?>
                    <tr><td colspan="7" class="text-center py-4">لا توجد أرقام مسجلة</td></tr>
                <?php else: 
                    $i = $offset + 1;
                    foreach ($phones as $p):
                ?>
                    <tr>
                        <td><?= $i++ ?></td>
                        <td><?= htmlspecialchars($p['employee_name']) ?></td>
                        <td><?= htmlspecialchars($p['phone_number']) ?></td>
                        <td><?= number_format($p['monthly_amount'], 2) ?> دج</td>
                        <td>
                            <span class="status-badge <?= $p['is_active'] ? 'status-active' : 'status-inactive' ?>">
                                <?= $p['is_active'] ? '✅ نشط' : '⏸️ غير نشط' ?>
                            </span>
                        </td>
                        <td><?= safeFormatDate($p['created_at']) ?></td>
                        <td>
                            <div class="action-buttons">
                                <a href="edit.php?id=<?= $p['id'] ?>" class="btn-sm btn-edit">✏️ تعديل</a>
                                <?php if ($p['is_active']): ?>
                                    <a href="deactivate.php?id=<?= $p['id'] ?>" class="btn-sm btn-danger" onclick="return confirm('هل أنت متأكد من إلغاء تنشيط هذا الرقم؟')">⏸️ إلغاء تنشيط</a>
                                <?php else: ?>
                                    <a href="activate.php?id=<?= $p['id'] ?>" class="btn-sm btn-success" onclick="return confirm('هل أنت متأكد من تفعيل هذا الرقم؟')">▶️ تفعيل</a>
                                <?php endif; ?>
                                <a href="delete.php?id=<?= $p['id'] ?>" class="btn-sm btn-delete" onclick="return confirm('هل أنت متأكد من حذف هذا الرقم نهائياً؟')">🗑️ حذف</a>
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

<?php require_once '../../includes/footer.php'; ?>