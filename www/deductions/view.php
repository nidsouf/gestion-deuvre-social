<?php
/**
 * deductions/view.php - عرض تفاصيل الاقتطاع مع الأقساط
 */
ob_start();
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';


$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    header('Location: list.php');
    exit;
}

// جلب بيانات الاقتطاع
$stmt = $pdo->prepare("
    SELECT d.*, e.name as employee_name, e.account_number, e.category as contract_type
    FROM deductions d
    JOIN employees e ON d.employee_id = e.id
    WHERE d.id = ?
");
$stmt->execute([$id]);
$deduction = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$deduction) {
    header('Location: list.php?error=notfound');
    exit;
}

// جلب الأقساط مرتبة حسب السنة والشهر
$stmt = $pdo->prepare("
    SELECT * FROM monthly_installments
    WHERE deduction_id = ?
    ORDER BY year ASC, month ASC
");
$stmt->execute([$id]);
$installments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// جلب معلومات التأجيل لكل قسط (للعرض)
$postponementInfo = [];
if (!empty($installments)) {
    $ids = array_column($installments, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("
        SELECT installment_id, original_month, new_month, reason
        FROM installment_postponements
        WHERE installment_id IN ($placeholders)
        ORDER BY installment_id
    ");
    $stmt->execute($ids);
    $postponements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($postponements as $p) {
        $postponementInfo[$p['installment_id']] = $p;
    }
}

// حساب المبالغ من الأقساط الفعلية
$totalAmount = 0;
$paidAmount = 0;
$remainingAmount = 0;
$paidCount = 0;
$remainingCount = 0;

foreach ($installments as $inst) {
    $amount = (float)$inst['amount'];
    $totalAmount += $amount;
    
    if ($inst['is_paid'] == 1) {
        $paidAmount += $amount;
        $paidCount++;
    } else {
        $remainingAmount += $amount;
        $remainingCount++;
    }
}

// جلب الدفعات المقدمة
$stmt = $pdo->prepare("
    SELECT * FROM early_payments 
    WHERE deduction_id = ? AND is_reversed = 0
    ORDER BY id DESC
");
$stmt->execute([$id]);
$earlyPayments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'تفاصيل الاقتطاع';
include '../includes/header.php';
?>

<style>
    .detail-card { background: #fff; border-radius: 16px; padding: 20px 24px; box-shadow: 0 2px 12px rgba(0,0,0,0.06); border: 1px solid #e9ecef; margin-bottom: 20px; }
    .detail-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px 20px; }
    .detail-grid .item { padding: 6px 0; }
    .detail-grid .item strong { color: #2c3e50; font-weight: 700; display: block; font-size: 13px; color: #6c757d; }
    .detail-grid .item .value { font-size: 16px; font-weight: 600; color: #1a1a2e; }
    .detail-grid .item .value.highlight { color: #1E5A4A; }
    .detail-grid .item .value.danger { color: #dc3545; }
    .detail-grid .item .value.success { color: #28a745; }
    .status-badge { padding: 4px 14px; border-radius: 20px; font-size: 12px; font-weight: 600; display: inline-block; }
    .status-paid { background: #d4edda; color: #155724; }
    .status-postponed { background: #fff3cd; color: #856404; }
    .status-future { background: #e2e3e5; color: #383d41; }
    .status-unpaid { background: #f8d7da; color: #721c24; }
    .action-buttons .btn { margin: 2px 4px; border-radius: 8px; padding: 6px 14px; font-size: 13px; }
    .total-remaining-box { background: linear-gradient(135deg, #1E5A4A, #2E7D64); color: #fff; border-radius: 12px; padding: 16px 24px; text-align: center; margin-bottom: 20px; }
    .total-remaining-box .label { font-size: 14px; opacity: 0.85; }
    .total-remaining-box .amount { font-size: 28px; font-weight: 800; }
    .btn-edit { background: #ffc107; color: #212529; border: none; }
    .btn-edit:hover { background: #e0a800; color: #212529; }
    .btn-postpone { background: #fd7e14; color: #fff; border: none; }
    .btn-postpone:hover { background: #e36209; color: #fff; }
    .btn-early { background: #9b59b6; color: #fff; border: none; }
    .btn-early:hover { background: #8e44ad; color: #fff; }
    .btn-period { background: #17a2b8; color: #fff; border: none; }
    .btn-period:hover { background: #117a8b; color: #fff; }
    .table-responsive { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 10px 12px; text-align: center; border-bottom: 1px solid #f0f0f0; }
    th { background: #f8f9fa; font-weight: 700; color: #2c3e50; }
    .alert { padding: 12px 20px; border-radius: 12px; background: #f8f9fa; color: #6c757d; }
    .badge-original { background: #e9ecef; color: #495057; padding: 2px 8px; border-radius: 12px; font-size: 11px; }
    .btn-print { background: linear-gradient(135deg, #1E5A4A, #2E7D64); color: #fff; border: none; }
.btn-print:hover { background: linear-gradient(135deg, #164236, #1E5A4A); color: #fff; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(30, 90, 74, 0.3); }
</style>

<div style="max-width: 1100px; margin: 0 auto;">

    <h2 class="mb-4">📄 تفاصيل الاقتطاع</h2>

    <!-- معلومات الاقتطاع -->
    <div class="detail-card">
        <div class="detail-grid">
            <div class="item">
                <strong>الموظف</strong>
                <span class="value highlight"><?= htmlspecialchars($deduction['employee_name']) ?></span>
            </div>
            <div class="item">
                <strong>رقم الحساب</strong>
                <span class="value"><?= htmlspecialchars($deduction['account_number'] ?? '—') ?></span>
            </div>
            <div class="item">
                <strong>نوع العقد</strong>
                <span class="value"><?= $deduction['contract_type'] == 'permanent' ? 'دائم' : 'متعاقد' ?></span>
            </div>
            <div class="item">
                <strong>نوع الاقتطاع</strong>
                <span class="value"><?= $deduction['is_loan'] ? 'سلفة' : 'اقتطاع شهري' ?></span>
            </div>
            <div class="item">
                <strong>المبلغ الإجمالي (من الأقساط)</strong>
                <span class="value highlight"><?= number_format($totalAmount, 2) ?> دج</span>
            </div>
            <div class="item">
                <strong>القسط الشهري</strong>
                <span class="value"><?= number_format($deduction['monthly_amount'], 2) ?> دج</span>
            </div>
            <div class="item">
                <strong>المتبقي (من الأقساط)</strong>
                <span class="value <?= $remainingAmount > 0 ? 'danger' : 'success' ?>">
                    <?= number_format($remainingAmount, 2) ?> دج
                </span>
            </div>
            <div class="item">
                <strong>عدد الأقساط الكلية</strong>
                <span class="value"><?= count($installments) ?></span>
            </div>
            <div class="item">
                <strong>المسدد / المتبقي</strong>
                <span class="value"><?= $paidCount ?> / <?= count($installments) - $paidCount ?></span>
            </div>
            <div class="item">
                <strong>تاريخ البداية</strong>
                <span class="value"><?= safeFormatDate($deduction['start_date']) ?></span>
            </div>
            <div class="item">
                <strong>تاريخ النهاية</strong>
                <span class="value"><?= safeFormatDate($deduction['end_date']) ?></span>
            </div>
            <div class="item">
                <strong>تاريخ الصرف</strong>
                <span class="value"><?= safeFormatDate($deduction['grant_date']) ?></span>
            </div>
        </div>
    </div>

    <!-- المبلغ الإجمالي المتبقي للسداد -->
    <div class="total-remaining-box">
        <div class="label">💵 المبلغ الإجمالي المتبقي للسداد</div>
        <div class="amount"><?= number_format($remainingAmount, 2) ?> دج</div>
        <small style="opacity:0.7;">(<?= count($installments) - $paidCount ?> أقساط متبقية من أصل <?= count($installments) ?>)</small>
    </div>

    <!-- أزرار الإجراءات -->
<div class="action-buttons mb-4 text-center">
    <a href="edit.php?id=<?= $id ?>" class="btn btn-edit">✏️ تعديل</a>
    <a href="postpone_period.php?id=<?= $id ?>" class="btn btn-period">⏰ تعديل الفترة</a>
    <a href="postpone_installment.php?deduction_id=<?= $id ?>" class="btn btn-postpone">📅 تأجيل قسط</a>
    <?php if ($deduction['is_loan'] && $remainingAmount > 0): ?>
        <a href="early_payment.php?id=<?= $id ?>" class="btn btn-early">💰 تسديد مقدم</a>
    <?php endif; ?>
    
    <!-- 🖨️ زر الطباعة الجديد -->
    <a href="print.php?id=<?= $id ?>" target="_blank" class="btn btn-print" style="background: linear-gradient(135deg, #1E5A4A, #2E7D64); color: white;">
        🖨️ طباعة الوصل
    </a>
</div>

    <!-- جدول الأقساط -->
    <h4 class="mb-3">📊 جدول الأقساط</h4>
    <div class="table-responsive" style="background:#fff; border-radius:16px; padding:0; border:1px solid #e9ecef;">
        <table class="table table-striped table-hover mb-0">
            <thead style="background:#f8f9fa;">
                <tr>
                    <th>#</th>
                    <th>الشهر</th>
                    <th>المبلغ (دج)</th>
                    <th>الحالة</th>
                    <th>ملاحظات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($installments)): ?>
                    <tr><td colspan="5" class="text-center py-4">لا توجد أقساط مسجلة</td></tr>
                <?php else:
                    $index = 1;
                    foreach ($installments as $inst):
                        // اسم الشهر بالعربية
                        $monthName = getMonthNameArabic($inst['month']) . ' ' . $inst['year'];
                        $statusText = '';
                        $statusClass = '';
                        $notes = '';
                        
                        // التحقق من وجود معلومات تأجيل لهذا القسط
                        $postInfo = $postponementInfo[$inst['id']] ?? null;
                        $originalDate = '';
                        if ($postInfo) {
                            $origParts = explode('-', $postInfo['original_month']);
                            $originalDate = getMonthNameArabic((int)$origParts[1]) . ' ' . $origParts[0];
                        }
                        
                        if ($inst['is_paid'] == 1) {
                            $statusText = '✅ مسدد';
                            $statusClass = 'status-paid';
                            $notes = 'تم التسديد';
                        } elseif ($inst['is_postponed'] == 1) {
                            $statusText = '⏰ مؤجل';
                            $statusClass = 'status-postponed';
                            $notes = 'مؤجل' . ($originalDate ? ' (كان ' . $originalDate . ')' : '');
                        } elseif (strtotime($inst['year'] . '-' . $inst['month'] . '-01') > time()) {
                            $statusText = '📅 مستقبلي';
                            $statusClass = 'status-future';
                            $notes = 'غير مستحق بعد';
                        } else {
                            $statusText = '❌ غير مسدد';
                            $statusClass = 'status-unpaid';
                            $notes = 'متأخر';
                        }
                ?>
                    <tr>
                        <td><?= $index++ ?></td>
                        <td><?= htmlspecialchars($monthName) ?></td>
                        <td><?= number_format($inst['amount'], 2) ?> دج</td>
                        <td><span class="status-badge <?= $statusClass ?>"><?= $statusText ?></span></td>
                        <td><?= htmlspecialchars($notes) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- الدفعات المقدمة -->
    <h4 class="mb-3 mt-4">📅 الدفعات المقدمة المسجلة</h4>
    <?php if (empty($earlyPayments)): ?>
        <div class="alert">لا توجد دفعات مقدمة مسجلة.</div>
    <?php else: ?>
        <div class="table-responsive" style="background:#fff; border-radius:16px; padding:0; border:1px solid #e9ecef;">
            <table class="table table-striped table-hover mb-0">
                <thead style="background:#f8f9fa;">
                    <tr>
                        <th>#</th>
                        <th>عدد الأشهر</th>
                        <th>المبلغ (دج)</th>
                        <th>الحالة</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $i=1; foreach ($earlyPayments as $ep): ?>
                        <tr>
                            <td><?= $i++ ?></td>
                            <td><?= $ep['months_paid'] ?></td>
                            <td><?= number_format($ep['amount'], 2) ?> دج</td>
                            <td><span class="status-badge status-paid">✅ نشط</span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <div class="mt-4">
        <a href="list.php" class="btn btn-secondary">⬅️ العودة إلى القائمة</a>
    </div>
</div>

<?php include '../includes/footer.php'; ?>