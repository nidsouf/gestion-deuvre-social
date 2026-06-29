<?php
session_start();
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}
require_once 'config/database.php';
require_once 'includes/functions.php';

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$action = isset($_GET['action']) ? $_GET['action'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

$sql = "SELECT * FROM audit_logs WHERE 1=1";
$params = [];

if ($search !== '') {
    $sql .= " AND (username LIKE ? OR action LIKE ? OR details LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($action !== '') {
    $sql .= " AND action = ?";
    $params[] = $action;
}
if ($date_from !== '') {
    $sql .= " AND date(created_at) >= ?";
    $params[] = $date_from;
}
if ($date_to !== '') {
    $sql .= " AND date(created_at) <= ?";
    $params[] = $date_to;
}
$sql .= " ORDER BY created_at DESC LIMIT 500";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

include 'includes/header.php';
?>

<style>
    .log-table { width: 100%; border-collapse: collapse; }
    .log-table th, .log-table td { border: 1px solid #ddd; padding: 8px; text-align: center; font-size: 12px; }
    .log-table th { background: #2a5298; color: white; }
    .filter-bar { background: #f8f9fa; padding: 15px; border-radius: 10px; margin-bottom: 20px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
    .filter-bar input, .filter-bar select { padding: 6px 12px; border-radius: 20px; border: 1px solid #ccc; }
    .badge-action { padding: 3px 8px; border-radius: 15px; font-size: 10px; display: inline-block; }
    .badge-add { background: #28a745; color: white; }
    .badge-edit { background: #ffc107; color: #333; }
    .badge-delete { background: #dc3545; color: white; }
    .badge-payment { background: #17a2b8; color: white; }
    .badge-budget { background: #6c757d; color: white; }
</style>

<div style="max-width: 1200px; margin: 0 auto;">
    <h2>📜 سجل التدقيق (Audit Log)</h2>

    <div class="filter-bar">
        <form method="GET" style="display: flex; gap: 10px; flex-wrap: wrap; width: 100%;">
            <input type="text" name="search" placeholder="بحث..." value="<?= htmlspecialchars($search ?? '') ?>">
            <select name="action">
                <option value="">جميع العمليات</option>
                <option value="ADD_DEDUCTION" <?= $action == 'ADD_DEDUCTION' ? 'selected' : '' ?>>إضافة اقتطاع</option>
                <option value="EDIT_DEDUCTION" <?= $action == 'EDIT_DEDUCTION' ? 'selected' : '' ?>>تعديل اقتطاع</option>
                <option value="DELETE_DEDUCTION" <?= $action == 'DELETE_DEDUCTION' ? 'selected' : '' ?>>حذف اقتطاع</option>
                <option value="EARLY_PAYMENT" <?= $action == 'EARLY_PAYMENT' ? 'selected' : '' ?>>تسديد مقدم</option>
                <option value="UNDO_EARLY_PAYMENT" <?= $action == 'UNDO_EARLY_PAYMENT' ? 'selected' : '' ?>>إلغاء تسديد</option>
                <option value="BUDGET_UPDATED" <?= $action == 'BUDGET_UPDATED' ? 'selected' : '' ?>>تعديل ميزانية</option>
                <option value="BUDGET_RESET" <?= $action == 'BUDGET_RESET' ? 'selected' : '' ?>>إعادة تعيين ميزانية</option>
                <option value="EMPLOYEE_GRANT_DELETED" <?= $action == 'EMPLOYEE_GRANT_DELETED' ? 'selected' : '' ?>>حذف منحة موظف</option>
            </select>
            <input type="date" name="date_from" value="<?= htmlspecialchars($date_from ?? '') ?>">
            <input type="date" name="date_to" value="<?= htmlspecialchars($date_to ?? '') ?>">
            <button type="submit" class="btn-primary">فلترة</button>
            <a href="audit_log.php" class="btn-secondary">إلغاء</a>
        </form>
    </div>

    <div style="overflow-x: auto;">
        <table class="log-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>التاريخ</th>
                    <th>المستخدم</th>
                    <th>العملية</th>
                    <th>التفاصيل</th>
                    <th>IP</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr><td colspan="6" style="text-align: center;">لا توجد سجلات</td></tr>
                <?php else: $i = 1; foreach ($logs as $log): ?>
                    <tr>
                        <td><?= $i++ ?></td>
                        <td><?= date('d/m/Y H:i:s', strtotime($log['created_at'])) ?></td>
                        <td><?= htmlspecialchars($log['username'] ?? 'guest') ?></td>
                        <td>
                            <span class="badge-action 
                                <?php 
                                    if (strpos($log['action'] ?? '', 'ADD') !== false) echo 'badge-add';
                                    elseif (strpos($log['action'] ?? '', 'EDIT') !== false) echo 'badge-edit';
                                    elseif (strpos($log['action'] ?? '', 'DELETE') !== false) echo 'badge-delete';
                                    elseif (strpos($log['action'] ?? '', 'PAYMENT') !== false) echo 'badge-payment';
                                    else echo 'badge-budget';
                                ?>
                            ">
                                <?= htmlspecialchars($log['action'] ?? '-') ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($log['details'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($log['ip_address'] ?? '-') ?></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'includes/footer.php'; ?>