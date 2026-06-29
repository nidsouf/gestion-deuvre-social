<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
require_once '../config/database.php';
require_once '../includes/functions.php';

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

$month_name_ar = $arabicMonths[$month] . ' ' . $year;
$year_month = sprintf("%04d-%02d", $year, $month);

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
        'honorees_year' => null,
        'show_cheques' => 0,
        'show_djezzy' => 0
    ];
}

// المنح
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

// السلف المرتبطة فقط بهذا المحضر
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
      AND d.included_in_minute_id = :minute_id
    ORDER BY e.name
");
$stmtLoans->execute([':minute_id' => $minute['id'] ?? 0]);
$loans = $stmtLoans->fetchAll();
$totalLoans = array_sum(array_column($loans, 'total_amount'));

// ثلاثي سعدين
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

// جيزي (يحسب فقط إذا كان الخيار مفعلاً)
$show_djezzy = !empty($minute['show_djezzy']);
$djezzy_monthly_total = 0;
if ($show_djezzy) {
    $stmtDjezzy = $pdo->prepare("SELECT COALESCE(SUM(epn.monthly_amount), 0) as total FROM employee_phone_numbers epn WHERE epn.is_active = 1");
    $stmtDjezzy->execute();
    $djezzy_monthly_total = $stmtDjezzy->fetchColumn();
}

// تسديد سعدين (من source_payments، ولكن سيتم استبعاده من المجموع إذا تم عرض الشيكات)
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

// المكرمون
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

