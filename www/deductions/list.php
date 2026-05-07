<?php
ob_start();
require_once '../includes/auth_check.php';
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

error_reporting(0);

require_once '../config/database.php';
require_once '../includes/functions.php';

// ========== معالجة الحذف (قبل أي ناتج) ==========
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    if ($id > 0) {
        try {
            $pdo->prepare("DELETE FROM deductions WHERE id = ?")->execute([$id]);
            $_SESSION['toast'] = ['message' => 'تم حذف الاقتطاع بنجاح', 'type' => 'success', 'duration' => 3000];
        } catch (Exception $e) {
            $_SESSION['toast'] = ['message' => 'خطأ أثناء الحذف: ' . $e->getMessage(), 'type' => 'error', 'duration' => 5000];
        }
    }
    header("Location: list.php");
    exit;
}

// ========== الحصول على التبويب النشط ==========
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'all';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$source_filter = isset($_GET['source']) ? (int)$_GET['source'] : 0;
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

// ========== جلب المصادر للفلاتر (للمصادر العادية) ==========
$sources = $pdo->query("SELECT id, name FROM sources ORDER BY name")->fetchAll();

// ========== بناء الاستعلام الأساسي (للاستعلامات العادية) ==========
function buildDeductionsQuery($where_extra = '', $params = [], $search = '', $source_filter = 0, $status_filter = '') {
    global $pdo;
    $sql = "
        SELECT 
            d.id,
            e.name as employee_name,
            s.name as source_name,
            d.monthly_amount,
            d.total_months,
            d.start_date,
            d.end_date,
            d.is_loan,
            CASE 
                WHEN d.end_date < date('now') THEN 'منتهي'
                WHEN d.end_date < date('now', '+30 days') THEN 'ينتهي قريباً'
                ELSE 'نشط'
            END as status
        FROM deductions d
        JOIN employees e ON d.employee_id = e.id
        JOIN sources s ON d.source_id = s.id
        WHERE 1=1
    ";
    if ($search) {
        $sql .= " AND e.name LIKE :search";
        $params[':search'] = "%$search%";
    }
    if ($source_filter > 0) {
        $sql .= " AND d.source_id = :source_id";
        $params[':source_id'] = $source_filter;
    }
    if ($status_filter == 'active') {
        $sql .= " AND d.end_date >= date('now')";
    } elseif ($status_filter == 'expired') {
        $sql .= " AND d.end_date < date('now')";
    } elseif ($status_filter == 'expiring') {
        $sql .= " AND d.end_date BETWEEN date('now') AND date('now', '+30 days')";
    }
    $sql .= " $where_extra ORDER BY d.id DESC";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val);
    }
    $stmt->execute();
    return $stmt->fetchAll();
}

// ========== جلب البيانات حسب التبويب ==========
$deductions = [];
$totalAll = 0;
$totalActive = 0;
$totalExpired = 0;
$totalExpiring = 0;

if ($active_tab == 'djezzy') {
    // تبويب Djezzy: من جدول employee_phone_numbers
    $djezzy_sql = "
        SELECT 
            e.id as employee_id,
            e.name as employee_name,
            e.category,
            COALESCE(SUM(epn.monthly_amount), 0) as monthly_amount,
            COUNT(epn.id) as phone_count,
            GROUP_CONCAT(epn.phone_number || ' (' || epn.monthly_amount || ' دج)') as phone_details
        FROM employees e
        JOIN employee_phone_numbers epn ON e.id = epn.employee_id AND epn.is_active = 1
        GROUP BY e.id
        HAVING monthly_amount > 0
        ORDER BY e.name
    ";
    $stmt = $pdo->query($djezzy_sql);
    $deductions = $stmt->fetchAll();
    $totalAll = count($deductions);
    $totalActive = $totalAll; // كل الأرقام النشطة تعتبر نشطة
    $totalExpired = 0;
    $totalExpiring = 0;
} else {
    // الاستعلامات العادية للتبويبات الأخرى
    $where_extra = '';
    if ($active_tab == 'loans') {
        $where_extra = " AND d.is_loan = 1";
    } elseif ($active_tab == 'saadine') {
        $where_extra = " AND s.name = 'سعدين للتجهير'";
    } elseif ($active_tab == 'others') {
        $where_extra = " AND s.name NOT IN ('سعدين للتجهير', 'djezzy') AND d.is_loan = 0";
    }
    $params = [];
    if ($search) $params[':search'] = "%$search%";
    if ($source_filter > 0) $params[':source_id'] = $source_filter;
    $deductions = buildDeductionsQuery($where_extra, $params, $search, $source_filter, $status_filter);
    
    // إحصائيات سريعة
    $totalAll = count($deductions);
    $totalActive = count(array_filter($deductions, fn($d) => $d['status'] == 'نشط'));
    $totalExpired = count(array_filter($deductions, fn($d) => $d['status'] == 'منتهي'));
    $totalExpiring = count(array_filter($deductions, fn($d) => $d['status'] == 'ينتهي قريباً'));
}

