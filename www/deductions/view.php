<?php
ob_start();
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    $_SESSION['toast'] = ['message' => 'اقتطاع غير صالح', 'type' => 'error', 'duration' => 3000];
    header("Location: list.php");
    exit;
}

$stmt = $pdo->prepare("SELECT d.*, e.name as employee_name, s.name as source_name FROM deductions d JOIN employees e ON d.employee_id = e.id JOIN sources s ON d.source_id = s.id WHERE d.id = ?");
$stmt->execute([$id]);
$ded = $stmt->fetch();

if (!$ded) {
    $_SESSION['toast'] = ['message' => 'الاقتطاع غير موجود', 'type' => 'error', 'duration' => 3000];
    header("Location: list.php");
    exit;
}

$monthly = $ded['monthly_amount'];
$remaining_months = $ded['total_months'];
$credit_balance = $ded['credit_balance'];
$remaining_amount = ($remaining_months * $monthly) - $credit_balance;
if ($remaining_amount < 0) $remaining_amount = 0;

$stmtEarly = $pdo->prepare("SELECT * FROM early_payments WHERE deduction_id = ? AND is_reversed = 0 ORDER BY payment_date DESC");
$stmtEarly->execute([$id]);
$early_payments = $stmtEarly->fetchAll();

// جلب الأقساط من جدول monthly_installments
$stmtInst = $pdo->prepare("
    SELECT id, year, month, amount, is_paid, is_postponed
    FROM monthly_installments
    WHERE deduction_id = ? AND is_paid = 0
    ORDER BY year, month
");
$stmtInst->execute([$id]);
$installments = $stmtInst->fetchAll();

include '../includes/header.php';
?>

<style>
    .details-container { max-width: 1000px; margin: 0 auto; background: white; border-radius: 20px; padding: 25px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
    .info-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eee; }
    .info-label { font-weight: bold; width: 200px; }
    .remaining-box { background: #e3f2fd; padding: 15px; border-radius: 15px; margin: 20px 0; text-align: center; }
    .remaining-box .amount { font-size: 28px; font-weight: bold; color: #2a5298; }
    table { width: 100%; border-collapse: collapse; margin-top: 15px; margin-bottom: 25px; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: center; }
    th { background: #2a5298; color: white; }
    .section-title { font-size: 18px; font-weight: bold; margin: 25px 0 10px; border-right: 4px solid #2a5298; padding-right: 10px; }
    .btn-back { display: inline-block; margin-top: 20px; background: #6c757d; color: white; padding: 8px 20px; border-radius: 30px; text-decoration: none; }
    .badge-postponed { background: #ffc107; color: #333; padding: 4px 10px; border-radius: 20px; }
    .badge-coming { background: #28a745; color: white; padding: 4px 10px; border-radius: 20px; }
    .badge-paid { background: #17a2b8; color: white; padding: 4px 10px; border-radius: 20px; }
</style>

<div class="details-container">
    <h2>📋 تفاصيل الاقتطاع</h2>

    <div class="info-row"><span class="info-label">الموظف:</span><span><?= htmlspecialchars($ded['employee_name']) ?></span></div>
    <div class="info-row"><span class="info-label">المصدر:</span><span><?= htmlspecialchars($ded['source_name']) ?></span></div>
    <div class="info-row"><span class="info-label">النوع:</span><span><?= $ded['is_loan'] ? '<span style="background:#ff9800; color:white; padding:4px 10px; border-radius:20px;">💰 سلفة</span>' : '📌 اقتطاع عادي' ?></span></div>
    <div class="info-row"><span class="info-label">القسط الشهري:</span><span><?= number_format($monthly, 2) ?> دج</span></div>
    <div class="info-row"><span class="info-label">عدد الأشهر المتبقية:</span><span><?= $remaining_months ?> شهر</span></div>
    <div class="info-row"><span class="info-label">تاريخ البداية:</span><span><?= date('d/m/Y', strtotime($ded['start_date'])) ?></span></div>
    <div class="info-row"><span class="info-label">تاريخ النهاية المتوقع:</span><span><?= date('d/m/Y', strtotime($ded['end_date'])) ?></span></div>
    <div class="info-row"><span class="info-label">الرصيد الدائن:</span><span><?= number_format($credit_balance, 2) ?> دج</span></div>

    <div class="remaining-box">
        <div>💵 المبلغ الإجمالي المتبقي للسداد</div>
        <div class="amount"><?= number_format($remaining_amount, 2) ?> دج</div>
    </div>

    <div class="section-title">📆 الأقساط الشهرية</div>
    <?php if (empty($installments)): ?>
        <p>لا توجد أقساط متبقية.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>الشهر</th>
                    <th>السنة</th>
                    <th>المبلغ (دج)</th>
                    <th>الحالة</th>
                    <th>ملاحظات</th>
                </tr>
            </thead>
            <tbody>
                <?php $i = 1; foreach ($installments as $inst): ?>
                    <tr>
                        <td><?= $i++ ?></td>
                        <td><?= getMonthNameArabic($inst['month']) ?></td>
                        <td><?= $inst['year'] ?></td>
                        <td><?= number_format($inst['amount'], 2) ?> دج</td>
                        <td>
                            <?php if ($inst['is_postponed']): ?>
                                <span class="badge-postponed">⏰ مؤجل</span>
                            <?php else: ?>
                                <span class="badge-coming">✅ قادم</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($inst['is_postponed']): ?>
                                <span style="color:#ff9800;">تم تأجيل هذا القسط إلى نهاية المدة</span>
                            <?php else: ?>
                                <span style="color:#6c757d;">لم يتم السداد بعد</span>
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
            <thead><tr><th>#</th><th>التاريخ</th><th>المبلغ (دج)</th><th>عدد الأشهر المخصومة</th><th>الحالة</th></tr></thead>
            <tbody>
                <?php $i=1; foreach ($early_payments as $ep): ?>
                <tr>
                    <td><?= $i++ ?></td>
                    <td><?= date('d/m/Y H:i', strtotime($ep['payment_date'])) ?></td>
                    <td><?= number_format($ep['amount'], 2) ?> دج</td>
                    <td><?= $ep['months_paid'] ?> شهر</td>
                    <td><span style="color:#28a745;">نشط</span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <a href="list.php" class="btn-back">🔙 العودة إلى قائمة الاقتطاعات</a>
</div>

<?php include '../includes/footer.php'; ?>