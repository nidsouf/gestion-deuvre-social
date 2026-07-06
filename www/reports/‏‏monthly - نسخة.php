<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
require_once '../config/database.php';
require_once '../includes/functions.php';

if (!function_exists('getMonthNameArabic')) {
    function getMonthNameArabic($month) {
        $months = [1=>'جانفي',2=>'فيفري',3=>'مارس',4=>'أفريل',5=>'ماي',6=>'جوان',7=>'جويلية',8=>'أوت',9=>'سبتمبر',10=>'أكتوبر',11=>'نوفمبر',12=>'ديسمبر'];
        return $months[(int)$month] ?? '';
    }
}

$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : date('m');
$source_id = isset($_GET['source_id']) ? (int)$_GET['source_id'] : 0;
$employee_id = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;
$is_print = isset($_GET['print']) && $_GET['print'] == 1;

$month_name_ar = getMonthNameArabic($month);
$report_ym = sprintf("%04d-%02d", $year, $month);

$sources = $pdo->query("SELECT id, name FROM sources ORDER BY name")->fetchAll();
$employees = $pdo->query("SELECT id, name, category FROM employees ORDER BY name")->fetchAll();

// ========== الاقتطاعات العادية ==========
$sql = "SELECT 
            mi.amount as monthly_amount,
            e.id as employee_id,
            e.name as employee_name,
            e.category,
            s.name as source_name,
            d.is_loan,
            d.credit_balance,
            (SELECT MIN(ep.payment_date) FROM early_payments ep WHERE ep.deduction_id = d.id AND ep.is_reversed = 0) as first_early_payment_date,
            'regular' as type
        FROM monthly_installments mi
        JOIN employees e ON mi.employee_id = e.id
        JOIN sources s ON mi.source_id = s.id
        JOIN deductions d ON mi.deduction_id = d.id
        WHERE mi.year = :year AND mi.month = :month";
$params = [':year' => $year, ':month' => $month];
if ($source_id > 0) { $sql .= " AND mi.source_id = :source_id"; $params[':source_id'] = $source_id; }
if ($employee_id > 0) { $sql .= " AND mi.employee_id = :employee_id"; $params[':employee_id'] = $employee_id; }
$sql .= " ORDER BY e.name ASC";

$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->execute();
$installments = $stmt->fetchAll();

// ========== اقتطاعات جيزي ==========
$djezzy_items = [];
$show_djezzy = true;
if ($source_id > 0) {
    $src_name = $pdo->prepare("SELECT name FROM sources WHERE id = ?");
    $src_name->execute([$source_id]);
    if ($src_name->fetchColumn() != 'Djezzy') $show_djezzy = false;
}
if ($show_djezzy) {
    $sql_dj = "SELECT 
                epn.monthly_amount,
                e.id as employee_id,
                e.name as employee_name,
                e.category,
                'Djezzy' as source_name,
                0 as is_loan,
                0 as credit_balance,
                NULL as first_early_payment_date,
                'djezzy' as type
            FROM employee_phone_numbers epn
            JOIN employees e ON epn.employee_id = e.id
            WHERE epn.is_active = 1";
    if ($employee_id > 0) $sql_dj .= " AND e.id = $employee_id";
    $sql_dj .= " ORDER BY e.name ASC";
    $djezzy_items = $pdo->query($sql_dj)->fetchAll();
}

$all_items = array_merge($installments, $djezzy_items);
usort($all_items, fn($a,$b)=>strcmp($a['employee_name'],$b['employee_name']));

// ========== دالة حساب المبلغ الفعلي حسب المنطق الجديد ==========
function getEffectiveAmount($item, $report_ym) {
    if ($item['type'] == 'djezzy') return $item['monthly_amount'];
    $monthly = $item['monthly_amount'];
    $pay_date = $item['first_early_payment_date'];
    if (!empty($pay_date)) {
        $pay_ym = substr($pay_date, 0, 7);
        // شهر التسديد: يعرض المبلغ المدفوع (credit_balance)
        if ($pay_ym == $report_ym) {
            return $item['credit_balance'];  // مثال: 4400
        }
        // الشهر التالي للتسديد: يعرض المبلغ المتبقي (القسط - credit_balance)
        $next_ym = date('Y-m', strtotime($pay_date . ' +1 month'));
        if ($next_ym == $report_ym) {
            $remaining = $monthly - $item['credit_balance'];
            return $remaining < 0 ? 0 : $remaining;  // مثال: 5600
        }
    }
    return $monthly;
}

