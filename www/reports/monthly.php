<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
require_once '../config/database.php';
require_once '../includes/functions.php';

$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : date('m');
$source_id = isset($_GET['source_id']) ? (int)$_GET['source_id'] : 0;
$employee_id = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;
$is_print = isset($_GET['print']) && $_GET['print'] == 1;

$year_month = sprintf("%04d-%02d", $year, $month);

// جلب قائمة المصادر والموظفين
$sources = $pdo->query("SELECT id, name FROM sources ORDER BY name")->fetchAll();
$employees = $pdo->query("SELECT id, name, category FROM employees ORDER BY name")->fetchAll();

// ============================================================
// الاستعلام الأصلي (الاقتطاعات العادية من جدول deductions)
// ============================================================
$sql = "
    SELECT 
        d.id,
        d.employee_id,
        d.source_id,
        e.name as employee_name,
        e.category,
        s.name as source_name,
        d.monthly_amount,
        d.total_months,
        d.start_date,
        d.end_date,
        d.is_loan,
        (d.monthly_amount * d.total_months) AS total_amount,
        'regular' as deduction_type
    FROM deductions d
    JOIN employees e ON d.employee_id = e.id
    JOIN sources s ON d.source_id = s.id
    WHERE strftime('%Y-%m', d.start_date) <= :year_month
      AND strftime('%Y-%m', d.end_date) >= :year_month
";

$params = [':year_month' => $year_month];

// فلتر المصدر (نتجنب إضافة فلتر djezzy هنا لأننا سنأتي بها من استعلام منفصل)
$djezzy_source_id = $pdo->query("SELECT id FROM sources WHERE name = 'djezzy' LIMIT 1")->fetchColumn();
if ($source_id > 0 && $source_id != $djezzy_source_id) {
    $sql .= " AND d.source_id = :source_id";
    $params[':source_id'] = $source_id;
}
if ($employee_id > 0) {
    $sql .= " AND d.employee_id = :employee_id";
    $params[':employee_id'] = $employee_id;
}

$sql .= " ORDER BY e.category DESC, e.name ASC, d.start_date ASC";

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$deductions = $stmt->fetchAll();

// ============================================================
// جلب اقتطاعات جيزي من جدول employee_phone_numbers
// ============================================================
$djezzy_deductions = [];
if ($djezzy_source_id && ($source_id == 0 || $source_id == $djezzy_source_id)) {
    $sql_dj = "
        SELECT 
            NULL as id,
            e.id as employee_id,
            e.name as employee_name,
            e.category,
            'djezzy' as source_name,
            COALESCE(SUM(epn.monthly_amount), 0) as monthly_amount,
            1 as total_months,
            :start_date as start_date,
            :end_date as end_date,
            0 as is_loan,
            COALESCE(SUM(epn.monthly_amount), 0) as total_amount,
            'djezzy' as deduction_type
        FROM employees e
        JOIN employee_phone_numbers epn ON e.id = epn.employee_id AND epn.is_active = 1
    ";
    if ($employee_id > 0) {
        $sql_dj .= " WHERE e.id = :employee_id";
    }
    $sql_dj .= " GROUP BY e.id HAVING monthly_amount > 0 ORDER BY e.category DESC, e.name ASC";
    
    $stmt_dj = $pdo->prepare($sql_dj);
    $stmt_dj->bindValue(':start_date', "$year-$month-01");
    $stmt_dj->bindValue(':end_date', date('Y-m-t', strtotime("$year-$month-01")));
    if ($employee_id > 0) {
        $stmt_dj->bindValue(':employee_id', $employee_id);
    }
    $stmt_dj->execute();
    $djezzy_deductions = $stmt_dj->fetchAll();
}

// دمج جميع الاقتطاعات
$all_deductions = array_merge($deductions, $djezzy_deductions);

