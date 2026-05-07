<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

require_once '../config/database.php';
require_once '../includes/functions.php';

$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
$source_filter = isset($_GET['source']) ? (int)$_GET['source'] : 0;

$quarters = [
    1 => ['start' => "$year-01-01", 'end' => "$year-03-31"],
    2 => ['start' => "$year-04-01", 'end' => "$year-06-30"],
    3 => ['start' => "$year-07-01", 'end' => "$year-09-30"],
    4 => ['start' => "$year-10-01", 'end' => "$year-12-31"]
];

$sources = $pdo->query("SELECT id, name FROM sources ORDER BY name")->fetchAll();

$sql = "
    SELECT 
        d.id as deduction_id,
        e.name as employee_name,
        e.category,
        d.source_id,
        s.name as source_name,
        d.monthly_amount,
        d.total_months,
        d.start_date,
        d.end_date
    FROM deductions d
    JOIN employees e ON d.employee_id = e.id
    JOIN sources s ON d.source_id = s.id
    WHERE strftime('%Y', d.start_date) <= :year 
      AND strftime('%Y', d.end_date) >= :year
";

if ($source_filter > 0) {
    $sql .= " AND d.source_id = :source_id";
}

$sql .= " ORDER BY e.category DESC, e.name ASC";

$stmt = $pdo->prepare($sql);
$stmt->bindParam(':year', $year);
if ($source_filter > 0) {
    $stmt->bindParam(':source_id', $source_filter);
}
$stmt->execute();
$results = $stmt->fetchAll();

$report = [];
$totalQ1 = $totalQ2 = $totalQ3 = $totalQ4 = $grandTotal = 0;
$sourceTotals = [];

foreach ($results as $row) {
    $startDate = new DateTime($row['start_date']);
    $endDate = new DateTime($row['end_date']);
    $monthly = (float)$row['monthly_amount'];
    $sourceName = $row['source_name'];
    
    $q1 = $q2 = $q3 = $q4 = 0;
    
    foreach ($quarters as $qNum => $qDates) {
        $qStart = new DateTime($qDates['start']);
        $qEnd = new DateTime($qDates['end']);
        
        // حساب فترة التداخل بين الاقتطاع والربع
        $intersectStart = max($startDate, $qStart);
        $intersectEnd = min($endDate, $qEnd);
        
        if ($intersectStart <= $intersectEnd) {
            // حساب عدد الأشهر الصحيحة بين التاريخين (شهر كامل أو جزئي)
            $months = ($intersectEnd->format('Y') - $intersectStart->format('Y')) * 12 
                    + ($intersectEnd->format('m') - $intersectStart->format('m')) 
                    + 1;
            
            // لا يمكن أن يتجاوز عدد الأشهر عدد أشهر الربع (3)
            if ($months > 3) $months = 3;
            if ($months < 0) $months = 0;
            
            if ($months > 0) {
                $amount = $monthly * $months;
                switch ($qNum) {
                    case 1: $q1 += $amount; break;
                    case 2: $q2 += $amount; break;
                    case 3: $q3 += $amount; break;
                    case 4: $q4 += $amount; break;
                }
            }
        }
    }
    
    $total = $q1 + $q2 + $q3 + $q4;
    if ($total == 0) continue;
    
    $report[] = [
        'deduction_id' => $row['deduction_id'],
        'employee_name' => $row['employee_name'],
        'category' => $row['category'],
        'source_name' => $sourceName,
        'monthly' => $monthly,
        'q1' => $q1,
        'q2' => $q2,
        'q3' => $q3,
        'q4' => $q4,
        'total' => $total,
        'start_date' => $row['start_date'],
        'end_date' => $row['end_date']
    ];
    
    $totalQ1 += $q1;
    $totalQ2 += $q2;
    $totalQ3 += $q3;
    $totalQ4 += $q4;
    $grandTotal += $total;
    
    if (!isset($sourceTotals[$sourceName])) {
        $sourceTotals[$sourceName] = 0;
    }
    $sourceTotals[$sourceName] += $total;
}