// فصل الموظفين
$permanent = []; $contract = [];
foreach ($all_items as $it) {
    if ($it['category'] == 'Permanent') $permanent[] = $it;
    else $contract[] = $it;
}
usort($permanent, fn($a,$b)=>strcmp($a['employee_name'],$b['employee_name']));
usort($contract, fn($a,$b)=>strcmp($a['employee_name'],$b['employee_name']));

$totalPermanent = array_sum(array_map(fn($it)=>getEffectiveAmount($it,$report_ym), $permanent));
$totalContract = array_sum(array_map(fn($it)=>getEffectiveAmount($it,$report_ym), $contract));
$grandTotal = $totalPermanent + $totalContract;

// ========== وضع الطباعة (مع التجميع) ==========
if ($is_print) {
    $grouped = [];
    foreach ($all_items as $it) {
        $eff = getEffectiveAmount($it, $report_ym);
        $key = $it['employee_id'] . '|' . $it['source_name'];
        if (!isset($grouped[$key])) {
            $grouped[$key] = [
                'employee_name' => $it['employee_name'],
                'category' => $it['category'],
                'source_name' => $it['source_name'],
                'total_monthly' => 0
            ];
        }
        $grouped[$key]['total_monthly'] += $eff;
    }
    $permG = []; $contG = [];
    foreach ($grouped as $g) {
        if ($g['category'] == 'Permanent') $permG[] = $g;
        else $contG[] = $g;
    }
    usort($permG, fn($a,$b)=>strcmp($a['employee_name'],$b['employee_name']));
    usort($contG, fn($a,$b)=>strcmp($a['employee_name'],$b['employee_name']));
    $totalPermG = array_sum(array_column($permG,'total_monthly'));
    $totalContG = array_sum(array_column($contG,'total_monthly'));
    ?>
    <!DOCTYPE html>
    <html lang="ar" dir="rtl">
    <head><meta charset="UTF-8"><title>تقرير شهري - <?=$month_name_ar.' '.$year?></title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Segoe UI',Tahoma,Arial,sans-serif;background:white;padding:20px}
        .print-header{text-align:center;margin-bottom:25px;border-bottom:2px solid #2a5298;padding-bottom:10px}
        .print-header h2{color:#2a5298}
        .section-title{font-size:18px;font-weight:bold;margin:20px 0 10px;border-right:4px solid #2a5298;padding-right:10px}
        table{width:100%;border-collapse:collapse;margin-bottom:20px}
        th,td{border:1px solid #999;padding:8px;text-align:center}
        th{background:#2a5298;color:white}
        .total-row{background:#f0f0f0;font-weight:bold}
        .footer{text-align:center;margin-top:30px;font-size:10px;color:#666}
        @media print{body{margin:0;padding:0}}
    </style>
    </head>
    <body>
        <div class="print-header">
            <h2>مركز التكوين والتعليم المهنيين</h2>
            <h3>الشهيد علي بوسحابة - بكوينين</h3>
            <h4>لجنة الخدمات الاجتماعية</h4>
            <p>التقرير الشهري للاقتطاعات - <?=$month_name_ar.' '.$year?></p>
        </div>
        <div class="section-title">👔 الموظفون الدائمون</div>
        <table><thead><tr><th>#</th><th>الموظف</th><th>المصدر</th><th>المبلغ الشهري (دج)</th></tr></thead>
        <tbody>
        <?php if(empty($permG)): ?><tr><td colspan="4" style="text-align:center;">لا توجد بيانات</td></tr>
        <?php else: $i=1; foreach($permG as $it): ?>
        <tr><td><?=$i++?></td><td><?=htmlspecialchars($it['employee_name'])?></td><td><?=htmlspecialchars($it['source_name'])?></td><td><?=number_format($it['total_monthly'],2)?> دج</td></tr>
        <?php endforeach; ?>
        <tr class="total-row"><td colspan="3"><strong>الإجمالي</strong></td><td><strong><?=number_format($totalPermG,2)?> دج</strong></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
        <div style="page-break-before:always;"></div>
        <div class="section-title">👕 الموظفون المتعاقدون</div>
        <div style="text-align:center;margin:10px 0;font-size:16px;font-weight:bold;">📅 التقرير الشهري للاقتطاعات - <?=$month_name_ar.' '.$year?> - المتعاقدون</div>
        <table><thead><tr><th>#</th><th>الموظف</th><th>المصدر</th><th>المبلغ الشهري (دج)</th></tr></thead>
        <tbody>
        <?php if(empty($contG)): ?><tr><td colspan="4" style="text-align:center;">لا توجد بيانات</td></tr>
        <?php else: $i=1; foreach($contG as $it): ?>
        <tr><td><?=$i++?></td><td><?=htmlspecialchars($it['employee_name'])?></td><td><?=htmlspecialchars($it['source_name'])?></td><td><?=number_format($it['total_monthly'],2)?> دج</td></tr>
        <?php endforeach; ?>
        <tr class="total-row"><td colspan="3"><strong>الإجمالي</strong></td><td><strong><?=number_format($totalContG,2)?> دج</strong></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
        <div class="footer">تم إنشاء التقرير بواسطة نظام إدارة الاقتطاعات بتاريخ <?=date('Y-m-d H:i:s')?></div>
        <script>window.onload = function() { window.print(); };</script>
    </body>
    </html>
    <?php
    exit;
}

// ========== العرض العادي ==========
include '../includes/header.php';
?>
<style>
    .report-container{direction:rtl;max-width:1400px;margin:0 auto;padding:20px}
    .report-header{background:linear-gradient(135deg,#1e3c72,#2a5298);color:white;padding:15px;border-radius:10px;text-align:center;margin-bottom:20px}
    .filters{background:#f8f9fa;border-radius:20px;padding:15px;margin-bottom:20px;display:flex;gap:10px;flex-wrap:wrap;align-items:center}
    .filter-group{display:flex;align-items:center;gap:5px;background:white;padding:5px 10px;border-radius:8px;border:1px solid #ddd}
    .filter-group label{font-weight:bold;margin:0}
    .filters select,.filters button,.filters a{padding:8px 15px;border-radius:8px;border:1px solid #ccc;font-size:14px;text-decoration:none;display:inline-block;cursor:pointer}
    .btn-primary{background:#2a5298;color:white;border:none}
    .btn-success{background:#28a745;color:white;border:none}
    .section-title{font-size:18px;font-weight:bold;margin:20px 0 10px;padding-right:10px;border-right:4px solid #2a5298}
    .data-table{width:100%;border-collapse:collapse;background:white;margin-bottom:20px}
    .data-table th,.data-table td{border:1px solid #ddd;padding:10px;text-align:center}
    .data-table th{background:#2a5298;color:white}
    .total-row{background:#ffd700;font-weight:bold}
    .badge-loan{background:#ff9800;color:white;padding:4px 10px;border-radius:20px;display:inline-block}
    .badge-normal{background:#28a745;color:white;padding:4px 10px;border-radius:20px;display:inline-block}
    .status-active{background:#d4edda;color:#155724;padding:4px 8px;border-radius:20px;display:inline-block}
</style>
<div class="report-container">
    <div class="report-header"><h2>📅 التقرير الشهري للاقتطاعات</h2><h3><?=$month_name_ar.' '.$year?></h3></div>
    <div class="filters">
        <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;width:100%;">
            <div class="filter-group"><label>السنة:</label><select name="year"><?php for($y=2020;$y<=date('Y')+1;$y++): ?><option value="<?=$y?>" <?=$y==$year?'selected':''?>><?=$y?></option><?php endfor; ?></select></div>
            <div class="filter-group"><label>الشهر:</label><select name="month"><?php for($m=1;$m<=12;$m++): ?><option value="<?=$m?>" <?=$m==$month?'selected':''?>><?=getMonthNameArabic($m)?></option><?php endfor; ?></select></div>
            <div class="filter-group"><label>المصدر:</label><select name="source_id"><option value="0">جميع المصادر</option><?php foreach($sources as $src): ?><option value="<?=$src['id']?>" <?=($source_id==$src['id'])?'selected':''?>><?=htmlspecialchars($src['name'])?></option><?php endforeach; ?></select></div>
            <div class="filter-group"><label>الموظف:</label><select name="employee_id"><option value="0">جميع الموظفين</option><?php foreach($employees as $emp): ?><option value="<?=$emp['id']?>" <?=($employee_id==$emp['id'])?'selected':''?>><?=htmlspecialchars($emp['name'])?></option><?php endforeach; ?></select></div>
            <button type="submit" class="btn-primary">🔍 عرض</button>
            <a href="?year=<?=$year?>&month=<?=$month?>&source_id=<?=$source_id?>&employee_id=<?=$employee_id?>&print=1" target="_blank" class="btn-success">🖨️ طباعة</a>
        </form>
    </div>
    <?php if(empty($all_items)): ?>
        <div style="background:#f8d7da;padding:20px;text-align:center;">⚠️ لا توجد بيانات للشهر والفلاتر المحددة</div>
    <?php else: ?>
    <div class="section-title">👔 الموظفون الدائمون</div>
    <table class="data-table">
        <thead><tr><th>#</th><th>الموظف</th><th>المصدر</th><th>المبلغ الشهري (دج)</th><th>النوع</th><th>الحالة</th></tr></thead>
        <tbody>
        <?php if(empty($permanent)): ?><tr><td colspan="6" style="text-align:center;">لا توجد بيانات للدائمين</td></tr>
        <?php else: $i=1; foreach($permanent as $it):
            $eff = getEffectiveAmount($it, $report_ym);
            $loanBadge = $it['is_loan'] ? '<span class="badge-loan">💰 قرض</span>' : '<span class="badge-normal">📌 اقتطاع</span>';
        ?>
        <tr><td><?=$i++?></td><td><?=htmlspecialchars($it['employee_name'])?></td><td><?=htmlspecialchars($it['source_name'])?></td><td><?=number_format($eff,2)?> دج</td><td><?=$loanBadge?></td><td><span class="status-active">✅ نشط</span></td></tr>
        <?php endforeach; ?>
        <tr class="total-row"><td colspan="3"><strong>الإجمالي</strong></td><td colspan="3"><strong><?=number_format($totalPermanent,2)?> دج</strong></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    <div class="section-title">👕 الموظفون المتعاقدون</div>
    <table class="data-table">
        <thead><tr><th>#</th><th>الموظف</th><th>المصدر</th><th>المبلغ الشهري (دج)</th><th>النوع</th><th>الحالة</th></tr></thead>
        <tbody>
        <?php if(empty($contract)): ?><tr><td colspan="6" style="text-align:center;">لا توجد بيانات للمتعاقدين</td></tr>
        <?php else: $i=1; foreach($contract as $it):
            $eff = getEffectiveAmount($it, $report_ym);
            $loanBadge = $it['is_loan'] ? '<span class="badge-loan">💰 قرض</span>' : '<span class="badge-normal">📌 اقتطاع</span>';
        ?>
        <tr><td><?=$i++?></td><td><?=htmlspecialchars($it['employee_name'])?></td><td><?=htmlspecialchars($it['source_name'])?></td><td><?=number_format($eff,2)?> دج</td><td><?=$loanBadge?></td><td><span class="status-active">✅ نشط</span></td></tr>
        <?php endforeach; ?>
        <tr class="total-row"><td colspan="3"><strong>الإجمالي</strong></td><td colspan="3"><strong><?=number_format($totalContract,2)?> دج</strong></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    <div style="margin-top:20px;padding:12px;background:#ff9800;border-radius:8px;text-align:center;font-weight:bold;">💰 الإجمالي العام للشهر: <?=number_format($grandTotal,2)?> دج</div>
    <?php endif; ?>
</div>
<?php include '../includes/footer.php'; ?>