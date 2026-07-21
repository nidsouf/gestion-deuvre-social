<?php
/**
 * deductions/list.php - قائمة الاقتطاعات (محسّنة)
 * - البطاقات ملونة وجذابة مثل لوحة الميزانية
 * - النقر على البطاقة يفلتر الجدول تلقائياً
 * - العرض الافتراضي: النشط فقط
 */
ob_start();
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';
require_once 'helpers.php';

// ========== تعريف safeFormatDate ==========
if (!function_exists('safeFormatDate')) {
    function safeFormatDate($date) {
        if (empty($date) || $date === '0000-00-00' || $date === '1970-01-01') return '—';
        return date('d/m/Y', strtotime($date));
    }
}

// ========== المعاملات ==========
$search = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';
$source_filter = isset($_GET['source']) ? (int)$_GET['source'] : 0;
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'active'; // الافتراضي: نشط

$sources = $pdo->query("SELECT id, name FROM sources ORDER BY name")->fetchAll();

// ========== جلب جميع الاقتطاعات (للإحصائيات والفلترة) ==========
$allDeductions = getDeductionsList($pdo, ['search' => $search], 1000, 0);

// ========== تطبيق فلتر الحالة يدوياً ==========
if ($status_filter === 'active') {
    $deductions = array_values(array_filter($allDeductions, fn($d) => $d['status'] === 'نشط'));
} elseif ($status_filter === 'expired') {
    $deductions = array_values(array_filter($allDeductions, fn($d) => $d['status'] === 'منتهي'));
} elseif ($status_filter === 'expiring') {
    $deductions = array_values(array_filter($allDeductions, fn($d) => $d['status'] === 'ينتهي قريباً'));
} elseif ($status_filter === 'loan') {
    // السلف النشطة فقط (is_loan = 1 و status = نشط)
    $deductions = array_values(array_filter($allDeductions, fn($d) => $d['is_loan'] == 1 && $d['status'] === 'نشط'));
} else { // all أو أي قيمة أخرى
    $deductions = $allDeductions;
}

// ========== فلتر المصدر (إذا كان محدداً) ==========
if ($source_filter > 0) {
    $deductions = array_values(array_filter($deductions, fn($d) => $d['source_id'] == $source_filter));
}

// ========== الإحصائيات ==========
$totalAll = count($allDeductions);
$totalActive = count(array_filter($allDeductions, fn($d) => $d['status'] === 'نشط'));
$totalExpiring = count(array_filter($allDeductions, fn($d) => $d['status'] === 'ينتهي قريباً'));
$totalExpired = count(array_filter($allDeductions, fn($d) => $d['status'] === 'منتهي'));
$totalLoans = count(array_filter($allDeductions, fn($d) => $d['is_loan'] == 1 && $d['status'] === 'نشط'));
$totalMonthlyAmount = array_sum(array_column($allDeductions, 'monthly_amount'));

// ========== التسديد المقدم ==========
$earlyMap = [];
$stmtEarly = $pdo->query("SELECT deduction_id, id FROM early_payments WHERE is_reversed = 0");
while ($row = $stmtEarly->fetch()) {
    $earlyMap[$row['deduction_id']] = $row['id'];
}

$csrf_token = generateCSRFToken();
include '../includes/header.php';
?>
<link rel="stylesheet" href="../assets/css/deductions.css">

