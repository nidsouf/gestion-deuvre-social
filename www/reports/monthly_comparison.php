<?php
/**
 * monthly_comparison.php - مقارنة التقرير الشهري بين شهرين
 * تم التحسين:
 * - إضافة خيار عرض الأقساط المدفوعة
 * - تحسين الأداء بجلب early_payments دفعة واحدة
 * - استخدام source_id في المفتاح المركب
 * - توحيد حساب فرق عدد الموظفين
 */
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/common_helpers.php';

if (!function_exists('getMonthNameArabic')) {
    function getMonthNameArabic($month) {
        $months = [1=>'جانفي',2=>'فيفري',3=>'مارس',4=>'أفريل',5=>'ماي',6=>'جوان',7=>'جويلية',8=>'أوت',9=>'سبتمبر',10=>'أكتوبر',11=>'نوفمبر',12=>'ديسمبر'];
        return $months[(int)$month] ?? '';
    }
}

// ============================================================
// معالجة المدخلات
// ============================================================
$year1 = isset($_GET['year1']) ? (int)$_GET['year1'] : date('Y');
$month1 = isset($_GET['month1']) ? (int)$_GET['month1'] : date('m');
$year2 = isset($_GET['year2']) ? (int)$_GET['year2'] : date('Y');
$month2 = isset($_GET['month2']) ? (int)$_GET['month2'] : date('m') - 1;
if ($month2 < 1) { $month2 = 12; $year2--; }
$source_id = isset($_GET['source_id']) ? (int)$_GET['source_id'] : 0;
$show_paid = isset($_GET['show_paid']) ? (int)$_GET['show_paid'] : 0;
$print = isset($_GET['print']) && $_GET['print'] == '1';

