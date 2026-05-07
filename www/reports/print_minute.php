<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
require_once '../config/database.php';
require_once '../includes/functions.php';

// مصفوفة الأشهر العربية
$arabicMonths = [
    1 => 'جانفي', 2 => 'فيفري', 3 => 'مارس', 4 => 'أفريل',
    5 => 'ماي', 6 => 'جوان', 7 => 'جويلية', 8 => 'أوت',
    9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر'
];

$arabicDays = [
    'Monday' => 'الاثنين', 'Tuesday' => 'الثلاثاء', 'Wednesday' => 'الأربعاء',
    'Thursday' => 'الخميس', 'Friday' => 'الجمعة', 'Saturday' => 'السبت',
    'Sunday' => 'الأحد'
];

function formatDateArabic($date, $arabicDays, $arabicMonths) {
    $ts = strtotime($date);
    $dayEn = date('l', $ts);
    $dayAr = $arabicDays[$dayEn] ?? $dayEn;
    $dayNum = date('d', $ts);
    $monthNum = (int)date('m', $ts);
    $monthAr = $arabicMonths[$monthNum] ?? date('F', $ts);
    $year = date('Y', $ts);
    return "$dayAr $dayNum $monthAr $year";
}

$month = isset($_GET['month']) ? (int)$_GET['month'] : date('m');
$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
$session_number = isset($_GET['session_number']) ? (int)$_GET['session_number'] : 1;
$meeting_number = isset($_GET['meeting_number']) ? $_GET['meeting_number'] : '';
$meeting_date = isset($_GET['meeting_date']) ? $_GET['meeting_date'] : date('Y-m-d');
$meeting_time = isset($_GET['meeting_time']) ? $_GET['meeting_time'] : '10:00';
$closing_time = isset($_GET['closing_time']) ? $_GET['closing_time'] : '11:00';

$year_month = sprintf("%04d-%02d", $year, $month);
$month_name = date('F Y', strtotime("$year-$month-01"));
$startDate = "$year-$month-01";
$endDate = date('Y-m-t', strtotime($startDate));

// جلب المحضر المحفوظ
$stmtMinute = $pdo->prepare("SELECT * FROM meeting_minutes WHERE month = ? AND year = ? AND session_number = ?");
$stmtMinute->execute([$month, $year, $session_number]);
$minute = $stmtMinute->fetch();
if (!$minute) {
    $minute = [
        'meeting_date' => $meeting_date,
        'meeting_number' => $meeting_number,
        'content' => '',
        'notes' => '',
        'total_grants_amount' => 0,
        'umrah_draw_event_id' => null,
        'show_honorees' => 0,
        'honorees_year' => null
    ];
}