// ========== إذا كان وضع الطباعة: تجميع الاقتطاعات حسب (الموظف + المصدر) ==========
if ($is_print) {
    $grouped = [];
    foreach ($all_deductions as $d) {
        $key = $d['employee_id'] . '|' . ($d['deduction_type'] == 'djezzy' ? 'djezzy' : $d['source_id']);
        if (!isset($grouped[$key])) {
            $grouped[$key] = [
                'employee_name' => $d['employee_name'],
                'category' => $d['category'],
                'source_name' => $d['source_name'],
                'total_monthly' => 0,
                'total_overall' => 0,
                'total_months' => 0,
                'start_date' => $d['start_date'],
                'end_date' => $d['end_date'],
                'is_loan' => $d['is_loan'],
                'deduction_type' => $d['deduction_type'],
            ];
        }
        $grouped[$key]['total_monthly'] += $d['monthly_amount'];
        $grouped[$key]['total_overall'] += $d['total_amount'];
        $grouped[$key]['total_months'] += $d['total_months'];
        if ($d['start_date'] < $grouped[$key]['start_date']) $grouped[$key]['start_date'] = $d['start_date'];
        if ($d['end_date'] > $grouped[$key]['end_date']) $grouped[$key]['end_date'] = $d['end_date'];
    }
    $dataSource = $grouped;
} else {
    $dataSource = $all_deductions;
}

// فصل البيانات إلى دائمين ومتعاقدين
$permanent = [];
$contract = [];
$totalPermanent = 0;
$totalContract = 0;

foreach ($dataSource as $item) {
    $monthly_amt = ($is_print ? $item['total_monthly'] : $item['monthly_amount']);
    if ($item['category'] == 'Permanent') {
        $permanent[] = $item;
        $totalPermanent += $monthly_amt;
    } else {
        $contract[] = $item;
        $totalContract += $monthly_amt;
    }
}
$grandTotal = $totalPermanent + $totalContract;

include '../includes/header.php';
?>