// ============================================================
// دالة جلب بيانات شهر معين (مع خيار عرض المدفوعة)
// ============================================================
function getMonthData($pdo, $year, $month, $source_id = 0, $include_paid = false) {
    $sql = "SELECT 
                mi.amount as monthly_amount,
                mi.is_paid,
                e.id as employee_id,
                e.name as employee_name,
                e.category,
                s.id as source_id,
                s.name as source_name,
                d.is_loan,
                d.credit_balance,
                d.id as deduction_id,
                'regular' as type
            FROM monthly_installments mi
            JOIN employees e ON mi.employee_id = e.id
            JOIN sources s ON mi.source_id = s.id
            JOIN deductions d ON mi.deduction_id = d.id
            WHERE mi.year = :year AND mi.month = :month
              AND mi.is_postponed = 0
            ";
    // إضافة شرط is_paid فقط إذا لم نطلب عرض المدفوعة
    if (!$include_paid) {
        $sql .= " AND mi.is_paid = 0";
    }
    $params = [':year' => $year, ':month' => $month];
    if ($source_id > 0) { $sql .= " AND mi.source_id = :source_id"; $params[':source_id'] = $source_id; }
    $sql .= " ORDER BY e.name ASC, s.name ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll();
    
    // جلب جميع الدفعات المقدمة لهذه الاقتطاعات دفعة واحدة (تحسين الأداء)
    $deductionIds = array_column($items, 'deduction_id');
    $earlyPayments = [];
    if (!empty($deductionIds)) {
        $placeholders = implode(',', array_fill(0, count($deductionIds), '?'));
        $stmtEarly = $pdo->prepare("
            SELECT deduction_id, MIN(payment_date) as first_payment_date
            FROM early_payments 
            WHERE deduction_id IN ($placeholders) AND is_reversed = 0
            GROUP BY deduction_id
        ");
        $stmtEarly->execute($deductionIds);
        while ($row = $stmtEarly->fetch()) {
            $earlyPayments[$row['deduction_id']] = $row['first_payment_date'];
        }
    }
    
    $report_ym = sprintf("%04d-%02d", $year, $month);
    $result = [];
    $total = 0;
    $permanent = 0;
    $contract = 0;
    $count = 0;
    $loanTotal = 0;
    $normalTotal = 0;
    
    foreach ($items as $it) {
        $amount = $it['monthly_amount'];
        $pay_date = $earlyPayments[$it['deduction_id']] ?? null;
        if (!empty($pay_date)) {
            $pay_ym = substr($pay_date, 0, 7);
            if ($pay_ym == $report_ym) {
                $amount = $it['credit_balance'];
            } else {
                $next_ym = date('Y-m', strtotime($pay_date . ' +1 month'));
                if ($next_ym == $report_ym) {
                    $amount = $it['monthly_amount'] - $it['credit_balance'];
                    if ($amount < 0) $amount = 0;
                }
            }
        }
        // مفتاح مركب: employee_id|source_id
        $key = $it['employee_id'] . '|' . $it['source_id'];
        // تجميع الأقساط المتعددة لنفس الموظف والمصدر (إن وجدت)
        if (!isset($result[$key])) {
            $result[$key] = [
                'employee_id' => $it['employee_id'],
                'employee_name' => $it['employee_name'],
                'category' => $it['category'],
                'source_id' => $it['source_id'],
                'source_name' => $it['source_name'],
                'is_loan' => $it['is_loan'],
                'amount' => $amount,
                'monthly_amount' => $it['monthly_amount'],
                'credit_balance' => $it['credit_balance'],
                'is_paid' => $it['is_paid'],
            ];
        } else {
            // جمع المبالغ (حالة نادرة ولكنها آمنة)
            $result[$key]['amount'] += $amount;
            // إذا كان أحد الأقساط مدفوعاً، نعتبر الكل مدفوعاً (لأغراض التصنيف)
            if ($it['is_paid']) {
                $result[$key]['is_paid'] = 1;
            }
        }
        $total += $amount;
        $count++;
        $isPermanent = (strtolower(trim($it['category'])) === 'permanent');
        if ($isPermanent) {
            $permanent += $amount;
        } else {
            $contract += $amount;
        }
        if ($it['is_loan']) $loanTotal += $amount;
        else $normalTotal += $amount;
    }
    
    return [
        'data' => $result,
        'total' => $total,
        'permanent' => $permanent,
        'contract' => $contract,
        'count' => $count,
        'loanTotal' => $loanTotal,
        'normalTotal' => $normalTotal,
        'year' => $year,
        'month' => $month,
        'label' => getMonthNameArabic($month).' '.$year
    ];
}

// ============================================================
// جلب بيانات الشهرين
// ============================================================
$data1 = getMonthData($pdo, $year1, $month1, $source_id, (bool)$show_paid);
$data2 = getMonthData($pdo, $year2, $month2, $source_id, (bool)$show_paid);

// ============================================================
// بناء خريطة المقارنة (بالمفتاح المركب)
// ============================================================
$comparison = [];
foreach ($data1['data'] as $key => $info) {
    $comparison[$key] = [
        'employee_id' => $info['employee_id'],
        'employee_name' => $info['employee_name'],
        'source_id' => $info['source_id'],
        'source_name' => $info['source_name'],
        'category' => $info['category'],
        'amount1' => $info['amount'],
        'amount2' => 0,
        'exists1' => true,
        'exists2' => false,
        'is_paid1' => $info['is_paid'] ?? 0,
    ];
}
foreach ($data2['data'] as $key => $info) {
    if (isset($comparison[$key])) {
        $comparison[$key]['amount2'] = $info['amount'];
        $comparison[$key]['exists2'] = true;
        $comparison[$key]['is_paid2'] = $info['is_paid'] ?? 0;
    } else {
        $comparison[$key] = [
            'employee_id' => $info['employee_id'],
            'employee_name' => $info['employee_name'],
            'source_id' => $info['source_id'],
            'source_name' => $info['source_name'],
            'category' => $info['category'],
            'amount1' => 0,
            'amount2' => $info['amount'],
            'exists1' => false,
            'exists2' => true,
            'is_paid1' => 0,
            'is_paid2' => $info['is_paid'] ?? 0,
        ];
    }
}

// إضافة الفروقات والملاحظات لكل مفتاح
foreach ($comparison as &$emp) {
    $diff = $emp['amount2'] - $emp['amount1'];
    $emp['diff'] = $diff;
    
    if ($emp['exists1'] && $emp['exists2']) {
        if ($diff > 0) {
            $emp['note'] = '⬆️ زيادة';
            $emp['class'] = 'positive';
        } elseif ($diff < 0) {
            $emp['note'] = '⬇️ نقصان';
            $emp['class'] = 'negative';
        } else {
            $emp['note'] = '➖ ثابت';
            $emp['class'] = 'zero';
        }
    } elseif ($emp['exists1'] && !$emp['exists2']) {
        $emp['note'] = '❌ منتهي';
        $emp['class'] = 'removed';
    } elseif (!$emp['exists1'] && $emp['exists2']) {
        $emp['note'] = '🆕 جديد';
        $emp['class'] = 'new';
    }
}
unset($emp);

// حساب الإجماليات للفروقات (موحدة)
$totalDiff = $data2['total'] - $data1['total'];
$totalDiffPercent = $data1['total'] > 0 
    ? round(($totalDiff / $data1['total']) * 100, 2) 
    : ($data2['total'] > 0 ? 100 : 0);

// فرق عدد الموظفين (موحد بين العرض والطباعة)
$countDiff = $data2['count'] - $data1['count'];
$countDiffPercent = $data1['count'] > 0 ? round(($countDiff / $data1['count']) * 100, 2) : ($data2['count'] > 0 ? 100 : 0);

// ============================================================
// وضع الطباعة
// ============================================================
if ($print) {
    // جلب اسم المصدر باستخدام Prepared Statement
    $sourceName = '';
    if ($source_id > 0) {
        $stmt = $pdo->prepare("SELECT name FROM sources WHERE id = ?");
        $stmt->execute([$source_id]);
        $sourceName = $stmt->fetchColumn();
    }
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html dir="rtl" lang="ar">
    <head>
        <meta charset="UTF-8">
        <title>مقارنة التقرير الشهري</title>
        <style>
            body { font-family: 'Segoe UI', Tahoma, Arial, sans-serif; padding: 20px; background: white; }
            h2 { text-align: center; color: #2a5298; }
            .subtitle { text-align: center; font-size: 16px; margin-bottom: 20px; }
            .print-header { text-align:center; margin-bottom:20px; border-bottom:2px solid #2a5298; padding-bottom:10px; }
            table { width: 100%; border-collapse: collapse; margin: 10px 0; }
            th, td { border: 1px solid #999; padding: 6px; text-align: center; font-size: 13px; }
            th { background: #2a5298; color: white; }
            .positive { background: #d4edda !important; color: #155724; }
            .negative { background: #f8d7da !important; color: #721c24; }
            .removed { background: #e9ecef !important; color: #6c757d; }
            .new { background: #d1ecf1 !important; color: #0c5460; }
            .zero { background: #fff3cd !important; color: #856404; }
            .footer { text-align: center; margin-top: 30px; font-size: 10px; color: #666; }
            .summary-box { background: #f8f9fa; padding: 10px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #ddd; }
            .table-container { display: flex; gap: 20px; flex-wrap: wrap; }
            .table-container .table-wrapper { flex: 1; min-width: 300px; }
            @media print { .table-container { display: block; } }
        </style>
    </head>
    <body>
        <div class="print-header">
            <h2>مركز التكوين والتعليم المهنيين</h2>
            <h3>لجنة الخدمات الاجتماعية</h3>
            <p><strong>مقارنة التقرير الشهري</strong></p>
        </div>
        
        <div class="subtitle">
            <strong><?= htmlspecialchars($data1['label']) ?></strong> مقابل <strong><?= htmlspecialchars($data2['label']) ?></strong>
            <?php if ($source_id > 0 && $sourceName): ?>
                <br>المصدر: <?= htmlspecialchars($sourceName) ?>
            <?php endif; ?>
            <?php if ($show_paid): ?>
                <br><span style="color:#17a2b8;">(تشمل الأقساط المدفوعة)</span>
            <?php endif; ?>
        </div>

        <!-- ملخص المقارنة -->
        <div class="summary-box">
            <table>
                <tr><th>المؤشر</th><th><?= htmlspecialchars($data1['label']) ?></th><th><?= htmlspecialchars($data2['label']) ?></th><th>الفرق</th><th>نسبة التغير</th></tr>
                <tr><td>الإجمالي (دج)</td><td><?= number_format($data1['total'],2) ?></td><td><?= number_format($data2['total'],2) ?></td><td class="<?= $totalDiff>=0?'positive':'negative' ?>"><?= number_format($totalDiff,2) ?></td><td><?= $totalDiffPercent ?>%</td></tr>
                <tr><td>عدد الموظفين</td><td><?= $data1['count'] ?></td><td><?= $data2['count'] ?></td><td><?= $countDiff ?></td><td><?= $countDiffPercent ?>%</td></tr>
                <tr><td>الدائمون (دج)</td><td><?= number_format($data1['permanent'],2) ?></td><td><?= number_format($data2['permanent'],2) ?></td><td class="<?= ($data2['permanent']-$data1['permanent'])>=0?'positive':'negative' ?>"><?= number_format($data2['permanent']-$data1['permanent'],2) ?></td><td><?= $data1['permanent']>0?round((($data2['permanent']-$data1['permanent'])/$data1['permanent'])*100,2):($data2['permanent']>0?100:0) ?>%</td></tr>
                <tr><td>المتعاقدون (دج)</td><td><?= number_format($data1['contract'],2) ?></td><td><?= number_format($data2['contract'],2) ?></td><td class="<?= ($data2['contract']-$data1['contract'])>=0?'positive':'negative' ?>"><?= number_format($data2['contract']-$data1['contract'],2) ?></td><td><?= $data1['contract']>0?round((($data2['contract']-$data1['contract'])/$data1['contract'])*100,2):($data2['contract']>0?100:0) ?>%</td></tr>
            </table>
        </div>

        <!-- جدولين منفصلين -->
        <div class="table-container">
            <!-- الجدول الأول -->
            <div class="table-wrapper">
                <h4 style="text-align:center;">📋 <?= htmlspecialchars($data1['label']) ?></h4>
                <table>
                    <thead><tr><th>#</th><th>الموظف</th><th>المصدر</th><th>المبلغ (دج)</th><th>الفرق عن الشهر الآخر</th><th>الملاحظة</th></tr></thead>
                    <tbody>
                    <?php if(empty($data1['data'])): ?><tr><td colspan="6">لا توجد بيانات</td></tr>
                    <?php else: $i=1; foreach($data1['data'] as $key => $emp):
                        $cmp = $comparison[$key] ?? null;
                        $diff = $cmp ? ($cmp['amount2'] - $cmp['amount1']) : 0;
                        $note = $cmp ? $cmp['note'] : 'غير موجود';
                        $class = $cmp ? $cmp['class'] : '';
                        $diffDisplay = '';
                        if ($cmp) {
                            if ($cmp['exists1'] && $cmp['exists2']) {
                                $diffDisplay = ($diff > 0 ? '+' : '') . number_format($diff, 2);
                            } elseif ($cmp['exists1'] && !$cmp['exists2']) {
                                $diffDisplay = '—';
                            }
                        }
                    ?>
                    <tr class="<?= $class ?>">
                        <td><?= $i++ ?></td>
                        <td><?= htmlspecialchars($emp['employee_name']) ?></td>
                        <td><?= htmlspecialchars($emp['source_name']) ?></td>
                        <td><?= number_format($emp['amount'], 2) ?></td>
                        <td><?= $diffDisplay ?></td>
                        <td><?= $note ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- الجدول الثاني -->
            <div class="table-wrapper">
                <h4 style="text-align:center;">📋 <?= htmlspecialchars($data2['label']) ?></h4>
                <table>
                    <thead><tr><th>#</th><th>الموظف</th><th>المصدر</th><th>المبلغ (دج)</th><th>الفرق عن الشهر الآخر</th><th>الملاحظة</th></tr></thead>
                    <tbody>
                    <?php if(empty($data2['data'])): ?><tr><td colspan="6">لا توجد بيانات</td></tr>
                    <?php else: $i=1; foreach($data2['data'] as $key => $emp):
                        $cmp = $comparison[$key] ?? null;
                        $diff = $cmp ? ($cmp['amount2'] - $cmp['amount1']) : 0;
                        $note = $cmp ? $cmp['note'] : 'غير موجود';
                        $class = $cmp ? $cmp['class'] : '';
                        $diffDisplay = '';
                        if ($cmp) {
                            if ($cmp['exists1'] && $cmp['exists2']) {
                                $diffDisplay = ($diff > 0 ? '+' : '') . number_format($diff, 2);
                            } elseif (!$cmp['exists1'] && $cmp['exists2']) {
                                $diffDisplay = '—';
                            }
                        }
                    ?>
                    <tr class="<?= $class ?>">
                        <td><?= $i++ ?></td>
                        <td><?= htmlspecialchars($emp['employee_name']) ?></td>
                        <td><?= htmlspecialchars($emp['source_name']) ?></td>
                        <td><?= number_format($emp['amount'], 2) ?></td>
                        <td><?= $diffDisplay ?></td>
                        <td><?= $note ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="footer">تم إنشاء التقرير بتاريخ <?= date('Y-m-d H:i:s') ?></div>
        <script>window.onload = function() { window.print(); };</script>
    </body>
    </html>
    <?php
    exit;
}

// ============================================================
// العرض العادي
// ============================================================
include '../includes/header.php';
$sources = $pdo->query("SELECT id, name FROM sources ORDER BY name")->fetchAll();
?>
<style>
    .container { max-width: 1400px; margin: 0 auto; padding: 20px; }
    .card { background: white; border-radius: 20px; padding: 25px; margin-bottom: 25px; box-shadow: 0 2px 15px rgba(0,0,0,0.08); }
    .card-title { font-size: 20px; font-weight: 700; margin-bottom: 15px; color: #2a5298; border-right: 4px solid #2a5298; padding-right: 12px; }
    .filters { background: #f8f9fa; border-radius: 20px; padding: 15px; margin-bottom: 20px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
    .filter-group { display: flex; align-items: center; gap: 5px; background: white; padding: 5px 10px; border-radius: 8px; border: 1px solid #ddd; }
    .filter-group label { font-weight: bold; margin: 0; }
    .filters select, .filters button, .filters a { padding: 8px 15px; border-radius: 8px; border: 1px solid #ccc; font-size: 14px; text-decoration: none; display: inline-block; cursor: pointer; }
    .btn-primary { background: #2a5298; color: white; border: none; }
    .btn-success { background: #28a745; color: white; border: none; }
    .btn-secondary { background: #6c757d; color: white; border: none; }
    
    .table { width: 100%; border-collapse: collapse; font-size: 14px; }
    .table th, .table td { border: 1px solid #ddd; padding: 10px; text-align: center; }
    .table th { background: #2a5298; color: white; }
    
    .positive { background: #d4edda !important; }
    .positive td { background: #d4edda !important; }
    .negative { background: #f8d7da !important; }
    .negative td { background: #f8d7da !important; }
    .new { background: #d1ecf1 !important; }
    .new td { background: #d1ecf1 !important; }
    .removed { background: #e9ecef !important; }
    .removed td { background: #e9ecef !important; }
    .zero { background: #fff3cd !important; }
    .zero td { background: #fff3cd !important; }
    
    .summary-box { background: #f0f4f8; padding: 20px; border-radius: 15px; margin: 20px 0; }
    .table-container { display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-top: 20px; }
    .table-wrapper { background: white; border-radius: 15px; padding: 15px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
    .table-wrapper h4 { text-align: center; margin-bottom: 15px; color: #2a5298; }
    
    @media (max-width: 768px) {
        .table-container { grid-template-columns: 1fr; }
    }
</style>

<div class="container">
    <h2>📊 مقارنة التقرير الشهري</h2>
    
    <div class="filters">
        <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;width:100%;">
            <div class="filter-group">
                <label>الشهر الأول:</label>
                <select name="month1">
                    <?php for($m=1;$m<=12;$m++): ?>
                        <option value="<?=$m?>" <?=$m==$month1?'selected':''?>><?=getMonthNameArabic($m)?></option>
                    <?php endfor; ?>
                </select>
                <select name="year1">
                    <?php for($y=2020;$y<=date('Y')+1;$y++): ?>
                        <option value="<?=$y?>" <?=$y==$year1?'selected':''?>><?=$y?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>الشهر الثاني:</label>
                <select name="month2">
                    <?php for($m=1;$m<=12;$m++): ?>
                        <option value="<?=$m?>" <?=$m==$month2?'selected':''?>><?=getMonthNameArabic($m)?></option>
                    <?php endfor; ?>
                </select>
                <select name="year2">
                    <?php for($y=2020;$y<=date('Y')+1;$y++): ?>
                        <option value="<?=$y?>" <?=$y==$year2?'selected':''?>><?=$y?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>المصدر:</label>
                <select name="source_id">
                    <option value="0">جميع المصادر</option>
                    <?php foreach($sources as $src): ?>
                        <option value="<?=$src['id']?>" <?=$source_id==$src['id']?'selected':''?>><?=htmlspecialchars($src['name'])?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>عرض المدفوعة:</label>
                <select name="show_paid">
                    <option value="0" <?= $show_paid==0?'selected':'' ?>>إخفاء المدفوعة</option>
                    <option value="1" <?= $show_paid==1?'selected':'' ?>>عرض الكل (بما فيها المدفوعة)</option>
                </select>
            </div>
            <button type="submit" class="btn-primary">🔍 عرض</button>
            <a href="?year1=<?=$year1?>&month1=<?=$month1?>&year2=<?=$year2?>&month2=<?=$month2?>&source_id=<?=$source_id?>&show_paid=<?=$show_paid?>&print=1" target="_blank" class="btn-success">🖨️ طباعة</a>
            <a href="?year1=<?=$year1?>&month1=<?=$month1?>&year2=<?=$year2?>&month2=<?=$month2?>&source_id=<?=$source_id?>&show_paid=<?=$show_paid?>" class="btn-secondary">🔄 إعادة تعيين</a>
        </form>
    </div>

    <?php if (empty($data1['data']) && empty($data2['data'])): ?>
        <div style="background:#f8d7da;padding:20px;text-align:center;">⚠️ لا توجد بيانات للشهرين المحددين</div>
    <?php else: ?>
        <div class="card">
            <div class="card-title">📈 ملخص المقارنة</div>
            <div class="summary-box">
                <table class="table">
                    <thead><tr><th>المؤشر</th><th><?= htmlspecialchars($data1['label']) ?></th><th><?= htmlspecialchars($data2['label']) ?></th><th>الفرق</th><th>نسبة التغير</th></tr></thead>
                    <tbody>
                        <tr>
                            <td><strong>الإجمالي العام (دج)</strong></td>
                            <td><?= number_format($data1['total'], 2) ?></td>
                            <td><?= number_format($data2['total'], 2) ?></td>
                            <td class="<?= $totalDiff >= 0 ? 'positive' : 'negative' ?>"><?= number_format($totalDiff, 2) ?></td>
                            <td><?= $totalDiffPercent ?>%</td>
                        </tr>
                        <tr>
                            <td><strong>عدد الموظفين</strong></td>
                            <td><?= $data1['count'] ?></td>
                            <td><?= $data2['count'] ?></td>
                            <td><?= $countDiff ?></td>
                            <td><?= $countDiffPercent ?>%</td>
                        </tr>
                        <tr>
                            <td><strong>الدائمون (دج)</strong></td>
                            <td><?= number_format($data1['permanent'], 2) ?></td>
                            <td><?= number_format($data2['permanent'], 2) ?></td>
                            <td class="<?= ($data2['permanent']-$data1['permanent']) >= 0 ? 'positive' : 'negative' ?>"><?= number_format($data2['permanent'] - $data1['permanent'], 2) ?></td>
                            <td><?= $data1['permanent'] > 0 ? round((($data2['permanent'] - $data1['permanent']) / $data1['permanent']) * 100, 2) : ($data2['permanent'] > 0 ? 100 : 0) ?>%</td>
                        </tr>
                        <tr>
                            <td><strong>المتعاقدون (دج)</strong></td>
                            <td><?= number_format($data1['contract'], 2) ?></td>
                            <td><?= number_format($data2['contract'], 2) ?></td>
                            <td class="<?= ($data2['contract']-$data1['contract']) >= 0 ? 'positive' : 'negative' ?>"><?= number_format($data2['contract'] - $data1['contract'], 2) ?></td>
                            <td><?= $data1['contract'] > 0 ? round((($data2['contract'] - $data1['contract']) / $data1['contract']) * 100, 2) : ($data2['contract'] > 0 ? 100 : 0) ?>%</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="table-container">
            <!-- الجدول الأول -->
            <div class="table-wrapper">
                <h4>📋 <?= htmlspecialchars($data1['label']) ?></h4>
                <table class="table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>الموظف</th>
                            <th>المصدر</th>
                            <th>المبلغ (دج)</th>
                            <th>الفرق عن الشهر الآخر</th>
                            <th>الملاحظة</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if(empty($data1['data'])): ?>
                        <tr><td colspan="6">لا توجد بيانات</td></tr>
                    <?php else: $i=1; foreach($data1['data'] as $key => $emp):
                        $cmp = $comparison[$key] ?? null;
                        $diff = $cmp ? ($cmp['amount2'] - $cmp['amount1']) : 0;
                        $note = $cmp ? $cmp['note'] : 'غير موجود';
                        $class = $cmp ? $cmp['class'] : '';
                        $diffDisplay = '';
                        if ($cmp) {
                            if ($cmp['exists1'] && $cmp['exists2']) {
                                $diffDisplay = ($diff > 0 ? '+' : '') . number_format($diff, 2);
                            } elseif ($cmp['exists1'] && !$cmp['exists2']) {
                                $diffDisplay = '—';
                            }
                        }
                    ?>
                    <tr class="<?= $class ?>">
                        <td><?= $i++ ?></td>
                        <td><?= htmlspecialchars($emp['employee_name']) ?></td>
                        <td><?= htmlspecialchars($emp['source_name']) ?></td>
                        <td><?= number_format($emp['amount'], 2) ?></td>
                        <td><?= $diffDisplay ?></td>
                        <td><?= $note ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- الجدول الثاني -->
            <div class="table-wrapper">
                <h4>📋 <?= htmlspecialchars($data2['label']) ?></h4>
                <table class="table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>الموظف</th>
                            <th>المصدر</th>
                            <th>المبلغ (دج)</th>
                            <th>الفرق عن الشهر الآخر</th>
                            <th>الملاحظة</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if(empty($data2['data'])): ?>
                        <tr><td colspan="6">لا توجد بيانات</td></tr>
                    <?php else: $i=1; foreach($data2['data'] as $key => $emp):
                        $cmp = $comparison[$key] ?? null;
                        $diff = $cmp ? ($cmp['amount2'] - $cmp['amount1']) : 0;
                        $note = $cmp ? $cmp['note'] : 'غير موجود';
                        $class = $cmp ? $cmp['class'] : '';
                        $diffDisplay = '';
                        if ($cmp) {
                            if ($cmp['exists1'] && $cmp['exists2']) {
                                $diffDisplay = ($diff > 0 ? '+' : '') . number_format($diff, 2);
                            } elseif (!$cmp['exists1'] && $cmp['exists2']) {
                                $diffDisplay = '—';
                            }
                        }
                    ?>
                    <tr class="<?= $class ?>">
                        <td><?= $i++ ?></td>
                        <td><?= htmlspecialchars($emp['employee_name']) ?></td>
                        <td><?= htmlspecialchars($emp['source_name']) ?></td>
                        <td><?= number_format($emp['amount'], 2) ?></td>
                        <td><?= $diffDisplay ?></td>
                        <td><?= $note ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div style="display:flex; gap:20px; flex-wrap:wrap; margin-top:20px; padding:15px; background:#f8f9fa; border-radius:10px;">
            <span><span style="background:#d4edda; padding:5px 10px; border-radius:5px;">🟢 زيادة</span></span>
            <span><span style="background:#f8d7da; padding:5px 10px; border-radius:5px;">🔴 نقصان</span></span>
            <span><span style="background:#d1ecf1; padding:5px 10px; border-radius:5px;">🔵 جديد</span></span>
            <span><span style="background:#e9ecef; padding:5px 10px; border-radius:5px;">⚪ منتهي</span></span>
            <span><span style="background:#fff3cd; padding:5px 10px; border-radius:5px;">🟡 ثابت</span></span>
        </div>
    <?php endif; ?>
</div>
<?php include '../includes/footer.php'; ?>