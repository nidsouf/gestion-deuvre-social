<?php
session_start();
require_once __DIR__ . '/includes/auth_check.php';
require_once 'config/database.php';
require_once 'includes/functions.php';
include 'includes/header.php';

// معالجة طلبات الإشعارات (تحديد كمقروء)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['mark_read'])) {
        $id = (int)$_POST['mark_read'];
        $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND (user_id IS NULL OR user_id = ?)")->execute([$id, $_SESSION['user_id']]);
        header("Location: index.php?tab=notifications");
        exit;
    }
    if (isset($_POST['mark_all_read'])) {
        $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id IS NULL OR user_id = ?")->execute([$_SESSION['user_id']]);
        header("Location: index.php?tab=notifications");
        exit;
    }
}

// ========== إصلاح جدول الإشعارات (إضافة عمود title إذا لم يكن موجوداً) ==========
$cols = $pdo->query("PRAGMA table_info(notifications)")->fetchAll(PDO::FETCH_COLUMN, 1);
if (!in_array('title', $cols)) {
    $pdo->exec("ALTER TABLE notifications ADD COLUMN title TEXT DEFAULT ''");
}
if (!in_array('type', $cols)) {
    $pdo->exec("ALTER TABLE notifications ADD COLUMN type TEXT DEFAULT 'info'");
}
if (!in_array('is_read', $cols)) {
    $pdo->exec("ALTER TABLE notifications ADD COLUMN is_read INTEGER DEFAULT 0");
}

// ========== السنة المحددة من الفلتر ==========
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

