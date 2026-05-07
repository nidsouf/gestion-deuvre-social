<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
require_once '../config/database.php';
require_once '../includes/functions.php';

// مصفوفة أسماء الأشهر العربية
$arabicMonths = [
    1 => 'جانفي',
    2 => 'فيفري',
    3 => 'مارس',
    4 => 'أفريل',
    5 => 'ماي',
    6 => 'جوان',
    7 => 'جويلية',
    8 => 'أوت',
    9 => 'سبتمبر',
    10 => 'أكتوبر',
    11 => 'نوفمبر',
    12 => 'ديسمبر'
];

// مصفوفة أسماء الأيام العربية
$arabicDays = [
    'Monday' => 'الاثنين',
    'Tuesday' => 'الثلاثاء',
    'Wednesday' => 'الأربعاء',
    'Thursday' => 'الخميس',
    'Friday' => 'الجمعة',
    'Saturday' => 'السبت',
    'Sunday' => 'الأحد'
];

/**
 * تحويل تاريخ من Y-m-d إلى نص عربي مع اليوم والشهر
 */
function formatDateArabic($date, $arabicDays, $arabicMonths) {
    $timestamp = strtotime($date);
    $dayEn = date('l', $timestamp);
    $dayAr = $arabicDays[$dayEn] ?? $dayEn;
    $dayNum = date('d', $timestamp);
    $monthNum = (int)date('m', $timestamp);
    $monthAr = $arabicMonths[$monthNum] ?? date('F', $timestamp);
    $year = date('Y', $timestamp);
    return "$dayAr $dayNum $monthAr $year";
}

function getMonthNameArabic($monthNumber, $arabicMonths) {
    return $arabicMonths[(int)$monthNumber] ?? date('F', mktime(0,0,0,$monthNumber,1));
}

// =============================================
// التأكد من وجود الأعمدة المطلوبة
// =============================================
$columns = $pdo->query("PRAGMA table_info(meeting_minutes)")->fetchAll(PDO::FETCH_COLUMN, 1);
if (!in_array('meeting_number', $columns)) {
    $pdo->exec("ALTER TABLE meeting_minutes ADD COLUMN meeting_number TEXT");
}
if (!in_array('total_grants_amount', $columns)) {
    $pdo->exec("ALTER TABLE meeting_minutes ADD COLUMN total_grants_amount REAL DEFAULT 0");
}
if (!in_array('total_deductions_amount', $columns)) {
    $pdo->exec("ALTER TABLE meeting_minutes ADD COLUMN total_deductions_amount REAL DEFAULT 0");
}
if (!in_array('umrah_draw_event_id', $columns)) {
    $pdo->exec("ALTER TABLE meeting_minutes ADD COLUMN umrah_draw_event_id INTEGER DEFAULT NULL");
}
if (!in_array('session_number', $columns)) {
    $pdo->exec("ALTER TABLE meeting_minutes ADD COLUMN session_number INTEGER DEFAULT 1");
}
if (!in_array('show_honorees', $columns)) {
    $pdo->exec("ALTER TABLE meeting_minutes ADD COLUMN show_honorees INTEGER DEFAULT 0");
}
if (!in_array('honorees_year', $columns)) {
    $pdo->exec("ALTER TABLE meeting_minutes ADD COLUMN honorees_year INTEGER DEFAULT NULL");
}

$message = '';
$month = isset($_GET['month']) ? (int)$_GET['month'] : date('m');
$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
$session_number = isset($_GET['session_number']) ? (int)$_GET['session_number'] : 1;

$year_month = sprintf("%04d-%02d", $year, $month);
$startDate = "$year-$month-01";
$endDate = date('Y-m-t', strtotime($startDate));

// جلب المحضر الموجود (إن وجد)
$stmt = $pdo->prepare("SELECT * FROM meeting_minutes WHERE month = ? AND year = ? AND session_number = ?");
$stmt->execute([$month, $year, $session_number]);
$minute = $stmt->fetch();

