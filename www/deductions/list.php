<?php
/**
 * deductions/list.php - قائمة الاقتطاعات (مع تصفية تكرار الهواتف)
 * تم التعديل: استبعاد source_id = 999 من الاقتطاعات العادية، 
 * وجلب الهواتف بشكل منفصل ومجمع حسب الموظف.
 */
ob_start();
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';
require_once 'helpers.php';

// ============================================================
// دوال مساعدة
// ============================================================
if (!function_exists('safeFormatDate')) {
    function safeFormatDate($date) {
        if (empty($date) || $date === '0000-00-00' || $date === '1970-01-01') return '—';
        return date('d/m/Y', strtotime($date));
    }
}

// ============================================================
// المعاملات (فلاتر)
// ============================================================
$search = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';
$source_filter = isset($_GET['source']) ? (int)$_GET['source'] : 0;
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'active';

// ============================================================
// 1. جلب الاقتطاعات العادية (مع استبعاد المصدر 999)
// ============================================================
$filters = [
    'type' => isset($_GET['type']) ? $_GET['type'] : '',
    'status' => isset($_GET['status']) ? $_GET['status'] : '',
    'search' => $search,
    'employee_id' => isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0
];
$regularDeductions = getDeductionsList($pdo, $filters, 1000, 0);

// تصفية لإزالة أي سجل مصدره 999 (لأننا سنجلب الهواتف بشكل منفصل)
$regularDeductions = array_filter($regularDeductions, function($d) {
    return ($d['source_id'] ?? 0) != 999;
});
// إعادة ترقيم المصفوفة
$regularDeductions = array_values($regularDeductions);

// ============================================================
// 2. جلب الهواتف من deductions مع تجميع حسب الموظف (سجل واحد لكل موظف)
// ============================================================
$showPhones = true;
if ($source_filter > 0 && $source_filter != 999) $showPhones = false;
if (in_array($status_filter, ['expired','expiring','loan'])) $showPhones = false;

