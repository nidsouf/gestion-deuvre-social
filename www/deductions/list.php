<?php
/**
 * deductions/list.php - قائمة الاقتطاعات
 * - إحصائيات (الكل، نشط، ينتهي قريباً، منتهي)
 * - فلاتر (المصدر، الحالة، البحث)
 * - أزرار: تعديل، عرض التفاصيل، تسديد مقدم (للسلف فقط)، إلغاء التسديد (في حال وجود تسديد نشط)، حذف (مودال)
 */
ob_start();
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';

$search = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';
$source_filter = isset($_GET['source']) ? (int)$_GET['source'] : 0;
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

$sources = $pdo->query("SELECT id, name FROM sources ORDER BY name")->fetchAll();

// بناء الاستعلام الرئيسي (إضافة credit_balance)
$sql = "
    SELECT 
        d.id, e.name as employee_name, s.name as source_name,
        d.monthly_amount, d.total_months, d.start_date, d.end_date, d.is_loan, d.credit_balance,
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

$params = [];
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
$sql .= " ORDER BY d.id DESC";

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$deductions = $stmt->fetchAll();

// إحصائيات سريعة
$totalAll = count($deductions);
$totalActive = count(array_filter($deductions, fn($d) => $d['status'] == 'نشط'));
$totalExpiring = count(array_filter($deductions, fn($d) => $d['status'] == 'ينتهي قريباً'));
$totalExpired = count(array_filter($deductions, fn($d) => $d['status'] == 'منتهي'));

// جلب التسديدات المقدمة النشطة (غير ملغاة) لكل اقتطاع
$earlyMap = [];
$stmtEarly = $pdo->query("SELECT deduction_id, id FROM early_payments WHERE is_reversed = 0");
while ($row = $stmtEarly->fetch()) {
    $earlyMap[$row['deduction_id']] = $row['id'];
}

$csrf_token = generateCSRFToken();
include '../includes/header.php';
?>

