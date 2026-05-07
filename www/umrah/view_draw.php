<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
require_once '../config/database.php';
include '../includes/header.php';

$draw_event_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$draw_event_id) {
    header("Location: draw_list.php");
    exit;
}

// جلب معلومات السحب
$draw = $pdo->prepare("SELECT * FROM umrah_draw_events WHERE id = ?");
$draw->execute([$draw_event_id]);
$draw = $draw->fetch();
if (!$draw) {
    echo "<div class='container'>⚠️ السحب غير موجود.</div>";
    include '../includes/footer.php';
    exit;
}

// جلب المشاركين مع النتائج
$participants = $pdo->prepare("
    SELECT u.*, e.name as employee_name
    FROM umrah_draws u
    JOIN employees e ON u.employee_id = e.id
    WHERE u.draw_event_id = ?
    ORDER BY u.is_winner DESC, u.reserve_order ASC
");
$participants->execute([$draw_event_id]);
$participants = $participants->fetchAll();

$winner = null;
$reserves = [];
foreach ($participants as $p) {
    if ($p['is_winner']) {
        $winner = $p;
    } elseif ($p['reserve_order'] > 0) {
        $reserves[$p['reserve_order']] = $p;
    }
}
ksort($reserves);
?>

<style>
    .container { max-width: 900px; margin: auto; padding: 20px; }
    .winner-card { background: #ffd700; padding: 20px; border-radius: 20px; text-align: center; margin-bottom: 20px; }
    .reserve-card { background: #e3f2fd; padding: 10px; margin-bottom: 10px; border-radius: 12px; }
    table { width: 100%; border-collapse: collapse; background: white; margin-top: 20px; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: center; }
    th { background: #2a5298; color: white; }
    .btn-edit { background: #ff9800; color: white; padding: 8px 16px; border-radius: 30px; text-decoration: none; display: inline-block; margin-bottom: 15px; }
    .btn-print { background: #17a2b8; color: white; padding: 8px 16px; border-radius: 30px; text-decoration: none; display: inline-block; margin-left: 10px; }
</style>

<div class="container">
    <h2>🕋 نتائج سحب العمرة</h2>
    <div class="winner-card">
        <h3>🏆 الفائز الرئيسي 🏆</h3>
        <h2><?= $winner ? htmlspecialchars($winner['employee_name']) : '—' ?></h2>
        <div>(عدد الأوراق: <?= $winner ? $winner['tickets_count'] : 0 ?>)</div>
    </div>

    <h3>الاحتياطيين</h3>
    <?php if (empty($reserves)): ?>
        <p>لا يوجد احتياطيين.</p>
    <?php else: ?>
        <?php foreach ($reserves as $order => $res): ?>
            <div class="reserve-card">
                <strong>الاحتياطي <?= $order ?>:</strong> <?= htmlspecialchars($res['employee_name']) ?>
                (<?= $res['tickets_count'] ?> ورقة)
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <h3>قائمة المشاركين</h3>
    <?php if (count($participants) == 0): ?>
        <p>لا يوجد مشاركون.</p>
    <?php else: ?>
        <table>
            <thead><tr><th>الموظف</th><th>عدد الأوراق</th><th>النتيجة</th></tr></thead>
            <tbody>
                <?php foreach ($participants as $p): ?>
                <tr>
                    <td><?= htmlspecialchars($p['employee_name']) ?></td>
                    <td><?= $p['tickets_count'] ?></td>
                    <td>
                        <?php if ($p['is_winner']): ?>
                            <span style="color:green; font-weight:bold;">🏆 فائز</span>
                        <?php elseif ($p['reserve_order'] > 0): ?>
                            احتياطي <?= $p['reserve_order'] ?>
                        <?php else: ?>
                            غير فائز
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <div style="margin-top: 20px;">
        <?php if ($draw['status'] == 'pending'): ?>
            <a href="perform_draw.php?id=<?= $draw_event_id ?>" class="btn-print" style="background:#28a745;">🎲 إجراء قرعة آلية</a>
            <a href="manual_result.php?id=<?= $draw_event_id ?>" class="btn-edit">✍️ تحديد النتائج يدوياً</a>
        <?php else: ?>
            <a href="manual_result.php?id=<?= $draw_event_id ?>" class="btn-edit">✏️ تعديل النتائج يدوياً</a>
        <?php endif; ?>
        <a href="draw_list.php" class="btn-print" style="background:#6c757d;">🔙 العودة للقائمة</a>
    </div>
</div>

<?php include '../includes/footer.php'; ?>