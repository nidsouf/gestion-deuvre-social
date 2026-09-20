<?php
/**
 * reports/quarterly.php - التقرير الثلاثي (ربع سنوي)
 * يعرض الإحصائيات الكلية مع إضافة إجمالي الربع (المبلغ المستحق للثلاثي)
 */
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

require_once '../config/database.php';
require_once '../includes/functions.php';

$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
$quarter = isset($_GET['quarter']) ? (int)$_GET['quarter'] : 2;

// تحديد نطاق الربع
if ($quarter == 1) {
    $quarterStart = $year . '-01-01';
    $quarterEnd   = $year . '-03-31';
    $quarterMonths = [1, 2, 3];
    $quarterLabel = 'الربع الأول (جانفي - مارس)';
} elseif ($quarter == 2) {
    $quarterStart = $year . '-04-01';
    $quarterEnd   = $year . '-06-30';
    $quarterMonths = [4, 5, 6];
    $quarterLabel = 'الربع الثاني (أفريل - جوان)';
} elseif ($quarter == 3) {
    $quarterStart = $year . '-07-01';
    $quarterEnd   = $year . '-09-30';
    $quarterMonths = [7, 8, 9];
    $quarterLabel = 'الربع الثالث (جويلية - سبتمبر)';
} else {
    $quarterStart = $year . '-10-01';
    $quarterEnd   = $year . '-12-31';
    $quarterMonths = [10, 11, 12];
    $quarterLabel = 'الربع الرابع (أكتوبر - ديسمبر)';
}

$monthNames = ['جانفي', 'فيفري', 'مارس', 'أفريل', 'ماي', 'جوان', 'جويلية', 'أوت', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];

// ============================================================
// جلب الاقتطاعات النشطة خلال الربع (مصدر سعدين فقط)
// ============================================================
$sql = "
    SELECT 
        d.id as deduction_id,
        e.name as employee_name,
        e.category,
        d.monthly_amount,
        d.total_months,
        d.start_date,
        d.end_date,
        d.is_loan,
        (d.monthly_amount * d.total_months) as total_amount,
        -- حساب عدد الأشهر المتداخلة مع الربع
        CASE 
            WHEN d.start_date <= :start AND d.end_date >= :end THEN 3
            WHEN d.start_date > :start AND d.end_date < :end THEN (strftime('%m', d.end_date) - strftime('%m', d.start_date) + 1)
            WHEN d.start_date <= :start AND d.end_date < :end THEN (strftime('%m', d.end_date) - strftime('%m', :start) + 1)
            WHEN d.start_date > :start AND d.end_date >= :end THEN (strftime('%m', :end) - strftime('%m', d.start_date) + 1)
            ELSE 0
        END as overlap_months
    FROM deductions d
    JOIN employees e ON d.employee_id = e.id
    WHERE d.source_id = 1
      AND d.start_date <= :end
      AND d.end_date >= :start
    ORDER BY e.category DESC, e.name ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':start' => $quarterStart,
    ':end' => $quarterEnd
]);
$deductions = $stmt->fetchAll();

