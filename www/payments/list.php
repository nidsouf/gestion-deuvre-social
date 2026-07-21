<?php
ob_start();
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';
require_once '../includes/common_helpers.php';

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $pdo->prepare("DELETE FROM source_payments WHERE id = ?")->execute([$id]);
    setToast('✅ تم حذف الشيك بنجاح', 'success');
    redirectTo('list.php');
}

$payments = $pdo->query("SELECT sp.*, s.name as source_name FROM source_payments sp JOIN sources s ON sp.source_id = s.id ORDER BY sp.cheque_date DESC, sp.id DESC")->fetchAll();

$totalCheques = count($payments);
$totalAmount = array_sum(array_column($payments, 'amount'));
$saadineCount = count(array_filter($payments, fn($p) => $p['source_id'] == 1));

include '../includes/header.php';
?>
<link rel="stylesheet" href="../assets/css/payments.css">

<div class="payments-container">
    <div class="payments-header">
        <h2>💵 إدارة الشيكات</h2>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <a href="add.php" class="btn-sm btn-edit" style="background:#28a745; color:white; padding:8px 20px;">➕ إضافة شيك</a>
            <a href="reconcile.php" class="btn-sm" style="background:#2a5298; color:white; padding:8px 20px;">🔍 مطابقة</a>
            <a href="report.php" class="btn-sm" style="background:#17a2b8; color:white; padding:8px 20px;">📄 تقرير</a>
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat-card total"><div class="stat-icon">🧾</div><div class="stat-label">إجمالي الشيكات</div><div class="stat-value"><?= number_format($totalCheques) ?></div></div>
        <div class="stat-card amount"><div class="stat-icon">💰</div><div class="stat-label">إجمالي المبلغ</div><div class="stat-value"><?= formatAmount($totalAmount) ?></div></div>
        <div class="stat-card saadine"><div class="stat-icon">🏦</div><div class="stat-label">شيكات سعدين</div><div class="stat-value"><?= number_format($saadineCount) ?></div></div>
    </div>

    <div style="overflow-x:auto;">
        <table class="payments-table">
            <thead><tr><th>#</th><th>المصدر</th><th>رقم الشيك</th><th>التاريخ</th><th>الربع</th><th>المبلغ</th><th>ملاحظات</th><th>الإجراءات</th></tr></thead>
            <tbody>
                <?php $i=1; foreach($payments as $p): $is_saadine = ($p['source_id'] == 1); ?>
                <tr>
                    <td><?= $i++ ?></td>
                    <td><?= htmlspecialchars($p['source_name']) ?></td>
                    <td><?= htmlspecialchars($p['cheque_number'] ?? '-') ?></td>
                    <td><?= safeFormatDate($p['cheque_date']) ?></td>
                    <td><strong style="color:<?= $is_saadine ? '#007bff' : '#999' ?>"><?= $is_saadine && $p['quarter'] ? 'الربع '.$p['quarter'] : '---' ?></strong></td>
                    <td><?= number_format($p['amount'], 2) ?> دج</td>
                    <td><?= htmlspecialchars($p['notes'] ?? '-') ?></td>
                    <td>
                        <a href="edit.php?id=<?= $p['id'] ?>" class="btn-sm btn-edit">✏️ تعديل</a>
                        <a href="?delete=<?= $p['id'] ?>" class="btn-sm btn-delete" onclick="return confirm('حذف هذا الشيك؟')">🗑️ حذف</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if (empty($payments)): ?><div style="text-align:center; padding:40px; color:#666;">لا توجد شيكات مسجلة.</div><?php endif; ?>
</div>

<?php include '../includes/footer.php'; ?>