<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
require_once '../config/database.php';
require_once '../includes/functions.php';

// ============================================================
// دوال مساعدة للتواريخ
// ============================================================
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

function safeFormatDate($date) {
    if (empty($date) || $date === '0000-00-00') return '—';
    return date('d/m/Y', strtotime($date));
}

function formatDateArabicLocal($date, $arabicDays, $arabicMonths) {
    if (empty($date) || $date === '0000-00-00') return '—';
    $ts = strtotime($date);
    $dayEn = date('l', $ts);
    $dayAr = $arabicDays[$dayEn] ?? $dayEn;
    $dayNum = date('d', $ts);
    $monthNum = (int)date('m', $ts);
    $monthAr = $arabicMonths[$monthNum] ?? date('F', $ts);
    $year = date('Y', $ts);
    return "$dayAr $dayNum $monthAr $year";
}

// التأكد من وجود الأعمدة
$columns = $pdo->query("PRAGMA table_info(meeting_minutes)")->fetchAll(PDO::FETCH_COLUMN, 1);
if (!in_array('meeting_number', $columns)) $pdo->exec("ALTER TABLE meeting_minutes ADD COLUMN meeting_number TEXT");
if (!in_array('total_grants_amount', $columns)) $pdo->exec("ALTER TABLE meeting_minutes ADD COLUMN total_grants_amount REAL DEFAULT 0");
if (!in_array('total_deductions_amount', $columns)) $pdo->exec("ALTER TABLE meeting_minutes ADD COLUMN total_deductions_amount REAL DEFAULT 0");
if (!in_array('umrah_draw_event_id', $columns)) $pdo->exec("ALTER TABLE meeting_minutes ADD COLUMN umrah_draw_event_id INTEGER DEFAULT NULL");
if (!in_array('session_number', $columns)) $pdo->exec("ALTER TABLE meeting_minutes ADD COLUMN session_number INTEGER DEFAULT 1");
if (!in_array('show_honorees', $columns)) $pdo->exec("ALTER TABLE meeting_minutes ADD COLUMN show_honorees INTEGER DEFAULT 0");
if (!in_array('honorees_year', $columns)) $pdo->exec("ALTER TABLE meeting_minutes ADD COLUMN honorees_year INTEGER DEFAULT NULL");
if (!in_array('show_cheques', $columns)) $pdo->exec("ALTER TABLE meeting_minutes ADD COLUMN show_cheques INTEGER DEFAULT 0");
if (!in_array('show_djezzy', $columns)) $pdo->exec("ALTER TABLE meeting_minutes ADD COLUMN show_djezzy INTEGER DEFAULT 0");

$loanColumns = $pdo->query("PRAGMA table_info(deductions)")->fetchAll(PDO::FETCH_COLUMN, 1);
if (!in_array('included_in_minute_id', $loanColumns)) {
    $pdo->exec("ALTER TABLE deductions ADD COLUMN included_in_minute_id INTEGER DEFAULT NULL");
}
$chequeColumns = $pdo->query("PRAGMA table_info(source_payments)")->fetchAll(PDO::FETCH_COLUMN, 1);
if (!in_array('included_in_minute_id', $chequeColumns)) {
    $pdo->exec("ALTER TABLE source_payments ADD COLUMN included_in_minute_id INTEGER DEFAULT NULL");
}

$month = isset($_GET['month']) ? (int)$_GET['month'] : date('m');
$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
$session_number = isset($_GET['session_number']) ? (int)$_GET['session_number'] : 1;

$year_month = sprintf("%04d-%02d", $year, $month);
$startDate = "$year-$month-01";
$endDate = date('Y-m-t', strtotime($startDate));

// جلب المحضر الموجود
$stmt = $pdo->prepare("SELECT * FROM meeting_minutes WHERE month = ? AND year = ? AND session_number = ?");
$stmt->execute([$month, $year, $session_number]);
$minute = $stmt->fetch();