// ========== الإحصائيات الأساسية (لا تعتمد على السنة) ==========
$statsRow = $pdo->query("
    SELECT
        (SELECT COUNT(*) FROM employees)                                        AS total_employees,
        (SELECT COUNT(*) FROM sources)                                          AS total_sources,
        (SELECT COUNT(*) FROM deductions)                                       AS total_deductions,
        (SELECT COUNT(eg.id) FROM employee_grants eg)                           AS total_grants,
        (SELECT COUNT(*) FROM deductions WHERE is_loan = 1 AND end_date >= date('now')) AS active_loans,
        (SELECT COALESCE(AVG(monthly_amount * total_months), 0) FROM deductions WHERE start_date >= date('now', '-1 year')) AS avg_monthly_deduction,
        (SELECT COALESCE(SUM(g.amount), 0) FROM employee_grants eg JOIN grants g ON eg.grant_id = g.id WHERE strftime('%Y', eg.grant_date) = strftime('%Y', 'now')) AS total_grants_this_year
")->fetch(PDO::FETCH_ASSOC);

$totalEmployees        = $statsRow['total_employees'];
$totalSources          = $statsRow['total_sources'];
$totalDeductions       = $statsRow['total_deductions'];
$totalGrants           = $statsRow['total_grants'];
$activeLoans           = $statsRow['active_loans'];
$avgMonthlyDeduction   = $statsRow['avg_monthly_deduction'];
$totalGrantsThisYear   = $statsRow['total_grants_this_year'];

// إجمالي الاقتطاعات الشهرية العادية (النشطة حالياً)
$stmt = $pdo->query("SELECT COALESCE(SUM(monthly_amount), 0) FROM deductions WHERE end_date >= date('now')");
$totalRegularMonthly = $stmt->fetchColumn();

// إجمالي اقتطاعات جيزي
$stmt = $pdo->query("SELECT COALESCE(SUM(monthly_amount), 0) FROM employee_phone_numbers WHERE is_active = 1");
$totalDjezy = $stmt->fetchColumn();
$totalMonthlyAll = $totalRegularMonthly + $totalDjezy;

// ========== حساب إجمالي المبالغ المقتطعة خلال السنة المحددة ==========
$totalAmountForYear = 0;
$yearStart = $year . '-01-01';
$yearEnd   = $year . '-12-31';

$stmt = $pdo->prepare("
    SELECT monthly_amount, start_date, end_date
    FROM deductions
    WHERE start_date <= :end_date AND end_date >= :start_date
");
$stmt->execute([':start_date' => $yearStart, ':end_date' => $yearEnd]);
$deductionsForYear = $stmt->fetchAll(PDO::FETCH_ASSOC);

$yearStartDT = new DateTime($yearStart);
$yearEndDT   = new DateTime($yearEnd);

foreach ($deductionsForYear as $ded) {
    $monthly = (float)$ded['monthly_amount'];
    $dedStart = new DateTime($ded['start_date']);
    $dedEnd   = new DateTime($ded['end_date']);
    
    $overlapStart = max($yearStartDT, $dedStart);
    $overlapEnd   = min($yearEndDT, $dedEnd);
    
    if ($overlapStart <= $overlapEnd) {
        $diff = $overlapStart->diff($overlapEnd);
        $months = ($diff->y * 12) + $diff->m + 1;
        if ($months < 0) $months = 0;
        $totalAmountForYear += $monthly * $months;
    }
}

// ========== حساب الميزانية المتبقية ==========
$budgetRow = $pdo->query("
    SELECT COALESCE((SELECT remaining_budget FROM social_budget ORDER BY year DESC LIMIT 1), 0) AS remaining_budget
")->fetch(PDO::FETCH_ASSOC);
$remainingBudget = (float)$budgetRow['remaining_budget'];

$initialBudget = $pdo->query("SELECT initial_budget FROM social_budget ORDER BY year DESC LIMIT 1")->fetchColumn();
$initialBudget = $initialBudget ?: 1;
$spentPercent = min(100, round((($initialBudget - $remainingBudget) / $initialBudget) * 100));

// ========== بيانات الرسوم البيانية ==========
$monthlyDeductions = $pdo->prepare("
    SELECT CAST(strftime('%m', start_date) AS INTEGER) AS month,
           COALESCE(SUM(monthly_amount * total_months), 0) AS total
    FROM deductions
    WHERE strftime('%Y', start_date) = :year
    GROUP BY month
    ORDER BY month
");
$monthlyDeductions->execute([':year' => (string)$year]);
$monthlyDeductions = $monthlyDeductions->fetchAll(PDO::FETCH_ASSOC);

$months = [];
$monthlyTotals = [];
$arabicMonths = ['يناير','فبراير','مارس','أبريل','مايو','يونيو',
                 'يوليو','أغسطس','سبتمبر','أكتوبر','نوفمبر','ديسمبر'];
for ($i = 1; $i <= 12; $i++) {
    $months[] = $arabicMonths[$i - 1];
    $found = false;
    foreach ($monthlyDeductions as $d) {
        if ((int)$d['month'] === $i) {
            $monthlyTotals[] = (float)$d['total'];
            $found = true;
            break;
        }
    }
    if (!$found) $monthlyTotals[] = 0;
}

$sourceDeductions = $pdo->query("
    SELECT s.name, COALESCE(SUM(d.monthly_amount * d.total_months), 0) AS total
    FROM deductions d
    JOIN sources s ON d.source_id = s.id
    GROUP BY s.id, s.name
    ORDER BY total DESC
")->fetchAll(PDO::FETCH_ASSOC);

$topEmployees = $pdo->query("
    SELECT e.name, COALESCE(SUM(d.monthly_amount * d.total_months), 0) AS total
    FROM deductions d
    JOIN employees e ON d.employee_id = e.id
    GROUP BY e.id, e.name
    ORDER BY total DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

$recentTransactions = $pdo->query("
    SELECT bt.*,
           CASE bt.type
               WHEN 'grant'       THEN 'منحة'
               WHEN 'loan'        THEN 'سلفة'
               WHEN 'installment' THEN 'قسط مردود'
               ELSE bt.type
           END AS type_ar,
           CASE bt.type
               WHEN 'grant'       THEN 'badge-grant'
               WHEN 'loan'        THEN 'badge-loan'
               WHEN 'installment' THEN 'badge-installment'
               ELSE 'badge-default'
           END AS type_class
    FROM budget_transactions bt
    ORDER BY bt.transaction_date DESC
    LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

// آخر المحاضر
$recentMinutes = $pdo->query("
    SELECT meeting_date, meeting_number, content 
    FROM meeting_minutes 
    ORDER BY meeting_date DESC 
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// ========== الإشعارات (بعد التأكد من وجود الأعمدة) ==========
$stmtNotif = $pdo->prepare("SELECT id, title, message, type, is_read, created_at FROM notifications WHERE user_id IS NULL OR user_id = ? ORDER BY created_at DESC LIMIT 10");
$stmtNotif->execute([$_SESSION['user_id']]);
$notificationsList = $stmtNotif->fetchAll();

$availableYears = $pdo->query("
    SELECT DISTINCT CAST(strftime('%Y', start_date) AS INTEGER) AS y
    FROM deductions
    ORDER BY y DESC
")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array($year, $availableYears)) {
    $availableYears[] = $year;
    rsort($availableYears);
}

// التبويب النشط (جعل "nav" هو الافتراضي)
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'nav';
?>

<style>
    /* ========== أنماط التبويبات ========== */
    .dashboard-tabs {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-bottom: 25px;
        background: white;
        border-radius: 20px;
        padding: 12px 20px;
        box-shadow: 0 2px 12px rgba(0,0,0,0.05);
    }
    .tab-btn {
        padding: 8px 20px;
        border: none;
        background: #f1f5f9;
        border-radius: 40px;
        cursor: pointer;
        font-size: 14px;
        font-weight: 600;
        transition: all 0.3s ease;
        color: #334155;
    }
    .tab-btn:hover {
        background: #e2e8f0;
        transform: translateY(-2px);
    }
    .tab-btn.active {
        background: #2a5298;
        color: white;
        box-shadow: 0 4px 12px rgba(42,82,152,0.3);
    }
    .tab-content {
        display: none;
    }
    .tab-content.active {
        display: block;
    }
    
    /* أنماط الأزرار السريعة (لتبويب التنقل) */
    .quick-links-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
        gap: 16px;
        margin-top: 20px;
    }
    .nav-card {
        background: white;
        border-radius: 20px;
        padding: 20px;
        text-align: center;
        box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        transition: all 0.3s;
        text-decoration: none;
        color: #1e293b;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 10px;
        border: 1px solid #e2e8f0;
    }
    .nav-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 20px rgba(0,0,0,0.1);
        border-color: #2a5298;
    }
    .nav-card i {
        font-size: 32px;
        color: #2a5298;
    }
    .nav-card span {
        font-weight: 600;
        font-size: 15px;
    }
    
    /* أنماط الإحصائيات */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }
    .stat-card {
        background: white;
        border-radius: 20px;
        padding: 20px;
        text-align: center;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        transition: all 0.3s ease;
        border-bottom: 3px solid;
    }
    .stat-card:hover { transform: translateY(-5px); }
    .stat-card .icon { font-size: 32px; margin-bottom: 8px; display: block; }
    .stat-card .number { font-size: 28px; font-weight: 700; margin: 8px 0; }
    .stat-card small { color: #999; font-size: 12px; }
    
    /* أنماط الرسوم البيانية */
    .chart-container {
        background: white;
        padding: 25px;
        border-radius: 20px;
        margin-bottom: 25px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    }
    .chart-container h4 { text-align: center; margin-bottom: 20px; color: #333; }
    .charts-row { display: flex; flex-wrap: wrap; gap: 20px; margin-bottom: 25px; }
    .charts-row .chart-container { flex: 1; min-width: 280px; }
    
    /* أنماط الجداول */
    .data-table {
        width: 100%;
        border-collapse: collapse;
        background: white;
    }
    .data-table th, .data-table td {
        padding: 12px;
        border: 1px solid #ddd;
        text-align: center;
    }
    .data-table th { background: #2a5298; color: white; }
    .badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 12px; }
    .badge-grant { background: #fff3e0; color: #e65100; }
    .badge-loan { background: #e8f5e9; color: #2e7d32; }
    .badge-installment { background: #e3f2fd; color: #1565c0; }
    
    .section-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        flex-wrap: wrap;
        gap: 10px;
    }
    .btn-sm {
        background: #2a5298;
        color: white;
        padding: 6px 16px;
        border-radius: 20px;
        text-decoration: none;
        font-size: 12px;
    }
    .year-filter {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        background: white;
        padding: 5px 15px;
        border-radius: 30px;
    }
    .year-filter select {
        padding: 6px 12px;
        border-radius: 20px;
        border: 1px solid #ddd;
    }
    .notification-item {
        transition: background 0.2s;
    }
    .notification-item:hover {
        background: #f1f5f9;
    }
</style>

<div class="section">
    <div class="section-header">
        <h2>📊 لوحة التحكم الرئيسية</h2>
        <div style="display: flex; gap: 15px; align-items: center;">
            <form method="GET" class="year-filter">
                <label>📅 السنة:</label>
                <select name="year" onchange="this.form.submit()">
                    <?php foreach ($availableYears as $y): ?>
                        <option value="<?= (int)$y ?>" <?= $y == $year ? 'selected' : '' ?>><?= (int)$y ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" name="tab" value="<?= $active_tab ?>">
            </form>
        </div>
    </div>

    <!-- ========== التبويبات ========== -->
    <div class="dashboard-tabs">
        <button class="tab-btn <?= $active_tab == 'nav' ? 'active' : '' ?>" data-tab="nav">🧭 أزرار التنقل</button>
        <button class="tab-btn <?= $active_tab == 'overview' ? 'active' : '' ?>" data-tab="overview">📊 نظرة عامة</button>
        <button class="tab-btn <?= $active_tab == 'charts' ? 'active' : '' ?>" data-tab="charts">📈 الرسوم البيانية</button>
        <button class="tab-btn <?= $active_tab == 'transactions' ? 'active' : '' ?>" data-tab="transactions">📋 آخر المعاملات</button>
        <button class="tab-btn <?= $active_tab == 'minutes' ? 'active' : '' ?>" data-tab="minutes">📜 آخر المحاضر</button>
        <button class="tab-btn <?= $active_tab == 'notifications' ? 'active' : '' ?>" data-tab="notifications">🔔 الإشعارات</button>
    </div>

    <!-- ========== تبويب 1: أزرار التنقل ========== -->
    <div id="tab-nav" class="tab-content <?= $active_tab == 'nav' ? 'active' : '' ?>">
        <div class="quick-links-grid">
            <a href="employees/list.php" class="nav-card"><i class="fas fa-users"></i><span>الموظفون</span></a>
            <a href="deductions/list.php" class="nav-card"><i class="fas fa-hand-holding-usd"></i><span>الاقتطاعات</span></a>
            <a href="grants/list.php" class="nav-card"><i class="fas fa-gift"></i><span>المنح</span></a>
            <a href="sources/list.php" class="nav-card"><i class="fas fa-database"></i><span>المصادر</span></a>
            <a href="budget/dashboard.php" class="nav-card"><i class="fas fa-chart-pie"></i><span>الميزانية</span></a>
            <a href="reports/monthly.php" class="nav-card"><i class="fas fa-calendar-alt"></i><span>التقرير الشهري</span></a>
            <a href="reports/quarterly.php" class="nav-card"><i class="fas fa-chart-line"></i><span>التقرير الثلاثي</span></a>
            <a href="reports/annual.php" class="nav-card"><i class="fas fa-chart-line"></i><span>التقرير السنوي</span></a>
            <a href="meals/index.php" class="nav-card"><i class="fas fa-utensils"></i><span>وجبات المطعم</span></a>
            <a href="umrah/draw_list.php" class="nav-card"><i class="fas fa-mosque"></i><span>سحب العمرة</span></a>
            <a href="honors/index.php" class="nav-card"><i class="fas fa-trophy"></i><span>عيد العمال</span></a>
            <a href="regulations.php" class="nav-card"><i class="fas fa-book"></i><span>القوانين الداخلية</span></a>
            <a href="backup.php" class="nav-card"><i class="fas fa-database"></i><span>النسخ الاحتياطي</span></a>
            <a href="settings.php" class="nav-card"><i class="fas fa-sliders-h"></i><span>الإعدادات</span></a>
        </div>
        <!-- بطاقة تسيير الشيكات -->
<div class="dashboard-card" style="background: linear-gradient(135deg, #20c997, #0f9d76);">
    <div class="card-icon"><i class="fas fa-money-bill-wave"></i></div>
    <div class="card-title">💵 تسيير الشيكات</div>
    <div class="card-value">
        <?php
        // إحصائيات سريعة: عدد الشيكات في السنة الحالية
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM source_payments WHERE strftime('%Y', cheque_date) = ?");
        $stmt->execute([date('Y')]);
        $count = $stmt->fetchColumn();
        echo $count . ' شيك / ' . date('Y');
        ?>
    </div>
    <a href="/payments/list.php" class="card-link">عرض القائمة <i class="fas fa-arrow-left"></i></a>
</div>
    </div>

    <!-- ========== تبويب 2: نظرة عامة ========== -->
    <div id="tab-overview" class="tab-content <?= $active_tab == 'overview' ? 'active' : '' ?>">
        <div class="stats-grid">
            <div class="stat-card employees" style="border-bottom-color: #2a5298;"><span class="icon">👥</span><h3>الموظفون</h3><div class="number"><?= number_format($totalEmployees) ?></div></div>
            <div class="stat-card sources" style="border-bottom-color: #f39fb4;"><span class="icon">🏦</span><h3>المصادر</h3><div class="number"><?= number_format($totalSources) ?></div></div>
            <div class="stat-card deductions" style="border-bottom-color: #28a745;"><span class="icon">📋</span><h3>الاقتطاعات</h3><div class="number"><?= number_format($totalDeductions) ?></div></div>
            <div class="stat-card amount" style="border-bottom-color: #9c27b0;"><span class="icon">💰</span><h3>إجمالي المبالغ المقتطعة</h3><div class="number"><?= number_format($totalAmountForYear, 2) ?> <small>دج</small></div><small>للسنة <?= $year ?></small></div>
            <div class="stat-card grants" style="border-bottom-color: #dc3545;"><span class="icon">🎁</span><h3>منح الموظفين</h3><div class="number"><?= number_format($totalGrants) ?></div><small>عدد المنح المسجلة</small></div>
            <div class="stat-card budget" style="border-bottom-color: #00bcd4;"><span class="icon">📊</span><h3>الميزانية المتبقية</h3><div class="number" style="color: <?= $remainingBudget >= 0 ? '#00897b' : '#e53935' ?>"><?= number_format($remainingBudget, 2) ?> <small>دج</small></div>
                <div class="budget-bar-wrap" style="background:#f0f0f0; border-radius:50px; height:8px; margin-top:10px;"><div class="budget-bar-fill" style="width: <?= $spentPercent ?>%; height:100%; border-radius:50px; background: <?= $spentPercent < 70 ? '#00bcd4' : ($spentPercent < 90 ? '#ff9800' : '#f44336') ?>;"></div></div>
                <small>تم إنفاق <?= $spentPercent ?>% من الميزانية</small>
            </div>
        </div>
        <div class="stats-grid" style="margin-bottom: 40px;">
            <div class="stat-card year-grant" style="border-bottom-color: #4caf50;"><span class="icon">🗓️</span><h3>منح هذا العام</h3><div class="number"><?= number_format($totalGrantsThisYear, 2) ?> <small>دج</small></div></div>
            <div class="stat-card loans" style="border-bottom-color: #d65283;"><span class="icon">💳</span><h3>سلف نشطة</h3><div class="number"><?= number_format($activeLoans) ?></div></div>
            <div class="stat-card avg" style="border-bottom-color: #607d8b;"><span class="icon">📉</span><h3>متوسط الاقتطاع السنوي</h3><div class="number"><?= number_format($avgMonthlyDeduction, 2) ?> <small>دج</small></div></div>
            <div class="stat-card djezy" style="border-bottom-color: #f8b353;"><span class="icon">📱</span><h3>اقتطاعات جيزي (Djezy)</h3><div class="number"><?= number_format($totalDjezy, 2) ?> <small>دج</small></div><small>شهرياً</small></div>
            <div class="stat-card total-monthly" style="border-bottom-color: #9c27b0;"><span class="icon">💰</span><h3>إجمالي الاقتطاعات الشهرية</h3><div class="number"><?= number_format($totalMonthlyAll, 2) ?> <small>دج</small></div><small>(عادي + جيزي)</small></div>
        </div>
        <!-- بطاقة الاختبارات في لوحة التحكم -->
<div style="background: linear-gradient(135deg, #6f42c1, #59359a); border-radius: 20px; padding: 20px; color: white; text-align: center;">
    <div style="font-size: 40px; margin-bottom: 10px;">🧪</div>
    <div style="font-size: 16px; opacity: 0.9;">الاختبارات</div>
    <div style="font-size: 24px; font-weight: bold; margin: 10px 0;">
        <?php
        $lastResults = $_SESSION['last_test_results'] ?? null;
        if ($lastResults) {
            $total = $lastResults['tests'] ?? 0;
            $failed = ($lastResults['failures'] ?? 0) + ($lastResults['errors'] ?? 0);
            echo $failed == 0 ? "✅ {$total} نجاح" : "⚠️ {$failed} فشل";
        } else {
            echo "⏳ لم يتم التشغيل";
        }
        ?>
    </div>
    <a href="tests/run_tests.php" style="color: rgba(255,255,255,0.8); text-decoration: none;">عرض التفاصيل 🧪</a>
</div>
    </div>

    <!-- ========== تبويب 3: الرسوم البيانية ========== -->
    <div id="tab-charts" class="tab-content <?= $active_tab == 'charts' ? 'active' : '' ?>">
        <div class="charts-row">
            <div class="chart-container"><h4>📈 الاقتطاعات الشهرية – <?= (int)$year ?></h4><?php if (array_sum($monthlyTotals) > 0): ?><canvas id="monthlyChart" style="height: 300px;"></canvas><?php else: ?><div class="no-data" style="text-align:center; padding:50px;">لا توجد بيانات</div><?php endif; ?></div>
            <div class="chart-container"><h4>🥧 توزيع الاقتطاعات حسب المصدر</h4><?php if (!empty($sourceDeductions)): ?><canvas id="sourceChart" style="height: 300px;"></canvas><?php else: ?><div class="no-data" style="text-align:center; padding:50px;">لا توجد بيانات</div><?php endif; ?></div>
        </div>
        <div class="chart-container"><h4>🏆 أعلى 5 موظفين في الاقتطاعات</h4><?php if (!empty($topEmployees)): ?><canvas id="topEmployeesChart" style="height: 300px;"></canvas><?php else: ?><div class="no-data" style="text-align:center; padding:50px;">لا توجد بيانات</div><?php endif; ?></div>
    </div>

    <!-- ========== تبويب 4: آخر المعاملات ========== -->
    <div id="tab-transactions" class="tab-content <?= $active_tab == 'transactions' ? 'active' : '' ?>">
        <div class="section">
            <div class="section-header"><h3>🧾 آخر المعاملات على الميزانية</h3><a href="budget/report.php" class="btn-sm">عرض الكل ←</a></div>
            <div style="overflow-x:auto;"><table class="data-table"><thead><tr><th>التاريخ</th><th>النوع</th><th>الوصف</th><th>المبلغ</th></tr></thead>
            <tbody><?php if (!empty($recentTransactions)): foreach ($recentTransactions as $trans): ?><td><?= date('d/m/Y H:i', strtotime($trans['transaction_date'])) ?></td><td><span class="badge <?= htmlspecialchars($trans['type_class']) ?>"><?= htmlspecialchars($trans['type_ar']) ?></span></td><td><?= htmlspecialchars($trans['description'] ?? '—') ?></td><td><?= number_format((float)$trans['amount'], 2) ?> دج</td></tr><?php endforeach; else: ?><td><td colspan="4"><div class="no-data">لا توجد معاملات حديثة</div></td></tr><?php endif; ?></tbody></table></div>
        </div>
    </div>

    <!-- ========== تبويب 5: آخر المحاضر ========== -->
    <div id="tab-minutes" class="tab-content <?= $active_tab == 'minutes' ? 'active' : '' ?>">
        <div class="section">
            <div class="section-header"><h3>📋 آخر المحاضر</h3><a href="reports/meeting_minutes.php" class="btn-sm">عرض الكل ←</a></div>
            <?php if (!empty($recentMinutes)): ?>
                <div style="overflow-x:auto;"><table class="data-table"><thead><tr><th>التاريخ</th><th>رقم الجلسة</th><th>المحتوى</th></tr></thead>
                <tbody><?php foreach ($recentMinutes as $minute): ?><td><?= date('d/m/Y', strtotime($minute['meeting_date'])) ?></td><td><?= htmlspecialchars($minute['meeting_number'] ?? '-') ?></td><td><?= substr(htmlspecialchars($minute['content'] ?? ''), 0, 100) ?>...</td></tr><?php endforeach; ?></tbody></table></div>
            <?php else: ?><div class="no-data">لا توجد محاضر مسجلة بعد</div><?php endif; ?>
        </div>
    </div>

    <!-- ========== تبويب 6: الإشعارات ========== -->
    <div id="tab-notifications" class="tab-content <?= $active_tab == 'notifications' ? 'active' : '' ?>">
    <div class="section">
        <div class="section-header">
            <h3>🔔 إشعارات النظام</h3>
            <?php if ($notificationsList): ?>
                <button id="markAllReadBtn" class="btn-sm" style="background:#28a745;">تحديد الكل كمقروء</button>
            <?php endif; ?>
        </div>
        <div id="notifications-list">
            <?php if (empty($notificationsList)): ?>
                <div class="no-data" style="text-align:center; padding:50px;">لا توجد إشعارات جديدة</div>
            <?php else: ?>
                <div style="max-height:400px; overflow-y:auto;">
                    <?php foreach ($notificationsList as $notif): ?>
                        <div class="notification-item" data-id="<?= $notif['id'] ?>" style="padding:15px; border-bottom:1px solid #eee; <?= $notif['is_read'] ? '' : 'background:#e8f5e9;' ?>">
                            <div><strong><?= htmlspecialchars($notif['title']) ?></strong> <small style="color:#888;"><?= date('d/m/Y H:i', strtotime($notif['created_at'])) ?></small></div>
                            <div><?= htmlspecialchars($notif['message']) ?></div>
                            <?php if (!$notif['is_read']): ?>
                                <button class="mark-read-btn btn-sm" data-id="<?= $notif['id'] ?>" style="background:#17a2b8; margin-top:8px;">تحديد كمقروء</button>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// التعامل مع تحديث إشعار واحد
document.querySelectorAll('.mark-read-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        let id = this.dataset.id;
        let itemDiv = this.closest('.notification-item');
        fetch(`notification.php?mark_read=${id}`)
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    itemDiv.style.background = '';
                    btn.remove(); // إزالة الزر
                    // يمكنك تحديث العداد إذا أردت
                }
            });
    });
});

// تحديد الكل كمقروء
document.getElementById('markAllReadBtn')?.addEventListener('click', function() {
    fetch('notification.php?mark_all_read=1')
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                document.querySelectorAll('.notification-item').forEach(item => {
                    item.style.background = '';
                    let btn = item.querySelector('.mark-read-btn');
                    if (btn) btn.remove();
                });
            }
        });
});
</script>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
// تبديل التبويبات
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const tabId = this.getAttribute('data-tab');
        const url = new URL(window.location.href);
        url.searchParams.set('tab', tabId);
        window.history.pushState({}, '', url);
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
        document.getElementById(`tab-${tabId}`).classList.add('active');
    });
});