// الشيكات المرتبطة بهذا المحضر فقط
$show_cheques = !empty($minute['show_cheques']);
$cheques = [];
$total_cheques = 0;
if ($show_cheques) {
    $stmtCheques = $pdo->prepare("
        SELECT sp.*, s.name as source_name
        FROM source_payments sp
        JOIN sources s ON sp.source_id = s.id
        WHERE sp.included_in_minute_id = :minute_id
        ORDER BY sp.cheque_date DESC
    ");
    $stmtCheques->execute([':minute_id' => $minute['id']]);
    $cheques = $stmtCheques->fetchAll();
    $total_cheques = array_sum(array_column($cheques, 'amount'));
}

// ========== حساب المجموع الكلي مع تجنب تكرار شيك سعدين ==========
if ($show_cheques) {
    // عند عرض جدول الشيكات، نعتمد على مجموع الشيكات (الذي يحتوي بالفعل على كل المدفوعات للمصادر)
    $total_minute_amount = $totalGrants + $totalLoans 
                         + ($show_djezzy ? $djezzy_monthly_total : 0) 
                         + $saadine_tri_total 
                         + $totalHonorValue 
                         + $total_cheques;
} else {
    // في حالة عدم عرض الشيكات، نضيف تسديد سعدين بشكل منفصل
    $total_minute_amount = $totalGrants + $totalLoans 
                         + ($show_djezzy ? $djezzy_monthly_total : 0) 
                         + $saadine_paid + $saadine_tri_total 
                         + $totalHonorValue;
}

$total_words = numberToWords($total_minute_amount);

// سحب العمرة
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
            <div style="text-align: right;"><h4>محضـــر رقم: <?= htmlspecialchars($minute['meeting_number'] ?: '___/م.ت.م.ت كوينين/' . $year) ?></h4></div>
            <div style="text-align: left;"><h4>كوينين في: <?= date('d/m/Y', strtotime($minute['meeting_date'] ?? $meeting_date)) ?></h4></div>
        </div>
        <div style="text-align: center;"><h4>محضـــر جلســـــة شهــــر <?= $month_name_ar ?> (الجلسة رقم <?= $session_number ?>)</h4></div>
    </div>

    <p>في يوم <strong><?= formatDateArabic($minute['meeting_date'] ?? $meeting_date, $arabicDays, $arabicMonths) ?></strong> وعلى الساعة <strong><?= $meeting_time ?></strong> صباحاً انعقدت جلسة للجنــــــة الخدمات الاجتماعية بالمركز بحضور الأعضاء الآتية أسماؤهم:</p>

    <table style="width:100%; border-collapse: collapse; margin: 15px 0;">
        <thead><tr><th>الرقم</th><th>الاسم واللقب</th><th>الوظيفة</th><th>الصفة</th><th>الملاحظات</th></tr></thead>
        <tbody>
            <tr><td style="text-align:center">01</span></small><td>نيد شوقي</span></small><td>مساعد مهندس.م.1.إ.آ</span></small><td>رئيس اللجنة</span></small><td></span></small></tr>
            <tr><td style="text-align:center">02</span></small><td>زبيدي رياض</span></small><td>مساعد تكوين رئيسي</span></small><td>نائب الرئيس</span></small><td></span></small></tr>
            <tr><td style="text-align:center">03</span></small><td>عمري لطفي</span></small><td>أستاذ.م.ت.ت.م.ر1</span></small><td>عضـــو</span></small><td></span></small></tr>
            <tr><td style="text-align:center">04</span></small><td>قديري بدر الدين</span></small><td>أ.ت.م</span></small><td>عضـــو</span></small><td></span></small></tr>
            <tr><td style="text-align:center">05</span></small><td>بوزنادة مفيدة</span></small><td>أستاذ.م.ت.ت.م.ر2</span></small><td>عضـــو</span></small><td></span></small></tr>
        </tbody>
    </table>

    <p>افتتح السيد رئيس لجنة الخدمات الاجتماعية بالمركز الكلمة مرحباً بالحضور ثم عرج مباشرة على جدول الأعمال:</p>

    <h4>جدول الأعمال:</h4>
    <ul>
        <li>دراسة واعتماد المنح المقدمة للموظفين خلال شهر <?= $month_name_ar ?>.</li>
        <li>متابعة السلف الممنوحة للموظفين.</li>
        <?php if ($show_djezzy): ?>
        <li>إجمالي الاقتطاعات الشهرية لجيزي.</li>
        <?php endif; ?>
        <li>تسديد مستحقات سعدين للتجهير.</li>
        <?php if($show_tri_total): ?>
        <li>مراجعة إجمالي الاقتطاع الثلاثي لفائدة سعدين للتجهير.</li>
        <?php endif; ?>
        <?php if (!empty($honorees)): ?>
        <li>الاطلاع على قائمة المكرمين في عيد العمال للسنة <?= $minute['honorees_year'] ?>.</li>
        <?php endif; ?>
        <?php if (!empty($cheques)): ?>
        <li>مراجعة الشيكات المدفوعة للمصادر خلال شهر <?= $month_name_ar ?>.</li>
        <?php endif; ?>
    </ul>

    <!-- المنح -->
    <?php if(!empty($grants)): ?>
    <h4>1. المنح المقدمة:</h4>
    <table style="width:100%; border-collapse: collapse; margin: 15px 0;">
        <thead><tr><th>#</th><th>الموظف</th><th>نوع المنحة</th><th>المبلغ (دج)</th><th>تاريخ المنح</th><th>السبب</th></tr></thead>
        <tbody><?php $i=1; foreach($grants as $g): ?>
        <tr><td style="text-align:center"><?= $i++ ?></td><td><?= htmlspecialchars($g['employee_name']) ?></td><td><?= htmlspecialchars($g['grant_name']) ?></td><td><?= number_format($g['amount'], 2) ?></span></small></td><td><?= date('d/m/Y', strtotime($g['grant_date'])) ?></td><td><?= htmlspecialchars($g['notes'] ?? '') ?></td></tr>
        <?php endforeach; ?></tbody>
<tfoot>
    <tr>
        <td colspan="3"><strong>الإجمالي</strong></td>
        <td colspan="3"><strong><?= number_format($totalGrants, 2) ?> دج</strong></td>
    </tr>
</tfoot>    </table>
    <?php else: ?><p>⚫ لا توجد منح مسجلة هذا الشهر.</p><?php endif; ?>

    <!-- السلف -->
    <?php if(!empty($loans)): ?>
    <h4>2. السلف الممنوحة (المبلغ الكلي وتاريخ الصرف):</h4>
    <table style="width:100%; border-collapse: collapse; margin: 15px 0;">
        <thead><tr><th>#</th><th>الموظف</th><th>نوع السلفة</th><th>المبلغ الكلي (دج)</th><th>تاريخ الصرف</th><th>تاريخ بداية الاقتطاع</th></tr></thead>
        <tbody><?php $i=1; foreach($loans as $l): ?>
        <tr><td style="text-align:center"><?= $i++ ?></td><td><?= htmlspecialchars($l['employee_name']) ?></td><td><?= htmlspecialchars($l['source_name']) ?></td><td><?= number_format($l['total_amount'], 2) ?></span></small></td><td><?= date('d/m/Y', strtotime($l['grant_date'])) ?></td><td><?= date('d/m/Y', strtotime($l['start_date'])) ?></td></tr>
        <?php endforeach; ?></tbody>
<tfoot>
    <tr>
        <td colspan="3"><strong>الإجمالي</strong></td>
        <td colspan="3"><strong><?= number_format($totalLoans, 2) ?> دج</strong></td>
    </tr>
</tfoot>    </table>
    <?php else: ?><p>⚫ لا توجد سلف مسجلة لهذا المحضر.</p><?php endif; ?>

    <!-- الاقتطاعات والتسديدات -->
    <h4>3. الاقتطاعات والتسديدات:</h4>
    <ul>
        <?php if ($show_djezzy): ?>
        <li>إجمالي الاقتطاعات الشهرية لجيزي: <strong><?= number_format($djezzy_monthly_total, 2) ?> دج</strong></li>
        <?php endif; ?>
        <li>إجمالي المبلغ المستحق لجيزي: <strong>28,300.00 دج</strong></li>
        <?php if (!$show_cheques): ?>
        <li>تسديد مستحقات سعدين للتجهير: <strong><?= number_format($saadine_paid, 2) ?> دج</strong></li>
        <?php endif; ?>
        <?php if($show_tri_total): ?>
        <li>إجمالي الاقتطاع الثلاثي لسعدين للتجهير (آخر 3 أشهر): <strong><?= number_format($saadine_tri_total, 2) ?> دج</strong></li>
        <?php endif; ?>
    </ul>

    <!-- الشيكات -->
    <?php if (!empty($cheques)): ?>
    <h4>4. الشيكات المدفوعة للمصادر خلال شهر <?= $month_name_ar ?>:</h4>
    <table style="width:100%; border-collapse: collapse; margin: 15px 0;">
        <thead><tr><th>#</th><th>المصدر</th><th>رقم الشيك</th><th>التاريخ</th><th>الربع</th><th>المبلغ (دج)</th><th>ملاحظات</th></tr></thead>
        <tbody><?php $i=1; foreach($cheques as $ch): ?>
        <tr><td style="text-align:center"><?= $i++ ?></td><td><?= htmlspecialchars($ch['source_name']) ?></td><td><?= htmlspecialchars($ch['cheque_number'] ?? '-') ?></td><td><?= date('d/m/Y', strtotime($ch['cheque_date'])) ?></td><td><?= $ch['quarter'] ? 'الربع '.$ch['quarter'] : '---' ?></td><td><?= number_format($ch['amount'], 2) ?> دج</span></small></td><td><?= htmlspecialchars($ch['notes'] ?? '-') ?></td></tr>
        <?php endforeach; ?></tbody>
<tfoot>
    <tr>
        <td colspan="5"><strong>الإجمالي</strong></td>
        <td colspan="2"><strong><?= number_format($total_cheques, 2) ?> دج</strong></td>
    </tr>
</tfoot>    </table>
    <?php endif; ?>

    <!-- سحب العمرة -->
    <?php if ($umrah_draw): ?>
    <div style="margin-top: 30px; page-break-inside: avoid;">
        <h4>🕋 نتائج سحب العمرة</h4>
        <p><strong>تاريخ السحب:</strong> <?= date('d/m/Y H:i', strtotime($umrah_draw['draw_date'])) ?></p>
        <p><strong>العنوان:</strong> <?= htmlspecialchars($umrah_draw['title'] ?? 'سحب العمرة') ?></p>
        <?php
        $winner = null; $reserves = [];
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
        <table style="width:100%; border-collapse: collapse;"><thead><tr><th>الموظف</th><th>عدد الأوراق</th><th>النتيجة</th></tr></thead>
        <tbody><?php foreach ($umrah_participants as $p): ?>
        <tr><td style="text-align:center"><?= htmlspecialchars($p['employee_name']) ?></td><td style="text-align:center"><?= $p['tickets_count'] ?></td><td style="text-align:center"><?= $p['is_winner'] ? '🏆 فائز' : ($p['reserve_order'] ? 'احتياطي '.$p['reserve_order'] : 'غير فائز') ?></td></tr>
        <?php endforeach; ?></tbody></table>
    </div>
    <?php endif; ?>

    <!-- المكرمون -->
    <?php if (!empty($honorees)): ?>
    <div style="margin-top: 30px; page-break-inside: avoid;">
        <h4>🎖️ المكرمون في عيد العمال (سنة <?= $minute['honorees_year'] ?>)</h4>
        <table style="width:100%; border-collapse: collapse;"><thead><tr><th>#</th><th>الموظف</th><th>نوع الجائزة</th><th>القيمة (دج)</th><th>تاريخ التكريم</th><th>سبب التكريم</th></tr></thead>
        <tbody><?php $i=1; foreach($honorees as $h): ?>
        <tr><td style="text-align:center"><?= $i++ ?></td><td style="text-align:center"><?= htmlspecialchars($h['employee_name']) ?><br><small>(<?= $h['category'] == 'Permanent' ? 'دائم' : 'متعاقد' ?>)</small></td><td style="text-align:center"><?= htmlspecialchars($h['prize_type']) ?></td><td style="text-align:center"><?= number_format($h['prize_value'], 2) ?> دج</span></small></td><td style="text-align:center"><?= date('d/m/Y', strtotime($h['honor_date'])) ?></td><td style="text-align:center"><?= htmlspecialchars($h['reason']) ?></td></tr>
        <?php endforeach; ?></tbody>
<tfoot>
    <tr>
        <td colspan="2"><strong>الإجمالي</strong></td>
        <td colspan="4"><strong><?= number_format($totalHonorValue, 2) ?> دج</strong></td>
    </tr>
</tfoot>    </table>
    </div>
    <?php endif; ?>

    <!-- الخاتمة -->
    <p style="margin-top: 25px; text-align: center; line-height: 1.8;">
        <strong style="font-size: 14pt;">أغلق هذا المحضر بمبلغ قدره: <?= number_format($total_minute_amount, 2) ?> دج</strong><br>
        <span style="font-size: 14pt;">(<?= $total_words ?>)</span>
    </p>
    <p>رفعـــــت الجلســة على الســاعــة <strong><?= $closing_time ?></strong> صبــــاحا مـن نفــــس اليـــــوم والشهــــــر والسنـــــــة المذكــــــورين أعــــلاه.</p>

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