// جلب المنح
$stmtGrants = $pdo->prepare("
    SELECT eg.*, e.name as employee_name, g.name as grant_name, g.amount
    FROM employee_grants eg
    JOIN employees e ON eg.employee_id = e.id
    JOIN grants g ON eg.grant_id = g.id
    WHERE strftime('%Y-%m', eg.grant_date) = :year_month
    ORDER BY eg.grant_date DESC
");
$stmtGrants->execute([':year_month' => $year_month]);
$grants = $stmtGrants->fetchAll();
$totalGrants = array_sum(array_column($grants, 'amount'));

// جلب السلف
$stmtLoans = $pdo->prepare("
    SELECT 
        d.id,
        e.name as employee_name,
        s.name as source_name,
        d.monthly_amount,
        d.total_months,
        d.start_date,
        d.end_date,
        d.grant_date,
        (d.monthly_amount * d.total_months) AS total_amount
    FROM deductions d
    JOIN employees e ON d.employee_id = e.id
    JOIN sources s ON d.source_id = s.id
    WHERE d.is_loan = 1
      AND strftime('%Y-%m', d.grant_date) = :year_month
    ORDER BY e.name
");
$stmtLoans->execute([':year_month' => $year_month]);
$loans = $stmtLoans->fetchAll();
$totalLoans = array_sum(array_column($loans, 'total_amount'));

// إجمالي ثلاثي سعدين
$saadine_tri_total = 0;
$show_tri_total = false;
if (in_array($month, [3,6,9,12])) {
    $show_tri_total = true;
    if ($month == 3) { $startTri = "$year-01-01"; $endTri = "$year-03-31"; }
    elseif ($month == 6) { $startTri = "$year-04-01"; $endTri = "$year-06-30"; }
    elseif ($month == 9) { $startTri = "$year-07-01"; $endTri = "$year-09-30"; }
    else { $startTri = "$year-10-01"; $endTri = "$year-12-31"; }
    $stmtTri = $pdo->prepare("
        SELECT COALESCE(SUM(d.monthly_amount), 0) as total
        FROM deductions d
        JOIN sources s ON d.source_id = s.id
        WHERE s.name = 'سعدين للتجهير'
          AND d.start_date <= :end_date AND d.end_date >= :start_date
    ");
    $stmtTri->execute([':start_date' => $startTri, ':end_date' => $endTri]);
    $saadine_tri_total = $stmtTri->fetchColumn();
}

/// =============================================
// 4. إجمالي جيزي الشهري (من جدول employee_phone_numbers)
// =============================================
$djezzy_monthly_total = 0;
$stmtDjezzy = $pdo->prepare("
    SELECT COALESCE(SUM(epn.monthly_amount), 0) as total
    FROM employee_phone_numbers epn
    WHERE epn.is_active = 1
");
$stmtDjezzy->execute();
$djezzy_monthly_total = $stmtDjezzy->fetchColumn();

// تسديد مستحقات سعدين
$sourceSaadine = $pdo->query("SELECT id FROM sources WHERE name = 'سعدين للتجهير'")->fetchColumn();
$saadine_paid = 0;
if ($sourceSaadine) {
    $stmtSaadinePay = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) as total
        FROM source_payments
        WHERE source_id = :source_id
          AND strftime('%Y-%m', cheque_date) = :year_month
    ");
    $stmtSaadinePay->execute([':source_id' => $sourceSaadine, ':year_month' => $year_month]);
    $saadine_paid = $stmtSaadinePay->fetchColumn();
}
// جلب المكرمين (إذا كان مطلوباً في المحضر)
$honorees = [];
$totalHonorValue = 0;
if (!empty($minute['show_honorees']) && !empty($minute['honorees_year'])) {
    $stmtHon = $pdo->prepare("
        SELECT h.*, e.name as employee_name, e.category
        FROM labor_day_honorees h
        JOIN employees e ON h.employee_id = e.id
        WHERE h.year = ?
        ORDER BY e.name ASC
    ");
    $stmtHon->execute([$minute['honorees_year']]);
    $honorees = $stmtHon->fetchAll();
    $totalHonorValue = array_sum(array_column($honorees, 'prize_value'));
}
// إجمالي المبلغ
$total_minute_amount = $totalGrants + $totalLoans + $djezzy_monthly_total + $saadine_paid + $saadine_tri_total + $totalHonorValue;
// دالة تحويل الأرقام إلى حروف
function numberToWords($number) {
    // ... (نفس الدالة من النسخة الأصلية، لا تغيير)
    $parts = explode('.', number_format($number, 2, '.', ''));
    $dinars = (int)$parts[0];
    $centimes = isset($parts[1]) ? (int)$parts[1] : 0;
    if ($dinars == 0 && $centimes == 0) return 'صفر دينار جزائري';
    $units = ['', 'واحد', 'اثنان', 'ثلاثة', 'أربعة', 'خمسة', 'ستة', 'سبعة', 'ثمانية', 'تسعة'];
    $teens = ['عشرة', 'أحد عشر', 'اثنا عشر', 'ثلاثة عشر', 'أربعة عشر', 'خمسة عشر', 'ستة عشر', 'سبعة عشر', 'ثمانية عشر', 'تسعة عشر'];
    $tens = ['', '', 'عشرون', 'ثلاثون', 'أربعون', 'خمسون', 'ستون', 'سبعون', 'ثمانون', 'تسعون'];
    $hundreds = ['', 'مائة', 'مائتان', 'ثلاثمائة', 'أربعمائة', 'خمسمائة', 'ستمائة', 'سبعمائة', 'ثمانمائة', 'تسعمائة'];
    function convertUnderThousand($num, $units, $teens, $tens, $hundreds) {
        if ($num == 0) return '';
        $h = (int)($num / 100);
        $r = $num % 100;
        $parts = [];
        if ($h > 0) $parts[] = $hundreds[$h];
        if ($r >= 10 && $r <= 19) $parts[] = $teens[$r - 10];
        elseif ($r > 0) {
            $t = (int)($r / 10);
            $u = $r % 10;
            if ($u > 0) $parts[] = $units[$u] . ' و ' . $tens[$t];
            else $parts[] = $tens[$t];
        }
        return implode(' و ', $parts);
    }
    $result = '';
    if ($dinars > 0) {
        $thousands = (int)($dinars / 1000);
        $remainder = $dinars % 1000;
        if ($thousands > 0) {
            if ($thousands == 1) $result .= 'ألف';
            elseif ($thousands == 2) $result .= 'ألفان';
            else {
                $thousandsWord = convertUnderThousand($thousands, $units, $teens, $tens, $hundreds);
                if ($thousands >= 3 && $thousands <= 10) $result .= $thousandsWord . ' آلاف';
                else $result .= $thousandsWord . ' ألفًا';
            }
            if ($remainder > 0) $result .= ' و ';
        }
        if ($remainder > 0) $result .= convertUnderThousand($remainder, $units, $teens, $tens, $hundreds);
        $result .= ' دينار';
    }
    if ($centimes > 0) {
        if ($dinars > 0) $result .= ' و ';
        $result .= convertUnderThousand($centimes, $units, $teens, $tens, $hundreds) . ' سنتيم';
    }
    $result .= ' فقط لا غير';
    return $result;
}
$total_words = numberToWords($total_minute_amount);

// جلب سحب العمرة (إن وجد)
$umrah_draw = null;
$umrah_participants = [];
if (!empty($minute['umrah_draw_event_id'])) {
    $stmtDraw = $pdo->prepare("SELECT * FROM umrah_draw_events WHERE id = ?");
    $stmtDraw->execute([$minute['umrah_draw_event_id']]);
    $umrah_draw = $stmtDraw->fetch();
    if ($umrah_draw) {
        $stmtPart = $pdo->prepare("
            SELECT u.*, e.name as employee_name
            FROM umrah_draws u
            JOIN employees e ON u.employee_id = e.id
            WHERE u.draw_event_id = ?
            ORDER BY u.is_winner DESC, u.reserve_order ASC
        ");
        $stmtPart->execute([$umrah_draw['id']]);
        $umrah_participants = $stmtPart->fetchAll();
    }
}



include '../includes/header.php';
?>

<style media="print">
    @media print {
        .no-print { display: none; }
        body { margin: 1cm; padding: 0; }
        .minute-content { font-size: 14pt; line-height: 1.5; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #000; padding: 6px; text-align: center; }
        th { background: #f0f0f0; }
        .sidebar, .top-bar, .filters, .btn, .btn-primary, .btn-success, .btn-secondary, .no-print, .footer {
            display: none !important;
        }
        .main-content { margin: 0 !important; padding: 0 !important; }
        body { margin: 0; padding: 0; background: white; }
        .signatures { display: flex !important; flex-direction: row !important; justify-content: space-between !important; width: 100% !important; margin-top: 50px !important; }
        .signature-item { flex: 1 !important; text-align: center !important; }
        .signature-line { border-bottom: 1px solid #000; width: 100%; margin-bottom: 5px; }
    }
</style>

<div class="minute-container" style="direction: rtl; font-family: 'Traditional Arabic', 'Segoe UI', 'Tahoma', serif; font-size: 14pt; line-height: 1.6; padding: 20px; max-width: 1000px; margin: auto;">
    <!-- الترويسة الرسمية -->
    <div class="header-line" style="text-align: center; width: 100%; margin-bottom: 10px;">
        <p style="margin-top: 15px; text-align: center; line-height: 1.8;">
            <span style="font-weight: 700; font-size: 16pt;">الجمهورية الجزائرية الديمقراطية الشعبية</span>
        </p>
        <p style="margin-top: 10px; text-align: center; line-height: 1.8;">
            <span style="font-weight: 700; font-size: 16pt;">وزارة التكوين والتعليم المهنيين</span>
        </p>
        <hr>
        <h4 style="margin-bottom:0;">لجنة الخدمات الاجتماعية</h4>
        <div style="display: flex; justify-content: space-between; align-items: baseline; margin-top:10px;">
            <div style="text-align: right;">
                <h4>محضـــر رقم: <?= htmlspecialchars($minute['meeting_number'] ?: '___/م.ت.م.ت كوينين/' . $year) ?></h4>
            </div>
            <div style="text-align: left;">
                <h4>كوينين في: <?= date('d/m/Y', strtotime($minute['meeting_date'] ?? $meeting_date)) ?></h4>
            </div>
        </div>
        <div style="text-align: center;">
            <h4>محضـــر جلســـــة شهــــر <?= $month_name ?> (الجلسة رقم <?= $session_number ?>)</h4>
        </div>
    </div>

    <p>
        في يوم <strong><?= formatDateArabic($minute['meeting_date'] ?? $meeting_date, $arabicDays, $arabicMonths) ?></strong> وعلى الساعة <strong><?= $meeting_time ?></strong> صباحاً 
        انعقدت جلسة للجنــــــة الخدمات الاجتماعية بالمركز بحضور الأعضاء الآتية أسماؤهم:
    </p>

    <!-- جدول الأعضاء (ثابت) -->
    <table style="width:100%; border-collapse: collapse; margin: 15px 0;">
        <thead><tr><th>الرقم</th><th>الاسم واللقب</th><th>الوظيفة</th><th>الصفة</th><th>الملاحظات</th></tr></thead>
        <tbody>
            <tr><td>01</td><td>نيد شوقي</td><td>مساعد مهندس.م.1.إ.آ</td><td>رئيس اللجنة</td><td></td></tr>
            <tr><td>02</td><td>زبيدي رياض</td><td>مساعد تكوين رئيسي</td><td>نائب الرئيس</td><td></td></tr>
            <tr><td>03</td><td>عمري لطفي</td><td>أستاذ.م.ت.ت.م.ر1</td><td>عضـــو</td><td></td></tr>
            <tr><td>04</td><td>قديري بدر الدين</td><td>أ.ت.م</td><td>عضـــو</td><td></td></tr>
            <tr><td>05</td><td>بوزنادة مفيدة</td><td>أستاذ.م.ت.ت.م.ر2</td><td>عضـــو</td><td></td></tr>
        </tbody>
    </table>

    <p>افتتح السيد رئيس لجنة الخدمات الاجتماعية بالمركز الكلمة مرحباً بالحضور ثم عرج مباشرة على جدول الأعمال:</p>

    <h4>جدول الأعمال:</h4>
    <ul>
        <li>دراسة واعتماد المنح المقدمة للموظفين خلال شهر <?= $month_name ?>.</li>
        <li>متابعة السلف الممنوحة للموظفين.</li>
        <li>إجمالي الاقتطاعات الشهرية لجيزي.</li>
        <li>تسديد مستحقات سعدين للتجهير.</li>
        <?php if($show_tri_total): ?>
        <li>مراجعة إجمالي الاقتطاع الثلاثي لفائدة سعدين للتجهير.</li>
        <?php endif; ?>
        <?php if (!empty($honorees)): ?>
        <li>الاطلاع على قائمة المكرمين في عيد العمال للسنة <?= $minute['honorees_year'] ?>.</li>
        <?php endif; ?>
    </ul>

    <!-- المنح -->
    <?php if(!empty($grants)): ?>
    <h4>1. المنح المقدمة:</h4>
    <table style="width:100%; border-collapse: collapse; margin: 15px 0;"><thead><tr><th>#</th><th>الموظف</th><th>نوع المنحة</th><th>المبلغ (دج)</th><th>تاريخ المنح</th><th>السبب</th></tr></thead>
    <tbody><?php $i=1; foreach($grants as $g): ?>
        <tr><td><?= $i++ ?></td><td><?= htmlspecialchars($g['employee_name']) ?></td><td><?= htmlspecialchars($g['grant_name']) ?></td><td><?= number_format($g['amount'], 2) ?></td><td><?= date('d/m/Y', strtotime($g['grant_date'])) ?></td><td><?= htmlspecialchars($g['notes'] ?? '') ?></td></tr>
    <?php endforeach; ?></tbody>
    <tfoot><tr><td colspan="3"><strong>الإجمالي</strong></td><td colspan="3"><strong><?= number_format($totalGrants, 2) ?> دج</strong></td></tr></tfoot></table>
    <?php else: ?><p>⚫ لا توجد منح مسجلة هذا الشهر.</p><?php endif; ?>

    <!-- السلف -->
    <?php if(!empty($loans)): ?>
    <h4>2. السلف الممنوحة (المبلغ الكلي وتاريخ الصرف):</h4>
    <table style="width:100%; border-collapse: collapse; margin: 15px 0;"><thead><tr><th>#</th><th>الموظف</th><th>نوع السلفة</th><th>المبلغ الكلي (دج)</th><th>تاريخ الصرف</th><th>تاريخ بداية الاقتطاع</th></tr></thead>
    <tbody><?php $i=1; foreach($loans as $l): ?>
        <tr><td><?= $i++ ?></td><td><?= htmlspecialchars($l['employee_name']) ?></td><td><?= htmlspecialchars($l['source_name']) ?></td><td><?= number_format($l['total_amount'], 2) ?></td><td><?= date('d/m/Y', strtotime($l['grant_date'])) ?></td><td><?= date('d/m/Y', strtotime($l['start_date'])) ?></td></tr>
    <?php endforeach; ?></tbody>
    <tfoot><tr><td colspan="3"><strong>الإجمالي</strong></td><td colspan="3"><strong><?= number_format($totalLoans, 2) ?> دج</strong></td></tr></tfoot></table>
    <?php else: ?><p>⚫ لا توجد سلف مسجلة هذا الشهر.</p><?php endif; ?>

    <!-- الاقتطاعات والتسديدات -->
    <h4>3. الاقتطاعات والتسديدات:</h4>
    <ul>
        <li>إجمالي الاقتطاعات الشهرية لجيزي: <strong><?= number_format($djezzy_monthly_total, 2) ?> دج</strong></li>
        <li>تسديد مستحقات سعدين للتجهير: <strong><?= number_format($saadine_paid, 2) ?> دج</strong></li>
        <?php if($show_tri_total): ?>
        <li>إجمالي الاقتطاع الثلاثي لسعدين للتجهير (آخر 3 أشهر): <strong><?= number_format($saadine_tri_total, 2) ?> دج</strong></li>
        <?php endif; ?>
    </ul>

    <!-- سحب العمرة (إن وجد) -->
    <?php if ($umrah_draw): ?>
    <div style="margin-top: 30px; page-break-inside: avoid;">
        <h4>🕋 نتائج سحب العمرة</h4>
        <p><strong>تاريخ السحب:</strong> <?= date('d/m/Y H:i', strtotime($umrah_draw['draw_date'])) ?></p>
        <p><strong>العنوان:</strong> <?= htmlspecialchars($umrah_draw['title'] ?? 'سحب العمرة') ?></p>
        <?php
        $winner = null;
        $reserves = [];
        foreach ($umrah_participants as $p) {
            if ($p['is_winner']) $winner = $p;
            elseif ($p['reserve_order']) $reserves[$p['reserve_order']] = $p;
        }
        ksort($reserves);
        ?>
        <?php if ($winner): ?><p><strong>🏆 الفائز الرئيسي:</strong> <?= htmlspecialchars($winner['employee_name']) ?> (<?= $winner['tickets_count'] ?> ورقة)</p><?php endif; ?>
        <?php if (!empty($reserves)): ?>
        <p><strong>🥇 الاحتياطيين:</strong></p>
        <ul><?php foreach ($reserves as $order => $res): ?><li>الاحتياطي <?= $order ?>: <?= htmlspecialchars($res['employee_name']) ?> (<?= $res['tickets_count'] ?> ورقة)</li><?php endforeach; ?></ul>
        <?php endif; ?>
        <h5>قائمة المشاركين والنتائج</h5>
        <table style="width:100%; border-collapse: collapse;">
            <thead><tr><th>الموظف</th><th>عدد الأوراق</th><th>النتيجة</th></tr></thead>
            <tbody><?php foreach ($umrah_participants as $p): ?>
                <tr><td><?= htmlspecialchars($p['employee_name']) ?></td><td><?= $p['tickets_count'] ?></td><td><?= $p['is_winner'] ? '🏆 فائز' : ($p['reserve_order'] ? 'احتياطي '.$p['reserve_order'] : 'غير فائز') ?></td></tr>
            <?php endforeach; ?></tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- المكرمون في عيد العمال (إذا تم اختيارهم) -->
    <?php if (!empty($honorees)): ?>
    <div style="margin-top: 30px; page-break-inside: avoid;">
        <h4>🎖️ المكرمون في عيد العمال (سنة <?= $minute['honorees_year'] ?>)</h4>
        <table style="width:100%; border-collapse: collapse;">
            <thead><tr><th>#</th><th>الموظف</th><th>نوع الجائزة</th><th>القيمة (دج)</th><th>تاريخ التكريم</th><th>سبب التكريم</th></tr></thead>
            <tbody>
                <?php $i=1; foreach($honorees as $h): ?>
                <tr>
                    <td><?= $i++ ?></td>
                    <td><?= htmlspecialchars($h['employee_name']) ?><br><small>(<?= $h['category'] == 'Permanent' ? 'دائم' : 'متعاقد' ?>)</small></td>
                    <td><?= htmlspecialchars($h['prize_type']) ?></td>
                    <td><?= number_format($h['prize_value'], 2) ?> دج</td>
                    <td><?= date('d/m/Y', strtotime($h['honor_date'])) ?></td>
                    <td><?= htmlspecialchars($h['reason']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot><tr><td colspan="2"><strong>الإجمالي</strong></td><td colspan="4"><strong><?= number_format($totalHonorValue, 2) ?> دج</strong></td></tr></tfoot>
        </table>
    </div>
    <?php endif; ?>

    <!-- الخاتمة -->
    <p style="margin-top: 25px; text-align: center; line-height: 1.8;">
        <strong style="font-size: 14pt;">أغلق هذا المحضر بمبلغ قدره: <?= number_format($total_minute_amount, 2) ?> دج</strong><br>
        <span style="font-size: 14pt;">(<?= $total_words ?>)</span>
    </p>
    <p>رفعـــــت الجلســة على الســاعــة <strong><?= $closing_time ?></strong> صبــــاحا مـن نفــــس اليـــــوم والشهــــــر والسنـــــــة المذكــــــورين أعــــلاه.</p>

    <!-- التوقيعات (أفقية) -->
    <div style="display: flex; justify-content: space-between; align-items: center; width: 100%; margin-top: 50px; direction: ltr;">
        <div style="text-align: center; flex: 1;"><div style="border-bottom: 1px solid #000; width: 80%; margin: 0 auto 5px auto;"></div><div>رئيس اللجنة</div><div>(نيد شوقي)</div></div>
        <div style="text-align: center; flex: 1;"><div style="border-bottom: 1px solid #000; width: 80%; margin: 0 auto 5px auto;"></div><div>نائب الرئيس</div><div>(زبيدي رياض)</div></div>
        <div style="text-align: center; flex: 1;"><div style="border-bottom: 1px solid #000; width: 80%; margin: 0 auto 5px auto;"></div><div>أمين الصندوق</div><div>(بن حامدي معمر)</div></div>
    </div>

    <div class="no-print" style="text-align: center; margin-top: 30px;">
        <button onclick="window.print()">🖨️ طباعة المحضر</button>
    </div>
</div>

<?php include '../includes/footer.php'; ?>