include '../includes/header.php';
?>
<style>
.report-container{max-width:1300px;margin:0 auto;padding:20px;font-family:'Segoe UI',sans-serif;direction:rtl}
.report-header{background:linear-gradient(135deg,#1e3c72,#2a5298);color:#fff;padding:15px;border-radius:10px;text-align:center;margin-bottom:20px}
.filters{background:#f0f2f5;padding:15px;border-radius:10px;margin-bottom:20px;display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.filters select,.filters button{padding:8px 15px;border-radius:8px;border:1px solid #ccc}
.btn-primary{background:#2a5298;color:#fff;border:none;cursor:pointer}
.btn-success{background:#28a745;color:#fff;border:none;cursor:pointer}
.stats-cards{display:flex;gap:15px;flex-wrap:wrap;margin-bottom:20px}
.stat-card{background:#fff;padding:15px;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,0.1);flex:1;text-align:center;min-width:150px}
.stat-card .number{font-size:24px;font-weight:bold;color:#2a5298}
.data-table{width:100%;border-collapse:collapse;background:#fff;margin-bottom:20px;font-size:13px}
.data-table th,.data-table td{border:1px solid #ddd;padding:8px;text-align:center;vertical-align:middle}
.data-table th{background:#2a5298;color:#fff;font-weight:bold}
.permanent-row{background-color:#e8f5e9}
.contract-row{background-color:#fff3e0}
.total-row{background:#ffd700;font-weight:bold}
.print-header,.print-footer{display:none}
@media print{
.sidebar,.top-bar,.filters,.stats-cards,.btn-primary,.btn-success,.toggle-sidebar,.dark-mode-toggle,.date-badge,.no-print,.footer{display:none!important}
body,.main-content,.report-container{margin:0;padding:0;background:#fff}
.data-table tr{page-break-inside:avoid}
.data-table thead{display:table-header-group}
.data-table tfoot{display:table-footer-group}
.print-header{display:block;text-align:center;margin-bottom:20px;border-bottom:2px solid #000;padding-bottom:10px}
.print-footer{display:block;position:fixed;bottom:0;width:100%;text-align:center;font-size:10px;border-top:1px solid #ccc;padding-top:5px}
.report-header{background:#2a5298!important;color:#fff!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.permanent-row,.contract-row,.total-row,.data-table th{-webkit-print-color-adjust:exact;print-color-adjust:exact}
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
<h2>التقرير السنوي - سنة <?= $year ?></h2>
</div>
<div class="filters">
<form method="GET">
<select name="year">
<?php for($y = 2020; $y <= date('Y')+1; $y++): ?>
<option value="<?= $y ?>" <?= $y == $year ? 'selected' : '' ?>><?= $y ?></option>
<?php endfor; ?>
</select>
<select name="source">
<option value="0">جميع المصادر</option>
<?php foreach($sources as $s): ?>
<option value="<?= $s['id'] ?>" <?= $source_filter == $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
<?php endforeach; ?>
</select>
<button type="submit" class="btn-primary">عرض التقرير</button>
<button type="button" class="btn-success" onclick="window.print()">طباعة</button>
</form>
</div>
<?php if(empty($report)): ?>
<div style="background:#f8d7da;padding:20px;text-align:center;">لا توجد بيانات للسنة المحددة</div>
<?php else: ?>
<div class="stats-cards">
<div class="stat-card"><h4>الربع الأول</h4><div class="number"><?= number_format($totalQ1,2) ?> دج</div></div>
<div class="stat-card"><h4>الربع الثاني</h4><div class="number"><?= number_format($totalQ2,2) ?> دج</div></div>
<div class="stat-card"><h4>الربع الثالث</h4><div class="number"><?= number_format($totalQ3,2) ?> دج</div></div>
<div class="stat-card"><h4>الربع الرابع</h4><div class="number"><?= number_format($totalQ4,2) ?> دج</div></div>
<div class="stat-card"><h4>إجمالي الاقتطاعات</h4><div class="number"><?= number_format($grandTotal,2) ?> دج</div></div>
</div>
<table class="data-table">
<thead>
<tr>
<th>#</th>
<th>التصنيف</th>
<th>اسم الموظف</th>
<th>المصدر</th>
<th>المبلغ الشهري (دج)</th>
<th>الربع الأول (دج)</th>
<th>الربع الثاني (دج)</th>
<th>الربع الثالث (دج)</th>
<th>الربع الرابع (دج)</th>
<th>الإجمالي السنوي (دج)</th>
<th>فترة الاقتطاع</th>
</tr>
</thead>
<tbody>
<?php $i = 1; foreach($report as $item): ?>
<tr class="<?= $item['category'] == 'Permanent' ? 'permanent-row' : 'contract-row' ?>">
<td><?= $i++ ?> <small>(ID:<?= $item['deduction_id'] ?>)</small></td>
<td><?= $item['category'] == 'Permanent' ? 'دائم' : 'متعاقد' ?></td>
<td><strong><?= htmlspecialchars($item['employee_name']) ?></strong></td>
<td><?= htmlspecialchars($item['source_name']) ?></td>
<td><?= number_format($item['monthly'], 2) ?> دج</td>
<td><?= number_format($item['q1'], 2) ?> دج</td>
<td><?= number_format($item['q2'], 2) ?> دج</td>
<td><?= number_format($item['q3'], 2) ?> دج</td>
<td><?= number_format($item['q4'], 2) ?> دج</td>
<td><strong><?= number_format($item['total'], 2) ?> دج</strong></td>
<td><small><?= date('d/m/Y', strtotime($item['start_date'])) ?> → <?= date('d/m/Y', strtotime($item['end_date'])) ?></small></td>
</tr>
<?php endforeach; ?>
</tbody>
<tfoot>
<tr class="total-row">
<td colspan="5"><strong>الإجمالي الكلي</strong></td>
<td><strong><?= number_format($totalQ1,2) ?> دج</strong></td>
<td><strong><?= number_format($totalQ2,2) ?> دج</strong></td>
<td><strong><?= number_format($totalQ3,2) ?> دج</strong></td>
<td><strong><?= number_format($totalQ4,2) ?> دج</strong></td>
<td colspan="2"><strong><?= number_format($grandTotal,2) ?> دج</strong></td>
</tr>
</tfoot>
</table>
<?php if(count($sourceTotals) > 1): ?>
<div style="margin-top:20px;padding:15px;background:#e3f2fd;border-radius:10px;">
<h4>ملخص حسب المصدر</h4>
<?php foreach($sourceTotals as $srcName => $srcTotal): ?>
<p><strong><?= htmlspecialchars($srcName) ?>:</strong> <?= number_format($srcTotal,2) ?> دج</p>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>
</div>
<div class="footer no-print" style="text-align:center;margin-top:20px;color:#666;">
تم إنشاء التقرير بواسطة نظام إدارة الاقتطاعات - <?= date('Y-m-d H:i:s') ?>
</div>
<div class="print-footer">
<p>تقرير رسمي - مركز التكوين والتعليم المهنيين - لجنة الخدمات الاجتماعية - تاريخ الطباعة: <?= date('Y-m-d H:i:s') ?></p>
</div>
<?php include '../includes/footer.php'; ?>