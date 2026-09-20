<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

require_once '../config/database.php';
require_once '../includes/functions.php';

$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
$quarter = isset($_GET['quarter']) ? (int)$_GET['quarter'] : 2;

if ($quarter == 1) {
    $quarterStart = $year . '-01-01';
    $quarterEnd   = $year . '-03-31';
} elseif ($quarter == 2) {
    $quarterStart = $year . '-04-01';
    $quarterEnd   = $year . '-06-30';
} elseif ($quarter == 3) {
    $quarterStart = $year . '-07-01';
    $quarterEnd   = $year . '-09-30';
} else {
    $quarterStart = $year . '-10-01';
    $quarterEnd   = $year . '-12-31';
}

$sql = "
    SELECT 
        d.id as deduction_id,
        e.name, 
        e.category,
        d.monthly_amount,
        d.start_date,
        d.end_date
    FROM deductions d
    JOIN employees e ON d.employee_id = e.id
    WHERE d.start_date <= :end 
        AND d.end_date >= :start
        AND d.source_id = 1
    ORDER BY e.category DESC, e.name ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute([':start' => $quarterStart, ':end' => $quarterEnd]);
$results = $stmt->fetchAll();

$report = [];
$grandTotal = 0;
$totalPermanent = 0;
$totalContract = 0;
$countPermanent = 0;
$countContract = 0;

foreach ($results as $row) {
    $start = new DateTime(max($row['start_date'], $quarterStart));
    $end   = new DateTime(min($row['end_date'], $quarterEnd));
    if ($start > $end) continue;

    $monthsCount = 0;
    $totalAmount = 0;
    $monthsDetails = [];
    $tempStart = clone $start;

    while ($tempStart <= $end) {
        $monthsCount++;
        $totalAmount += $row['monthly_amount'];
        $monthsDetails[] = $tempStart->format('Y-m') . ' (' . number_format($row['monthly_amount'], 2) . ' دج)';
        $tempStart->modify('+1 month');
    }
    if ($monthsCount > 3) $monthsCount = 3;

    $report[] = [
        'deduction_id' => $row['deduction_id'],
        'name' => $row['name'],
        'category' => $row['category'],
        'monthly_amount' => $row['monthly_amount'],
        'start_date' => $row['start_date'],
        'end_date' => $row['end_date'],
        'months_count' => $monthsCount,
        'total_amount' => $totalAmount,
        'months_details' => implode(' | ', $monthsDetails)
    ];

    $grandTotal += $totalAmount;
    if ($row['category'] == 'Permanent') {
        $totalPermanent += $totalAmount;
        $countPermanent++;
    } else {
        $totalContract += $totalAmount;
        $countContract++;
    }
}

$quarterNames = [
    1 => 'الربع الأول (يناير - مارس)',
    2 => 'الربع الثاني (أبريل - يونيو)',
    3 => 'الربع الثالث (يوليو - سبتمبر)',
    4 => 'الربع الرابع (أكتوبر - ديسمبر)'
];

include '../includes/header.php';
?>

