<?php
/**
 * grants/employee_list.php - قائمة المنح (زر الحذف يرسل GET)
 */
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';
include '../includes/header.php';

$grant_filter = isset($_GET['grant_id']) ? (int)$_GET['grant_id'] : 0;
$search = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';

$grantsList = $pdo->query("SELECT id, name FROM grants ORDER BY name")->fetchAll();

$sql = "
    SELECT 
        eg.id, 
        eg.grant_date, 
        eg.notes as grant_notes,
        eg.amount as grant_amount,
        e.name as employee_name, 
        e.category,
        g.name as grant_name,
        g.amount as default_amount
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
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$grants = $stmt->fetchAll();

$totalAmount = 0;
foreach ($grants as $g) {
    $displayAmount = ($g['grant_amount'] > 0) ? $g['grant_amount'] : $g['default_amount'];
    $totalAmount += $displayAmount;
}
$totalCount = count($grants);
?>

<style>
    .stats-card { background: #e3f2fd; padding: 15px; border-radius: 12px; margin-bottom: 20px; text-align: center; }
    .stats-number { font-size: 24px; font-weight: bold; color: #2a5298; }
    .filters { background: #f0f2f5; padding: 15px; border-radius: 10px; margin-bottom: 20px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
    .filter-group { display: flex; align-items: center; gap: 5px; background: white; padding: 5px 10px; border-radius: 8px; border: 1px solid #ddd; }
    .data-table { width: 100%; border-collapse: collapse; background: white; }
    .data-table th, .data-table td { border: 1px solid #ddd; padding: 10px; text-align: center; }
    .data-table th { background: #2a5298; color: white; }
    .total-row { background: #ffd700; font-weight: bold; }
    .btn-sm { background: #2a5298; color: white; padding: 5px 12px; border-radius: 20px; text-decoration: none; font-size: 12px; }
    .btn-reset { background: #6c757d; }
    .btn-back { background: #17a2b8; margin-bottom: 15px; display: inline-block; }
    .btn-delete { background: #dc3545; color: white; padding: 4px 12px; border-radius: 20px; text-decoration: none; font-size: 12px; display: inline-block; }
    .btn-delete:hover { background: #c82333; }
</style>

<div style="max-width: 1200px; margin: 0 auto;">
    <a href="list.php" class="btn-sm btn-back">🔙 العودة إلى أنواع المنح</a>
    <h2>🎁 منح الموظفين (<?= $totalCount ?> منحة) - إجمالي المبلغ: <?= number_format($totalAmount, 2) ?> دج</h2>
    
    <div class="filters">
        <form method="GET" style="display: flex; gap: 10px; flex-wrap: wrap; width: 100%;">
            <div class="filter-group">
                <label>🏷️ نوع المنحة:</label>
                <select name="grant_id">
                    <option value="0">جميع الأنواع</option>
                    <?php foreach ($grantsList as $g): ?>
                        <option value="<?= $g['id'] ?>" <?= ($grant_filter == $g['id']) ? 'selected' : '' ?>><?= escape($g['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>🔍 بحث:</label>
                <input type="text" name="search" value="<?= escape($search) ?>" placeholder="اسم الموظف...">
            </div>
            <button type="submit" class="btn-sm">بحث</button>
            <?php if ($grant_filter > 0 || $search): ?>
                <a href="employee_list.php" class="btn-sm btn-reset">إلغاء الفلتر</a>
            <?php endif; ?>
        </form>
    </div>
    
    <div style="overflow-x: auto;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>الموظف</th>
                    <th>نوع المنحة</th>
                    <th>المبلغ (دج)</th>
                    <th>تاريخ المنح</th>
                    <th>السبب</th>
                    <th>الإجراءات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($grants)): ?>
                    <tr><td colspan="7" style="text-align:center;">لا توجد منح مسجلة</span></small></td>
                <?php else: ?>
                    <?php $i = 1; foreach ($grants as $g): 
                        $displayAmount = ($g['grant_amount'] > 0) ? $g['grant_amount'] : $g['default_amount'];
                    ?>
                        <tr>
                            <td><?= $i++ ?> </span></small>
                            <td><?= escape($g['employee_name']) ?><br><small>(<?= $g['category'] == 'Permanent' ? 'دائم' : 'متعاقد' ?>)</small></td>
                            <td><?= escape($g['grant_name']) ?> </span></small>
                            <td><?= number_format($displayAmount, 2) ?> دج</span></small>
                            <td><?= date('d/m/Y', strtotime($g['grant_date'])) ?> </span></small>
                            <td><?= escape($g['grant_notes'] ?? '-') ?> </span></small>
                            <td class="action-buttons">
                                <a href="delete_employee_grant.php?id=<?= $g['id'] ?>" class="btn-delete">🗑️ حذف</a>
                             </span></small>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr class="total-row">
                    <td colspan="2"><strong>الإجمالي</strong></span></small></td>
                    <td colspan="5"><strong><?= number_format($totalAmount, 2) ?> دج</strong></span></small></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<?php include '../includes/footer.php'; ?>