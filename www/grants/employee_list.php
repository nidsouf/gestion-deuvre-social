<?php
/**
 * grants/employee_list.php - قائمة المنح الموزعة
 * مع إضافة بطاقات إحصائيات لكل نوع منحة
 */
session_start();
require_once __DIR__ . '/../includes/auth_check.php';
// ensure correct path to database config so $pdo is defined
require_once __DIR__ . '/../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';
require_once '../includes/common_helpers.php';
require_once '../includes/grant_helpers.php';
require_once '../includes/grant_table.php';

// ============================================================
// معالجة POST (التحديث، إعادة الحساب، الحذف) – كما هي سابقاً
// ============================================================
// ... (نفس الكود السابق، لا تغيير) ...

// ============================================================
// جلب البيانات
// ============================================================
$grant_filter = isset($_GET['grant_id']) ? (int)$_GET['grant_id'] : 0;
$search = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';

$grantsList = $pdo->query("SELECT id, name FROM grants ORDER BY name")->fetchAll();

$sql = "
    SELECT 
        eg.id, eg.grant_date, eg.notes as grant_notes,
        eg.amount as stored_amount, eg.invoice_amount,
        e.name as employee_name, e.category,
        g.name as grant_name, g.amount as current_amount,
        g.calculation_type, g.percentage_value, g.max_amount,
        g.id as grant_type_id
    FROM employee_grants eg
    JOIN employees e ON eg.employee_id = e.id
    JOIN grants g ON eg.grant_id = g.id
    WHERE 1=1
";

$params = [];
if ($grant_filter > 0) {
    $sql .= " AND eg.grant_id = :grant_id";
    $params[':grant_id'] = $grant_filter;
}
if ($search) {
    $sql .= " AND e.name LIKE :search";
    $params[':search'] = "%$search%";
}
$sql .= " ORDER BY eg.grant_date DESC, e.name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$grants = $stmt->fetchAll();

// ============================================================
// حساب الإجماليات العامة
// ============================================================
$totalAmount = calculateGrantTotal($grants);
$totalCount = count($grants);

// ============================================================
// حساب إحصائيات كل نوع منحة
// ============================================================
$grantStats = [];
foreach ($grants as $g) {
    $typeName = $g['grant_name'];
    $amount = ($g['stored_amount'] > 0) ? $g['stored_amount'] : $g['current_amount'];
    if (!isset($grantStats[$typeName])) {
        $grantStats[$typeName] = [
            'count' => 0,
            'total' => 0,
            'type_id' => $g['grant_type_id']
        ];
    }
    $grantStats[$typeName]['count']++;
    $grantStats[$typeName]['total'] += $amount;
}

// ترتيب البطاقات حسب عدد المنح تنازلياً (الأكثر شيوعاً أولاً)
uasort($grantStats, fn($a, $b) => $b['count'] - $a['count']);

// ============================================================
// تصفية حسب الفئة للجدول
// ============================================================
$permanent = filterGrantsByCategory($grants, 'Permanent');
$contract = filterGrantsByCategory($grants, 'Contract');
sortGrantsByName($permanent);
sortGrantsByName($contract);

$totalPerm = calculateGrantTotal($permanent);
$totalCont = calculateGrantTotal($contract);

$csrf_token = generateCSRFToken();

// ============================================================
// عرض الصفحة
// ============================================================
include '../includes/header.php';
?>
<link rel="stylesheet" href="../assets/css/grants.css">

<?php if (hasToast()) { $toast = getToast(); ?>
    <div class="toast-container">
        <div class="toast-item toast-<?= $toast['type'] ?>"><?= $toast['message'] ?></div>
    </div>
    <script>
        setTimeout(function() {
            document.querySelector('.toast-container').style.display = 'none';
        }, <?= $toast['duration'] ?? 4000 ?>);
    </script>
<?php } ?>