<style>
    /* أنماط العرض العادي */
    .report-container {
        direction: rtl;
        max-width: 1200px;
        margin: 0 auto;
        padding: 20px;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }
    .report-header {
        background: linear-gradient(135deg, #1e3c72, #2a5298);
        color: white;
        padding: 15px;
        border-radius: 10px;
        text-align: center;
        margin-bottom: 20px;
    }
    .filters {
        background: #f0f2f5;
        padding: 15px;
        border-radius: 10px;
        margin-bottom: 20px;
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        align-items: center;
    }
    .filters select, .filters button {
        padding: 8px 15px;
        border-radius: 8px;
        border: 1px solid #ccc;
    }
    .btn-primary { background: #2a5298; color: white; border: none; cursor: pointer; }
    .btn-success { background: #28a745; color: white; border: none; cursor: pointer; }
    .stats-cards {
        display: flex;
        gap: 15px;
        flex-wrap: wrap;
        margin-bottom: 20px;
    }
    .stat-card {
        background: white;
        padding: 15px;
        border-radius: 10px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        flex: 1;
        text-align: center;
        min-width: 150px;
    }
    .stat-card .number { font-size: 24px; font-weight: bold; color: #2a5298; }
    .data-table {
        width: 100%;
        border-collapse: collapse;
        background: white;
        margin-bottom: 20px;
        font-size: 14px;
    }
    .data-table th, .data-table td {
        border: 1px solid #ddd;
        padding: 10px;
        text-align: center;
        vertical-align: middle;
    }
    .data-table th {
        background: #2a5298;
        color: white;
        font-weight: bold;
    }
    .permanent-row { background-color: #e8f5e9; }
    .contract-row { background-color: #fff3e0; }
    .total-row { background: #ffd700; font-weight: bold; }
    .badge-success { background: #28a745; color: white; padding: 2px 8px; border-radius: 20px; font-size: 12px; }
    .badge-warning { background: #ffc107; color: #333; padding: 2px 8px; border-radius: 20px; font-size: 12px; }
    .print-header, .print-footer { display: none; }

    /* أنماط الطباعة */
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
        .data-table tr {
            page-break-inside: avoid;
        }
        .data-table thead {
            display: table-header-group;
        }
        .data-table tfoot {
            display: table-footer-group;
        }
        .print-header {
            display: block;
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #000;
            padding-bottom: 10px;
        }
        .print-footer {
            display: block;
            position: fixed;
            bottom: 0;
            width: 100%;
            text-align: center;
            font-size: 10px;
            border-top: 1px solid #ccc;
            padding-top: 5px;
        }
        .report-header {
            background: #2a5298 !important;
            color: white !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .permanent-row, .contract-row, .total-row, .data-table th {
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
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
        <h3><?= $quarterNames[$quarter] . ' ' . $year ?></h3>
    </div>

    <div class="filters">
        <form method="GET">
            <select name="year">
                <?php for($y = 2020; $y <= date('Y')+1; $y++): ?>
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
            <button type="button" class="btn-success" onclick="window.print()">طباعة</button>
        </form>
    </div>

    <?php if(empty($report)): ?>
        <div style="background:#f8d7da; padding:20px; text-align:center;">⚠️ لا توجد بيانات</div>
    <?php else: ?>

    <div class="stats-cards">
        <div class="stat-card"><h4>📋 عدد الاقتطاعات</h4><div class="number"><?= count($report) ?></div></div>
        <div class="stat-card"><h4>👔 دائم</h4><div class="number"><?= $countPermanent ?> (<?= number_format($totalPermanent,2) ?> دج)</div></div>
        <div class="stat-card"><h4>👕 متعاقد</h4><div class="number"><?= $countContract ?> (<?= number_format($totalContract,2) ?> دج)</div></div>
        <div class="stat-card"><h4>💰 الإجمالي الكلي</h4><div class="number"><?= number_format($grandTotal,2) ?> دج</div></div>
    </div>

    <table class="data-table">
        <thead>
            <tr>
                <th>#</th>
                <th>التصنيف</th>
                <th>اسم الموظف</th>
                <th>المبلغ الشهري (دج)</th>
                <th>عدد الأشهر في الربع</th>
                <th>إجمالي الربع (دج)</th>
                <th>تفاصيل الأشهر</th>
                <th>فترة الاقتطاع</th>
            </tr>
        </thead>
        <tbody>
            <?php $i = 1; foreach($report as $item): ?>
            <tr class="<?= $item['category'] == 'Permanent' ? 'permanent-row' : 'contract-row' ?>">
                <td><?= $i++ ?> <small>(ID:<?= $item['deduction_id'] ?>)</small></td>
                <td><?= $item['category'] == 'Permanent' ? 'دائم' : 'متعاقد' ?></td>
                <td><strong><?= htmlspecialchars($item['name']) ?></strong></td>
                <td><?= number_format($item['monthly_amount'], 2) ?> دج</td>
                <td>
                    <span class="<?= $item['months_count'] == 3 ? 'badge-success' : 'badge-warning' ?>">
                        <?= $item['months_count'] ?> / 3 أشهر
                    </span>
                 </td>
                <td><strong><?= number_format($item['total_amount'], 2) ?> دج</strong></td>
                <td><small><?= $item['months_details'] ?></small> </td>
                <td><small><?= date('d/m/Y', strtotime($item['start_date'])) ?> → <?= date('d/m/Y', strtotime($item['end_date'])) ?></small></td>
             
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="total-row">
                <td colspan="5"><strong>الإجمالي الكلي لسعدين للتجهيز</strong></td>
                <td colspan="3"><strong><?= number_format($grandTotal, 2) ?> دج</strong></td>
             </tr>
        </tfoot>
    </table>

    <div style="margin-top:20px; padding:15px; background:#e3f2fd; border-radius:10px;" class="no-print">
        <p><strong>ملخص حسب التصنيف:</strong> دائم: <?= number_format($totalPermanent,2) ?> دج | متعاقد: <?= number_format($totalContract,2) ?> دج</p>
        <p><strong>إجمالي الاقتطاعات من سعدين للتجهيز:</strong> <?= number_format($grandTotal,2) ?> دج</p>
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