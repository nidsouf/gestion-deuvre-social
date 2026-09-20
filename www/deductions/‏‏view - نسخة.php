<?php
/**
 * deductions/view.php
 * عرض تفاصيل الاقتطاع مع جدول الأقساط
 */

require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/helpers.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$pdo = new PDO('sqlite:' . __DIR__ . '/../database.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    header('Location: list.php');
    exit;
}

$deduction = getDeductionDetails($pdo, $id);
if (!$deduction) {
    header('Location: list.php?error=notfound');
    exit;
}

// معالج تسديد قسط (عبر AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'pay_installment') {
        $installmentId = (int)$_POST['installment_id'];
        
        try {
            $pdo->beginTransaction();
            
            // جلب بيانات القسط
            $stmt = $pdo->prepare("SELECT * FROM monthly_installments WHERE id = ? AND deduction_id = ?");
            $stmt->execute([$installmentId, $id]);
            $installment = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$installment) {
                throw new Exception("القسط غير موجود");
            }
            if ($installment['is_paid']) {
                throw new Exception("القسط مسدد مسبقاً");
            }
            
            // تحديث القسط إلى مدفوع
            $pdo->prepare("UPDATE monthly_installments SET is_paid = 1, paid_at = datetime('now') WHERE id = ?")
                ->execute([$installmentId]);
            
            // تحديث المبلغ المتبقي في الاقتطاع
            $remaining = $deduction['remaining_amount'] - $installment['amount'];
            $pdo->prepare("UPDATE deductions SET remaining_amount = ? WHERE id = ?")
                ->execute([max(0, $remaining), $id]);
            
            // إذا كان الاقتطاع سلفة (loan)، سجل استرجاع في الميزانية
            if ($deduction['type'] === 'loan') {
                $stmt = $pdo->prepare("
                    INSERT INTO budget_transactions (type, reference_id, amount, is_deduct, description, created_at)
                    VALUES ('installment', ?, ?, 0, ?, datetime('now'))
                ");
                $desc = "تسديد قسط رقم {$installment['month_year']} من سلفة رقم {$id}";
                $stmt->execute([$id, $installment['amount'], $desc]);
                
                $pdo->prepare("UPDATE social_budget SET remaining_budget = remaining_budget + ?, updated_at = datetime('now') WHERE id = 1")
                    ->execute([$installment['amount']]);
            }
            
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'تم تسديد القسط بنجاح']);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'postpone_installment') {
        // إعادة توجيه إلى صفحة التأجيل مع معرف القسط
        $installmentId = (int)$_POST['installment_id'];
        header("Location: postpone_installment.php?deduction_id=$id&installment_id=$installmentId");
        exit;
    }
}

$pageTitle = 'تفاصيل الاقتطاع';
require_once __DIR__ . '/../includes/header.php';
?>

