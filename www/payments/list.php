<?php
session_start();
require_once '../includes/auth_check.php';
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

require_once '../config/database.php';

// ========== معالجة الحذف أولاً ==========
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM source_payments WHERE id = ?");
    $stmt->execute([$id]);
    
    $_SESSION['message'] = "✅ تم حذف الشيك بنجاح.";
    header("Location: list.php");
    exit;
}

// ========== جلب البيانات ==========
$payments = $pdo->query("
    SELECT sp.*, s.name as source_name 
    FROM source_payments sp 
    JOIN sources s ON sp.source_id = s.id 
    ORDER BY sp.cheque_date DESC, sp.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

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

    <table class="data-table">
        <thead>
            <tr>
                <th>#</th>
                <th>المصدر</th>
                <th>رقم الشيك</th>
                <th>التاريخ</th>
                <th>الربع</th>          <!-- العمود الجديد -->
                <th>المبلغ</th>
                <th>ملاحظات</th>
                <th>الإجراءات</th>
            </tr>
        </thead>
        <tbody>
            <?php $i = 1; foreach($payments as $p): 
                $is_saadine = ($p['source_id'] == 1);
                $quarter_text = '';
                if ($is_saadine && !empty($p['quarter'])) {
                    $quarter_text = 'الربع ' . $p['quarter'];
                } else {
                    $quarter_text = '-';
                }
            ?>
            <tr>
                <td><?= $i++ ?></td>
                <td><?= htmlspecialchars($p['source_name']) ?></td>
                <td><?= htmlspecialchars($p['cheque_number'] ?? '-') ?></td>
                <td><?= date('d/m/Y', strtotime($p['cheque_date'])) ?></td>
                <td>
                    <strong style="<?= $is_saadine ? 'color:#007bff;' : 'color:#999;' ?>">
                        <?= $quarter_text ?>
                    </strong>
                </td>
                <td style="text-align: right; font-weight: 600;">
                    <?= number_format($p['amount'], 2) ?> دج
                </td>
                <td><?= htmlspecialchars($p['notes'] ?? '-') ?></td>
                <td>
                    <a href="edit.php?id=<?= $p['id'] ?>" class="btn-sm" style="background:#ffc107; color:#333;">✏️ تعديل</a>
                    <a href="?delete=<?= $p['id'] ?>" 
                       onclick="return confirm('هل أنت متأكد من حذف هذا الشيك؟')" 
                       class="btn-sm" style="background:#dc3545; color:white;">🗑️ حذف</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if (empty($payments)): ?>
        <div style="text-align:center; padding:40px; color:#666;">
            لا توجد شيكات مسجلة حتى الآن.
        </div>
    <?php endif; ?>
</div>

<?php include '../includes/footer.php'; ?>