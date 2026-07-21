<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/common_helpers.php';

$draws = $pdo->query("
    SELECT d.*, 
           (SELECT COUNT(*) FROM umrah_draws WHERE draw_event_id = d.id) as participants,
           (SELECT e.name FROM employees e INNER JOIN umrah_draws ud ON ud.employee_id = e.id WHERE ud.draw_event_id = d.id AND ud.is_winner = 1 LIMIT 1) as winner_name
    FROM umrah_draw_events d
    ORDER BY d.draw_date DESC
")->fetchAll();

$totalDraws = count($draws);
$completed = count(array_filter($draws, fn($d) => $d['status'] == 'completed'));
$pending = $totalDraws - $completed;

include '../includes/header.php';
?>
<link rel="stylesheet" href="../assets/css/umrah.css">

<div class="umrah-container">
    <div class="umrah-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
        <div><h2 style="margin:0;">🕋 إدارة سحوبات العمرة</h2></div>
        <a href="create_draw.php" class="btn-add">➕ إنشاء سحب جديد</a>
    </div>

    <div class="stats-grid">
        <div class="stat-card primary"><div class="stat-icon">📋</div><div class="stat-label">إجمالي السحوبات</div><div class="stat-value"><?= number_format($totalDraws) ?></div></div>
        <div class="stat-card completed"><div class="stat-icon">✅</div><div class="stat-label">مكتملة</div><div class="stat-value"><?= number_format($completed) ?></div></div>
        <div class="stat-card pending"><div class="stat-icon">⏳</div><div class="stat-label">قيد الانتظار</div><div class="stat-value"><?= number_format($pending) ?></div></div>
    </div>

    <table class="draws-table">
        <thead><tr><th>التاريخ</th><th>العنوان</th><th>المشاركين</th><th>الفائز</th><th>الحالة</th><th>الإجراءات</th></tr></thead>
        <tbody>
            <?php foreach ($draws as $draw): ?>
            <tr>
                <td><?= safeFormatDate($draw['draw_date']) ?></td>
                <td><?= htmlspecialchars($draw['title'] ?? 'سحب بدون عنوان') ?></td>
                <td><?= $draw['participants'] ?></td>
                <td><?= htmlspecialchars($draw['winner_name'] ?? '-') ?></td>
                <td><?= $draw['status'] == 'completed' ? '<span style="color:green;">✅ مكتمل</span>' : '<span style="color:orange;">⏳ قيد الانتظار</span>' ?></td>
                <td><?= $draw['status'] == 'pending' ? '<a href="perform_draw.php?id='.$draw['id'].'" style="background:#2a5298; color:white; padding:4px 12px; border-radius:20px; text-decoration:none;">🎲 إجراء القرعة</a>' : '<a href="view_draw.php?id='.$draw['id'].'" style="background:#17a2b8; color:white; padding:4px 12px; border-radius:20px; text-decoration:none;">👁️ عرض النتائج</a>' ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php include '../includes/footer.php'; ?>