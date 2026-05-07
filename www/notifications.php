<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
require_once 'config/database.php';
include 'includes/header.php';

// تحديد الكل كمقروء
if (isset($_GET['mark_all'])) {
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    header("Location: notifications.php");
    exit;
}

// تحديد إشعار واحد كمقروء
if (isset($_GET['read'])) {
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    $stmt->execute([$_GET['read'], $_SESSION['user_id']]);
    header("Location: notifications.php");
    exit;
}

// جلب جميع الإشعارات
$stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$_SESSION['user_id']]);
$notifications = $stmt->fetchAll();
?>

<style>
    .data-table { width: 100%; border-collapse: collapse; }
    .data-table th, .data-table td { padding: 10px; border-bottom: 1px solid #ddd; text-align: center; }
    .data-table th { background: #2a5298; color: white; }
    .unread { background: #fff8e1; }
    .read { background: #fff; }
    .btn-sm { background: #2a5298; color: white; padding: 5px 12px; border-radius: 20px; text-decoration: none; display: inline-block; margin-bottom: 15px; }
    .btn-sm:hover { background: #1e3c72; }
</style>

<h2>📬 مركز الإشعارات</h2>
<a href="?mark_all=read" class="btn-sm">✅ تحديد الكل كمقروء</a>

<table class="data-table">
    <thead>
        <tr>
            <th>#</th>
            <th>الرسالة</th>
            <th>التاريخ</th>
            <th>الحالة</th>
            <th>إجراء</th>
        </tr>
    </thead>
    <tbody>
        <?php $i = 1; foreach ($notifications as $notif): ?>
        <tr class="<?= $notif['is_read'] ? 'read' : 'unread' ?>">
            <td><?= $i++ ?></td>
            <td>
                <a href="<?= $notif['link'] ?>" style="text-decoration: none; color: #333;">
                    <?= htmlspecialchars($notif['message']) ?>
                </a>
             </span></small></td>
            <td><?= date('d/m/Y H:i', strtotime($notif['created_at'])) ?></td>
            <td><?= $notif['is_read'] ? '✅ مقروء' : '🟡 جديد' ?></td>
            <td>
                <?php if (!$notif['is_read']): ?>
                    <a href="?read=<?= $notif['id'] ?>">تحديد كمقروء</a>
                <?php else: ?>
                    —
                <?php endif; ?>
             </span></small></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php include 'includes/footer.php'; ?>