// الرسوم البيانية
<?php if (array_sum($monthlyTotals) > 0): ?>
new Chart(document.getElementById('monthlyChart'), { type: 'bar', data: { labels: <?= json_encode($months, JSON_UNESCAPED_UNICODE) ?>, datasets: [{ label: 'إجمالي الاقتطاعات (دج)', data: <?= json_encode($monthlyTotals) ?>, backgroundColor: 'rgba(42, 82, 152, 0.8)', borderRadius: 8 }] }, options: { responsive: true, maintainAspectRatio: true, plugins: { legend: { position: 'bottom' } } } });
<?php endif; ?>
<?php if (!empty($sourceDeductions)): ?>
new Chart(document.getElementById('sourceChart'), { type: 'doughnut', data: { labels: <?= json_encode(array_column($sourceDeductions, 'name'), JSON_UNESCAPED_UNICODE) ?>, datasets: [{ data: <?= json_encode(array_column($sourceDeductions, 'total')) ?>, backgroundColor: ['#2a5298','#ff9800','#4caf50','#9c27b0','#f44336','#00bcd4','#795548'] }] }, options: { responsive: true, plugins: { legend: { position: 'bottom' } } } });
<?php endif; ?>
<?php if (!empty($topEmployees)): ?>
new Chart(document.getElementById('topEmployeesChart'), { type: 'bar', data: { labels: <?= json_encode(array_column($topEmployees, 'name'), JSON_UNESCAPED_UNICODE) ?>, datasets: [{ label: 'إجمالي الاقتطاعات (دج)', data: <?= json_encode(array_column($topEmployees, 'total')) ?>, backgroundColor: ['rgba(244,67,54,0.8)','rgba(255,152,0,0.8)','rgba(76,175,80,0.8)','rgba(33,150,243,0.8)','rgba(156,39,176,0.8)'], borderRadius: 8 }] }, options: { indexAxis: 'y', responsive: true, plugins: { legend: { display: false } } } });
<?php endif; ?>
</script>

<?php include 'includes/footer.php'; ?>