<style>
    /* الأزرار والتصميمات */
    .btn-edit { background: #ffc107; color: #000; padding: 4px 12px; border-radius: 20px; text-decoration: none; display: inline-block; margin: 2px; font-size: 12px; }
    .btn-view { background: #17a2b8; color: white; padding: 4px 12px; border-radius: 20px; text-decoration: none; font-size: 12px; display: inline-block; margin: 2px; }
    .btn-view:hover { background: #138496; }
    .btn-delete { background: #dc3545; color: white; padding: 4px 12px; border-radius: 20px; border: none; cursor: pointer; font-size: 12px; display: inline-block; margin: 2px; }
    .btn-early-payment { background: #fd7e14; color: white; padding: 4px 12px; border-radius: 20px; text-decoration: none; font-size: 12px; display: inline-block; margin: 2px; }
    .btn-early-payment:hover { background: #e36209; }
    .btn-undo { background: #6c757d; color: white; padding: 4px 12px; border-radius: 20px; text-decoration: none; font-size: 12px; display: inline-block; margin: 2px; }
    .btn-undo:hover { background: #5a6268; }
    .stats-grid { display: flex; gap: 20px; flex-wrap: wrap; margin-bottom: 30px; }
    .stat-card { background: white; border-radius: 20px; padding: 20px; text-align: center; flex: 1; min-width: 150px; border-bottom: 3px solid; text-decoration: none; color: inherit; }
    .stat-card.all { border-bottom-color: #2a5298; }
    .stat-card.active { border-bottom-color: #28a745; }
    .stat-card.expiring { border-bottom-color: #ffc107; }
    .stat-card.expired { border-bottom-color: #dc3545; }
    .stat-card .number { font-size: 28px; font-weight: 700; }
    .filters { background: white; border-radius: 20px; padding: 15px; margin-bottom: 20px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
    .filters select, .filters input { padding: 8px 15px; border: 1px solid #ddd; border-radius: 30px; }
    .data-table { width: 100%; border-collapse: collapse; background: white; border-radius: 15px; overflow: hidden; }
    .data-table th, .data-table td { border: 1px solid #ddd; padding: 10px; text-align: center; }
    .data-table th { background: #2a5298; color: white; }
    .status-badge { padding: 4px 12px; border-radius: 20px; font-size: 12px; display: inline-block; }
    .status-active { background: #d4edda; color: #155724; }
    .status-expiring { background: #fff3cd; color: #856404; }
    .status-expired { background: #f8d7da; color: #721c24; }
    .btn-add { background: #28a745; color: white; padding: 8px 20px; border-radius: 30px; text-decoration: none; display: inline-block; margin-bottom: 20px; }
    .btn-sm { background: #2a5298; color: white; padding: 6px 15px; border-radius: 30px; text-decoration: none; display: inline-block; }
    .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center; }
    .modal-content { background: white; border-radius: 20px; padding: 25px; width: 400px; text-align: center; }
    .btn-confirm { background: #dc3545; color: white; border: none; padding: 8px 20px; border-radius: 30px; cursor: pointer; }
    .btn-cancel { background: #6c757d; color: white; border: none; padding: 8px 20px; border-radius: 30px; cursor: pointer; }
.btn-postpone {
    background: #ff9800;
    color: white;
    padding: 4px 12px;
    border-radius: 20px;
    text-decoration: none;
    font-size: 12px;
    display: inline-block;
    margin: 2px;
}
</style>

<div style="max-width: 1200px; margin: 0 auto;">
    <h2>📋 قائمة الاقتطاعات</h2>
    
    <!-- الإحصائيات -->
    <div class="stats-grid">
        <a href="?status=all" class="stat-card all"><div>📊 الكل</div><div class="number"><?= $totalAll ?></div></a>
        <a href="?status=active" class="stat-card active"><div>✅ نشط</div><div class="number"><?= $totalActive ?></div></a>
        <a href="?status=expiring" class="stat-card expiring"><div>⚠️ ينتهي قريباً</div><div class="number"><?= $totalExpiring ?></div></a>
        <a href="?status=expired" class="stat-card expired"><div>❌ منتهي</div><div class="number"><?= $totalExpired ?></div></a>
    </div>
    
    <!-- فلاتر البحث -->
    <div class="filters">
        <form method="GET" style="display: flex; gap: 10px; flex-wrap: wrap; width: 100%;">
            <select name="source">
                <option value="0">جميع المصادر</option>
                <?php foreach ($sources as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= $source_filter == $s['id'] ? 'selected' : '' ?>><?= escape($s['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="status">
                <option value="">الكل</option>
                <option value="active" <?= $status_filter == 'active' ? 'selected' : '' ?>>نشط</option>
                <option value="expiring" <?= $status_filter == 'expiring' ? 'selected' : '' ?>>ينتهي قريباً</option>
                <option value="expired" <?= $status_filter == 'expired' ? 'selected' : '' ?>>منتهي</option>
            </select>
            <input type="text" name="search" placeholder="🔍 اسم الموظف" value="<?= escape($search) ?>">
            <button type="submit" class="btn-sm">بحث</button>
            <a href="list.php" class="btn-sm" style="background:#6c757d;">إلغاء</a>
        </form>
    </div>
    
    <a href="add.php" class="btn-add">➕ إضافة اقتطاع جديد</a>
    
    <div style="overflow-x: auto;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>الموظف</th>
                    <th>المصدر</th>
                    <th>المبلغ الشهري (دج)</th>
                    <th>عدد الأشهر</th>
                    <th>الرصيد الدائن (دج)</th>
                    <th>تاريخ البداية</th>
                    <th>تاريخ النهاية</th>
                    <th>الحالة</th>
                    <th>الإجراءات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($deductions)): ?>
                    <tr><td colspan="10" style="text-align:center;">لا توجد بيانات</span></small></td>
                <?php else: ?>
                    <?php foreach ($deductions as $d): 
                        $hasEarly = isset($earlyMap[$d['id']]);
                        $earlyId = $hasEarly ? $earlyMap[$d['id']] : 0;
                    ?>
                        <tr>
                            <td>
                                <?= $d['id'] ?>
                                <?php if ($d['is_loan']): ?>
                                    <span style="background:#ff9800; color:#fff; padding:2px 6px; border-radius:12px; font-size:10px; display:inline-block; margin-right:5px;">سلفة</span>
                                <?php endif; ?>
                             </span></small>
                            <td><?= escape($d['employee_name']) ?> </span></small>
                            <td><?= escape($d['source_name']) ?> </span></small>
                            <td><?= number_format($d['monthly_amount'], 2) ?> </span></small>
                            <td><?= $d['total_months'] ?> شهر</span></small>
                            <td><?= number_format($d['credit_balance'], 2) ?> </span></small>
                            <td><?= date('d/m/Y', strtotime($d['start_date'])) ?> </span></small>
                            <td><?= date('d/m/Y', strtotime($d['end_date'])) ?> </span></small>
                            <td>
                                <span class="status-badge status-<?= $d['status'] == 'نشط' ? 'active' : ($d['status'] == 'ينتهي قريباً' ? 'expiring' : 'expired') ?>">
                                    <?= $d['status'] ?>
                                </span>
                             </span></small>
                            <td class="action-buttons" style="text-align:center;">
                                <a href="edit.php?id=<?= $d['id'] ?>" class="btn-edit">✏️ تعديل</a>
                                <a href="view.php?id=<?= $d['id'] ?>" class="btn-view">👁️ عرض التفاصيل</a>
                                <a href="postpone.php?id=<?= $d['id'] ?>" class="btn-postpone">⏰ تعديل الفترة</a>
                                <a href="postpone_installment.php?id=<?= $d['id'] ?>" class="btn-postpone">📅 تأجيل قسط</a>
                                <?php if ($d['is_loan']): ?>
                                    <a href="early_payment.php?id=<?= $d['id'] ?>" class="btn-early-payment">💰 تسديد مقدم</a>
                                <?php endif; ?>
                                <?php if ($hasEarly): ?>
                                    <a href="undo_early_payment.php?id=<?= $earlyId ?>" class="btn-undo" target="_blank">↩️ إلغاء التسديد</a>
                                <?php endif; ?>
                                <button type="button" class="btn-delete" data-id="<?= $d['id'] ?>" data-name="<?= escape($d['employee_name']) ?>">🗑️ حذف</button>
                             </span></small>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- مودال تأكيد الحذف -->
<div id="deleteModal" class="modal">
    <div class="modal-content">
        <h3>⚠️ تأكيد الحذف</h3>
        <p>هل أنت متأكد من حذف الاقتطاع الخاص بـ <strong id="deleteEmployeeName"></strong>؟</p>
        <form id="deleteForm" method="POST" action="delete.php">
            <input type="hidden" name="id" id="deleteId">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <button type="submit" class="btn-confirm">🗑️ حذف</button>
            <button type="button" class="btn-cancel" onclick="closeModal()">إلغاء</button>
        </form>
    </div>
</div>

<script>
    const modal = document.getElementById('deleteModal');
    const deleteIdInput = document.getElementById('deleteId');
    const deleteEmployeeNameSpan = document.getElementById('deleteEmployeeName');

    document.querySelectorAll('.btn-delete').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            deleteIdInput.value = this.getAttribute('data-id');
            deleteEmployeeNameSpan.innerText = this.getAttribute('data-name');
            modal.style.display = 'flex';
        });
    });

    function closeModal() {
        modal.style.display = 'none';
        deleteIdInput.value = '';
        deleteEmployeeNameSpan.innerText = '';
    }

    window.onclick = function(event) {
        if (event.target === modal) closeModal();
    }
</script>

<?php include '../includes/footer.php'; ?>