<?php
ob_start();
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';

// تعريف safeFormatDate إن لم تكن موجودة
if (!function_exists('safeFormatDate')) {
    function safeFormatDate($date) {
        if (empty($date) || $date === '0000-00-00' || $date === '1970-01-01') return '—';
        return date('d/m/Y', strtotime($date));
    }
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    $_SESSION['toast'] = ['message' => 'اقتطاع غير صالح', 'type' => 'error', 'duration' => 3000];
    header("Location: list.php");
    exit;
}

// ========== جلب بيانات الاقتطاع ==========
$stmt = $pdo->prepare("
    SELECT d.*, e.name as employee_name, e.category, e.account_number, s.name as source_name
    FROM deductions d
    JOIN employees e ON d.employee_id = e.id
    JOIN sources s ON d.source_id = s.id
    WHERE d.id = ?
");
$stmt->execute([$id]);
$ded = $stmt->fetch();

if (!$ded) {
    $_SESSION['toast'] = ['message' => 'الاقتطاع غير موجود', 'type' => 'error', 'duration' => 3000];
    header("Location: list.php");
    exit;
}

// ========== جلب الأقساط (الإصلاح: يجب أن يكون هنا) ==========
$stmtInst = $pdo->prepare("
    SELECT id, year, month, amount, is_paid, is_postponed, paid_date
    FROM monthly_installments
    WHERE deduction_id = ?
    ORDER BY year, month
");
$stmtInst->execute([$id]);
$installments = $stmtInst->fetchAll();

// ========== حساب المدفوع والمتبقي ==========
$monthly = $ded['monthly_amount'];
$totalMonths = $ded['total_months'];
$credit_balance = $ded['credit_balance'];

// حساب المبلغ الإجمالي من الأقساط الفعلية (وليست من total_months)
$totalAmount = 0;
$paidAmount = 0;
foreach ($installments as $inst) {
    $totalAmount += $inst['amount'];
    if ($inst['is_paid']) {
        $paidAmount += $inst['amount'];
    }
}
$remainingAmount = $totalAmount - $paidAmount;
if ($remainingAmount < 0) $remainingAmount = 0;

// ========== جلب الدفعات المقدمة النشطة ==========
$stmtEarly = $pdo->prepare("
    SELECT * FROM early_payments
    WHERE deduction_id = ? AND is_reversed = 0
    ORDER BY payment_date DESC
");
$stmtEarly->execute([$id]);
$early_payments = $stmtEarly->fetchAll();
$totalEarly = array_sum(array_column($early_payments, 'amount'));

include '../includes/header.php';
?>

<style>
    .details-container { max-width: 1000px; margin: 0 auto; background: white; border-radius: 20px; padding: 25px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
    .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 20px; margin-bottom: 20px; }
    .info-item { padding: 8px 0; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; }
    .info-label { font-weight: bold; color: #555; }
    .info-value { font-weight: 500; }
    .remaining-box { background: #e3f2fd; padding: 15px; border-radius: 15px; margin: 20px 0; text-align: center; }
    .remaining-box .amount { font-size: 28px; font-weight: bold; color: #2a5298; }
    table { width: 100%; border-collapse: collapse; margin-top: 15px; margin-bottom: 25px; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: center; }
    th { background: #2a5298; color: white; }
    .section-title { font-size: 18px; font-weight: bold; margin: 25px 0 10px; border-right: 4px solid #2a5298; padding-right: 10px; }
    .btn-back { display: inline-block; margin-top: 20px; background: #6c757d; color: white; padding: 8px 20px; border-radius: 30px; text-decoration: none; }
    .badge-paid { background: #28a745; color: white; padding: 4px 10px; border-radius: 20px; }
    .badge-postponed { background: #ffc107; color: #333; padding: 4px 10px; border-radius: 20px; }
    .badge-overdue { background: #dc3545; color: white; padding: 4px 10px; border-radius: 20px; }
    .badge-current { background: #ff9800; color: white; padding: 4px 10px; border-radius: 20px; }
    .badge-future { background: #6c757d; color: white; padding: 4px 10px; border-radius: 20px; }
    .early-summary { background: #f8f0ff; border: 2px solid #6c3483; padding: 10px; border-radius: 10px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; }
    .early-summary .label { font-weight: bold; }
    .early-summary .value { font-size: 20px; font-weight: bold; color: #6c3483; }
    .note-early { font-size: 12px; color: #6c3483; background: #f3e8ff; padding: 2px 8px; border-radius: 10px; display: inline-block; }
</style>

<div class="details-container">
    <h2>📄 تفاصيل الاقتطاع</h2>

    <div class="info-grid">
        <div class="info-item"><span class="info-label">الموظف:</span><span class="info-value"><?= htmlspecialchars($ded['employee_name']) ?></span></div>
        <div class="info-item"><span class="info-label">رقم الحساب:</span><span class="info-value"><?= htmlspecialchars($ded['account_number'] ?? '—') ?></span></div>
        <div class="info-item"><span class="info-label">نوع العقد:</span><span class="info-value"><?= $ded['category'] == 'Permanent' ? 'دائم' : 'متعاقد' ?></span></div>
        <div class="info-item"><span class="info-label">نوع الاقتطاع:</span><span class="info-value"><?= $ded['is_loan'] ? 'سلفة' : 'اقتطاع عادي' ?></span></div>
        <div class="info-item"><span class="info-label">المبلغ الإجمالي:</span><span class="info-value"><?= number_format($totalAmount, 2) ?> دج</span></div>
        <div class="info-item"><span class="info-label">القسط الشهري:</span><span class="info-value"><?= number_format($monthly, 2) ?> دج</span></div>
        <div class="info-item"><span class="info-label">المتبقي:</span><span class="info-value"><?= number_format($remainingAmount, 2) ?> دج</span></div>
        <div class="info-item"><span class="info-label">تاريخ البداية:</span><span class="info-value"><?= safeFormatDate($ded['start_date']) ?></span></div>
        <div class="info-item"><span class="info-label">تاريخ النهاية:</span><span class="info-value"><?= safeFormatDate($ded['end_date']) ?></span></div>
        <div class="info-item"><span class="info-label">تاريخ الصرف:</span><span class="info-value"><?= $ded['is_loan'] ? safeFormatDate($ded['grant_date']) : '—' ?></span></div>
        <div class="info-item"><span class="info-label">الرصيد الدائن:</span><span class="info-value"><?= number_format($credit_balance, 2) ?> دج</span></div>
        <?php if ($totalEarly > 0): ?>
            <div class="info-item" style="background:#f3e8ff; border-radius:8px; padding:8px; grid-column: span 2; justify-content: center;">
                <span class="info-label" style="color:#6c3483;">💰 إجمالي الدفعات المقدمة:</span>
                <span class="info-value" style="font-size:18px; color:#6c3483; font-weight:bold;"><?= number_format($totalEarly, 2) ?> دج</span>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- ========== أزرار الإجراءات ========== -->
<div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:20px;">
    <a href="edit.php?id=<?= $id ?>" class="btn btn-warning" style="padding:8px 16px; border-radius:8px; text-decoration:none; color:white; background:#ffc107; display:inline-block;">
        ✏️ تعديل
    </a>
    <a href="postpone.php?id=<?= $id ?>" class="btn btn-info" style="padding:8px 16px; border-radius:8px; text-decoration:none; color:white; background:#17a2b8; display:inline-block;">
        ⏰ تعديل الفترة
    </a>
    <a href="postpone_installment.php?id=<?= $id ?>" class="btn btn-warning" style="padding:8px 16px; border-radius:8px; text-decoration:none; color:white; background:#ff9800; display:inline-block;">
        📅 تأجيل قسط
    </a>
    <?php if ($ded['is_loan']): ?>
        <a href="early_payment.php?id=<?= $id ?>" class="btn btn-success" style="padding:8px 16px; border-radius:8px; text-decoration:none; color:white; background:#28a745; display:inline-block;">
            💰 تسديد مقدم
        </a>
    <?php endif; ?>
    <?php if (!empty($early_payments)): ?>
        <?php foreach ($early_payments as $ep): ?>
            <a href="undo_early_payment.php?id=<?= $ep['id'] ?>" class="btn btn-danger" style="padding:8px 16px; border-radius:8px; text-decoration:none; color:white; background:#dc3545; display:inline-block;">
                ↩️ إلغاء التسديد
            </a>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

    <div class="remaining-box">
        <div>💵 المبلغ الإجمالي المتبقي للسداد</div>
        <div class="amount"><?= number_format($remainingAmount, 2) ?> دج</div>
    </div>

    <?php if (!empty($early_payments)): ?>
        <div class="early-summary">
            <span class="label">📌 دفعات مقدمة نشطة:</span>
            <span class="value"><?= number_format($totalEarly, 2) ?> دج</span>
            <span style="font-size:14px; color:#555;">
                (<?= count($early_payments) ?> دفعة<?= count($early_payments) > 1 ? 'ات' : '' ?>)
            </span>
        </div>
    <?php endif; ?>

    <div class="section-title">📊 جدول الأقساط</div>
    <?php if (empty($installments)): ?>
        <p>لا توجد أقساط مسجلة.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>الشهر</th>
                    <th>المبلغ (دج)</th>
                    <th>الحالة</th>
                    <th>تاريخ التسديد</th>
                    <th>ملاحظات</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $i = 1;
                $today = new DateTime();
                $currentYear = (int)$today->format('Y');
                $currentMonth = (int)$today->format('m');

                foreach ($installments as $inst):
                    $year = (int)$inst['year'];
                    $month = (int)$inst['month'];
                    $month_name = getMonthNameArabic($month);
                    $is_early_affected = ($inst['amount'] > $monthly);
                    $early_note = $is_early_affected ? '🟣 يشمل دفعة مقدمة' : '';

                    if ($inst['is_postponed']) {
                        $status = '⏰ مؤجل';
                        $badge_class = 'badge-postponed';
                        $paid_date = '—';
                    } elseif ($inst['is_paid']) {
                        $status = '✅ مسدد';
                        $badge_class = 'badge-paid';
                        $paid_date = safeFormatDate($inst['paid_date']);
                    } elseif ($year < $currentYear || ($year == $currentYear && $month < $currentMonth)) {
                        $status = '⏰ متأخر';
                        $badge_class = 'badge-overdue';
                        $paid_date = '—';
                    } elseif ($year == $currentYear && $month == $currentMonth) {
                        $status = '🔴 مستحق هذا الشهر';
                        $badge_class = 'badge-current';
                        $paid_date = '—';
                    } else {
                        $status = '📅 مستقبلي';
                        $badge_class = 'badge-future';
                        $paid_date = '—';
                    }
                ?>
                    <tr>
                        <td><?= $i++ ?></td>
                        <td><?= $month_name . ' ' . $year ?></td>
                        <td><?= number_format($inst['amount'], 2) ?> دج</td>
                        <td><span class="<?= $badge_class ?>"><?= $status ?></span></td>
                        <td><?= $paid_date ?></td>
                        <td>
                            <?php if ($is_early_affected): ?>
                                <span class="note-early"><?= $early_note ?></span>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <div class="section-title">📅 الدفعات المقدمة المسجلة</div>
    <?php if (empty($early_payments)): ?>
        <p>لا توجد دفعات مقدمة مسجلة.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>التاريخ</th>
                    <th>المبلغ (دج)</th>
                    <th>عدد الأشهر المخصومة</th>
                    <th>الحالة</th>
                </tr>
            </thead>
            <tbody>
                <?php $i=1; foreach ($early_payments as $ep): ?>
                <tr>
                    <td><?= $i++ ?></td>
                    <td><?= safeFormatDate($ep['payment_date']) ?></td>
                    <td><?= number_format($ep['amount'], 2) ?> دج</td>
                    <td><?= $ep['months_paid'] ?> شهر</td>
                    <td><span style="color:#28a745;">نشط</span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <div style="margin-top:20px; display:flex; gap:10px; flex-wrap:wrap;">
        <a href="list.php" class="btn-back">⬅️ العودة للقائمة</a>
    </div>
</div>

<?php include '../includes/footer.php'; ?>