<div class="grants-container">
    <div class="grants-header">
        <h2>🎁 منح الموظفين (<?= $totalCount ?> منحة) - إجمالي المبلغ: <?= formatGrantAmount($totalAmount) ?></h2>
    </div>
    
    <!-- ============================================================ -->
    <!-- بطاقات إحصائيات كل نوع منحة -->
    <!-- ============================================================ -->
    <?php if (!empty($grantStats)): ?>
    <div class="stats-grid" style="margin-bottom: 20px;">
        <?php foreach ($grantStats as $name => $stats): ?>
            <div class="stat-card grant-type" style="border-bottom-color: #6c3483; background: #f8f0ff; min-width: 180px;">
                <div class="label" style="font-weight: bold; font-size: 16px;">🎁 <?= htmlspecialchars($name) ?></div>
                <div class="number" style="font-size: 20px; margin: 5px 0;">
                    <?= $stats['count'] ?> منحة
                </div>
                <div style="font-size: 16px; color: #6c3483; font-weight: bold;">
                    <?= formatGrantAmount($stats['total']) ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    
    <!-- الفلاتر -->
    <div class="filters">
        <form method="GET" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center; width:100%;">
            <div class="filter-group">
                <label>🏷️ نوع المنحة:</label>
                <select name="grant_id">
                    <option value="0">جميع الأنواع</option>
                    <?php foreach ($grantsList as $g): ?>
                        <option value="<?= $g['id'] ?>" <?= ($grant_filter == $g['id']) ? 'selected' : '' ?>><?= htmlspecialchars($g['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>🔍 بحث:</label>
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="اسم الموظف...">
            </div>
            <button type="submit" class="btn btn-primary">بحث</button>
            <?php if ($grant_filter > 0 || $search): ?>
                <a href="employee_list.php" class="btn btn-secondary">إلغاء الفلتر</a>
            <?php endif; ?>
        </form>
    </div>
    
    <!-- الجداول -->
    <?php if (empty($grants)): ?>
        <div style="background:#f8d7da; padding:20px; text-align:center;">⚠️ لا توجد منح مسجلة</div>
    <?php else: ?>
        <?php renderGrantTable($permanent, '👔 الموظفون الدائمون', $totalPerm, true, $csrf_token, $search, $grant_filter); ?>
        <?php renderGrantTable($contract, '👕 الموظفون المتعاقدون', $totalCont, true, $csrf_token, $search, $grant_filter); ?>
        
        <div class="legend">
            <span class="legend-item"><span class="legend-color" style="background:#d4edda;"></span> محدث</span>
            <span class="legend-item"><span class="legend-color" style="background:#fff3cd;"></span> غير محدث</span>
            <span class="legend-item"><span class="legend-color" style="background:#f8f0ff;"></span> نسبة مئوية</span>
        </div>
    <?php endif; ?>
</div>

<!-- مودال تأكيد الحذف -->
<div id="deleteModal" class="modal-overlay">
    <div class="modal-box">
        <h3>⚠️ تأكيد الحذف</h3>
        <p>هل أنت متأكد من حذف منحة <strong id="deleteEmployeeName"></strong>؟</p>
        <form method="POST" id="deleteForm" novalidate>
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <input type="hidden" name="grant_id" id="deleteGrantId">
            <input type="hidden" name="delete_grant" value="1">
            <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
            <input type="hidden" name="grant_filter" value="<?= $grant_filter ?>">
            <div class="actions">
                <button type="button" class="btn-cancel-modal" onclick="closeDeleteModal()">إلغاء</button>
                <button type="submit" class="btn-confirm">🗑️ نعم، حذف</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openDeleteModal(grantId, employeeName) {
        document.getElementById('deleteGrantId').value = grantId;
        document.getElementById('deleteEmployeeName').textContent = employeeName;
        document.getElementById('deleteModal').classList.add('active');
    }
    function closeDeleteModal() {
        document.getElementById('deleteModal').classList.remove('active');
    }
    document.getElementById('deleteModal').addEventListener('click', function(e) {
        if (e.target === this) closeDeleteModal();
    });
</script>

<?php include '../includes/footer.php'; ?>