ob_end_clean();
include '../includes/header.php';
?>

<style>
    /* ========== التصميم الحديث ========== */
    body { font-family: 'Tajawal', 'Segoe UI', system-ui, sans-serif; background: #f4f7fc; }
    .stats-grid-modern { display: flex; gap: 1.5rem; flex-wrap: wrap; margin-bottom: 2rem; }
    .stat-card-modern { flex: 1; min-width: 160px; background: white; border-radius: 28px; padding: 1.2rem 1rem; text-align: center; box-shadow: 0 8px 20px rgba(0,0,0,0.05); transition: all 0.3s ease; border-bottom: 4px solid; cursor: pointer; text-decoration: none; display: block; }
    .stat-card-modern:hover { transform: translateY(-5px); box-shadow: 0 15px 30px rgba(0,0,0,0.1); }
    .stat-card-modern .stat-icon { font-size: 2rem; margin-bottom: 0.5rem; }
    .stat-card-modern .stat-label { font-size: 0.85rem; text-transform: uppercase; letter-spacing: 1px; font-weight: 600; color: #5a6874; }
    .stat-card-modern .stat-number { font-size: 2rem; font-weight: 800; margin-top: 0.25rem; }
    .stat-card-modern.all { border-bottom-color: #2a5298; }
    .stat-card-modern.active { border-bottom-color: #28a745; }
    .stat-card-modern.expiring { border-bottom-color: #ffc107; }
    .stat-card-modern.expired { border-bottom-color: #dc3545; }
    .stat-card-modern.all .stat-number { color: #2a5298; }
    .stat-card-modern.active .stat-number { color: #28a745; }
    .stat-card-modern.expiring .stat-number { color: #e67e22; }
    .stat-card-modern.expired .stat-number { color: #dc3545; }

    .tabs-modern { display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 1.5rem; border-bottom: 1px solid #e2e8f0; padding-bottom: 0.5rem; }
    .tab-link { padding: 0.5rem 1.2rem; border-radius: 40px; background: #eef2f9; color: #1e3c72; text-decoration: none; font-weight: 600; transition: 0.2s; }
    .tab-link.active { background: #2a5298; color: white; }
    .tab-link:hover { background: #cbd5e1; }

    .filters-modern { background: white; border-radius: 28px; padding: 1.2rem 1.8rem; margin-bottom: 2rem; box-shadow: 0 4px 12px rgba(0,0,0,0.03); border: 1px solid #eef2f6; }
    .filters-form { display: flex; flex-wrap: wrap; gap: 1rem; align-items: flex-end; }
    .filter-group-modern { display: flex; flex-direction: column; gap: 0.4rem; }
    .filter-group-modern label { font-size: 0.75rem; font-weight: 700; color: #4a5b6e; letter-spacing: 0.5px; }
    .filter-group-modern select, .filter-group-modern input { padding: 0.6rem 1rem; border: 1px solid #dce3ec; border-radius: 40px; background: #fefefe; font-family: inherit; transition: 0.2s; min-width: 140px; }
    .filter-group-modern select:focus, .filter-group-modern input:focus { outline: none; border-color: #2a5298; box-shadow: 0 0 0 3px rgba(42,82,152,0.1); }
    .btn-modern { padding: 0.6rem 1.5rem; border-radius: 40px; border: none; font-weight: 600; cursor: pointer; transition: 0.2s; font-family: inherit; background: #eef2f9; color: #1e3c72; }
    .btn-modern-primary { background: #2a5298; color: white; }
    .btn-modern-primary:hover { background: #1e3c72; transform: scale(1.02); }
    .btn-modern-reset { background: #f1f3f5; color: #5c6f87; text-decoration: none; display: inline-block; }
    .btn-modern-reset:hover { background: #e2e6ea; }
    .btn-add-modern { display: inline-flex; align-items: center; gap: 0.5rem; background: linear-gradient(135deg, #28a745, #1e7e34); color: white; padding: 0.7rem 1.5rem; border-radius: 40px; text-decoration: none; font-weight: 600; margin-bottom: 1.5rem; transition: 0.2s; box-shadow: 0 4px 8px rgba(0,0,0,0.05); }
    .btn-add-modern:hover { transform: translateY(-2px); box-shadow: 0 8px 16px rgba(0,0,0,0.1); }
    .table-wrapper { overflow-x: auto; border-radius: 24px; background: white; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.05); margin-bottom: 1.5rem; }
    .data-table-modern { width: 100%; border-collapse: collapse; font-size: 0.9rem; min-width: 800px; }
    .data-table-modern th { background: #f8fafd; color: #1e2f3e; font-weight: 700; padding: 1rem; border-bottom: 2px solid #e2e8f0; text-align: center; }
    .data-table-modern td { padding: 1rem; text-align: center; border-bottom: 1px solid #ecf3fa; vertical-align: middle; }
    .data-table-modern tbody tr:hover { background: #f9fbfe; }
    .status-badge { display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.25rem 0.8rem; border-radius: 40px; font-size: 0.75rem; font-weight: 600; width: fit-content; margin: 0 auto; }
    .status-active { background: #e3f7e8; color: #1f7840; }
    .status-expiring { background: #fff1e0; color: #c47d2e; }
    .status-expired { background: #ffe6e5; color: #c23d3d; }
    .action-buttons { display: flex; gap: 0.4rem; justify-content: center; flex-wrap: wrap; }
    .btn-action { padding: 0.3rem 0.8rem; border-radius: 40px; text-decoration: none; font-size: 0.75rem; font-weight: 500; transition: 0.2s; display: inline-flex; align-items: center; gap: 0.2rem; }
    .btn-edit-modern { background: #ffedd5; color: #b45309; }
    .btn-edit-modern:hover { background: #fed7aa; }
    .btn-postpone-modern { background: #e0f2fe; color: #0369a1; }
    .btn-postpone-modern:hover { background: #bae6fd; }
    .btn-delete-modern { background: #fee2e2; color: #b91c1c; }
    .btn-delete-modern:hover { background: #fecaca; }
    .loan-badge { background: #ff9800; color: white; padding: 2px 8px; border-radius: 20px; font-size: 10px; margin-right: 5px; }
    .quick-summary { background: #f1f5f9; border-radius: 20px; padding: 1rem 1.5rem; font-size: 0.85rem; color: #334155; display: flex; justify-content: space-between; flex-wrap: wrap; gap: 1rem; }
</style>

<div style="max-width: 1400px; margin: 0 auto;">
    <h2 style="font-size: 1.8rem; font-weight: 700; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.5rem;">📋 قائمة الاقتطاعات</h2>

    <!-- التبويبات -->
    <div class="tabs-modern">
        <a href="?tab=all" class="tab-link <?= $active_tab == 'all' ? 'active' : '' ?>">📊 الكل</a>
        <a href="?tab=djezzy" class="tab-link <?= $active_tab == 'djezzy' ? 'active' : '' ?>">📱 Djezzy</a>
        <a href="?tab=loans" class="tab-link <?= $active_tab == 'loans' ? 'active' : '' ?>">💰 السلف</a>
        <a href="?tab=saadine" class="tab-link <?= $active_tab == 'saadine' ? 'active' : '' ?>">🛠️ سعدين للتجهير</a>
        <a href="?tab=others" class="tab-link <?= $active_tab == 'others' ? 'active' : '' ?>">📋 اقتطاعات أخرى</a>
    </div>

    <!-- إحصائيات سريعة (تظهر فقط للتبويبات العادية، وليس لدجيزي) -->
    <?php if ($active_tab != 'djezzy'): ?>
    <div class="stats-grid-modern">
        <a href="?tab=<?= $active_tab ?>&status=all" class="stat-card-modern all" style="text-decoration: none;">
            <div class="stat-icon">📊</div>
            <div class="stat-label">الكل</div>
            <div class="stat-number"><?= $totalAll ?></div>
        </a>
        <a href="?tab=<?= $active_tab ?>&status=active" class="stat-card-modern active" style="text-decoration: none;">
            <div class="stat-icon">✅</div>
            <div class="stat-label">نشط</div>
            <div class="stat-number"><?= $totalActive ?></div>
        </a>
        <a href="?tab=<?= $active_tab ?>&status=expiring" class="stat-card-modern expiring" style="text-decoration: none;">
            <div class="stat-icon">⚠️</div>
            <div class="stat-label">ينتهي قريباً</div>
            <div class="stat-number"><?= $totalExpiring ?></div>
        </a>
        <a href="?tab=<?= $active_tab ?>&status=expired" class="stat-card-modern expired" style="text-decoration: none;">
            <div class="stat-icon">❌</div>
            <div class="stat-label">منتهي</div>
            <div class="stat-number"><?= $totalExpired ?></div>
        </a>
    </div>
    <?php else: ?>
    <div class="stats-grid-modern">
        <div class="stat-card-modern all"><div class="stat-icon">📱</div><div class="stat-label">إجمالي الموظفين</div><div class="stat-number"><?= $totalAll ?></div></div>
        <div class="stat-card-modern active"><div class="stat-icon">💰</div><div class="stat-label">إجمالي الاقتطاع</div><div class="stat-number"><?= number_format(array_sum(array_column($deductions, 'monthly_amount')), 2) ?> دج</div></div>
    </div>
    <?php endif; ?>

    <!-- نموذج الفلاتر (لغير دجيزي فقط) -->
    <?php if ($active_tab != 'djezzy'): ?>
    <div class="filters-modern">
        <form method="GET" class="filters-form">
            <input type="hidden" name="tab" value="<?= $active_tab ?>">
            <div class="filter-group-modern">
                <label>📁 المصدر</label>
                <select name="source">
                    <option value="0">جميع المصادر</option>
                    <?php foreach($sources as $s): ?>
                        <?php if ($active_tab == 'saadine' && $s['name'] != 'سعدين للتجهير') continue; ?>
                        <?php if ($active_tab == 'others' && $s['name'] == 'سعدين للتجهير') continue; ?>
                        <option value="<?= $s['id'] ?>" <?= ($source_filter == $s['id']) ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group-modern">
                <label>📌 الحالة</label>
                <select name="status">
                    <option value="">الكل</option>
                    <option value="active" <?= $status_filter == 'active' ? 'selected' : '' ?>>نشط</option>
                    <option value="expiring" <?= $status_filter == 'expiring' ? 'selected' : '' ?>>ينتهي قريباً</option>
                    <option value="expired" <?= $status_filter == 'expired' ? 'selected' : '' ?>>منتهي</option>
                </select>
            </div>
            <div class="filter-group-modern">
                <label>🔍 بحث</label>
                <input type="text" name="search" placeholder="اسم الموظف..." value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="filter-group-modern">
                <label>&nbsp;</label>
                <button type="submit" class="btn-modern btn-modern-primary">بحث</button>
            </div>
            <div class="filter-group-modern">
                <label>&nbsp;</label>
                <a href="?tab=<?= $active_tab ?>" class="btn-modern btn-modern-reset">إلغاء الكل</a>
            </div>
        </form>
    </div>
    <?php else: ?>
    <!-- فلتر Djezzy (بسيط: بحث بالاسم) -->
    <div class="filters-modern">
        <form method="GET" class="filters-form">
            <input type="hidden" name="tab" value="djezzy">
            <div class="filter-group-modern">
                <label>🔍 بحث باسم الموظف</label>
                <input type="text" name="search" placeholder="اسم الموظف..." value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="filter-group-modern">
                <label>&nbsp;</label>
                <button type="submit" class="btn-modern btn-modern-primary">بحث</button>
            </div>
            <div class="filter-group-modern">
                <label>&nbsp;</label>
                <a href="?tab=djezzy" class="btn-modern btn-modern-reset">إلغاء</a>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <a href="add.php" class="btn-add-modern"><span>➕</span> إضافة اقتطاع جديد</a>

    <div class="table-wrapper">
        <table class="data-table-modern">
            <thead>
                <tr>
                    <th>#</th>
                    <th>الموظف</th>
                    <th>المصدر / التفاصيل</th>
                    <th>المبلغ الشهري (دج)</th>
                    <th>عدد الأشهر</th>
                    <th>تاريخ البداية</th>
                    <th>تاريخ النهاية</th>
                    <th>الحالة</th>
                    <th>الإجراءات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($deductions)): ?>
                    <tr><td colspan="9" style="text-align: center; padding: 3rem;">لا توجد بيانات مطابقة</td></tr>
                <?php else: ?>
                    <?php if ($active_tab == 'djezzy'): ?>
                        <?php foreach ($deductions as $dd): ?>
                        <tr>
                            <td><?= $dd['employee_id'] ?> <?php if($dd['phone_count']>1): ?><span class="loan-badge"><?= $dd['phone_count'] ?> أرقام</span><?php endif; ?>
                            <td><strong><?= htmlspecialchars($dd['employee_name']) ?></strong>
                            <td><small><?= nl2br(htmlspecialchars($dd['phone_details'])) ?></small>
                            <td><?= number_format($dd['monthly_amount'], 2) ?> دج
                            <td>1 شهر (شهري)
                            <td><?= date('d/m/Y') ?>
                            <td><?= date('d/m/Y', strtotime('+1 month')) ?>
                            <td><span class="status-badge status-active">✅ نشط</span>
                            <td class="action-buttons">
                                <a href="../employees/phone_numbers.php?employee_id=<?= $dd['employee_id'] ?>" class="btn-action btn-edit-modern">📱 إدارة</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <?php foreach ($deductions as $d): ?>
                        <tr>
                            <td><?= $d['id'] ?> <?php if($d['is_loan']): ?><span class="loan-badge">💰 قرض</span><?php endif; ?>
                            <td><?= htmlspecialchars($d['employee_name']) ?>
                            <td><?= htmlspecialchars($d['source_name']) ?>
                            <td><?= number_format($d['monthly_amount'], 2) ?> دج
                            <td><?= $d['total_months'] ?> أشهر
                            <td><?= date('d/m/Y', strtotime($d['start_date'])) ?>
                            <td><?= date('d/m/Y', strtotime($d['end_date'])) ?>
                            <td>
                                <?php if($d['status'] == 'منتهي'): ?>
                                    <span class="status-badge status-expired">❌ منتهي</span>
                                <?php elseif($d['status'] == 'ينتهي قريباً'): ?>
                                    <span class="status-badge status-expiring">⏰ ينتهي قريباً</span>
                                <?php else: ?>
                                    <span class="status-badge status-active">✅ نشط</span>
                                <?php endif; ?>
                            </td>
                            <td class="action-buttons">
                                <a href="edit.php?id=<?= $d['id'] ?>" class="btn-action btn-edit-modern">✏️ تعديل</a>
                                <?php if ($d['is_loan']): ?>
                                    <a href="postpone.php?id=<?= $d['id'] ?>" class="btn-action btn-postpone-modern">⏰ تأجيل</a>
                                <?php endif; ?>
                                <a href="confirm_delete.php?id=<?= $d['id'] ?>" class="btn-action btn-delete-modern">🗑️ حذف</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="quick-summary">
        <span>📊 إجمالي السجلات: <?= count($deductions) ?></span>
        <?php if ($active_tab != 'djezzy'): ?>
            <span>💰 إجمالي المبالغ الشهرية: <?= number_format(array_sum(array_column($deductions, 'monthly_amount')), 2) ?> دج</span>
        <?php endif; ?>
    </div>
</div>

<?php include '../includes/footer.php'; ?>