<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/common_helpers.php';

$employee_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$status = isset($_GET['status']) ? $_GET['status'] : 'all';

if (!$employee_id) { header("Location: list.php"); exit; }

$stmtEmp = $pdo->prepare("SELECT name FROM employees WHERE id = ?");
$stmtEmp->execute([$employee_id]);
$employee = $stmtEmp->fetch();
if (!$employee) { die("⚠️ موظف غير موجود"); }

$today = date('Y-m-d');
$sql = "SELECT d.*, s.name as source_name FROM deductions d JOIN sources s ON d.source_id = s.id WHERE d.employee_id = :employee_id";
$params = [':employee_id' => $employee_id];
if ($status == 'active') { $sql .= " AND d.end_date >= :today"; $params[':today'] = $today; }
elseif ($status == 'expired') { $sql .= " AND d.end_date < :today"; $params[':today'] = $today; }
elseif ($status == 'expiring') { $sql .= " AND d.end_date >= :today AND julianday(d.end_date) - julianday(:today) <= 30"; $params[':today'] = $today; }
$sql .= " ORDER BY d.start_date DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$deductions = $stmt->fetchAll();

$total_all = count($deductions);
$stmtActive = $pdo->prepare("SELECT COUNT(*) FROM deductions WHERE employee_id = ? AND end_date >= ?");
$stmtActive->execute([$employee_id, $today]);
$total_active = $stmtActive->fetchColumn();
$stmtExpired = $pdo->prepare("SELECT COUNT(*) FROM deductions WHERE employee_id = ? AND end_date < ?");
$stmtExpired->execute([$employee_id, $today]);
$total_expired = $stmtExpired->fetchColumn();
$stmtExpiring = $pdo->prepare("SELECT COUNT(*) FROM deductions WHERE employee_id = ? AND end_date >= ? AND julianday(end_date) - julianday(?) <= 30");
$stmtExpiring->execute([$employee_id, $today, $today]);
$total_expiring = $stmtExpiring->fetchColumn();

include '../includes/header.php';
?>
<link rel="stylesheet" href="../assets/css/employees.css">

<div class="employees-container" style="max-width:1200px; margin:0 auto; padding:0 15px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:10px;">
        <a href="list.php" style="background:#6c757d; color:white; padding:8px 16px; border-radius:25px; text-decoration:none; display:inline-flex; align-items:center; gap:5px;">🔙 العودة</a>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <a href="?id=<?= $employee_id ?>&status=all" class="filter-btn <?= $status == 'all' ? 'active' : '' ?>" style="padding:6px 15px; border-radius:25px; text-decoration:none; background:<?= $status=='all'?'#2a5298':'#f0f2f5' ?>; color:<?= $status=='all'?'white':'#333' ?>;">📋 الكل (<?= $total_all ?>)</a>
            <a href="?id=<?= $employee_id ?>&status=active" class="filter-btn <?= $status == 'active' ? 'active' : '' ?>" style="padding:6px 15px; border-radius:25px; text-decoration:none; background:<?= $status=='active'?'#2a5298':'#f0f2f5' ?>; color:<?= $status=='active'?'white':'#333' ?>;">✅ نشط (<?= $total_active ?>)</a>
            <a href="?id=<?= $employee_id ?>&status=expiring" class="filter-btn <?= $status == 'expiring' ? 'active' : '' ?>" style="padding:6px 15px; border-radius:25px; text-decoration:none; background:<?= $status=='expiring'?'#2a5298':'#f0f2f5' ?>; color:<?= $status=='expiring'?'white':'#333' ?>;">⚠️ ينتهي قريباً (<?= $total_expiring ?>)</a>
            <a href="?id=<?= $employee_id ?>&status=expired" class="filter-btn <?= $status == 'expired' ? 'active' : '' ?>" style="padding:6px 15px; border-radius:25px; text-decoration:none; background:<?= $status=='expired'?'#2a5298':'#f0f2f5' ?>; color:<?= $status=='expired'?'white':'#333' ?>;">🔴 منتهي (<?= $total_expired ?>)</a>
        </div>
    </div>

    <h2>👤 اقتطاعات الموظف: <?= htmlspecialchars($employee['name']) ?></h2>

    <?php if (empty($deductions)): ?>
        <div style="text-align:center; padding:30px; color:#666;">لا توجد اقتطاعات مسجلة لهذا الموظف.</div>
    <?php else: ?>
        <table class="employees-table">
            <thead><tr><th>#</th><th>المصدر</th><th>المبلغ الشهري</th><th>عدد الأشهر</th><th>تاريخ البداية</th><th>تاريخ النهاية</th><th>الحالة</th></tr></thead>
            <tbody>
                <?php $i=1; foreach ($deductions as $d): 
                    $status_class = 'badge-active'; $status_text = 'نشط';
                    if ($d['end_date'] < $today) { $status_class = 'badge-expired'; $status_text = 'منتهي'; }
                    elseif ((strtotime($d['end_date']) - strtotime($today)) / (60*60*24) <= 30) { $status_class = 'badge-expiring'; $status_text = 'ينتهي قريباً'; }
                ?>
                <tr>
                    <td><?= $i++ ?></td>
                    <td><?= htmlspecialchars($d['source_name']) ?></td>
                    <td><?= number_format($d['monthly_amount'], 2) ?> دج</td>
                    <td><?= $d['total_months'] ?> شهر</td>
                    <td><?= safeFormatDate($d['start_date']) ?></td>
                    <td><?= safeFormatDate($d['end_date']) ?></td>
                    <td><span class="badge-status <?= $status_class ?>" style="padding:4px 10px; border-radius:20px; font-size:12px; <?= $status_class=='badge-active'?'background:#d4edda; color:#155724;':($status_class=='badge-expiring'?'background:#fff3cd; color:#856404;':'background:#f8d7da; color:#721c24;') ?>"><?= $status_text ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php include '../includes/footer.php'; ?>