// =============================================
// 1. المنح
// =============================================
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

// =============================================
// 2. السلف
// =============================================
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

// =============================================
// 3. ثلاثي سعدين
// =============================================
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

// =============================================
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

// =============================================
// 5. تسديد سعدين من source_payments
// =============================================
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

// =============================================
// 6. سحوبات العمرة المكتملة
// =============================================
$umrahDraws = $pdo->query("
    SELECT de.id, de.draw_date, de.title, e.name as winner_name
    FROM umrah_draw_events de
    LEFT JOIN employees e ON de.winner_id = e.id
    WHERE de.status = 'completed'
    ORDER BY de.draw_date DESC
")->fetchAll();

// =============================================
// 7. سنوات المكرمين المتاحة للاختيار
// =============================================
$honoreesYears = $pdo->query("SELECT DISTINCT year FROM labor_day_honorees ORDER BY year DESC")->fetchAll(PDO::FETCH_COLUMN);

// =============================================
// جلب المكرمين إذا كان مطلوباً في هذا المحضر
// =============================================
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

// =============================================
// معالجة حفظ المحضر
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $meeting_date = $_POST['meeting_date'];
    $meeting_number = $_POST['meeting_number'];
    $content = $_POST['content'];
    $notes = $_POST['notes'];
    $created_by = $_SESSION['username'] ?? 'admin';
    $umrah_draw_event_id = !empty($_POST['umrah_draw_event_id']) ? (int)$_POST['umrah_draw_event_id'] : null;
    $session_num = (int)$_POST['session_number'];
    $show_honorees = isset($_POST['show_honorees']) ? 1 : 0;
    $honorees_year = !empty($_POST['honorees_year']) ? (int)$_POST['honorees_year'] : null;

    $existing = $pdo->prepare("SELECT id FROM meeting_minutes WHERE month = ? AND year = ? AND session_number = ?");
    $existing->execute([$month, $year, $session_num]);
    if ($existing->fetch()) {
        $update = $pdo->prepare("
            UPDATE meeting_minutes 
            SET meeting_date = ?, meeting_number = ?, content = ?, notes = ?, total_grants_amount = ?, umrah_draw_event_id = ?, show_honorees = ?, honorees_year = ?, updated_at = CURRENT_TIMESTAMP
            WHERE month = ? AND year = ? AND session_number = ?
        ");
        $update->execute([$meeting_date, $meeting_number, $content, $notes, $totalGrants, $umrah_draw_event_id, $show_honorees, $honorees_year, $month, $year, $session_num]);
        $message = "✅ تم تحديث المحضر بنجاح.";
    } else {
        $insert = $pdo->prepare("
            INSERT INTO meeting_minutes (month, year, session_number, meeting_date, meeting_number, content, notes, total_grants_amount, created_by, umrah_draw_event_id, show_honorees, honorees_year)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $insert->execute([$month, $year, $session_num, $meeting_date, $meeting_number, $content, $notes, $totalGrants, $created_by, $umrah_draw_event_id, $show_honorees, $honorees_year]);
        $message = "✅ تم إنشاء المحضر بنجاح.";
    }
    // إعادة تحميل المحضر
    $stmt = $pdo->prepare("SELECT * FROM meeting_minutes WHERE month = ? AND year = ? AND session_number = ?");
    $stmt->execute([$month, $year, $session_num]);
    $minute = $stmt->fetch();
    // إعادة جلب المكرمين بعد الحفظ
    if (!empty($show_honorees) && !empty($honorees_year)) {
        $stmtHon = $pdo->prepare("
            SELECT h.*, e.name as employee_name, e.category
            FROM labor_day_honorees h
            JOIN employees e ON h.employee_id = e.id
            WHERE h.year = ?
            ORDER BY e.name ASC
        ");
        $stmtHon->execute([$honorees_year]);
        $honorees = $stmtHon->fetchAll();
        $totalHonorValue = array_sum(array_column($honorees, 'prize_value'));
    } else {
        $honorees = [];
        $totalHonorValue = 0;
    }
}

// =============================================
// قائمة الجلسات الموجودة لهذا الشهر
// =============================================
$sessionsList = $pdo->prepare("SELECT session_number FROM meeting_minutes WHERE month = ? AND year = ? ORDER BY session_number");
$sessionsList->execute([$month, $year]);
$existingSessions = $sessionsList->fetchAll(PDO::FETCH_COLUMN);
$nextSession = empty($existingSessions) ? 1 : max($existingSessions) + 1;

include '../includes/header.php';
?>

<style>
    .minutes-container { direction: rtl; padding: 20px; max-width: 1200px; margin: auto; }
    .filters { background: #f0f2f5; padding: 15px; border-radius: 10px; margin-bottom: 20px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
    .form-group { margin-bottom: 15px; }
    .form-group label { display: block; font-weight: bold; margin-bottom: 5px; }
    .form-group input, .form-group textarea, .form-group select { width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #ddd; }
    .grants-table, .loans-table { width: 100%; border-collapse: collapse; margin-top: 10px; margin-bottom: 20px; }
    .grants-table th, .loans-table th, .grants-table td, .loans-table td { border: 1px solid #ddd; padding: 8px; text-align: center; }
    .grants-table th { background: #28a745; color: white; }
    .loans-table th { background: #ff9800; color: white; }
    .info-box { background: #e3f2fd; padding: 15px; border-radius: 10px; margin-bottom: 20px; border-right: 5px solid #2196f3; }
    .info-box p { margin: 5px 0; }
    .btn-save { background: #2a5298; color: white; padding: 12px 25px; border: none; border-radius: 8px; cursor: pointer; font-size: 16px; }
    .print-btn { background: #17a2b8; color: white; padding: 12px 25px; border: none; border-radius: 8px; cursor: pointer; text-decoration: none; display: inline-block; }
    .section-title { font-size: 18px; font-weight: bold; margin-top: 20px; margin-bottom: 10px; border-right: 4px solid #2a5298; padding-right: 10px; }
    .btn-sm {
        display: inline-block;
        padding: 4px 8px;
        background: #6c757d;
        color: white;
        border-radius: 12px;
        text-decoration: none;
        font-size: 12px;
    }
</style>

<div class="minutes-container">
    <h2>📝 تحرير المحضر الشهري للجنة</h2>
    
    <div class="filters">
        <form method="GET">
            <select name="month">
                <?php for($m=1;$m<=12;$m++): ?>
                    <option value="<?=$m?>" <?=$m==$month?'selected':''?>><?=date('F',mktime(0,0,0,$m,1))?></option>
                <?php endfor; ?>
            </select>
            <select name="year">
                <?php for($y=2020;$y<=date('Y')+1;$y++): ?>
                    <option value="<?=$y?>" <?=$y==$year?'selected':''?>><?=$y?></option>
                <?php endfor; ?>
            </select>
            <select name="session_number">
                <?php foreach ($existingSessions as $s): ?>
                    <option value="<?=$s?>" <?=($session_number==$s)?'selected':''?>>الجلسة رقم <?=$s?></option>
                <?php endforeach; ?>
                <option value="<?=$nextSession?>">-- جلسة جديدة (رقم <?=$nextSession?>) --</option>
            </select>
            <button type="submit" class="btn-primary">عرض</button>
        </form>
    </div>

    <?php if($message): ?>
        <div style="background:#d4edda; padding:10px; border-radius:8px; margin-bottom:15px;"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="info-box">
        <p><strong>💰 إجمالي الاقتطاعات الشهرية لجيزي (هذا الشهر):</strong> <?= number_format($djezzy_monthly_total, 2) ?> دج</p>
        <p><strong>💰 تسديد مستحقات سعدين للتجهير (هذا الشهر):</strong> <?= number_format($saadine_paid, 2) ?> دج</p>
        <?php if($show_tri_total): ?>
            <p><strong>📊 إجمالي الاقتطاع الثلاثي لسعدين للتجهير (آخر 3 أشهر):</strong> <?= number_format($saadine_tri_total, 2) ?> دج</p>
        <?php endif; ?>
    </div>

    <form method="POST">
        <input type="hidden" name="session_number" value="<?= $session_number ?>">
        <div class="form-group">
            <label>📅 تاريخ الجلسة:</label>
            <input type="date" name="meeting_date" value="<?= htmlspecialchars($minute['meeting_date'] ?? date('Y-m-d')) ?>" required>
        </div>
        <div class="form-group">
            <label>🔢 رقم الجلسة الرسمي (مثال: 03/2026):</label>
            <input type="text" name="meeting_number" placeholder="مثال: 03/2026" value="<?= htmlspecialchars($minute['meeting_number'] ?? '') ?>">
        </div>

        <div class="form-group">
            <label>🕋 سحب العمرة (اختياري):</label>
            <select name="umrah_draw_event_id" class="form-control">
                <option value="">-- لا يوجد --</option>
                <?php foreach ($umrahDraws as $draw): ?>
                    <option value="<?= $draw['id'] ?>" <?= (($minute['umrah_draw_event_id'] ?? '') == $draw['id']) ? 'selected' : '' ?>>
                        <?= date('d/m/Y', strtotime($draw['draw_date'])) ?> - 
                        <?= htmlspecialchars($draw['title'] ?? 'سحب') ?> 
                        (الفائز: <?= htmlspecialchars($draw['winner_name'] ?? '?') ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label>🎖️ إدراج المكرمين في عيد العمال:</label>
            <div style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
                <label style="font-weight: normal;">
                    <input type="checkbox" name="show_honorees" value="1" <?= (!empty($minute['show_honorees']) ? 'checked' : '') ?>> عرض المكرمين
                </label>
                <select name="honorees_year" style="width: auto; min-width: 100px;">
                    <option value="">-- اختر السنة --</option>
                    <?php foreach ($honoreesYears as $y): ?>
                        <option value="<?= $y ?>" <?= (($minute['honorees_year'] ?? '') == $y) ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <small>يتم عرض قائمة المكرمين في المحضر بناءً على السنة المختارة</small>
        </div>

        <div class="section-title">🎁 المنح المقدمة في هذا الشهر</div>
        <?php if(empty($grants)): ?>
            <p style="color:gray;">⚠️ لا توجد منح مسجلة في هذا الشهر.</p>
        <?php else: ?>
            <table class="grants-table">
                <thead><tr><th>#</th><th>الموظف</th><th>نوع المنحة</th><th>المبلغ (دج)</th><th>تاريخ المنح</th><th>السبب</th></tr></thead>
                <tbody>
                    <?php $i=1; foreach($grants as $g): ?>
                    <tr>
                        <td><?= $i++ ?></td>
                        <td><?= htmlspecialchars($g['employee_name']) ?></td>
                        <td><?= htmlspecialchars($g['grant_name']) ?></td>
                        <td><?= number_format($g['amount'], 2) ?> دج</span></small></td>
                        <td><?= date('d/m/Y', strtotime($g['grant_date'])) ?></td>
                        <td><?= htmlspecialchars($g['notes'] ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot><tr class="total-row"><td colspan="3"><strong>إجمالي المنح</strong></td><td colspan="3"><strong><?= number_format($totalGrants, 2) ?> دج</strong></td></tr></tfoot>
            </table>
        <?php endif; ?>

        <div class="section-title">💸 السلف الممنوحة للموظفين في هذا الشهر (المبلغ الكلي)</div>
        <?php if(empty($loans)): ?>
            <p style="color:gray;">⚠️ لا توجد سلف مسجلة في هذا الشهر.</p>
        <?php else: ?>
            <table class="loans-table">
                <thead><tr><th>#</th><th>الموظف</th><th>نوع السلفة</th><th>المبلغ الكلي (دج)</th><th>تاريخ الصرف</th><th>تاريخ بداية الاقتطاع</th></tr></thead>
                <tbody>
                    <?php $i=1; foreach($loans as $l): ?>
                    <tr>
                        <td><?= $i++ ?></td>
                        <td><?= htmlspecialchars($l['employee_name']) ?></td>
                        <td><?= htmlspecialchars($l['source_name']) ?></td>
                        <td><?= number_format($l['total_amount'], 2) ?> دج</span></small></td>
                        <td><?= date('d/m/Y', strtotime($l['grant_date'])) ?></td>
                        <td><?= date('d/m/Y', strtotime($l['start_date'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot><tr class="total-row"><td colspan="3"><strong>إجمالي السلف</strong></td><td colspan="3"><strong><?= number_format($totalLoans, 2) ?> دج</strong></td></tr></tfoot>
            </table>
        <?php endif; ?>

        <?php if (!empty($honorees)): ?>
        <div class="section-title">🎖️ المكرمون في عيد العمال (سنة <?= $minute['honorees_year'] ?>)</div>
        <table class="grants-table">
            <thead>
                <tr><th>#</th><th>الموظف</th><th>نوع الجائزة</th><th>القيمة (دج)</th><th>تاريخ التكريم</th><th>سبب التكريم</th></tr>
            </thead>
            <tbody>
                <?php $i=1; foreach($honorees as $h): ?>
                <tr>
                    <td><?= $i++ ?></td>
                    <td><?= htmlspecialchars($h['employee_name']) ?><br><small>(<?= $h['category'] == 'Permanent' ? 'دائم' : 'متعاقد' ?>)</small></td>
                    <td><?= htmlspecialchars($h['prize_type']) ?></td>
                    <td><?= number_format($h['prize_value'], 2) ?> دج</span></small></td>
                    <td><?= date('d/m/Y', strtotime($h['honor_date'])) ?></td>
                    <td><?= htmlspecialchars($h['reason']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="total-row">
                    <td colspan="2"><strong>إجمالي قيمة المكرمين</strong></td>
                    <td colspan="4"><strong><?= number_format($totalHonorValue, 2) ?> دج</strong></td>
                </tr>
            </tfoot>
        </table>
        <?php endif; ?>

        <div class="form-group">
            <label>✍️ نص المحضر:</label>
            <textarea name="content" rows="15" style="font-family: monospace; line-height: 1.5;"><?= htmlspecialchars($minute['content'] ?? "محضر جلسة اللجنة الاجتماعية\nالتاريخ: " . date('d/m/Y') . "\n\nبعد المناقشة والاطلاع على تقارير المنح، تقرر ما يلي:\n\n1. الموافقة على المنح المقدمة لهذا الشهر بمبلغ إجمالي قدره " . number_format($totalGrants, 2) . " دج.\n2. إجمالي الاقتطاعات الشهرية لجيزي: " . number_format($djezzy_monthly_total, 2) . " دج.\n3. تسديد مستحقات سعدين للتجهير: " . number_format($saadine_paid, 2) . " دج.\n" . ($show_tri_total ? "4. إجمالي الاقتطاع الثلاثي لسعدين للتجهير: " . number_format($saadine_tri_total, 2) . " دج.\n" : "") . "\n\nالتوقيعات:\nرئيس اللجنة: __________\nالمقرر: __________\nأمين الصندوق: __________") ?></textarea>
        </div>

        <div class="form-group">
            <label>📝 ملاحظات إضافية:</label>
            <textarea name="notes" rows="3"><?= htmlspecialchars($minute['notes'] ?? '') ?></textarea>
        </div>

        <div style="display: flex; gap: 15px; margin-top: 20px;">
            <button type="submit" class="btn-save">💾 حفظ المحضر</button>
            <a href="print_minute.php?month=<?= $month ?>&year=<?= $year ?>&session_number=<?= $session_number ?>" target="_blank" class="print-btn">🖨️ معاينة وطباعة</a>
        </div>
    </form>
</div>

<?php include '../includes/footer.php'; ?>