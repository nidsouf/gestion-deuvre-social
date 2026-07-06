<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
require_once '../config/database.php';
require_once '../includes/functions.php';

// ========== استلام الفلاتر ==========
$grant_filter = isset($_GET['grant_id']) ? (int)$_GET['grant_id'] : 0;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// ========== جلب أنواع المنح للفلتر ==========
$grantsList = $pdo->query("SELECT id, name FROM grants ORDER BY name")->fetchAll();

// ========== بناء الاستعلام ==========
$sql = "
    SELECT 
        eg.id,
        eg.grant_date,
        eg.notes as grant_notes,
        e.name as employee_name,
        e.category,
        g.name as grant_name,
        g.amount
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

// ========== حساب الإجمالي ==========
$totalAmount = array_sum(array_column($grants, 'amount'));
$totalCount = count($grants);

include '../includes/header.php';
?>

<style>
    .grants-container { direction: rtl; padding: 20px; max-width: 1200px; margin: auto; }
    .stats-card { background: #e3f2fd; padding: 15px; border-radius: 12px; margin-bottom: 20px; text-align: center; }
    .stats-number { font-size: 24px; font-weight: bold; color: #2a5298; }
    .filters { background: #f0f2f5; padding: 15px; border-radius: 10px; margin-bottom: 20px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
    .filter-group { display: flex; align-items: center; gap: 5px; background: white; padding: 5px 10px; border-radius: 8px; border: 1px solid #ddd; }
    .filter-group label { font-weight: bold; margin: 0; }
    .data-table { width: 100%; border-collapse: collapse; background: white; }
    .data-table th, .data-table td { border: 1px solid #ddd; padding: 10px; text-align: center; vertical-align: middle; }
    .data-table th { background: #2a5298; color: white; }
    .total-row { background: #ffd700; font-weight: bold; }
    .btn-sm { background: #2a5298; color: white; padding: 5px 12px; border-radius: 20px; text-decoration: none; font-size: 12px; }
    .btn-reset { background: #6c757d; color: white; padding: 5px 12px; border-radius: 20px; text-decoration: none; font-size: 12px; }
    .btn-back { background: #17a2b8; color: white; padding: 5px 12px; border-radius: 20px; text-decoration: none; font-size: 12px; margin-right: 10px; }
</style>

<div class="grants-container">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 15px;">
        <h2 style="margin: 0;">🎁 منح الموظفين (<?= $totalCount ?> منحة) - إجمالي المبلغ: <?= number_format($totalAmount, 2) ?> دج</h2>
        <a href="/index.php" class="btn-back">🏠 العودة إلى لوحة التحكم</a>
    </div>

    <div class="filters">
        <form method="GET" action="">
            <div class="filter-group">
                <label>🏷️ نوع المنحة:</label>
                <select name="grant_id">
                    <option value="0">جميع الأنواع</option>
                    <?php foreach ($grantsList as $g): ?>
                        <option value="<?= $g['id'] ?>" <?= ($grant_filter == $g['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($g['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>🔍 بحث باسم الموظف:</label>
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="اسم الموظف...">
            </div>
            <button type="submit" class="btn-sm">بحث</button>
            <?php if ($grant_filter > 0 || $search): ?>
                <a href="employee_list.php" class="btn-reset">إلغاء الفلتر</a>
            <?php endif; ?>
        </form>
    </div>

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
                <?php $i = 1; foreach ($grants as $g): ?>
                <tr>
                    <td><?= $i++ ?> </span></small>
                    <td><?= htmlspecialchars($g['employee_name']) ?> <br><small>(<?= $g['category'] == 'Permanent' ? 'دائم' : 'متعاقد' ?>)</small></span></small>
                    <td><?= htmlspecialchars($g['grant_name']) ?> </span></small>
                    <td><?= number_format($g['amount'], 2) ?> دج</span></small>
                    <td><?= date('d/m/Y', strtotime($g['grant_date'])) ?> </span></small>
                    <td><?= htmlspecialchars($g['grant_notes']) ?> </span></small>
                    <td>
                        <a href="delete_employee_grant.php?id=<?= $g['id'] ?>" onclick="return confirm('هل أنت متأكد من حذف هذه المنحة؟')" class="btn-sm" style="background:#dc3545;">🗑️ حذف</a>
                     </span></small>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr class="total-row">
                <td colspan="2"><strong>الإجمالي</strong></span></small></td>
                <td colspan="5"><strong><?= number_format($totalAmount, 2) ?> دج</strong></span></small></td>
            </span></small></tr>
        </tfoot>
    </table>
</div>

<?php include '../includes/footer.php'; ?>