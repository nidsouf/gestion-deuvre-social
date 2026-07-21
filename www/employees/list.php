<?php
/**
 * employees/list.php - قائمة الموظفين (مع فلتر البطاقات)
 */
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';
require_once '../includes/common_helpers.php';

// ========== المعاملات ==========
$search = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';
$category_filter = isset($_GET['category']) ? $_GET['category'] : 'all';

// ========== بناء الاستعلام ==========
$sql = "SELECT * FROM employees WHERE 1=1";
$params = [];

if ($search) {
    $sql .= " AND name LIKE :search";
    $params[':search'] = "%$search%";
}

// فلتر الفئة (عند الضغط على البطاقة)
if ($category_filter === 'permanent') {
    $sql .= " AND category = 'Permanent'";
} elseif ($category_filter === 'contract') {
    $sql .= " AND category = 'Contract'";
}
// 'all' لا يضيف شرطاً

$sql .= " ORDER BY name ASC";

$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->execute();
$employees = $stmt->fetchAll();

// ========== الإحصائيات (جميع الموظفين بدون فلتر) ==========
$allEmployees = $pdo->query("SELECT * FROM employees")->fetchAll();
$totalAll = count($allEmployees);
$totalPermanent = count(array_filter($allEmployees, fn($e) => $e['category'] === 'Permanent'));
$totalContract = count(array_filter($allEmployees, fn($e) => $e['category'] === 'Contract'));

// ========== إحصائيات المعروضين (بعد الفلتر) ==========
$displayedCount = count($employees);

$csrf_token = generateCSRFToken();
include '../includes/header.php';
?>
<link rel="stylesheet" href="../assets/css/employees.css">

<div class="employees-container">
    <div class="employees-header">
        <h2>👥 قائمة الموظفين <?= $category_filter != 'all' ? '('.($category_filter=='permanent'?'الدائمين':'المتعاقدين').')' : '' ?></h2>
        <a href="add.php" class="btn-add">➕ إضافة موظف جديد</a>
    </div>

    <!-- ========== بطاقات الإحصائيات (قابلة للنقر كفلاتر) ========== -->
    <div class="stats-grid">
        <a href="?category=all<?= $search ? '&search='.urlencode($search) : '' ?>" 
           class="stat-card total <?= $category_filter == 'all' ? 'active-card' : '' ?>">
            <div class="stat-icon">👥</div>
            <div class="stat-label">إجمالي الموظفين</div>
            <div class="stat-value"><?= number_format($totalAll) ?></div>
        </a>
        <a href="?category=permanent<?= $search ? '&search='.urlencode($search) : '' ?>" 
           class="stat-card permanent <?= $category_filter == 'permanent' ? 'active-card' : '' ?>">
            <div class="stat-icon">👔</div>
            <div class="stat-label">دائم</div>
            <div class="stat-value"><?= number_format($totalPermanent) ?></div>
        </a>
        <a href="?category=contract<?= $search ? '&search='.urlencode($search) : '' ?>" 
           class="stat-card contract <?= $category_filter == 'contract' ? 'active-card' : '' ?>">
            <div class="stat-icon">👕</div>
            <div class="stat-label">متعاقد</div>
            <div class="stat-value"><?= number_format($totalContract) ?></div>
        </a>
    </div>

    <!-- عرض عدد النتائج المعروضة -->
    <div style="margin-bottom:15px; font-size:14px; color:#666;">
        عرض <?= number_format($displayedCount) ?> موظف<?= $displayedCount != 1 ? 'اً' : '' ?>
        <?php if ($category_filter != 'all'): ?>
            من فئة <strong><?= $category_filter == 'permanent' ? 'الدائمين' : 'المتعاقدين' ?></strong>
        <?php endif; ?>
        <?php if ($search): ?>
            (بحث: "<?= htmlspecialchars($search) ?>")
        <?php endif; ?>
    </div>

    <!-- ========== الفلاتر ========== -->
    <div class="filter-section">
        <form method="GET" class="filter-form">
            <!-- الحفاظ على فلتر الفئة عند البحث -->
            <input type="hidden" name="category" value="<?= $category_filter ?>">
            <div class="filter-group" style="flex:2;">
                <label>🔍 بحث</label>
                <input type="text" name="search" placeholder="اسم الموظف..." value="<?= htmlspecialchars($search) ?>">
            </div>
            <button type="submit" class="btn-filter">بحث</button>
            <?php if ($search || $category_filter != 'all'): ?>
                <a href="list.php" class="btn-reset">🗑️ إعادة تعيين</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- ========== الجدول ========== -->
    <div class="table-responsive">
        <table class="employees-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>الاسم</th>
                    <th>التصنيف</th>
                    <th>تاريخ التوظيف</th>
                    <th>رقم الحساب</th>
                    <th>الإجراءات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($employees)): ?>
                    <tr><td colspan="6" class="text-center">لا توجد بيانات مطابقة</td></tr>
                <?php else: ?>
                    <?php $i=1; foreach ($employees as $emp): ?>
                        <tr>
                            <td><?= $i++ ?> <small>(ID:<?= $emp['id'] ?>)</small></td>
                            <td><?= htmlspecialchars($emp['name']) ?></td>
                            <td><?= $emp['category'] == 'Permanent' ? 'دائم' : 'متعاقد' ?></td>
                            <td><?= safeFormatDate($emp['hire_date']) ?></td>
                            <td><?= htmlspecialchars($emp['account_number'] ?? '—') ?></td>
                            <td class="action-buttons">
                                <a href="edit.php?id=<?= $emp['id'] ?>" class="btn-sm btn-edit">✏️ تعديل</a>
                                <a href="deductions.php?id=<?= $emp['id'] ?>" class="btn-sm btn-view">📋 الاقتطاعات</a>
                                <button class="btn-sm btn-delete delete-btn" data-id="<?= $emp['id'] ?>" data-name="<?= htmlspecialchars($emp['name']) ?>">🗑️ حذف</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- مودال الحذف -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal-box">
        <h3>⚠️ تأكيد الحذف</h3>
        <p>هل أنت متأكد من حذف الموظف <strong id="deleteEmployeeName"></strong>؟</p>
        <p class="text-muted">سيتم حذف جميع بيانات الموظف المرتبطة.</p>
        <div class="modal-actions">
            <button class="btn-cancel" id="cancelDelete">إلغاء</button>
            <button class="btn-confirm-delete" id="confirmDelete">نعم، احذف</button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('deleteModal');
    const deleteName = document.getElementById('deleteEmployeeName');
    const confirmBtn = document.getElementById('confirmDelete');
    const cancelBtn = document.getElementById('cancelDelete');
    let currentId = null;

    document.querySelectorAll('.delete-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            currentId = this.dataset.id;
            deleteName.textContent = this.dataset.name;
            modal.classList.add('active');
        });
    });

    cancelBtn.addEventListener('click', function() {
        modal.classList.remove('active');
    });

    modal.addEventListener('click', function(e) {
        if (e.target === this) {
            modal.classList.remove('active');
        }
    });

    confirmBtn.addEventListener('click', function() {
        if (!currentId) return;
        const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';
        fetch('delete.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'id=' + currentId + '&csrf_token=' + encodeURIComponent(csrfToken)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(data.message);
                location.reload();
            } else {
                alert('خطأ: ' + data.message);
            }
        })
        .catch(() => {
            alert('حدث خطأ في الاتصال بالخادم');
        })
        .finally(() => {
            modal.classList.remove('active');
        });
    });
});
</script>

<?php include '../includes/footer.php'; ?>