// ============================================================
// جلب المبالغ المسددة من monthly_installments (لكل الاقتطاعات)
// ============================================================
$deductionIds = array_column($deductions, 'deduction_id');
$paidInfo = [];
if (!empty($deductionIds)) {
    $placeholders = implode(',', array_fill(0, count($deductionIds), '?'));
    $stmt = $pdo->prepare("
        SELECT deduction_id, COUNT(*) as paid_count, SUM(amount) as paid_amount
        FROM monthly_installments
        WHERE deduction_id IN ($placeholders) AND is_paid = 1
        GROUP BY deduction_id
    ");
    $stmt->execute($deductionIds);
    while ($row = $stmt->fetch()) {
        $paidInfo[$row['deduction_id']] = $row;
    }
}

// ============================================================
// معالجة البيانات
// ============================================================
$report = [];
$grandTotal = 0;        // إجمالي المبالغ الكلية
$grandQuarterTotal = 0; // إجمالي مبالغ الربع (المبلغ المستحق للثلاثي)
$totalPaid = 0;         // إجمالي المسدد من الكلي
$totalRemaining = 0;    // إجمالي المتبقي من الكلي
$totalPaidCount = 0;
$totalMonthsCount = 0;
$employeeCount = 0;
$permanentCount = 0;
$contractCount = 0;
$permanentTotal = 0;
$contractTotal = 0;
$permanentQuarter = 0;
$contractQuarter = 0;
$employees = [];

foreach ($deductions as $d) {
    $overlap = (int)$d['overlap_months'];
    if ($overlap <= 0) continue;
    
    $totalAmount = (float)$d['total_amount'];
    $quarterAmount = $d['monthly_amount'] * $overlap;
    $paid = $paidInfo[$d['deduction_id']] ?? ['paid_count' => 0, 'paid_amount' => 0];
    $paidAmount = (float)$paid['paid_amount'];
    $remainingAmount = $totalAmount - $paidAmount;
    
    $grandTotal += $totalAmount;
    $grandQuarterTotal += $quarterAmount;
    $totalPaid += $paidAmount;
    $totalRemaining += $remainingAmount;
    $totalPaidCount += (int)$paid['paid_count'];
    $totalMonthsCount += (int)$d['total_months'];
    
    if (!in_array($d['employee_name'], $employees)) {
        $employees[] = $d['employee_name'];
        $employeeCount++;
    }
    
    if ($d['category'] == 'Permanent') {
        $permanentCount++;
        $permanentTotal += $totalAmount;
        $permanentQuarter += $quarterAmount;
    } else {
        $contractCount++;
        $contractTotal += $totalAmount;
        $contractQuarter += $quarterAmount;
    }
    
    // تفاصيل الأشهر للربع
    $monthDetails = [];
    $tempDate = new DateTime(max($d['start_date'], $quarterStart));
    $endDate = new DateTime(min($d['end_date'], $quarterEnd));
    while ($tempDate <= $endDate) {
        $monthDetails[] = $tempDate->format('Y-m') . ' (' . number_format($d['monthly_amount'], 2) . ' دج)';
        $tempDate->modify('+1 month');
    }
    
    $report[] = [
        'deduction_id' => $d['deduction_id'],
        'employee_name' => $d['employee_name'],
        'category' => $d['category'],
        'monthly_amount' => $d['monthly_amount'],
        'total_months' => $d['total_months'],
        'overlap_months' => $overlap,
        'total_amount' => $totalAmount,
        'quarter_amount' => $quarterAmount,
        'paid_amount' => $paidAmount,
        'remaining_amount' => $remainingAmount,
        'paid_count' => (int)$paid['paid_count'],
        'is_loan' => $d['is_loan'],
        'start_date' => $d['start_date'],
        'end_date' => $d['end_date'],
        'month_details' => implode(' | ', $monthDetails)
    ];
}

$progressPercent = $grandTotal > 0 ? round(($totalPaid / $grandTotal) * 100) : 0;

$pageTitle = "التقرير الربعي - سعدين للتجهيز - $year";
include '../includes/header.php';
?>

<style>
    .report-container { direction: rtl; max-width: 1200px; margin: 0 auto; padding: 20px; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
    .report-header { background: linear-gradient(135deg, #1e3c72, #2a5298); color: white; padding: 15px; border-radius: 10px; text-align: center; margin-bottom: 20px; }
    .filters { background: #f0f2f5; padding: 15px; border-radius: 10px; margin-bottom: 20px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
    .filters select, .filters button { padding: 8px 15px; border-radius: 8px; border: 1px solid #ccc; }
    .btn-primary { background: #2a5298; color: white; border: none; cursor: pointer; }
    .btn-success { background: #28a745; color: white; border: none; cursor: pointer; }

    /* ============================================================
       شريط المبلغ المستحق للثلاثي (مميز)
    ============================================================ */
    .quarter-amount-banner {
        background: linear-gradient(135deg, #6f42c1, #8e44ad);
        color: white;
        padding: 18px 24px;
        border-radius: 12px;
        text-align: center;
        margin: 20px 0 25px 0;
        box-shadow: 0 4px 20px rgba(111, 66, 193, 0.3);
        border: 2px solid #5a2d8a;
    }
    .quarter-amount-banner .label {
        font-size: 18px;
        font-weight: 600;
        opacity: 0.9;
        display: block;
    }
    .quarter-amount-banner .amount {
        font-size: 36px;
        font-weight: 800;
        letter-spacing: 1px;
        display: block;
        margin-top: 4px;
        text-shadow: 0 2px 10px rgba(0,0,0,0.2);
    }
    .quarter-amount-banner .sub {
        font-size: 14px;
        opacity: 0.8;
        display: block;
        margin-top: 2px;
    }

    .stats-cards {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        gap: 15px;
        margin-bottom: 25px;
    }
    .stat-card {
        background: white;
        padding: 16px;
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        text-align: center;
        border-right: 4px solid #2a5298;
        transition: transform 0.2s;
    }
    .stat-card:hover { transform: translateY(-2px); }
    .stat-card .label { font-size: 13px; color: #6c757d; font-weight: 600; }
    .stat-card .number { font-size: 24px; font-weight: 700; color: #1e3c72; margin: 4px 0; }
    .stat-card .sub { font-size: 12px; color: #95a5a6; }
    .stat-card.green { border-right-color: #28a745; }
    .stat-card.green .number { color: #28a745; }
    .stat-card.orange { border-right-color: #fd7e14; }
    .stat-card.orange .number { color: #fd7e14; }
    .stat-card.red { border-right-color: #dc3545; }
    .stat-card.red .number { color: #dc3545; }
    .stat-card.purple { border-right-color: #6f42c1; }
    .stat-card.purple .number { color: #6f42c1; }

    .progress-section {
        background: white;
        padding: 18px 20px;
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        margin-bottom: 25px;
    }
    .progress-bar-container {
        height: 12px;
        background: #e9ecef;
        border-radius: 10px;
        overflow: hidden;
        margin-top: 6px;
    }
    .progress-bar-fill {
        height: 100%;
        border-radius: 10px;
        background: linear-gradient(90deg, #28a745, #2ecc71);
        transition: width 0.6s ease;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 10px;
        color: #fff;
        font-weight: 600;
        min-width: 30px;
    }

    .data-table {
        width: 100%;
        border-collapse: collapse;
        background: white;
        margin-bottom: 20px;
        font-size: 13px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        border-radius: 12px;
        overflow: hidden;
    }
    .data-table th, .data-table td {
        border: 1px solid #e9ecef;
        padding: 10px 8px;
        text-align: center;
        vertical-align: middle;
    }
    .data-table th {
        background: #2a5298;
        color: white;
        font-weight: 700;
    }
    .data-table tbody tr:hover { background: #f8f9fa; }
    .permanent-row { background-color: #e8f5e9; }
    .contract-row { background-color: #fff3e0; }
    .total-row { background: #ffd700; font-weight: 700; }
    .badge-success { background: #28a745; color: white; padding: 2px 10px; border-radius: 20px; font-size: 11px; }
    .badge-warning { background: #ffc107; color: #333; padding: 2px 10px; border-radius: 20px; font-size: 11px; }

    /* تنسيق عمود إجمالي الربع */
    .quarter-total-cell {
        font-weight: 800;
        color: #6f42c1;
        background-color: #f3e8ff;
        font-size: 14px;
    }

    .print-header, .print-footer { display: none; }

    /* ============================================================
       أنماط الطباعة
    ============================================================ */
    @media print {
        .sidebar, .top-bar, .filters, .stats-cards, .btn-primary, .btn-success,
        .toggle-sidebar, .dark-mode-toggle, .date-badge, .no-print, .footer {
            display: none !important;
        }
        body, .main-content, .report-container {
            margin: 0;
            padding: 0;
            background: white;
        }
        .data-table tr { page-break-inside: avoid; }
        .data-table thead { display: table-header-group; }
        .data-table tfoot { display: table-footer-group; }
        .print-header { display: block; text-align: center; margin-bottom: 20px; border-bottom: 2px solid #000; padding-bottom: 10px; }
        .print-footer { display: block; position: fixed; bottom: 0; width: 100%; text-align: center; font-size: 10px; border-top: 1px solid #ccc; padding-top: 5px; }
        .report-header { background: #2a5298 !important; color: white !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .permanent-row, .contract-row, .total-row, .data-table th { -webkit-print-color-adjust: exact; print-color-adjust: exact; }

        /* إبراز المبلغ المستحق للثلاثي في الطباعة */
        .quarter-amount-banner {
            background: #6f42c1 !important;
            color: white !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
            border: 2px solid #4a2a6a !important;
        }
        .quarter-amount-banner .amount {
            font-size: 32px !important;
        }
        .quarter-total-cell {
            font-weight: 800 !important;
            color: #6f42c1 !important;
            background-color: #f3e8ff !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .total-row td {
            font-weight: 700 !important;
        }
    }
</style>

<div class="report-container">
    <div class="print-header">
        <h2>مركز التكوين والتعليم المهنيين</h2>
        <h3>الشهيد علي بوسحابة - بكوينين</h3>
        <h4>لجنة الخدمات الاجتماعية</h4>
        <hr>
    </div>

    <div class="report-header">
        <h2>📆 التقرير الربعي - سعدين للتجهيز</h2>
        <h3><?= $quarterLabel . ' ' . $year ?></h3>
        <p style="margin:5px 0 0;font-size:14px;opacity:0.8;">
            عدد الاقتطاعات النشطة: <?= count($report) ?>
        </p>
    </div>

    <!-- ============================================================
         شريط المبلغ المستحق للثلاثي (بارز جداً)
    ============================================================ -->
    <div class="quarter-amount-banner">
        <span class="label">💰 المبلغ المستحق للثلاثي</span>
        <span class="amount"><?= number_format($grandQuarterTotal, 2) ?> دج</span>
        <span class="sub">إجمالي الاقتطاعات المستحقة في <?= $quarterLabel ?></span>
    </div>

    <div class="filters">
        <form method="GET">
            <select name="year">
                <?php for($y = date('Y')-5; $y <= date('Y')+1; $y++): ?>
                    <option value="<?= $y ?>" <?= $y == $year ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
            <select name="quarter">
                <option value="1" <?= $quarter==1 ? 'selected' : '' ?>>الربع الأول</option>
                <option value="2" <?= $quarter==2 ? 'selected' : '' ?>>الربع الثاني</option>
                <option value="3" <?= $quarter==3 ? 'selected' : '' ?>>الربع الثالث</option>
                <option value="4" <?= $quarter==4 ? 'selected' : '' ?>>الربع الرابع</option>
            </select>
            <button type="submit" class="btn-primary">عرض</button>
            <button type="button" class="btn-success" onclick="window.print()">🖨️ طباعة</button>
        </form>
    </div>

    <?php if (empty($report)): ?>
        <div style="background:#f8d7da; padding:20px; text-align:center;">
            ⚠️ لا توجد اقتطاعات نشطة في <?= $quarterLabel . ' ' . $year ?>
        </div>
    <?php else: ?>

    <!-- بطاقات الإحصائيات الكلية -->
    <div class="stats-cards">
        <div class="stat-card">
            <div class="label">📋 عدد الاقتطاعات</div>
            <div class="number"><?= count($report) ?></div>
            <div class="sub">من سعدين للتجهيز</div>
        </div>
        <div class="stat-card green">
            <div class="label">💰 إجمالي المبالغ الكلية</div>
            <div class="number"><?= number_format($grandTotal, 0) ?> دج</div>
            <div class="sub">طوال فترة الاقتطاعات</div>
        </div>
        <div class="stat-card purple">
            <div class="label">📊 إجمالي الربع (المستحق)</div>
            <div class="number" style="color:#6f42c1;"><?= number_format($grandQuarterTotal, 0) ?> دج</div>
            <div class="sub">المبلغ المستحق للثلاثي</div>
        </div>
        <div class="stat-card orange">
            <div class="label">✅ إجمالي المسدد</div>
            <div class="number"><?= number_format($totalPaid, 0) ?> دج</div>
            <div class="sub"><?= $totalPaidCount ?> قسط</div>
        </div>
        <div class="stat-card red">
            <div class="label">⏳ إجمالي المتبقي</div>
            <div class="number"><?= number_format($totalRemaining, 0) ?> دج</div>
            <div class="sub"><?= $totalMonthsCount - $totalPaidCount ?> قسط</div>
        </div>
        <div class="stat-card">
            <div class="label">📈 نسبة التسديد</div>
            <div class="number" style="color: <?= $progressPercent >= 70 ? '#28a745' : '#dc3545' ?>;">
                <?= $progressPercent ?>%
            </div>
            <div class="sub"><?= $totalPaidCount ?> / <?= $totalMonthsCount ?></div>
        </div>
    </div>

    <!-- شريط التقدم -->
    <div class="progress-section">
        <div class="d-flex justify-content-between">
            <span>نسبة التسديد الإجمالية</span>
            <span><?= $progressPercent ?>%</span>
        </div>
        <div class="progress-bar-container">
            <div class="progress-bar-fill" style="width: <?= $progressPercent ?>%;">
                <?= $progressPercent ?>%
            </div>
        </div>
        <div class="text-muted mt-2" style="font-size:13px;">
            <small><?= $totalPaidCount ?> قسط مسدد من أصل <?= $totalMonthsCount ?></small>
        </div>
    </div>

    <!-- الجدول التفصيلي -->
    <table class="data-table">
        <thead>
            <tr>
                <th>#</th>
                <th>التصنيف</th>
                <th>اسم الموظف</th>
                <th>النوع</th>
                <th>المبلغ الشهري</th>
                <th>عدد الأشهر (الكلي)</th>
                <th>الإجمالي الكلي (دج)</th>
                <th>إجمالي الربع (دج)</th>
                <th>المسدد</th>
                <th>المتبقي</th>
                <th>التقدم</th>
                <th>فترة الاقتطاع</th>
            </tr>
        </thead>
        <tbody>
            <?php $i = 1; foreach ($report as $item): ?>
            <?php 
                $progress = $item['total_amount'] > 0 ? round(($item['paid_amount'] / $item['total_amount']) * 100) : 0;
                $rowClass = $item['category'] == 'Permanent' ? 'permanent-row' : 'contract-row';
            ?>
            <tr class="<?= $rowClass ?>">
                <td><?= $i++ ?></td>
                <td><?= $item['category'] == 'Permanent' ? 'دائم' : 'متعاقد' ?></td>
                <td><strong><?= htmlspecialchars($item['employee_name']) ?></strong></td>
                <td>
                    <span class="badge <?= $item['is_loan'] ? 'badge-warning' : 'badge-success' ?>">
                        <?= $item['is_loan'] ? 'سلفة' : 'اقتطاع' ?>
                    </span>
                </td>
                <td><?= number_format($item['monthly_amount'], 2) ?></td>
                <td><?= $item['total_months'] ?></td>
                <td><strong><?= number_format($item['total_amount'], 2) ?></strong></td>
                <td class="quarter-total-cell"><?= number_format($item['quarter_amount'], 2) ?></td>
                <td><?= number_format($item['paid_amount'], 2) ?></td>
                <td><?= number_format($item['remaining_amount'], 2) ?></td>
                <td>
                    <div style="display:flex; align-items:center; gap:6px;">
                        <div style="flex:1; background:#e9ecef; height:8px; border-radius:4px; min-width:60px;">
                            <div style="width:<?= $progress ?>%; height:100%; background:<?= $progress >= 70 ? '#28a745' : ($progress >= 40 ? '#ffc107' : '#dc3545') ?>; border-radius:4px;"></div>
                        </div>
                        <span style="font-size:12px; font-weight:600; min-width:35px;"><?= $progress ?>%</span>
                    </div>
                </td>
                <td><small><?= date('d/m/Y', strtotime($item['start_date'])) ?> → <?= date('d/m/Y', strtotime($item['end_date'])) ?></small></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="total-row">
                <td colspan="6"><strong>الإجمالي</strong></td>
                <td><strong><?= number_format($grandTotal, 2) ?> دج</strong></td>
                <td><strong><?= number_format($grandQuarterTotal, 2) ?> دج</strong></td>
                <td><strong><?= number_format($totalPaid, 2) ?> دج</strong></td>
                <td><strong><?= number_format($totalRemaining, 2) ?> دج</strong></td>
                <td><strong><?= $progressPercent ?>%</strong></td>
                <td></td>
            </tr>
        </tfoot>
    </table>

    <!-- ملخص حسب التصنيف مع إبراز إجمالي الربع -->
    <div style="margin-top:20px; padding:15px; background:#e3f2fd; border-radius:10px;" class="no-print">
        <p><strong>📋 عدد الاقتطاعات:</strong> <?= count($report) ?></p>
        <p><strong>👔 دائم:</strong> <?= $permanentCount ?> (إجمالي كلي: <?= number_format($permanentTotal, 2) ?> دج | إجمالي الربع: <?= number_format($permanentQuarter, 2) ?> دج)</p>
        <p><strong>👕 متعاقد:</strong> <?= $contractCount ?> (إجمالي كلي: <?= number_format($contractTotal, 2) ?> دج | إجمالي الربع: <?= number_format($contractQuarter, 2) ?> دج)</p>
        <p><strong>💰 إجمالي المبالغ الكلية:</strong> <?= number_format($grandTotal, 2) ?> دج</p>
        <p style="font-size:20px; font-weight:800; color:#6f42c1; background:#f3e8ff; padding:8px 16px; border-radius:8px; display:inline-block; margin-top:5px;">
            📊 إجمالي الربع (المبلغ المستحق للثلاثي): <?= number_format($grandQuarterTotal, 2) ?> دج
        </p>
        <p><strong>📈 نسبة التسديد الإجمالية:</strong> <?= $progressPercent ?>% (<?= $totalPaidCount ?> / <?= $totalMonthsCount ?>)</p>
    </div>

    <?php endif; ?>
</div>

<div class="footer no-print" style="text-align:center; margin-top:20px; color:#666;">
    تم إنشاء التقرير بواسطة نظام إدارة الاقتطاعات - <?= date('Y-m-d H:i:s') ?>
</div>

<div class="print-footer">
    <p>تقرير رسمي - مركز التكوين والتعليم المهنيين - لجنة الخدمات الاجتماعية - تاريخ الطباعة: <?= date('Y-m-d H:i:s') ?></p>
</div>

<?php include '../includes/footer.php'; ?>