<div class="deductions-container">
    <div class="deductions-header">
        <h2>📋 إدارة الاقتطاعات</h2>
        <a href="add.php" class="btn-add">➕ إضافة اقتطاع جديد</a>
    </div>

    <!-- ========== بطاقات الإحصائيات (قابلة للنقر كفلاتر) ========== -->
    <div class="stats-grid">
        <a href="?status=all<?= $search ? '&search='.urlencode($search) : '' ?><?= $source_filter ? '&source='.$source_filter : '' ?>" 
           class="stat-card total <?= $status_filter == 'all' ? 'active-card' : '' ?>">
            <div class="stat-icon">📊</div>
            <div class="stat-label">إجمالي الاقتطاعات</div>
            <div class="stat-value"><?= number_format($totalAll) ?></div>
        </a>
        <a href="?status=active<?= $search ? '&search='.urlencode($search) : '' ?><?= $source_filter ? '&source='.$source_filter : '' ?>" 
           class="stat-card active <?= $status_filter == 'active' ? 'active-card' : '' ?>">
            <div class="stat-icon">✅</div>
            <div class="stat-label">نشط</div>
            <div class="stat-value"><?= number_format($totalActive) ?></div>
        </a>
        <a href="?status=expiring<?= $search ? '&search='.urlencode($search) : '' ?><?= $source_filter ? '&source='.$source_filter : '' ?>" 
           class="stat-card expiring <?= $status_filter == 'expiring' ? 'active-card' : '' ?>">
            <div class="stat-icon">⚠️</div>
            <div class="stat-label">ينتهي قريباً</div>
            <div class="stat-value"><?= number_format($totalExpiring) ?></div>
        </a>
        <a href="?status=expired<?= $search ? '&search='.urlencode($search) : '' ?><?= $source_filter ? '&source='.$source_filter : '' ?>" 
           class="stat-card expired <?= $status_filter == 'expired' ? 'active-card' : '' ?>">
            <div class="stat-icon">❌</div>
            <div class="stat-label">منتهي</div>
            <div class="stat-value"><?= number_format($totalExpired) ?></div>
        </a>
        <a href="?status=loan<?= $search ? '&search='.urlencode($search) : '' ?><?= $source_filter ? '&source='.$source_filter : '' ?>" 
           class="stat-card loans <?= $status_filter == 'loan' ? 'active-card' : '' ?>">
            <div class="stat-icon">💰</div>
            <div class="stat-label">سلف نشطة</div>
            <div class="stat-value"><?= number_format($totalLoans) ?></div>
        </a>
        <a href="?status=all<?= $search ? '&search='.urlencode($search) : '' ?><?= $source_filter ? '&source='.$source_filter : '' ?>" 
           class="stat-card amount <?= $status_filter == 'all' ? 'active-card' : '' ?>">
            <div class="stat-icon">💳</div>
            <div class="stat-label">إجمالي الاقتطاع الشهري</div>
            <div class="stat-value"><?= number_format($totalMonthlyAmount, 2) ?> <small>دج</small></div>
        </a>
    </div>

    <!-- ========== الفلاتر ========== -->
    <div class="filter-section">
        <form method="GET" class="filter-form">
            <div class="filter-group">
                <label>المصدر</label>
                <select name="source">
                    <option value="0">جميع المصادر</option>
                    <?php foreach ($sources as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= $source_filter == $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>الحالة</label>
                <select name="status" onchange="this.form.submit()">
                    <option value="all" <?= $status_filter == 'all' ? 'selected' : '' ?>>الكل</option>
                    <option value="active" <?= $status_filter == 'active' ? 'selected' : '' ?>>نشط</option>
                    <option value="expiring" <?= $status_filter == 'expiring' ? 'selected' : '' ?>>ينتهي قريباً</option>
                    <option value="expired" <?= $status_filter == 'expired' ? 'selected' : '' ?>>منتهي</option>
                    <option value="loan" <?= $status_filter == 'loan' ? 'selected' : '' ?>>سلف</option>
                </select>
            </div>
            <div class="filter-group" style="flex:2;">
                <label>بحث</label>
                <input type="text" name="search" placeholder="اسم الموظف..." value="<?= htmlspecialchars($search) ?>">
            </div>
            <button type="submit" class="btn-filter">🔍 بحث</button>
            <a href="list.php" class="btn-reset">🗑️ إعادة تعيين</a>
        </form>
    </div>

    <!-- ========== الجدول ========== -->
    <div class="table-responsive">
        <table class="deductions-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>الموظف</th>
                    <th>المصدر</th>
                    <th>المبلغ الشهري</th>
                    <th>المدة</th>
                    <th>الرصيد المتبقي</th>
                    <th>تاريخ البداية</th>
                    <th>تاريخ النهاية</th>
                    <th>الأقساط</th>
                    <th>التقدم</th>
                    <th>الحالة</th>
                    <th>الإجراءات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($deductions)): ?>
                    <tr><td colspan="12" class="text-center">لا توجد اقتطاعات مطابقة للبحث</td></tr>
                <?php else: ?>
                    <?php foreach ($deductions as $d): 
                        $paid = (int)($d['paid_count'] ?? 0);
                        $total = (int)($d['total_installments'] ?? $d['total_months']);
                        $unpaid = (int)($d['unpaid_count'] ?? 0);
                        $progress = $total > 0 ? round(($paid / $total) * 100) : 0;
                        $hasEarly = isset($earlyMap[$d['id']]);
                        $earlyId = $hasEarly ? $earlyMap[$d['id']] : 0;
                    ?>
                        <tr>
                            <td>
                                <?= $d['id'] ?>
                                <?php if ($d['is_loan']): ?>
                                    <span class="badge-loan">سلفة</span>
                                <?php endif; ?>
                            </td>
                            <td><strong><?= htmlspecialchars($d['full_name']) ?></strong></td>
                            <td><?= htmlspecialchars($d['source_name']) ?></td>
                            <td><?= number_format($d['monthly_amount'], 2) ?> دج</td>
                            <td><?= $d['total_months'] ?> شهر</td>
                            <td><?= number_format($d['credit_balance'], 2) ?> دج</td>
                            <td><?= safeFormatDate($d['start_date']) ?></td>
                            <td><?= safeFormatDate($d['end_date']) ?></td>
                            <td>
                                <div class="installment-info">
                                    <span class="installment-count"><?= $paid ?> / <?= $total ?></span>
                                    <?php if ($unpaid > 0): ?>
                                        <span class="installment-unpaid">(<?= $unpaid ?> متبقية)</span>
                                    <?php else: ?>
                                        <span class="installment-complete">✓ مكتملة</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <div class="progress-bar-wrapper">
                                    <div class="progress-bar-bg">
                                        <div class="progress-bar-fill" style="width: <?= $progress ?>%;"></div>
                                    </div>
                                    <span class="progress-label"><?= $progress ?>%</span>
                                </div>
                            </td>
                            <td>
                                <span class="status-badge <?= $d['status'] == 'نشط' ? 'status-active' : ($d['status'] == 'ينتهي قريباً' ? 'status-expiring' : 'status-expired') ?>">
                                    <?= $d['status'] ?>
                                </span>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <a href="view.php?id=<?= $d['id'] ?>" class="btn-sm btn-view">📄 عرض وتعديل</a>
                                    <button class="btn-sm btn-delete delete-btn" data-id="<?= $d['id'] ?>" data-name="<?= htmlspecialchars($d['full_name']) ?>">🗑️ حذف</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ========== مودال تأكيد الحذف ========== -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal-box">
        <h3>⚠️ تأكيد الحذف</h3>
        <p>هل أنت متأكد من حذف الاقتطاع الخاص بـ <strong id="deleteEmployeeName"></strong>؟</p>
        <p class="text-muted">سيتم حذف جميع الأقساط المرتبطة.</p>
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
        if (e.target === this) modal.classList.remove('active');
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
        .catch(() => { alert('حدث خطأ في الاتصال بالخادم'); })
        .finally(() => { modal.classList.remove('active'); });
    });
});
</script>

<?php include '../includes/footer.php'; ?>