<?php
ob_start();
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';

// معالجة الحذف
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM source_payments WHERE id = ?");
    $stmt->execute([$id]);
    $_SESSION['toast'] = ['message' => '✅ تم حذف الشيك بنجاح', 'type' => 'success', 'duration' => 3000];
    header("Location: list.php");
    exit;
}

$payments = $pdo->query("
    SELECT sp.*, s.name as source_name 
    FROM source_payments sp 
    JOIN sources s ON sp.source_id = s.id 
    ORDER BY sp.cheque_date DESC, sp.id DESC
")->fetchAll();

include '../includes/header.php';
?>

<div class="section">
    <div class="section-header">
        <h3>💵 قائمة المبالغ المسلمة (الشيكات)</h3>
        <div>
            <a href="add.php" class="btn-sm" style="background:#28a745; color:white;">➕ إضافة شيك جديد</a>
            <a href="reconcile.php" class="btn-sm">🔍 مطابقة الشيكات</a>
            <a href="report.php" class="btn-sm">📄 تقرير</a>
        </div>
    </div>

    <div style="overflow-x: auto;">
        <table class="data-table">
            <thead>
                <tr><th>#</th><th>المصدر</th><th>رقم الشيك</th><th>التاريخ</th><th>الربع</th><th>المبلغ</th><th>ملاحظات</th><th>الإجراءات</th></tr>
            </thead>
            <tbody>
                <?php $i = 1; foreach($payments as $p): 
                    $is_saadine = ($p['source_id'] == 1);
                    $quarter_text = ($is_saadine && !empty($p['quarter'])) ? 'الربع ' . $p['quarter'] : '-';
                ?>
                <tr>
                    <td><?= $i++ ?></td>
                    <td><?= htmlspecialchars($p['source_name']) ?></td>
                    <td><?= htmlspecialchars($p['cheque_number'] ?? '-') ?></td>
                    <td><?= date('d/m/Y', strtotime($p['cheque_date'])) ?></td>
                    <td><strong style="color:<?= $is_saadine ? '#007bff' : '#999' ?>"><?= $quarter_text ?></strong></td>
                    <td style="text-align:right;"><?= number_format($p['amount'], 2) ?> دج</td>
                    <td><?= htmlspecialchars($p['notes'] ?? '-') ?></td>
                    <td>
                        <a href="edit.php?id=<?= $p['id'] ?>" class="btn-sm" style="background:#ffc107; color:#333;">✏️ تعديل</a>
                        <a href="?delete=<?= $p['id'] ?>" onclick="return confirm('هل أنت متأكد من حذف هذا الشيك؟')" class="btn-sm" style="background:#dc3545; color:white;">🗑️ حذف</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if (empty($payments)): ?>
        <div style="text-align:center; padding:40px; color:#666;">لا توجد شيكات مسجلة حتى الآن.</div>
    <?php endif; ?>
</div>

<?php
ob_end_flush();
include '../includes/footer.php';
?>