<style>
    .report-container {
        direction: rtl;
        max-width: 1400px;
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
    .filter-group {
        display: flex;
        align-items: center;
        gap: 5px;
        background: white;
        padding: 5px 10px;
        border-radius: 8px;
        border: 1px solid #ddd;
    }
    .filter-group label {
        font-weight: bold;
        margin: 0;
    }
    .filters select, .filters button, .filters a.btn-success {
        padding: 8px 15px;
        border-radius: 8px;
        border: 1px solid #ccc;
        font-size: 14px;
        text-decoration: none;
        display: inline-block;
        cursor: pointer;
    }
    .btn-primary { background: #2a5298; color: white; border: none; cursor: pointer; }
    .btn-success { background: #28a745; color: white; border: none; }
    .btn-secondary { background: #6c757d; color: white; border: none; text-decoration: none; display: inline-block; }
    .section-title {
        font-size: 18px;
        font-weight: bold;
        margin: 20px 0 10px;
        padding-right: 10px;
        border-right: 4px solid #2a5298;
    }
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
    .total-row {
        background: #ffd700;
        font-weight: bold;
    }
    /* ========== تنسيقات الجداول بالألوان والأيقونات ========== */
    .row-sources {
        transition: background 0.2s;
    }
    .source-loan { background-color: #fff8e7; border-right: 4px solid #ff9800; }
    .source-saadine { background-color: #e8f5e9; border-right: 4px solid #4caf50; }
    .source-djezzy { background-color: #e3f2fd; border-right: 4px solid #2196f3; }
    .source-other { background-color: #ffffff; }

    .source-icon {
        margin-left: 5px;
        font-size: 1.1em;
        display: inline-block;
        vertical-align: middle;
    }
    .badge-loan {
        background: #ff9800;
        color: white;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 12px;
        display: inline-block;
    }
    .badge-normal {
        background: #28a745;
        color: white;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 12px;
        display: inline-block;
    }
    .badge-djezzy {
        background: #3b82f6;
        color: white;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 12px;
        display: inline-block;
        margin-right: 5px;
    }
    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 4px 8px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: bold;
    }
    .status-active { background: #28a74520; color: #1e7e34; border: 1px solid #28a74540; }
    .status-expiring { background: #ffc10720; color: #b26a00; border: 1px solid #ffc10740; }
    .status-expired { background: #dc354520; color: #a71d2a; border: 1px solid #dc354540; }
    .print-header, .print-footer { display: none; }

@media print {
    /* إخفاء العناصر غير المرغوب طباعتها */
    .sidebar, .top-bar, .filters, .btn-primary, .btn-success, .btn-secondary,
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
    /* الرأس والتذييل المخصص للطباعة */
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
    /* ========== تخصيص الألوان في الطباعة ========== */
    .report-header {
        background: #2a5298 !important;
        color: white !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .data-table th {
        background: #2a5298 !important;
        color: white !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .total-row {
    background: #f1d798 !important;  /* لون بيج فاتح (بدلاً من الذهبي الغامق) */
    color: #333 !important;
    font-weight: bold;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
    }
    /* تبييض جميع صفوف البيانات (إلغاء الخلفيات الملونة) */
    .row-sources.source-loan,
    .row-sources.source-saadine,
    .row-sources.source-djezzy,
    .row-sources.source-other {
        background: white !important;
        border-right: none !important;
    }
    /* إلغاء خلفيات الشارات وألوان الحالة */
    .badge-loan, .badge-normal, .badge-djezzy, .status-badge {
        background: white !important;
        border: 1px solid #aaa;
        color: black !important;
    }
    /* المحافظة على حدود الجدول */
    .data-table td {
        border: 1px solid #ddd;
        background: white;
        color: black;
    }
    .section-title {
        border-right-color: #2a5298;
    }
    /* بدء جدول المتعاقدين في صفحة جديدة */
    .contract-section {
        page-break-before: always;
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
        <h2>📅 التقرير الشهري للاقتطاعات</h2>
        <h3><?= date('F Y', mktime(0,0,0,$month,1,$year)) ?></h3>
    </div>

    <!-- نموذج الفلاتر (يظهر فقط في الوضع العادي) -->
    <?php if (!$is_print): ?>
    <div class="filters">
        <form method="GET" action="">
            <div class="filter-group">
                <label>📅 السنة:</label>
                <select name="year">
                    <?php for($y = 2020; $y <= date('Y')+1; $y++): ?>
                        <option value="<?= $y ?>" <?= $y == $year ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>📆 الشهر:</label>
                <select name="month">
                    <?php for($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= $m == $month ? 'selected' : '' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>🏷️ المصدر:</label>
                <select name="source_id">
                    <option value="0">-- جميع المصادر --</option>
                    <?php foreach($sources as $src): ?>
                        <option value="<?= $src['id'] ?>" <?= ($source_id == $src['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($src['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>👤 الموظف:</label>
                <select name="employee_id">
                    <option value="0">-- جميع الموظفين --</option>
                    <?php foreach($employees as $emp): ?>
                        <option value="<?= $emp['id'] ?>" <?= ($employee_id == $emp['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($emp['name']) . " (" . ($emp['category'] == 'Permanent' ? 'دائم' : 'متعاقد') . ")" ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn-primary">🔍 عرض</button>
            <a href="monthly.php?year=<?= $year ?>&month=<?= $month ?>&source_id=<?= $source_id ?>&employee_id=<?= $employee_id ?>&print=1" target="_blank" class="btn-success" style="text-decoration: none;">🖨️ طباعة (مجمّع)</a>
            <?php if($source_id > 0 || $employee_id > 0): ?>
                <a href="monthly.php?year=<?= $year ?>&month=<?= $month ?>" class="btn-secondary">❌ إلغاء الفلتر</a>
            <?php endif; ?>
        </form>
    </div>
    <?php endif; ?>

    <?php if(empty($dataSource)): ?>
        <div style="background:#f8d7da; padding:20px; text-align:center;">⚠️ لا توجد بيانات للشهر والفلاتر المحددة</div>
    <?php else: ?>

    <!-- جدول الدائمين -->
    <div class="section-title">👔 الموظفون الدائمون (Permanent)</div>
    <table class="data-table">
        <thead>
            <tr>
                <th>#</th>
                <th>الموظف</th>
                <th>المصدر</th>
                <th>المبلغ الشهري (دج)</th>
                <th>عدد الأشهر</th>
                <th>تاريخ البداية</th>
                <th>تاريخ النهاية</th>
                <th>الإجمالي الكلي (دج)</th>
                <th>النوع</th>
                <th>الحالة</th>
            </tr>
        </thead>
        <tbody>
            <?php if(empty($permanent)): ?>
                <tr><td colspan="10" style="text-align:center;">لا توجد بيانات للدائمين</span></small></td>
            <?php else: ?>
                <?php $i=1; foreach($permanent as $item):
                    // تحديد الكلاس والخلفية والرمز حسب المصدر
                    $source_class = 'source-other';
                    $display_source = htmlspecialchars($item['source_name']);
                    $source_icon = '📌';
                    if(isset($item['deduction_type']) && $item['deduction_type'] == 'djezzy') {
                        $source_class = 'source-djezzy';
                        $source_icon = '📱';
                        $display_source = 'Djezzy';
                    } elseif($item['is_loan'] == 1 || $item['source_name'] == 'سلفيات') {
                        $source_class = 'source-loan';
                        $source_icon = '💰';
                    } elseif($item['source_name'] == 'سعدين للتجهير') {
                        $source_class = 'source-saadine';
                        $source_icon = '🛠️';
                    }
                ?>
                <tr class="row-sources <?= $source_class ?>">
                    <td>
                        <?= $i++ ?>
                        <?php if (!$is_print && isset($item['id'])): ?>
                            <small>(ID:<?= $item['id'] ?>)</small>
                        <?php elseif($is_print): ?>
                            <small>(مجمّع)</small>
                        <?php endif; ?>
                     </span></small>
                    <td><strong><?= htmlspecialchars($item['employee_name']) ?></strong></span></small>
                    <td>
                        <?php if(isset($item['deduction_type']) && $item['deduction_type'] == 'djezzy'): ?>
                            <span class="badge-djezzy">📱 Djezzy</span>
                        <?php else: ?>
                            <span class="source-icon"><?= $source_icon ?></span> <?= $display_source ?>
                        <?php endif; ?>
                     </span></small>
                    <td>
                        <?= number_format($is_print ? $item['total_monthly'] : $item['monthly_amount'], 2) ?> دج
                     </span></small>
                    <td><?= ($is_print ? $item['total_months'] : $item['total_months']) ?> شهر</span></small>
                    <td><?= date('d/m/Y', strtotime($item['start_date'])) ?></span></small>
                    <td><?= date('d/m/Y', strtotime($item['end_date'])) ?></span></small>
                    <td>
                        <?= number_format($is_print ? $item['total_overall'] : ($item['total_amount'] ?? $item['monthly_amount']), 2) ?> دج
                     </span></small>
                    <td>
                        <?php if(isset($item['deduction_type']) && $item['deduction_type'] == 'djezzy'): ?>
                            <span class="badge-normal">📌 اقتطاع عادي</span>
                        <?php elseif($item['is_loan']): ?>
                            <span class="badge-loan">💰 قرض</span>
                        <?php else: ?>
                            <span class="badge-normal">📌 اقتطاع عادي</span>
                        <?php endif; ?>
                     </span></small>
                    <td>
                        <?php
                        if(isset($item['deduction_type']) && $item['deduction_type'] == 'djezzy') {
                            echo '<span class="status-badge status-active">✅ نشط</span>';
                        } else {
                            $today = new DateTime();
                            $end = new DateTime($item['end_date']);
                            $diff = $today->diff($end)->days;
                            if ($end < $today) {
                                echo '<span class="status-badge status-expired">🔴 منتهي</span>';
                            } elseif ($diff <= 30) {
                                echo '<span class="status-badge status-expiring">⚠️ ينتهي قريباً</span>';
                            } else {
                                echo '<span class="status-badge status-active">✅ نشط</span>';
                            }
                        }
                        ?>
                     </span></small>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr class="total-row">
                <td colspan="3"><strong>إجمالي الدائمين</strong></span></small></td>
                <td colspan="1"><strong><?= number_format($totalPermanent, 2) ?> دج</strong></span></small></td>
                <td colspan="6"> </span></small></td>
             </span></small></tr>
        </tfoot>
    </table>

    <!-- جدول المتعاقدين -->
    <div class="section-title contract-section">👕 الموظفون المتعاقدون (Contract)</div>
    <table class="data-table">
        <thead>
            <tr>
                <th>#</th>
                <th>الموظف</th>
                <th>المصدر</th>
                <th>المبلغ الشهري (دج)</th>
                <th>عدد الأشهر</th>
                <th>تاريخ البداية</th>
                <th>تاريخ النهاية</th>
                <th>الإجمالي الكلي (دج)</th>
                <th>النوع</th>
                <th>الحالة</th>
            </tr>
        </thead>
        <tbody>
            <?php if(empty($contract)): ?>
                <tr><td colspan="10" style="text-align:center;">لا توجد بيانات للمتعاقدين</span></small></td>
            <?php else: ?>
                <?php $i=1; foreach($contract as $item):
                    $source_class = 'source-other';
                    $display_source = htmlspecialchars($item['source_name']);
                    $source_icon = '📌';
                    if(isset($item['deduction_type']) && $item['deduction_type'] == 'djezzy') {
                        $source_class = 'source-djezzy';
                        $source_icon = '📱';
                        $display_source = 'Djezzy';
                    } elseif($item['is_loan'] == 1 || $item['source_name'] == 'سلفيات') {
                        $source_class = 'source-loan';
                        $source_icon = '💰';
                    } elseif($item['source_name'] == 'سعدين للتجهير') {
                        $source_class = 'source-saadine';
                        $source_icon = '🛠️';
                    }
                ?>
                <tr class="row-sources <?= $source_class ?>">
                    <td><?= $i++ ?>
                        <?php if (!$is_print && isset($item['id'])): ?>
                            <small>(ID:<?= $item['id'] ?>)</small>
                        <?php elseif($is_print): ?>
                            <small>(مجمّع)</small>
                        <?php endif; ?>
                     </span></small>
                    <td><strong><?= htmlspecialchars($item['employee_name']) ?></strong></span></small>
                    <td>
                        <?php if(isset($item['deduction_type']) && $item['deduction_type'] == 'djezzy'): ?>
                            <span class="badge-djezzy">📱 Djezzy</span>
                        <?php else: ?>
                            <span class="source-icon"><?= $source_icon ?></span> <?= $display_source ?>
                        <?php endif; ?>
                     </span></small>
                    <td>
                        <?= number_format($is_print ? $item['total_monthly'] : $item['monthly_amount'], 2) ?> دج
                     </span></small>
                    <td><?= ($is_print ? $item['total_months'] : $item['total_months']) ?> شهر</span></small>
                    <td><?= date('d/m/Y', strtotime($item['start_date'])) ?></span></small>
                    <td><?= date('d/m/Y', strtotime($item['end_date'])) ?></span></small>
                    <td>
                        <?= number_format($is_print ? $item['total_overall'] : ($item['total_amount'] ?? $item['monthly_amount']), 2) ?> دج
                     </span></small>
                    <td>
                        <?php if(isset($item['deduction_type']) && $item['deduction_type'] == 'djezzy'): ?>
                            <span class="badge-normal">📌 اقتطاع عادي</span>
                        <?php elseif($item['is_loan']): ?>
                            <span class="badge-loan">💰 قرض</span>
                        <?php else: ?>
                            <span class="badge-normal">📌 اقتطاع عادي</span>
                        <?php endif; ?>
                     </span></small>
                    <td>
                        <?php
                        if(isset($item['deduction_type']) && $item['deduction_type'] == 'djezzy') {
                            echo '<span class="status-badge status-active">✅ نشط</span>';
                        } else {
                            $today = new DateTime();
                            $end = new DateTime($item['end_date']);
                            $diff = $today->diff($end)->days;
                            if ($end < $today) {
                                echo '<span class="status-badge status-expired">🔴 منتهي</span>';
                            } elseif ($diff <= 30) {
                                echo '<span class="status-badge status-expiring">⚠️ ينتهي قريباً</span>';
                            } else {
                                echo '<span class="status-badge status-active">✅ نشط</span>';
                            }
                        }
                        ?>
                     </span></small>
                <tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr class="total-row">
                <td colspan="3"><strong>إجمالي المتعاقدين</strong></span></small></td>
                <td colspan="1"><strong><?= number_format($totalContract, 2) ?> دج</strong></span></small></td>
                <td colspan="6"> </span></small></td>
             </span></small></tr>
        </tfoot>
    </table>

    <!-- المجموع العام -->
    <div style="margin-top: 20px; padding: 12px; background: #ff9800; border-radius: 8px; text-align: center; font-weight: bold; font-size: 18px;">
        💰 الإجمالي العام للشهر: <?= number_format($grandTotal, 2) ?> دج
    </div>

    <?php endif; ?>
</div>

<div class="footer no-print" style="text-align:center; margin-top:20px; color:#666;">
    تم إنشاء التقرير بواسطة نظام إدارة الاقتطاعات - <?= date('Y-m-d H:i:s') ?>
</div>

<div class="print-footer">
    <p>تقرير رسمي - مركز التكوين والتعليم المهنيين - لجنة الخدمات الاجتماعية - تاريخ الطباعة: <?= date('Y-m-d H:i:s') ?></p>
</div>

<?php
if ($is_print): ?>
<script>
    window.onload = function() {
        window.print();
    };
</script>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>