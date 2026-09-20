<?php
ob_start();
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';
require_once '../includes/common_helpers.php';

// ============================================================
// معالج ربط جميع الشيكات غير المرتبطة
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['link_all'])) {
    requireCSRFToken();

    try {
        $pdo->beginTransaction();

        // جلب جميع الشيكات التي ليس لها budget_transaction_id
        $stmt = $pdo->query("SELECT id, amount, cheque_number, cheque_date FROM source_payments WHERE budget_transaction_id IS NULL");
        $unlinked = $stmt->fetchAll();

        if (empty($unlinked)) {
            setToast('ℹ️ لا توجد شيكات غير مرتبطة.', 'info');
            redirectTo('list.php');
            exit;
        }

        $count = 0;
        foreach ($unlinked as $ch) {
            // إنشاء سجل في budget_transactions
            $desc = "دفع شيك رقم {$ch['cheque_number']} - " . date('d/m/Y', strtotime($ch['cheque_date']));
            $stmt = $pdo->prepare("
                INSERT INTO budget_transactions (type, reference_id, amount, is_deduct, description, transaction_date)
                VALUES ('payment', ?, ?, 1, ?, datetime('now'))
            ");
            $stmt->execute([$ch['id'], $ch['amount'], $desc]);
            $btId = $pdo->lastInsertId();

            // تحديث source_payments بربط المعاملة
            $pdo->prepare("UPDATE source_payments SET budget_transaction_id = ? WHERE id = ?")
                ->execute([$btId, $ch['id']]);

            // تحديث social_budget (خصم)
            $pdo->prepare("
                UPDATE social_budget 
                SET remaining_budget = remaining_budget - ?,
                    last_updated = datetime('now')
                WHERE id = 1
            ")->execute([$ch['amount']]);

            $count++;
        }

        $pdo->commit();
        setToast("✅ تم ربط $count شيك بنجاح وتحديث الميزانية.", 'success');

    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("❌ خطأ في ربط الشيكات: " . $e->getMessage());
        setToast('❌ حدث خطأ أثناء الربط: ' . $e->getMessage(), 'error');
    }

    redirectTo('list.php');
    exit;
}

// ============================================================
// معالج الحذف (موجود سابقاً)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    requireCSRFToken();
    
    $id = (int)$_POST['delete_id'];

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT amount, budget_transaction_id FROM source_payments WHERE id = ?");
        $stmt->execute([$id]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$payment) {
            throw new Exception('الشيك غير موجود');
        }

        $amount = (float)$payment['amount'];
        $btId = $payment['budget_transaction_id'];

        if ($btId) {
            $pdo->prepare("DELETE FROM budget_transactions WHERE id = ?")->execute([$btId]);
        }

        $pdo->prepare("
            UPDATE social_budget 
            SET remaining_budget = remaining_budget + ?,
                last_updated = datetime('now')
            WHERE id = 1
        ")->execute([$amount]);

        $pdo->prepare("DELETE FROM source_payments WHERE id = ?")->execute([$id]);

        $pdo->commit();
        setToast('✅ تم حذف الشيك واسترجاع ' . number_format($amount, 2) . ' دج إلى الميزانية', 'success');

    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("❌ خطأ حذف الشيك: " . $e->getMessage());
        setToast('❌ حدث خطأ أثناء الحذف: ' . $e->getMessage(), 'error');
    }

    redirectTo('list.php');
    exit;
}

// ============================================================
// إحصائيات المصادر (كل مصدر على حدة)
// ============================================================
$sourceStats = $pdo->query("
    SELECT s.id, s.name, 
           COUNT(sp.id) as count, 
           COALESCE(SUM(sp.amount), 0) as total
    FROM sources s
    LEFT JOIN source_payments sp ON s.id = sp.source_id
    GROUP BY s.id
    ORDER BY s.name
")->fetchAll();

$totalCheques = array_sum(array_column($sourceStats, 'count'));
$totalAmount = array_sum(array_column($sourceStats, 'total'));

// عدد الشيكات غير المرتبطة
$unlinkedCount = $pdo->query("SELECT COUNT(*) FROM source_payments WHERE budget_transaction_id IS NULL")->fetchColumn();

// ============================================================
// آخر حركات الميزانية المرتبطة بالشيكات
// ============================================================
$recentTransactions = $pdo->query("
    SELECT bt.*, sp.cheque_number, s.name as source_name
    FROM source_payments sp
    LEFT JOIN budget_transactions bt ON sp.budget_transaction_id = bt.id
    LEFT JOIN sources s ON sp.source_id = s.id
    WHERE bt.id IS NOT NULL
    ORDER BY bt.transaction_date DESC
    LIMIT 5
")->fetchAll();

// ============================================================
// عرض القائمة الرئيسية
// ============================================================
$payments = $pdo->query("
    SELECT sp.*, s.name as source_name, bt.transaction_date as budget_date, bt.id as budget_id
    FROM source_payments sp 
    JOIN sources s ON sp.source_id = s.id 
    LEFT JOIN budget_transactions bt ON sp.budget_transaction_id = bt.id
    ORDER BY sp.cheque_date DESC, sp.id DESC
")->fetchAll();

$csrf_token = generateCSRFToken();
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
            <a href="../budget/report.php?type=payment" class="btn-sm" style="background:#6f42c1; color:white; padding:8px 20px;">📊 سجل الميزانية</a>
            <?php if ($unlinkedCount > 0): ?>
                <form method="POST" style="display:inline;">
                    <?= csrfField() ?>
                    <button type="submit" name="link_all" class="btn-sm" style="background:#dc3545; color:white; padding:8px 20px;" 
                            onclick="return confirm('⚠️ سيتم ربط <?= $unlinkedCount ?> شيك غير مرتبط بمعاملات ميزانية جديدة. هل أنت متأكد؟')">
                        🔗 ربط الكل (<?= $unlinkedCount ?>)
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- ========== بطاقات الإحصائيات ========== -->
    <div class="stats-grid">
        <div class="stat-card total">
            <div class="stat-icon">🧾</div>
            <div class="stat-label">إجمالي الشيكات</div>
            <div class="stat-value"><?= number_format($totalCheques) ?></div>
        </div>
        <div class="stat-card amount">
            <div class="stat-icon">💰</div>
            <div class="stat-label">إجمالي المبلغ</div>
            <div class="stat-value"><?= formatAmount($totalAmount) ?></div>
        </div>
        <!-- بطاقات لكل مصدر -->
        <?php foreach ($sourceStats as $src): 
            $hash = md5($src['name']);
            $color1 = substr($hash, 0, 6);
            $color2 = substr($hash, 6, 6);
        ?>
            <div class="stat-card" style="background:linear-gradient(135deg, #<?= $color1 ?>, #<?= $color2 ?>); color:#ffffff; text-shadow:0 1px 3px rgba(0,0,0,0.4);">
                <div class="stat-icon" style="color:#ffffff; opacity:0.9;">🏦</div>
                <div class="stat-label" style="color:#ffffff; font-weight:600; font-size:14px;"><?= htmlspecialchars($src['name']) ?></div>
                <div class="stat-value" style="color:#ffffff; font-weight:700; font-size:24px;"><?= formatAmount($src['total']) ?></div>
                <small style="color:#ffffff; opacity:0.9; font-weight:500;"><?= $src['count'] ?> شيك</small>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- ========== جدول آخر حركات الميزانية ========== -->
    <div style="margin: 25px 0 20px;">
        <h4>🕒 آخر حركات الميزانية (شيكات)</h4>
        <div style="overflow-x:auto;">
            <table class="payments-table" style="font-size:14px;">
                <thead>
                    <tr>
                        <th>التاريخ</th>
                        <th>رقم الشيك</th>
                        <th>المصدر</th>
                        <th>المبلغ</th>
                        <th>النوع</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recentTransactions)): ?>
                        <tr><td colspan="5" style="text-align:center;">
                            لا توجد معاملات مالية مرتبطة بالشيكات.
                            <?php if ($unlinkedCount > 0): ?>
                                <br><small>⚠️ يوجد <strong><?= $unlinkedCount ?></strong> شيك غير مرتبط. استخدم زر <strong>"🔗 ربط الكل"</strong> أعلاه.</small>
                            <?php endif; ?>
                        </td></tr>
                    <?php else: ?>
                        <?php foreach ($recentTransactions as $rt): ?>
                            <tr>
                                <td><?= safeFormatDate($rt['transaction_date'], 'Y-m-d H:i') ?></td>
                                <td><?= htmlspecialchars($rt['cheque_number'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($rt['source_name']) ?></td>
                                <td class="<?= $rt['is_deduct'] ? 'debit' : 'credit' ?>">
                                    <?= $rt['is_deduct'] ? '−' : '+' ?> <?= formatAmount($rt['amount']) ?>
                                </td>
                                <td><span class="badge bg-secondary"><?= $rt['type'] ?? 'شيك' ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div style="margin-top:5px;">
            <a href="../budget/report.php?type=payment" class="btn-sm" style="background:#6f42c1; color:white; padding:4px 16px; border-radius:20px; text-decoration:none;">📊 عرض كل الحركات</a>
        </div>
    </div>

    <!-- ========== الجدول الرئيسي للشيكات ========== -->
    <!-- ========== الجدول الرئيسي للشيكات ========== -->
<div style="overflow-x:auto;">
    <table class="payments-table">
        <thead>
            <tr>
                <th>#</th>
                <th>المصدر</th>
                <th>التصنيف</th>
                <th>رقم الشيك</th>
                <th>التاريخ</th>
                <th>الربع</th>
                <th>المبلغ</th>
                <th>رقم المعاملة</th>
                <th>تاريخ المعاملة</th>
                <th>ملاحظات</th>
                <th>الإجراءات</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($payments)): ?>
                <tr><td colspan="11" style="text-align:center;">لا توجد شيكات مسجلة.</td></tr>
            <?php else:
                $i = 1;
                foreach ($payments as $p):
                    $is_saadine = ($p['source_id'] == 1);
                    $category = $p['category'] ?? 'deduction';
                    $categoryInfo = [
                        'deduction' => ['label' => '🔗 اقتطاعات', 'color' => '#28a745'],
                        'purchase'  => ['label' => '🛒 مشتريات', 'color' => '#ff9800'],
                        'other'     => ['label' => '📦 أخرى', 'color' => '#6c757d']
                    ][$category] ?? ['label' => 'غير محدد', 'color' => '#999'];
            ?>
                <tr>
                    <td><?= $i++ ?></td>
                    <td><?= htmlspecialchars($p['source_name']) ?></td>
                    <td>
                        <span style="background:<?= $categoryInfo['color'] ?>; color:white; padding:3px 10px; border-radius:12px; font-size:12px; font-weight:bold;">
                            <?= $categoryInfo['label'] ?>
                        </span>
                    </td>
                    <td><?= htmlspecialchars($p['cheque_number'] ?? '-') ?></td>
                    <td><?= safeFormatDate($p['cheque_date']) ?></td>
                    <td><strong style="color:<?= $is_saadine ? '#007bff' : '#999' ?>"><?= $is_saadine && $p['quarter'] ? 'الربع '.$p['quarter'] : '---' ?></strong></td>
                    <td><?= number_format($p['amount'], 2) ?> دج</td>
                    <td>
                        <?php if ($p['budget_id']): ?>
                            <a href="../budget/report.php?type=payment&highlight=<?= $p['budget_id'] ?>" title="عرض في تقرير الميزانية">#<?= $p['budget_id'] ?></a>
                        <?php else: ?>
                            <span style="color:#999;">غير مرتبط</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $p['budget_date'] ? safeFormatDate($p['budget_date'], 'Y-m-d H:i') : '—' ?></td>
                    <td><?= htmlspecialchars($p['notes'] ?? '-') ?></td>
                    <td>
                        <a href="edit.php?id=<?= $p['id'] ?>" class="btn-sm btn-edit">✏️ تعديل</a>
                        <button type="button" class="btn-sm btn-delete" 
                                data-bs-toggle="modal" 
                                data-bs-target="#deleteModal"
                                data-id="<?= $p['id'] ?>"
                                data-name="<?= htmlspecialchars($p['source_name']) ?>"
                                data-cheque="<?= htmlspecialchars($p['cheque_number'] ?? '-') ?>"
                                data-amount="<?= number_format($p['amount'], 2) ?>">
                            🗑️ حذف
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>


<!-- ========== مودال تأكيد الحذف ========== -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">⚠️ تأكيد حذف الشيك</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>هل أنت متأكد من حذف الشيك التالي؟</p>
                <table class="table table-bordered">
                    <tr><th>المصدر</th><td id="deleteSourceName"></td></tr>
                    <tr><th>رقم الشيك</th><td id="deleteChequeNumber"></td></tr>
                    <tr><th>المبلغ</th><td id="deleteAmount" style="font-weight:bold; color:#dc3545;"></td></tr>
                </table>
                <p class="text-warning"><small>⚠️ سيتم استرجاع المبلغ إلى الميزانية تلقائياً.</small></p>
            </div>
            <div class="modal-footer">
                <form method="POST" id="deleteForm">
                    <?= csrfField() ?>
                    <input type="hidden" name="delete_id" id="deleteId" value="">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-danger">🗑️ نعم، احذف</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const deleteModal = document.getElementById('deleteModal');
    
    deleteModal.addEventListener('show.bs.modal', function(event) {
        const button = event.relatedTarget;
        document.getElementById('deleteId').value = button.getAttribute('data-id');
        document.getElementById('deleteSourceName').textContent = button.getAttribute('data-name');
        document.getElementById('deleteChequeNumber').textContent = button.getAttribute('data-cheque');
        document.getElementById('deleteAmount').textContent = button.getAttribute('data-amount') + ' دج';
    });
});
</script>

<?php include '../includes/footer.php'; ?>