$phoneDeductions = [];
if ($showPhones) {
    $params = [];
    $sql = "
        SELECT 
            MIN(d.id) as id,  -- نأخذ أي id (لن يكون مستخدماً في العمليات)
            d.employee_id,
            d.source_id,
            'هاتف' as source_name,
            e.name as full_name,
            e.account_number,
            e.category as contract_type,
            d.monthly_amount,
            MAX(d.total_months) as total_months,  -- سيكون 12
            SUM(CASE WHEN mi.is_paid = 0 THEN mi.amount ELSE 0 END) as credit_balance,
            MIN(d.start_date) as start_date,
            MAX(d.end_date) as end_date,
            d.is_loan,
            'phone' as type,
            SUM(CASE WHEN mi.is_paid = 1 THEN 1 ELSE 0 END) as paid_count,
            SUM(CASE WHEN mi.is_paid = 0 THEN 1 ELSE 0 END) as unpaid_count,
            COUNT(mi.id) as total_installments,
            CASE 
                WHEN SUM(CASE WHEN mi.is_paid = 0 THEN 1 ELSE 0 END) = 0 THEN 'مدفوع'
                ELSE 'نشط'
            END as status
        FROM deductions d
        JOIN employees e ON d.employee_id = e.id
        LEFT JOIN monthly_installments mi ON mi.deduction_id = d.id
        WHERE d.source_id = 999
    ";
    if (!empty($search)) {
        $sql .= " AND e.name LIKE :search";
        $params[':search'] = '%' . $search . '%';
    }
    $sql .= " GROUP BY d.employee_id, e.name, e.account_number, e.category, d.monthly_amount, d.is_loan, d.source_id";
    
    if ($status_filter === 'active') {
        $sql .= " HAVING unpaid_count > 0";
    }
    $sql .= " ORDER BY e.name ASC";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $phoneDeductions = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================================
// 3. دمج القائمتين
// ============================================================
$allDeductions = array_merge($regularDeductions, $phoneDeductions);

// ============================================================
// 4. تطبيق الفلاتر النهائية (على المصدر والحالة)
// ============================================================
if ($status_filter === 'active') {
    $deductions = array_values(array_filter($allDeductions, fn($d) => in_array($d['status'], ['نشط','مدفوع'])));
} elseif ($status_filter === 'expired') {
    $deductions = array_values(array_filter($allDeductions, fn($d) => $d['status'] === 'منتهي'));
} elseif ($status_filter === 'expiring') {
    $deductions = array_values(array_filter($allDeductions, fn($d) => $d['status'] === 'ينتهي قريباً'));
} elseif ($status_filter === 'loan') {
    $deductions = array_values(array_filter($allDeductions, fn($d) => $d['is_loan'] == 1 && $d['status'] === 'نشط'));
} else {
    $deductions = $allDeductions;
}
if ($source_filter > 0 && $source_filter != 999) {
    $deductions = array_values(array_filter($deductions, fn($d) => ($d['source_id'] ?? 0) != 999));
} elseif ($source_filter == 999) {
    $deductions = array_values(array_filter($deductions, fn($d) => ($d['source_id'] ?? 0) == 999));
}

// ============================================================
// 5. الإحصائيات
// ============================================================
$totalAll = count($allDeductions);
$totalActive = count(array_filter($allDeductions, fn($d) => in_array($d['status'], ['نشط','مدفوع'])));
$totalExpiring = count(array_filter($allDeductions, fn($d) => $d['status'] === 'ينتهي قريباً'));
$totalExpired = count(array_filter($allDeductions, fn($d) => $d['status'] === 'منتهي'));
$totalLoans = count(array_filter($allDeductions, fn($d) => $d['is_loan'] == 1 && $d['status'] === 'نشط'));
$totalMonthlyAmount = array_sum(array_column($allDeductions, 'monthly_amount'));

$csrf_token = generateCSRFToken();
include '../includes/header.php';
?>
<link rel="stylesheet" href="../assets/css/deductions.css">

<div class="deductions-container">
    <div class="deductions-header">
        <h2>📋 إدارة الاقتطاعات</h2>
        <div class="d-flex gap-2">
            <a href="../employees/phone_numbers/generate_phone_deductions.php" class="btn-add" style="background:#6f42c1;">📱 توليد الهواتف</a>
            <a href="add.php" class="btn-add">➕ إضافة اقتطاع جديد</a>
        </div>
    </div>

    <!-- بطاقات الإحصائيات -->
    <div class="stats-grid">
        <a href="?status=all<?= $search ? '&search='.urlencode($search) : '' ?><?= $source_filter ? '&source='.$source_filter : '' ?>" class="stat-card total <?= $status_filter == 'all' ? 'active-card' : '' ?>">
            <div class="stat-icon">📊</div>
            <div class="stat-label">إجمالي الاقتطاعات</div>
            <div class="stat-value"><?= number_format($totalAll) ?></div>
        </a>
        <a href="?status=active<?= $search ? '&search='.urlencode($search) : '' ?><?= $source_filter ? '&source='.$source_filter : '' ?>" class="stat-card active <?= $status_filter == 'active' ? 'active-card' : '' ?>">
            <div class="stat-icon">✅</div>
            <div class="stat-label">نشط</div>
            <div class="stat-value"><?= number_format($totalActive) ?></div>
        </a>
        <a href="?status=expiring<?= $search ? '&search='.urlencode($search) : '' ?><?= $source_filter ? '&source='.$source_filter : '' ?>" class="stat-card expiring <?= $status_filter == 'expiring' ? 'active-card' : '' ?>">
            <div class="stat-icon">⚠️</div>
            <div class="stat-label">ينتهي قريباً</div>
            <div class="stat-value"><?= number_format($totalExpiring) ?></div>
        </a>
        <a href="?status=expired<?= $search ? '&search='.urlencode($search) : '' ?><?= $source_filter ? '&source='.$source_filter : '' ?>" class="stat-card expired <?= $status_filter == 'expired' ? 'active-card' : '' ?>">
            <div class="stat-icon">❌</div>
            <div class="stat-label">منتهي</div>
            <div class="stat-value"><?= number_format($totalExpired) ?></div>
        </a>
        <a href="?status=loan<?= $search ? '&search='.urlencode($search) : '' ?><?= $source_filter ? '&source='.$source_filter : '' ?>" class="stat-card loans <?= $status_filter == 'loan' ? 'active-card' : '' ?>">
            <div class="stat-icon">💰</div>
            <div class="stat-label">سلف نشطة</div>
            <div class="stat-value"><?= number_format($totalLoans) ?></div>
        </a>
        <a href="?status=all<?= $search ? '&search='.urlencode($search) : '' ?><?= $source_filter ? '&source='.$source_filter : '' ?>" class="stat-card amount <?= $status_filter == 'all' ? 'active-card' : '' ?>">
            <div class="stat-icon">💳</div>
            <div class="stat-label">إجمالي الاقتطاع الشهري</div>
            <div class="stat-value"><?= number_format($totalMonthlyAmount, 2) ?> <small>دج</small></div>
        </a>
    </div>

    <!-- الفلاتر -->
    <div class="filter-section">
        <form method="GET" class="filter-form">
            <div class="filter-group">
                <label>المصدر</label>
                <select name="source">
                    <option value="0">جميع المصادر</option>
                    <?php
                    $sources = $pdo->query("SELECT id, name FROM sources ORDER BY name")->fetchAll();
                    foreach ($sources as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= $source_filter == $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
                    <?php endforeach; ?>
                    <option value="999" <?= $source_filter == 999 ? 'selected' : '' ?>>📱 هاتف</option>
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

    <!-- الجدول -->
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
                    <?php $i=1; foreach ($deductions as $d): 
                        $isPhone = isset($d['type']) && $d['type'] === 'phone';
                        $paid = (int)($d['paid_count'] ?? 0);
                        $total = (int)($d['total_months'] ?? 1);
                        if ($paid > $total) $paid = $total;
                        $unpaid = $total - $paid;
                        $progress = $total > 0 ? round(($paid / $total) * 100) : 0;
                        $creditBalance = (float)($d['credit_balance'] ?? 0);
                        $statusLabel = $d['status'] ?? 'نشط';
                    ?>
                        <tr class="<?= $isPhone ? 'phone-row' : '' ?>">
                            <td>
                                <?= $d['id'] ?? '—' ?>
                                <?php if ($isPhone): ?>
                                    <span class="badge-phone">📱 هاتف</span>
                                <?php elseif ($d['is_loan']): ?>
                                    <span class="badge-loan">سلفة</span>
                                <?php endif; ?>
                            </td>
                            <td><strong><?= htmlspecialchars($d['full_name']) ?></strong></td>
                            <td><?= htmlspecialchars($d['source_name']) ?></td>
                            <td><?= number_format($d['monthly_amount'], 2) ?> دج</td>
                            <td><?= $total . ' شهر' ?></td>
                            <td><?= number_format($creditBalance, 2) ?> دج</td>
                            <td><?= safeFormatDate($d['start_date'] ?? null) ?></td>
                            <td><?= safeFormatDate($d['end_date'] ?? null) ?></td>
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
                                <?php if ($statusLabel == 'مدفوع'): ?>
                                    <span class="status-badge status-paid">✅ مدفوع</span>
                                <?php elseif ($statusLabel == 'ينتهي قريباً'): ?>
                                    <span class="status-badge status-expiring">⚠️ ينتهي قريباً</span>
                                <?php elseif ($statusLabel == 'منتهي'): ?>
                                    <span class="status-badge status-expired">❌ منتهي</span>
                                <?php else: ?>
                                    <span class="status-badge status-active">✅ نشط</span>
                                <?php endif; ?>
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

<!-- مودال تأكيد الحذف -->
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

    cancelBtn.addEventListener('click', function() { modal.classList.remove('active'); });
    modal.addEventListener('click', function(e) { if (e.target === this) modal.classList.remove('active'); });

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
            if (data.success) { alert(data.message); location.reload(); }
            else alert('خطأ: ' + data.message);
        })
        .catch(() => alert('حدث خطأ في الاتصال بالخادم'))
        .finally(() => modal.classList.remove('active'));
    });
});
</script>

<?php include '../includes/footer.php'; ?>