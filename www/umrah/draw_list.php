<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
require_once '../config/database.php';
include '../includes/header.php';

// استعلام لا يستخدم winner_id مباشرة، بل يجلبه من subquery
$draws = $pdo->query("
    SELECT d.*, 
           (SELECT COUNT(*) FROM umrah_draws WHERE draw_event_id = d.id) as participants,
           (SELECT e.name FROM employees e 
            INNER JOIN umrah_draws ud ON ud.employee_id = e.id 
            WHERE ud.draw_event_id = d.id AND ud.is_winner = 1 LIMIT 1) as winner_name
    FROM umrah_draw_events d
    ORDER BY d.draw_date DESC
")->fetchAll();
?>

<style>
    .container { max-width: 1000px; margin: auto; padding: 20px; }
    .btn-primary { background: #2a5298; color: white; padding: 10px 20px; border-radius: 30px; text-decoration: none; display: inline-block; margin-bottom: 20px; }
    table { width: 100%; border-collapse: collapse; background: white; }
    th, td { padding: 12px; border: 1px solid #ddd; text-align: center; }
    th { background: #2a5298; color: white; }
    .status-pending { color: orange; font-weight: bold; }
    .status-completed { color: green; }
</style>

<div class="container">
    <h2>🕋 إدارة سحوبات العمرة</h2>
    <a href="create_draw.php" class="btn-primary">➕ إنشاء سحب جديد</a>

    <h3>📜 السحوبات السابقة</h3>
    <table>
        <thead>
            <tr><th>التاريخ</th><th>العنوان</th><th>عدد المشاركين</th><th>الفائز</th><th>الحالة</th><th>الإجراءات</th></tr>
        </thead>
        <tbody>
            <?php foreach ($draws as $draw): ?>
            <tr>
                <td><?= date('d/m/Y H:i', strtotime($draw['draw_date'])) ?></td>
                <td><?= htmlspecialchars($draw['title'] ?? 'سحب بدون عنوان') ?></td>
                <td><?= $draw['participants'] ?></td>
                <td><?= htmlspecialchars($draw['winner_name'] ?? '-') ?></td>
                <td class="<?= $draw['status'] == 'completed' ? 'status-completed' : 'status-pending' ?>">
                    <?= $draw['status'] == 'completed' ? 'مكتمل' : 'قيد الانتظار' ?>
                </td>
                <td>
                    <?php if ($draw['status'] == 'pending'): ?>
                        <a href="perform_draw.php?id=<?= $draw['id'] ?>">🎲 إجراء القرعة</a>
                    <?php else: ?>
                        <a href="view_draw.php?id=<?= $draw['id'] ?>">👁️ عرض النتائج</a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php include '../includes/footer.php'; ?>