<link rel="stylesheet" href="deductions.css">

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">📄 تفاصيل الاقتطاع</h1>
        <div>
            <a href="edit.php?id=<?= $id ?>" class="btn btn-warning">✏️ تعديل</a>
            <a href="list.php" class="btn btn-secondary">⬅️ رجوع</a>
        </div>
    </div>

    <!-- معلومات الاقتطاع -->
    <div class="card mb-4">
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <p><strong>الموظف:</strong> <?= htmlspecialchars($deduction['full_name']) ?></p>
                    <p><strong>رقم الحساب:</strong> <?= htmlspecialchars($deduction['account_number']) ?></p>
                    <p><strong>نوع العقد:</strong> <?= $deduction['contract_type'] === 'permanent' ? 'دائم' : 'متعاقد' ?></p>
                </div>
                <div class="col-md-6">
                    <p><strong>نوع الاقتطاع:</strong> 
                        <span class="badge-type <?= $deduction['type'] === 'loan' ? 'badge-loan' : 'badge-advance' ?>">
                            <?= $deduction['type'] === 'loan' ? 'سلفة' : 'اقتطاع شهري' ?>
                        </span>
                    </p>
                    <p><strong>المبلغ الإجمالي:</strong> <?= number_format($deduction['amount'], 2) ?> دج</p>
                    <p><strong>القسط الشهري:</strong> <?= number_format($deduction['monthly_deduction'], 2) ?> دج</p>
                    <p><strong>المتبقي:</strong> <?= number_format($deduction['remaining_amount'], 2) ?> دج</p>
                    <p><strong>تاريخ الصرف:</strong> <?= safeFormatDate($deduction['grant_date'] ?? $deduction['start_date']) ?></p>
                    <p><strong>تاريخ البداية:</strong> <?= safeFormatDate($deduction['start_date']) ?></p>
                    <p><strong>تاريخ النهاية:</strong> <?= safeFormatDate($deduction['end_date']) ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- جدول الأقساط -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">📊 جدول الأقساط</h5>
            <span class="badge bg-info">إجمالي الأقساط: <?= count($deduction['installments']) ?></span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>الشهر</th>
                            <th>المبلغ</th>
                            <th>الحالة</th>
                            <th>تاريخ التسديد</th>
                            <th>الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $index = 1;
                        $unpaidCount = 0;
                        foreach ($deduction['installments'] as $inst):
                            $isPaid = (bool)$inst['is_paid'];
                            $isPostponed = (bool)$inst['is_postponed'];
                            if (!$isPaid && !$isPostponed) $unpaidCount++;
                        ?>
                            <tr class="<?= $isPaid ? 'table-success' : ($isPostponed ? 'table-warning' : '') ?>">
                                <td><?= $index++ ?></td>
                                <td><?= safeFormatDate($inst['month_year']) ?></td>
                                <td><?= number_format($inst['amount'], 2) ?> دج</td>
                                <td>
                                    <?php if ($isPaid): ?>
                                        <span class="badge-status badge-completed">✅ مسدد</span>
                                    <?php elseif ($isPostponed): ?>
                                        <span class="badge-status badge-postponed">⏳ مؤجل</span>
                                    <?php else: ?>
                                        <span class="badge-status badge-active">⏳ غير مسدد</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= $isPaid ? safeFormatDate($inst['paid_at']) : '-' ?></td>
                                <td>
                                    <?php if (!$isPaid && !$isPostponed): ?>
                                        <button class="btn-sm btn-success pay-btn" data-id="<?= $inst['id'] ?>">💰 تسديد</button>
                                        <button class="btn-sm btn-postpone postpone-btn" data-id="<?= $inst['id'] ?>">⏳ تأجيل</button>
                                    <?php elseif ($isPostponed): ?>
                                        <span class="text-muted">مؤجل</span>
                                    <?php else: ?>
                                        <span class="text-muted">مسدد</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th colspan="2" class="text-end">الإجمالي:</th>
                            <th><?= number_format(array_sum(array_column($deduction['installments'], 'amount')), 2) ?> دج</th>
                            <th colspan="3">
                                <?php if ($unpaidCount > 0): ?>
                                    <span class="text-danger">المتبقي غير المسدد: <?= $unpaidCount ?> أقساط</span>
                                <?php else: ?>
                                    <span class="text-success">✅ جميع الأقساط مسددة</span>
                                <?php endif; ?>
                            </th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- مودال تأكيد التسديد -->
<div class="modal-overlay" id="payModal">
    <div class="modal-box">
        <h3>💰 تأكيد التسديد</h3>
        <p>هل أنت متأكد من تسديد هذا القسط؟</p>
        <p class="text-muted">سيتم تحديث المبلغ المتبقي تلقائياً.</p>
        <?php if ($deduction['type'] === 'loan'): ?>
            <p class="text-success"><small>سيتم إضافة المبلغ إلى الميزانية الاجتماعية كاسترجاع.</small></p>
        <?php endif; ?>
        <div class="modal-actions">
            <button class="btn-cancel" id="cancelPay">إلغاء</button>
            <button class="btn-confirm-delete" id="confirmPay" style="background:#28a745;">نعم، سدد</button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const payModal = document.getElementById('payModal');
    const confirmPay = document.getElementById('confirmPay');
    const cancelPay = document.getElementById('cancelPay');
    let currentInstallmentId = null;

    // زر التسديد
    document.querySelectorAll('.pay-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            currentInstallmentId = this.dataset.id;
            payModal.classList.add('active');
        });
    });

    // إلغاء
    cancelPay.addEventListener('click', () => payModal.classList.remove('active'));
    payModal.addEventListener('click', function(e) {
        if (e.target === this) payModal.classList.remove('active');
    });

    // تأكيد التسديد
    confirmPay.addEventListener('click', function() {
        if (!currentInstallmentId) return;
        
        fetch('view.php?id=<?= $id ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=pay_installment&installment_id=' + currentInstallmentId
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(data.message);
                location.reload();
            } else {
                alert('خطأ: ' + data.message);
            }
        })
        .catch(() => alert('حدث خطأ في الاتصال'))
        .finally(() => payModal.classList.remove('active'));
    });

    // زر التأجيل
    document.querySelectorAll('.postpone-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const installmentId = this.dataset.id;
            // إرسال نموذج POST للتوجيه إلى postpone_installment.php
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'postpone_installment.php';
            form.innerHTML = `
                <input type="hidden" name="deduction_id" value="<?= $id ?>">
                <input type="hidden" name="installment_id" value="${installmentId}">
            `;
            document.body.appendChild(form);
            form.submit();
        });
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>