// ========== 1. المنح العادية (باستثناء وجبات المطعم) ==========
$stmtGrants = $pdo->prepare("
    SELECT eg.*, e.name as employee_name, e.account_number, g.name as grant_name, eg.amount as amount
    FROM employee_grants eg
    JOIN employees e ON eg.employee_id = e.id
    JOIN grants g ON eg.grant_id = g.id
    WHERE strftime('%Y-%m', eg.grant_date) = :year_month
      AND g.name != 'منحة وجبات المطعم'
    ORDER BY eg.grant_date DESC
");
$stmtGrants->execute([':year_month' => $year_month]);
$grants = $stmtGrants->fetchAll();
$totalGrants = array_sum(array_column($grants, 'amount'));

// ========== 2. منح وجبات المطعم ==========
$stmtMealGrants = $pdo->prepare("
    SELECT 
        mi.*,
        e.name as employee_name,
        e.category,
        e.account_number
    FROM meal_installments mi
    JOIN employees e ON mi.employee_id = e.id
    WHERE mi.year = ? AND mi.month = ? AND mi.is_processed = 1
    ORDER BY e.name ASC
");
$stmtMealGrants->execute([$year, $month]);
$meal_grants = $stmtMealGrants->fetchAll();
$totalMealGrants = array_sum(array_column($meal_grants, 'grant_amount'));

// ========== 3. السلف (غير المضمنة سابقاً) ==========
$stmtLoans = $pdo->prepare("
    SELECT 
        d.id,
        e.name as employee_name,
        e.account_number,
        s.name as source_name,
        d.monthly_amount,
        d.total_months,
        d.start_date,
        d.end_date,
        d.grant_date,
        d.created_at,
        (d.monthly_amount * d.total_months) AS total_amount,
        d.included_in_minute_id
    FROM deductions d
    JOIN employees e ON d.employee_id = e.id
    JOIN sources s ON d.source_id = s.id
    WHERE d.is_loan = 1
      AND (d.included_in_minute_id IS NULL 
           OR d.included_in_minute_id NOT IN (
               SELECT id FROM meeting_minutes 
               WHERE month = :month AND year = :year AND id != :current_minute_id
           ))
      AND (strftime('%Y-%m', COALESCE(d.grant_date, d.created_at, d.start_date)) = :year_month)
    ORDER BY e.name
");
$stmtLoans->execute([
    ':year_month' => $year_month,
    ':month' => $month,
    ':year' => $year,
    ':current_minute_id' => $minute['id'] ?? 0
]);
$loans = $stmtLoans->fetchAll();

// ========== 4. ثلاثي سعدين ==========
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

// ========== 5. جيزي ==========
$djezzy_monthly_total = 0;
$stmtDjezzy = $pdo->prepare("
    SELECT COALESCE(SUM(epn.monthly_amount), 0) as total
    FROM employee_phone_numbers epn
    WHERE epn.is_active = 1
");
$stmtDjezzy->execute();
$djezzy_monthly_total = $stmtDjezzy->fetchColumn();

// ========== 6. تسديد مستحقات سعدين ==========
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

// ========== 7. سحوبات العمرة ==========
$umrahDraws = $pdo->query("
    SELECT de.id, de.draw_date, de.title, e.name as winner_name
    FROM umrah_draw_events de
    LEFT JOIN employees e ON de.winner_id = e.id
    WHERE de.status = 'completed'
    ORDER BY de.draw_date DESC
")->fetchAll();

// ========== 8. سنوات المكرمين ==========
$honoreesYears = $pdo->query("SELECT DISTINCT year FROM labor_day_honorees ORDER BY year DESC")->fetchAll(PDO::FETCH_COLUMN);

// ========== 9. المكرمين ==========
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

// ========== 10. الشيكات ==========
$show_cheques = isset($minute['show_cheques']) ? $minute['show_cheques'] : 0;
$cheques = [];
if ($show_cheques) {
    $stmtCheques = $pdo->prepare("
        SELECT sp.*, s.name as source_name
        FROM source_payments sp
        JOIN sources s ON sp.source_id = s.id
        WHERE strftime('%Y', sp.cheque_date) = :year
          AND strftime('%m', sp.cheque_date) = :month
          AND (sp.included_in_minute_id IS NULL 
               OR sp.included_in_minute_id NOT IN (
                   SELECT id FROM meeting_minutes 
                   WHERE month = :month AND year = :year AND id != :current_minute_id
               ))
        ORDER BY sp.cheque_date DESC
    ");
    $stmtCheques->execute([
        ':year' => (string)$year,
        ':month' => sprintf("%02d", $month),
        ':current_minute_id' => $minute['id'] ?? 0
    ]);
    $cheques = $stmtCheques->fetchAll();
}
$show_djezzy = isset($minute['show_djezzy']) ? $minute['show_djezzy'] : 0;

// ==============================
// معالجة POST (حفظ المحضر)
// ==============================
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
    $show_cheques = isset($_POST['show_cheques']) ? 1 : 0;
    $show_djezzy = isset($_POST['show_djezzy']) ? 1 : 0;

    $existing = $pdo->prepare("SELECT id FROM meeting_minutes WHERE month = ? AND year = ? AND session_number = ?");
    $existing->execute([$month, $year, $session_num]);
    if ($existing->fetch()) {
        $update = $pdo->prepare("
            UPDATE meeting_minutes 
            SET meeting_date = ?, meeting_number = ?, content = ?, notes = ?, total_grants_amount = ?, 
                umrah_draw_event_id = ?, show_honorees = ?, honorees_year = ?, show_cheques = ?, show_djezzy = ?, updated_at = CURRENT_TIMESTAMP
            WHERE month = ? AND year = ? AND session_number = ?
        ");
        $update->execute([$meeting_date, $meeting_number, $content, $notes, $totalGrants, 
                         $umrah_draw_event_id, $show_honorees, $honorees_year, $show_cheques, $show_djezzy,
                         $month, $year, $session_num]);
        $message = "✅ تم تحديث المحضر بنجاح.";
    } else {
        $insert = $pdo->prepare("
            INSERT INTO meeting_minutes (month, year, session_number, meeting_date, meeting_number, content, notes, total_grants_amount, created_by, umrah_draw_event_id, show_honorees, honorees_year, show_cheques, show_djezzy)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $insert->execute([$month, $year, $session_num, $meeting_date, $meeting_number, $content, $notes, $totalGrants, $created_by, $umrah_draw_event_id, $show_honorees, $honorees_year, $show_cheques, $show_djezzy]);
        $message = "✅ تم إنشاء المحضر بنجاح.";
    }

    // حفظ السلف المختارة
    $selected_loans = isset($_POST['selected_loans']) ? $_POST['selected_loans'] : [];
    if (!empty($selected_loans)) {
        $minuteId = $pdo->lastInsertId();
        if (!$minuteId) {
            $stmtId = $pdo->prepare("SELECT id FROM meeting_minutes WHERE month = ? AND year = ? AND session_number = ?");
            $stmtId->execute([$month, $year, $session_num]);
            $minuteId = $stmtId->fetchColumn();
        }
        $updateLoan = $pdo->prepare("UPDATE deductions SET included_in_minute_id = ? WHERE id = ?");
        foreach ($selected_loans as $loan_id) {
            $updateLoan->execute([$minuteId, $loan_id]);
        }
    }

    // حفظ الشيكات المختارة
    $selected_cheques = isset($_POST['selected_cheques']) ? $_POST['selected_cheques'] : [];
    if (!empty($selected_cheques)) {
        if (!isset($minuteId)) {
            $stmtId = $pdo->prepare("SELECT id FROM meeting_minutes WHERE month = ? AND year = ? AND session_number = ?");
            $stmtId->execute([$month, $year, $session_num]);
            $minuteId = $stmtId->fetchColumn();
        }
        $updateCheque = $pdo->prepare("UPDATE source_payments SET included_in_minute_id = ? WHERE id = ?");
        foreach ($selected_cheques as $cheque_id) {
            $updateCheque->execute([$minuteId, $cheque_id]);
        }
    }

    $_SESSION['toast'] = ['message' => $message, 'type' => 'success', 'duration' => 3000];
    ob_end_clean();
    header("Location: meeting_minutes.php?month=$month&year=$year&session_number=$session_num");
    exit;
}

// قائمة الجلسات الموجودة
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
    .grants-table, .loans-table, .cheques-table { width: 100%; border-collapse: collapse; margin-top: 10px; margin-bottom: 20px; }
    .grants-table th, .loans-table th, .cheques-table th { padding: 8px; text-align: center; border: 1px solid #ddd; }
    .grants-table td, .loans-table td, .cheques-table td { border: 1px solid #ddd; padding: 8px; text-align: center; }
    .grants-table th { background: #28a745; color: white; }
    .loans-table th { background: #ff9800; color: white; }
    .cheques-table th { background: #17a2b8; color: white; }
    .info-box { background: #e3f2fd; padding: 15px; border-radius: 10px; margin-bottom: 20px; border-right: 5px solid #2196f3; }
    .btn-save { background: #2a5298; color: white; padding: 12px 25px; border: none; border-radius: 8px; cursor: pointer; font-size: 16px; }
    .print-btn { background: #17a2b8; color: white; padding: 12px 25px; border: none; border-radius: 8px; cursor: pointer; text-decoration: none; display: inline-block; }
    .section-title { font-size: 18px; font-weight: bold; margin-top: 20px; margin-bottom: 10px; border-right: 4px solid #2a5298; padding-right: 10px; }
</style>

<div class="minutes-container">
    <h2>📝 تحرير المحضر الشهري للجنة</h2>
    
    <div class="filters">
        <form method="GET">
            <select name="month">
                <?php for($m=1;$m<=12;$m++): ?>
                    <option value="<?=$m?>" <?=$m==$month?'selected':''?>><?=date('F', mktime(0,0,0,$m,1))?></option>
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

    <?php if(isset($_SESSION['toast'])): ?>
        <div style="background:#d4edda; padding:10px; border-radius:8px; margin-bottom:15px;"><?= htmlspecialchars($_SESSION['toast']['message']) ?></div>
        <?php unset($_SESSION['toast']); ?>
    <?php endif; ?>

    <div class="info-box">
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
                        <?= safeFormatDate($draw['draw_date']) ?> - 
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
        </div>

        <div class="form-group">
            <label>🧾 إدراج الشيكات المدفوعة للمصادر (اختياري):</label>
            <label style="font-weight: normal;">
                <input type="checkbox" name="show_cheques" value="1" <?= ($show_cheques ? 'checked' : '') ?>> إظهار قائمة الشيكات المتاحة للاختيار
            </label>
        </div>

        <div class="form-group">
            <label>📱 إدراج إجمالي الاقتطاعات الشهرية لجيزي:</label>
            <label style="font-weight: normal;">
                <input type="checkbox" name="show_djezzy" value="1" <?= ($show_djezzy ? 'checked' : '') ?>> عرض مبلغ جيزي الشهري
            </label>
        </div>

        <!-- ========== جدول المنح العادية ========== -->
        <div class="section-title">🎁 المنح المقدمة في هذا الشهر</div>
        <?php if(empty($grants) && empty($meal_grants)): ?>
            <p style="color:gray;">⚠️ لا توجد منح مسجلة في هذا الشهر.</p>
        <?php else: ?>
            <?php if(!empty($grants)): ?>
            <table class="grants-table">
                <thead><tr><th>#</th><th>الموظف</th><th>نوع المنحة</th><th>المبلغ (دج)</th><th>تاريخ المنح</th><th>السبب</th></tr></thead>
                <tbody>
                    <?php $i=1; foreach($grants as $g): ?>
                    <tr>
                        <td><?= $i++ ?></td>
                        <td><?= htmlspecialchars($g['employee_name']) ?><br><small style="font-size:9pt; color:#555; font-weight:bold;">حساب: <?= htmlspecialchars($g['account_number'] ?? '—') ?></small></td>
                        <td><?= htmlspecialchars($g['grant_name']) ?></td>
                        <td><?= number_format($g['amount'], 2) ?> دج</td>
                        <td><?= safeFormatDate($g['grant_date']) ?></td>
                        <td><?= htmlspecialchars($g['notes'] ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot><tr class="total-row"><td colspan="3"><strong>إجمالي المنح</strong></td><td colspan="3"><strong><?= number_format($totalGrants, 2) ?> دج</strong></td></tr></tfoot>
            </table>
            <?php endif; ?>

            <!-- ========== جدول منح وجبات المطعم ========== -->
            <?php if(!empty($meal_grants)): ?>
            <div class="section-title">🍽️ منح وجبات المطعم (نصف القيمة)</div>
            <table class="grants-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>الموظف</th>
                        <th>الفئة</th>
                        <th>عدد الوجبات</th>
                        <th>القيمة الإجمالية (دج)</th>
                        <th>منحة اللجنة (دج)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $i=1; foreach ($meal_grants as $mg): ?>
                    <tr>
                        <td><?= $i++ ?></td>
                        <td><?= htmlspecialchars($mg['employee_name']) ?><br><small style="font-size:9pt; color:#555; font-weight:bold;">حساب: <?= htmlspecialchars($mg['account_number'] ?? '—') ?></small></td>
                        <td><?= $mg['category'] == 'Permanent' ? 'دائم' : 'متعاقد' ?></td>
                        <td><?= $mg['total_meals'] ?></td>
                        <td><?= number_format($mg['total_amount'], 2) ?> دج</td>
                        <td><?= number_format($mg['grant_amount'], 2) ?> دج</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="total-row">
                        <td colspan="4"><strong>الإجمالي</strong></td>
                        <td><strong><?= number_format($totalMealGrants, 2) ?> دج</strong></td>
                        <td><strong><?= number_format($totalMealGrants, 2) ?> دج</strong></td>
                    </tr>
                </tfoot>
            </table>
            <?php endif; ?>
        <?php endif; ?>

        <!-- ========== جدول السلف ========== -->
        <div class="section-title">💸 السلف الممنوحة (اختر التي تظهر في هذا المحضر)</div>
        <?php if(empty($loans)): ?>
            <p style="color:gray;">⚠️ لا توجد سلف جديدة قابلة للإضافة (جميع السلف تم عرضها سابقاً).</p>
        <?php else: ?>
            <table class="loans-table">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="select_all_loans"></th>
                        <th>#</th>
                        <th>الموظف</th>
                        <th>نوع السلفة</th>
                        <th>المبلغ الكلي (دج)</th>
                        <th>تاريخ الصرف</th>
                        <th>تاريخ بداية الاقتطاع</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $i=1; foreach($loans as $l): ?>
                    <tr>
                        <td><input type="checkbox" name="selected_loans[]" value="<?= $l['id'] ?>"></td>
                        <td><?= $i++ ?></td>
                        <td><?= htmlspecialchars($l['employee_name']) ?><br><small style="font-size:9pt; color:#555; font-weight:bold;">حساب: <?= htmlspecialchars($mg['account_number'] ?? '—') ?></small></td>
                        <td><?= htmlspecialchars($l['source_name']) ?></td>
                        <td><?= number_format($l['total_amount'], 2) ?> دج</td>
                        <td><?= safeFormatDate($l['grant_date']) ?></td>
                        <td><?= safeFormatDate($l['start_date']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <small>✅ اختر السلف التي تريد إدراجها في هذا المحضر. السلف المحددة مسبقاً في محاضر أخرى لن تظهر هنا.</small>
        <?php endif; ?>

        <!-- ========== جدول الشيكات ========== -->
        <?php if ($show_cheques): ?>
            <div class="section-title">🧾 الشيكات المدفوعة للمصادر (اختر التي تظهر في هذا المحضر)</div>
            <?php if(empty($cheques)): ?>
                <p style="color:gray;">⚠️ لا توجد شيكات جديدة قابلة للإضافة (جميع الشيكات تم عرضها سابقاً أو لا توجد شيكات في هذا الشهر).</p>
            <?php else: ?>
                <table class="cheques-table">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="select_all_cheques"></th>
                            <th>#</th>
                            <th>المصدر</th>
                            <th>رقم الشيك</th>
                            <th>التاريخ</th>
                            <th>الربع</th>
                            <th>المبلغ (دج)</th>
                            <th>ملاحظات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i=1; foreach($cheques as $ch): ?>
                        <tr>
                            <td><input type="checkbox" name="selected_cheques[]" value="<?= $ch['id'] ?>"></td>
                            <td><?= $i++ ?></td>
                            <td><?= htmlspecialchars($ch['source_name']) ?></td>
                            <td><?= htmlspecialchars($ch['cheque_number'] ?? '-') ?></td>
                            <td><?= safeFormatDate($ch['cheque_date']) ?></td>
                            <td><?= $ch['quarter'] ? 'الربع '.$ch['quarter'] : '---' ?></td>
                            <td><?= number_format($ch['amount'], 2) ?> دج</td>
                            <td><?= htmlspecialchars($ch['notes'] ?? '-') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <small>✅ اختر الشيكات التي تريد إدراجها في هذا المحضر. الشيكات المحددة مسبقاً في محاضر أخرى لن تظهر هنا.</small>
            <?php endif; ?>
        <?php endif; ?>

        <!-- ========== جدول المكرمين ========== -->
        <?php if (!empty($honorees)): ?>
        <div class="section-title">🎖️ المكرمون في عيد العمال (سنة <?= $minute['honorees_year'] ?>)</div>
        <table class="grants-table">
            <thead><tr><th>#</th><th>الموظف</th><th>نوع الجائزة</th><th>القيمة (دج)</th><th>تاريخ التكريم</th><th>سبب التكريم</th></tr></thead>
            <tbody>
                <?php $i=1; foreach($honorees as $h): ?>
                <tr>
                    <td><?= $i++ ?></td>
                    <td><?= htmlspecialchars($h['employee_name']) ?><br><small>(<?= $h['category'] == 'Permanent' ? 'دائم' : 'متعاقد' ?>)</small></td>
                    <td><?= htmlspecialchars($h['prize_type']) ?></td>
                    <td><?= number_format($h['prize_value'], 2) ?> دج</td>
                    <td><?= safeFormatDate($h['honor_date']) ?></td>
                    <td><?= htmlspecialchars($h['reason']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot><tr class="total-row"><td colspan="2"><strong>الإجمالي</strong></td><td colspan="4"><strong><?= number_format($totalHonorValue, 2) ?> دج</strong></td></tr></tfoot>
        </table>
        <?php endif; ?>

        <!-- ========== نص المحضر ========== -->
        <div class="form-group">
            <label>✍️ نص المحضر:</label>
            <textarea name="content" rows="15" style="font-family: monospace; line-height: 1.5;"><?= htmlspecialchars($minute['content'] ?? "محضر جلسة اللجنة الاجتماعية\nالتاريخ: " . date('d/m/Y') . "\n\nبعد المناقشة والاطلاع على تقارير المنح، تقرر ما يلي:\n\n1. الموافقة على المنح المقدمة لهذا الشهر بمبلغ إجمالي قدره " . number_format($totalGrants, 2) . " دج.\n" . ($show_djezzy ? "2. إجمالي الاقتطاعات الشهرية لجيزي: " . number_format($djezzy_monthly_total, 2) . " دج.\n" : "2. لم يتم تضمين بند جيزي في هذا المحضر.\n") . "3. تسديد مستحقات سعدين للتجهير: " . number_format($saadine_paid, 2) . " دج.\n" . ($show_tri_total ? "4. إجمالي الاقتطاع الثلاثي لسعدين للتجهير: " . number_format($saadine_tri_total, 2) . " دج.\n" : "") . "\n\nالتوقيعات:\nرئيس اللجنة: __________\nالمقرر: __________\nأمين الصندوق: __________") ?></textarea>
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

<script>
    document.getElementById('select_all_loans')?.addEventListener('change', function(e) {
        let checkboxes = document.querySelectorAll('input[name="selected_loans[]"]');
        checkboxes.forEach(cb => cb.checked = e.target.checked);
    });
    document.getElementById('select_all_cheques')?.addEventListener('change', function(e) {
        let checkboxes = document.querySelectorAll('input[name="selected_cheques[]"]');
        checkboxes.forEach(cb => cb.checked = e.target.checked);
    });
</script>

<?php include '../includes/footer.php'; ?>