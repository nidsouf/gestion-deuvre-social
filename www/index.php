<?php
/**
 * index.php - لوحة التحكم الرئيسية (محسّنة)
 */
ob_start();
session_start();
require_once __DIR__ . '/includes/auth_check.php';
require_once 'config/database.php';
require_once 'includes/security.php';
require_once 'includes/functions.php';

require_once 'includes/google_sheet_checker.php';

// فحص الطلبات الجديدة في Google Sheet
$gsheetCheck = checkNewGoogleSheetRequests($pdo);
$newGoogleRequests = $gsheetCheck['count'] ?? 0;

$userId = $_SESSION['user_id'];

// ============================================================
// إصلاح جدول الإشعارات
// ============================================================
$cols = $pdo->query("PRAGMA table_info(notifications)")->fetchAll(PDO::FETCH_COLUMN, 1);
if (!in_array('title', $cols)) $pdo->exec("ALTER TABLE notifications ADD COLUMN title TEXT DEFAULT ''");
if (!in_array('type', $cols)) $pdo->exec("ALTER TABLE notifications ADD COLUMN type TEXT DEFAULT 'info'");
if (!in_array('is_read', $cols)) $pdo->exec("ALTER TABLE notifications ADD COLUMN is_read INTEGER DEFAULT 0");

$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$active_tab = $_GET['tab'] ?? 'nav';

// ============================================================
// الإحصائيات (محسّنة)
// ============================================================
$stmt = $pdo->prepare("
    SELECT
        (SELECT COUNT(*) FROM employees) AS total_employees,
        (SELECT COUNT(*) FROM sources) AS total_sources,
        (SELECT COUNT(*) FROM deductions) AS total_deductions,
        (SELECT COUNT(eg.id) FROM employee_grants eg) AS total_grants,
        (SELECT COUNT(*) FROM deductions WHERE is_loan = 1 AND end_date >= date('now')) AS active_loans,
        (SELECT COALESCE(AVG(monthly_amount), 0) FROM deductions WHERE strftime('%Y', start_date) = :year) AS avg_monthly_deduction,
        (SELECT COALESCE(SUM(g.amount), 0) FROM employee_grants eg JOIN grants g ON eg.grant_id = g.id WHERE strftime('%Y', eg.grant_date) = :year) AS total_grants_this_year,
        (SELECT COALESCE(SUM(monthly_amount), 0) FROM deductions WHERE end_date >= date('now')) AS total_regular_monthly,
        (SELECT COALESCE(SUM(monthly_amount), 0) FROM employee_phone_numbers WHERE is_active = 1) AS total_djezy,
        (SELECT COUNT(*) FROM monthly_installments WHERE is_paid = 0 AND is_postponed = 0 AND (year || '-' || printf('%02d', month) || '-01') <= date('now')) AS overdue_installments
");
$stmt->execute([':year' => (string)$year]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

$totalEmployees = $stats['total_employees'];
$totalSources = $stats['total_sources'];
$totalDeductions = $stats['total_deductions'];
$totalGrants = $stats['total_grants'];
$activeLoans = $stats['active_loans'];
$avgMonthlyDeduction = $stats['avg_monthly_deduction'];
$totalGrantsThisYear = $stats['total_grants_this_year'];
$totalRegularMonthly = $stats['total_regular_monthly'];
$totalDjezy = $stats['total_djezy'];
$overdueInstallments = $stats['overdue_installments'];
$totalMonthlyAll = $totalRegularMonthly + $totalDjezy;

// ============================================================
// إجمالي المبالغ المقتطعة للسنة (محسّن)
// ============================================================
$yearStart = $year . '-01-01';
$yearEnd = $year . '-12-31';

$stmt = $pdo->prepare("
    SELECT monthly_amount, start_date, end_date
    FROM deductions
    WHERE start_date <= :end_date AND end_date >= :start_date
");
$stmt->execute([':start_date' => $yearStart, ':end_date' => $yearEnd]);
$deductionsForYear = $stmt->fetchAll(PDO::FETCH_ASSOC);

$yearStartDT = new DateTime($yearStart);
$yearEndDT = new DateTime($yearEnd);
$totalAmountForYear = 0;

foreach ($deductionsForYear as $ded) {
    $monthly = (float)$ded['monthly_amount'];
    $dedStart = new DateTime($ded['start_date']);
    $dedEnd = new DateTime($ded['end_date']);
    $overlapStart = max($yearStartDT, $dedStart);
    $overlapEnd = min($yearEndDT, $dedEnd);
    if ($overlapStart <= $overlapEnd) {
        $interval = $overlapStart->diff($overlapEnd);
        $months = ($interval->y * 12) + $interval->m + 1;
        $totalAmountForYear += $monthly * $months;
    }
}

// ============================================================
// الميزانية
// ============================================================
$budgetRow = $pdo->prepare("SELECT initial_budget, remaining_budget FROM social_budget WHERE year = :year ORDER BY id DESC LIMIT 1");
$budgetRow->execute([':year' => $year]);
$budget = $budgetRow->fetch();
$initialBudget = $budget['initial_budget'] ?? 1;
$remainingBudget = $budget['remaining_budget'] ?? 0;
$spentPercent = $initialBudget > 0 ? min(100, round((($initialBudget - $remainingBudget) / $initialBudget) * 100)) : 0;

// ============================================================
// الاسترجاعات
// ============================================================
$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM budget_transactions WHERE is_deduct = 0 AND strftime('%Y', transaction_date) = :year");
$stmt->execute([':year' => (string)$year]);
$totalRefunds = $stmt->fetchColumn();

// ============================================================
// نسبة المنح للاقتطاعات
// ============================================================
$grantRatio = ($totalAmountForYear > 0) ? round(($totalGrantsThisYear / $totalAmountForYear) * 100, 1) : 0;

// ============================================================
// الرسوم البيانية
// ============================================================
$monthlyDeductions = $pdo->prepare("
    SELECT CAST(strftime('%m', start_date) AS INTEGER) AS month, COALESCE(SUM(monthly_amount * total_months), 0) AS total
    FROM deductions WHERE strftime('%Y', start_date) = :year
    GROUP BY month ORDER BY month
");
$monthlyDeductions->execute([':year' => (string)$year]);
$monthlyDeductions = $monthlyDeductions->fetchAll(PDO::FETCH_ASSOC);

$months = [];
$monthlyTotals = [];
$arabicMonths = ['يناير','فبراير','مارس','أبريل','مايو','يونيو','يوليو','أغسطس','سبتمبر','أكتوبر','نوفمبر','ديسمبر'];
for ($i = 1; $i <= 12; $i++) {
    $months[] = $arabicMonths[$i - 1];
    $found = false;
    foreach ($monthlyDeductions as $d) {
        if ((int)$d['month'] === $i) { $monthlyTotals[] = (float)$d['total']; $found = true; break; }
    }
    if (!$found) $monthlyTotals[] = 0;
}

$sourceDeductions = $pdo->prepare("
    SELECT s.name, COALESCE(SUM(d.monthly_amount * d.total_months), 0) AS total
    FROM deductions d JOIN sources s ON d.source_id = s.id
    WHERE strftime('%Y', d.start_date) = :year
    GROUP BY s.id, s.name ORDER BY total DESC
");
$sourceDeductions->execute([':year' => (string)$year]);
$sourceDeductions = $sourceDeductions->fetchAll(PDO::FETCH_ASSOC);

$topEmployees = $pdo->prepare("
    SELECT e.name, COALESCE(SUM(d.monthly_amount * d.total_months), 0) AS total
    FROM deductions d JOIN employees e ON d.employee_id = e.id
    WHERE strftime('%Y', d.start_date) = :year
    GROUP BY e.id, e.name ORDER BY total DESC LIMIT 5
");
$topEmployees->execute([':year' => (string)$year]);
$topEmployees = $topEmployees->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// آخر المعاملات
// ============================================================
$recentTransactions = $pdo->prepare("
    SELECT bt.*,
           CASE bt.type WHEN 'grant' THEN 'منحة' WHEN 'loan' THEN 'سلفة' WHEN 'installment' THEN 'قسط مردود' WHEN 'payment' THEN 'شيك' ELSE bt.type END AS type_ar,
           CASE bt.type WHEN 'grant' THEN 'badge-grant' WHEN 'loan' THEN 'badge-loan' WHEN 'installment' THEN 'badge-installment' WHEN 'payment' THEN 'badge-payment' ELSE 'badge-default' END AS type_class
    FROM budget_transactions bt
    WHERE strftime('%Y', bt.transaction_date) = :year
    ORDER BY bt.transaction_date DESC LIMIT 6
");
$recentTransactions->execute([':year' => (string)$year]);
$recentTransactions = $recentTransactions->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// آخر المحاضر
// ============================================================
$recentMinutes = $pdo->query("SELECT meeting_date, meeting_number, content FROM meeting_minutes ORDER BY meeting_date DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

// ============================================================
// الإشعارات + عدد غير المقروء
// ============================================================
$stmtNotif = $pdo->prepare("SELECT id, title, message, type, is_read, created_at FROM notifications WHERE user_id IS NULL OR user_id = ? ORDER BY created_at DESC LIMIT 10");
$stmtNotif->execute([$userId]);
$notificationsList = $stmtNotif->fetchAll();

$stmtUnread = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE (user_id IS NULL OR user_id = ?) AND is_read = 0");
$stmtUnread->execute([$userId]);
$unreadCount = (int)$stmtUnread->fetchColumn();

// ============================================================
// قائمة السنوات (محسّنة)
// ============================================================
$availableYears = $pdo->query("
    SELECT DISTINCT CAST(y AS INTEGER) AS y FROM (
        SELECT strftime('%Y', start_date) AS y FROM deductions
        UNION SELECT strftime('%Y', grant_date) AS y FROM employee_grants
        UNION SELECT strftime('%Y', transaction_date) AS y FROM budget_transactions
        UNION SELECT CAST(year AS TEXT) AS y FROM social_budget
    ) WHERE y IS NOT NULL
    ORDER BY y DESC
")->fetchAll(PDO::FETCH_COLUMN);

$currentYear = (int)date('Y');
foreach ([$currentYear, $currentYear + 1] as $y) {
    if (!in_array($y, $availableYears)) $availableYears[] = $y;
}
rsort($availableYears);

// ============================================================
// عدد الشيكات
// ============================================================
$stmtCheque = $pdo->prepare("SELECT COUNT(*) FROM source_payments WHERE strftime('%Y', cheque_date) = :year");
$stmtCheque->execute([':year' => (string)$year]);
$chequeCount = $stmtCheque->fetchColumn();

// ============================================================
// تنبيهات ذكية
// ============================================================
$alerts = [];
if ($overdueInstallments > 0) {
    $alerts[] = ['type' => 'danger', 'icon' => '⏰', 'title' => number_format($overdueInstallments) . ' قسط متأخر', 'text' => 'يحتاج متابعة عاجلة', 'link' => 'reports/monthly.php'];
}
if ($spentPercent >= 85) {
    $alerts[] = ['type' => 'warning', 'icon' => '💰', 'title' => 'الميزانية استُهلكت بنسبة ' . $spentPercent . '%', 'text' => 'راجع المصروفات', 'link' => 'budget/dashboard.php?year=' . $year];
}
if ($unreadCount > 0) {
    $alerts[] = ['type' => 'info', 'icon' => '🔔', 'title' => $unreadCount . ' إشعار جديد', 'text' => 'انقر للعرض', 'link' => '?tab=notifications&year=' . $year];
}
$pendingRequests = 0;
try { $pendingRequests = $pdo->query("SELECT COUNT(*) FROM requests WHERE status = 'pending'")->fetchColumn(); } catch (Exception $e) {}
if ($pendingRequests > 0) {
    $alerts[] = ['type' => 'warning', 'icon' => '📋', 'title' => $pendingRequests . ' طلب قيد الانتظار', 'text' => 'يحتاج دراسة', 'link' => 'requests/index.php?status=pending'];
}

// بعد $alerts الموجودة
if ($newGoogleRequests > 0) {
    $alerts[] = [
        'type' => 'info',
        'icon' => '📥',
        'title' => $newGoogleRequests . ' طلب جديد في Google Sheet',
        'text' => 'انقر للمزامنة الآن',
        'link' => '/requests/sync_from_google.php'
    ];
}

$csrf_token = generateCSRFToken();
include 'includes/header.php';
?>
<link rel="stylesheet" href="assets/css/dashboard.css">
<link rel="stylesheet" href="assets/css/dashboard-improvements.css">

<!-- تمرير المتغيرات إلى JavaScript -->
<script>
    window.DASHBOARD_YEAR = <?= (int)$year ?>;
    window.CSRF_TOKEN = '<?= htmlspecialchars($csrf_token) ?>';
</script>

<div class="section">
    <!-- ============ الرأس ============ -->
    <div class="section-header">
        <h2>📊 لوحة التحكم الرئيسية</h2>
        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <!-- زر الوضع الداكن -->
            <button id="themeToggle" title="تبديل الوضع">🌙</button>
            
            <!-- شارة الإشعارات -->
            <a href="?tab=notifications&year=<?= $year ?>" class="notification-bell" title="الإشعارات">
                🔔
                <span class="notification-badge" style="<?= $unreadCount == 0 ? 'display:none;' : '' ?>"><?= $unreadCount ?></span>
            </a>
            
            <!-- فلتر السنة -->
            <form method="GET" class="year-filter">
                <label>📅 السنة:</label>
                <select name="year" onchange="this.form.submit()">
                    <?php foreach ($availableYears as $y): ?>
                        <option value="<?= (int)$y ?>" <?= $y == $year ? 'selected' : '' ?>><?= (int)$y ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" name="tab" value="<?= htmlspecialchars($active_tab) ?>">
            </form>
        </div>
    </div>

    <!-- ============ شريط الإجراءات السريعة ============ -->
    <div class="quick-actions-bar">
        <a href="employees/add.php" class="qa-btn primary">➕ موظف</a>
        <a href="deductions/add.php" class="qa-btn primary">➕ اقتطاع</a>
        <a href="grants/distribute.php" class="qa-btn primary">➕ منحة</a>
        <a href="payments/add.php" class="qa-btn primary">➕ شيك</a>
        <a href="requests/index.php" class="qa-btn">📋 الطلبات</a>
        <a href="budget/dashboard.php?year=<?= $year ?>" class="qa-btn">💰 الميزانية</a>
        <a href="reports/monthly.php" class="qa-btn">📊 التقارير</a>
    </div>

    <!-- ============ التنبيهات الذكية ============ -->
    <?php if (!empty($alerts)): ?>
    <div class="smart-alerts">
        <?php foreach ($alerts as $alert): ?>
            <a href="<?= htmlspecialchars($alert['link']) ?>" class="alert-card <?= $alert['type'] ?>">
                <span class="alert-icon"><?= $alert['icon'] ?></span>
                <div class="alert-content">
                    <strong><?= htmlspecialchars($alert['title']) ?></strong>
                    <small><?= htmlspecialchars($alert['text']) ?></small>
                </div>
                <span class="alert-arrow">←</span>
            </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ============ التبويبات ============ -->
    <div class="dashboard-tabs">
        <button class="tab-btn <?= $active_tab == 'nav' ? 'active' : '' ?>" data-tab="nav">🧭 التنقل</button>
        <button class="tab-btn <?= $active_tab == 'overview' ? 'active' : '' ?>" data-tab="overview">📊 نظرة عامة</button>
        <button class="tab-btn <?= $active_tab == 'charts' ? 'active' : '' ?>" data-tab="charts">📈 الرسوم</button>
        <button class="tab-btn <?= $active_tab == 'transactions' ? 'active' : '' ?>" data-tab="transactions">📋 المعاملات</button>
        <button class="tab-btn <?= $active_tab == 'minutes' ? 'active' : '' ?>" data-tab="minutes">📜 المحاضر</button>
        <button class="tab-btn <?= $active_tab == 'notifications' ? 'active' : '' ?>" data-tab="notifications">🔔 الإشعارات</button>
    </div>

    <!-- تبويب التنقل -->
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
            <a href="payments/list.php" class="nav-card"><i class="fas fa-money-bill-wave"></i><span>تسيير الشيكات</span></a>
            <a href="requests/index.php" class="nav-card"><i class="fas fa-clipboard-list"></i><span>الطلبات</span></a>
            <a href="regulations.php" class="nav-card"><i class="fas fa-book"></i><span>القوانين</span></a>
            <a href="backup.php" class="nav-card"><i class="fas fa-database"></i><span>النسخ الاحتياطي</span></a>
            <a href="settings.php" class="nav-card"><i class="fas fa-sliders-h"></i><span>الإعدادات</span></a>
        </div>
    </div>

    <!-- تبويب نظرة عامة -->
    <div id="tab-overview" class="tab-content <?= $active_tab == 'overview' ? 'active' : '' ?>">
        <div class="stats-grid">
            <div class="stat-card employees"><span class="icon">👥</span><h3>الموظفون</h3><div class="number"><?= number_format($totalEmployees) ?></div></div>
            <div class="stat-card sources"><span class="icon">🏦</span><h3>المصادر</h3><div class="number"><?= number_format($totalSources) ?></div></div>
            <div class="stat-card deductions"><span class="icon">📋</span><h3>الاقتطاعات</h3><div class="number"><?= number_format($totalDeductions) ?></div></div>
            <div class="stat-card amount"><span class="icon">💰</span><h3>إجمالي الاقتطاعات</h3><div class="number"><?= number_format($totalAmountForYear, 2) ?> <small>دج</small></div><div class="sub">للسنة <?= $year ?></div></div>
            <div class="stat-card grants"><span class="icon">🎁</span><h3>منح الموظفين</h3><div class="number"><?= number_format($totalGrants) ?></div></div>
            <div class="stat-card year-grant"><span class="icon">🗓️</span><h3>منح هذا العام</h3><div class="number"><?= number_format($totalGrantsThisYear, 2) ?> <small>دج</small></div></div>
            <div class="stat-card loans"><span class="icon">💳</span><h3>سلف نشطة</h3><div class="number"><?= number_format($activeLoans) ?></div></div>
            <div class="stat-card avg"><span class="icon">📉</span><h3>متوسط الاقتطاع الشهري</h3><div class="number"><?= number_format($avgMonthlyDeduction, 2) ?> <small>دج</small></div></div>
            <div class="stat-card refunds"><span class="icon">🔄</span><h3>استرجاعات السلف</h3><div class="number"><?= number_format($totalRefunds, 2) ?> <small>دج</small></div></div>
            <div class="stat-card djezy"><span class="icon">📱</span><h3>اقتطاعات الهواتف</h3><div class="number"><?= number_format($totalDjezy, 2) ?> <small>دج</small></div></div>
            <div class="stat-card total-monthly"><span class="icon">💰</span><h3>إجمالي الشهري</h3><div class="number"><?= number_format($totalMonthlyAll, 2) ?> <small>دج</small></div></div>
            <div class="stat-card overdue"><span class="icon">⏰</span><h3>أقساط متأخرة</h3><div class="number"><?= number_format($overdueInstallments) ?></div></div>
            <div class="stat-card budget"><span class="icon">📊</span><h3>الميزانية المتبقية</h3><div class="number" style="color: <?= $remainingBudget >= 0 ? '#00897b' : '#e53935' ?>"><?= number_format($remainingBudget, 2) ?> <small>دج</small></div>
                <div class="budget-bar-wrap"><div class="budget-bar-fill" style="width: <?= $spentPercent ?>%; background: <?= $spentPercent < 70 ? '#00bcd4' : ($spentPercent < 90 ? '#ff9800' : '#f44336') ?>;"></div></div>
                <div class="sub">إنفاق <?= $spentPercent ?>%</div>
            </div>
            <div class="stat-card cheques"><span class="icon">🧾</span><h3>الشيكات</h3><div class="number"><?= number_format($chequeCount) ?></div></div>
        </div>

        <div class="stat-card" style="border-bottom-color: #4285f4; background: #e3f2fd;">
    <span class="icon">📥</span>
    <h3>طلبات Google Sheet</h3>
    <div class="number" style="color: #1a73e8;"><?= number_format($newGoogleRequests) ?></div>
    <div class="sub">بانتظار المزامنة</div>
</div>

        <div class="stats-grid" style="margin-bottom: 20px; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
            <div class="stat-card" style="border-bottom-color: #9c27b0; background: #faf0ff;">
                <span class="icon">📊</span>
                <h3>نسبة المنح للاقتطاعات</h3>
                <div class="number"><?= $grantRatio ?>%</div>
            </div>
        </div>
    </div>

    <!-- تبويب الرسوم البيانية -->
    <div id="tab-charts" class="tab-content <?= $active_tab == 'charts' ? 'active' : '' ?>">
        <div class="charts-row">
            <div class="chart-container"><h4>📈 الاقتطاعات الشهرية – <?= (int)$year ?></h4><?php if (array_sum($monthlyTotals) > 0): ?><canvas id="monthlyChart" style="height: 300px;"></canvas><?php else: ?><div class="no-data" style="text-align:center; padding:50px;">لا توجد بيانات</div><?php endif; ?></div>
            <div class="chart-container"><h4>🥧 توزيع المصادر – <?= (int)$year ?></h4><?php if (!empty($sourceDeductions)): ?><canvas id="sourceChart" style="height: 300px;"></canvas><?php else: ?><div class="no-data" style="text-align:center; padding:50px;">لا توجد بيانات</div><?php endif; ?></div>
        </div>
        <div class="chart-container"><h4>🏆 أعلى 5 موظفين – <?= (int)$year ?></h4><?php if (!empty($topEmployees)): ?><canvas id="topEmployeesChart" style="height: 300px;"></canvas><?php else: ?><div class="no-data" style="text-align:center; padding:50px;">لا توجد بيانات</div><?php endif; ?></div>
    </div>

    <!-- تبويب آخر المعاملات -->
    <div id="tab-transactions" class="tab-content <?= $active_tab == 'transactions' ? 'active' : '' ?>">
        <div class="section">
            <div class="section-header">
                <h3>🧾 آخر المعاملات – <?= (int)$year ?></h3>
                <span style="font-size:12px; color:#6c757d;">🔄 تحديث تلقائي كل 30 ثانية</span>
                <a href="budget/report.php?year=<?= $year ?>" class="btn-sm">عرض الكل ←</a>
            </div>
            <div style="overflow-x:auto;">
                <table class="data-table">
                    <thead><tr><th>التاريخ</th><th>النوع</th><th>الوصف</th><th>المبلغ</th></tr></thead>
                    <tbody id="transactions-tbody">
                        <?php if (!empty($recentTransactions)): ?>
                            <?php foreach ($recentTransactions as $trans): ?>
                                <tr>
                                    <td><?= date('d/m/Y H:i', strtotime($trans['transaction_date'])) ?></td>
                                    <td><span class="badge <?= htmlspecialchars($trans['type_class']) ?>"><?= htmlspecialchars($trans['type_ar']) ?></span></td>
                                    <td><?= htmlspecialchars($trans['description'] ?? '—') ?></td>
                                    <td><?= number_format((float)$trans['amount'], 2) ?> دج</td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="4"><div class="no-data">لا توجد معاملات</div></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- تبويب المحاضر -->
    <div id="tab-minutes" class="tab-content <?= $active_tab == 'minutes' ? 'active' : '' ?>">
        <div class="section">
            <div class="section-header"><h3>📋 آخر المحاضر</h3><a href="reports/meeting_minutes.php" class="btn-sm">عرض الكل ←</a></div>
            <?php if (!empty($recentMinutes)): ?>
                <div style="overflow-x:auto;">
                    <table class="data-table">
                        <thead><tr><th>التاريخ</th><th>رقم الجلسة</th><th>المحتوى</th></tr></thead>
                        <tbody>
                            <?php foreach ($recentMinutes as $minute): ?>
                                <tr>
                                    <td><?= date('d/m/Y', strtotime($minute['meeting_date'])) ?></td>
                                    <td><?= htmlspecialchars($minute['meeting_number'] ?? '-') ?></td>
                                    <td><?= substr(htmlspecialchars($minute['content'] ?? ''), 0, 100) ?>...</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?><div class="no-data">لا توجد محاضر</div><?php endif; ?>
        </div>
    </div>

    <!-- تبويب الإشعارات -->
    <div id="tab-notifications" class="tab-content <?= $active_tab == 'notifications' ? 'active' : '' ?>">
        <div class="section">
            <div class="section-header">
                <h3>🔔 إشعارات النظام</h3>
                <?php if ($notificationsList): ?>
                    <button id="markAllReadBtn" class="btn-sm btn-success">✅ تحديد الكل كمقروء</button>
                <?php endif; ?>
            </div>
            <div id="notifications-list">
                <?php if (empty($notificationsList)): ?>
                    <div class="no-data" style="text-align:center; padding:50px;">لا توجد إشعارات</div>
                <?php else: ?>
                    <div style="max-height:400px; overflow-y:auto;">
                        <?php foreach ($notificationsList as $notif): ?>
                            <div class="notification-item <?= $notif['is_read'] ? '' : 'unread' ?>" data-id="<?= $notif['id'] ?>">
                                <div>
                                    <strong><?= htmlspecialchars($notif['title']) ?></strong>
                                    <small style="color:#888; margin-right:10px;"><?= date('d/m/Y H:i', strtotime($notif['created_at'])) ?></small>
                                </div>
                                <div><?= htmlspecialchars($notif['message']) ?></div>
                                <?php if (!$notif['is_read']): ?>
                                    <button class="mark-read-btn btn-sm btn-info" data-id="<?= $notif['id'] ?>" style="margin-top:8px;">📖 تحديد كمقروء</button>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Chart.js - محلي (حمّله من cdnjs) -->
<script src="assets/js/chart.umd.min.js"></script>

<!-- رسوم بيانية -->
<?php if (array_sum($monthlyTotals) > 0 || !empty($sourceDeductions) || !empty($topEmployees)): ?>
<script>
window.chartsReady = [];

<?php if (array_sum($monthlyTotals) > 0): ?>
window.chartsReady.push(new Chart(document.getElementById('monthlyChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($months, JSON_UNESCAPED_UNICODE) ?>,
        datasets: [{
            label: 'إجمالي الاقتطاعات (دج)',
            data: <?= json_encode($monthlyTotals) ?>,
            backgroundColor: 'rgba(42, 82, 152, 0.8)',
            borderRadius: 8
        }]
    },
    options: { responsive: true, maintainAspectRatio: true, plugins: { legend: { position: 'bottom' } }, scales: { y: { beginAtZero: true } } }
}));
<?php endif; ?>

<?php if (!empty($sourceDeductions)): ?>
window.chartsReady.push(new Chart(document.getElementById('sourceChart'), {
    type: 'doughnut',
    data: {
        labels: <?= json_encode(array_column($sourceDeductions, 'name'), JSON_UNESCAPED_UNICODE) ?>,
        datasets: [{
            data: <?= json_encode(array_column($sourceDeductions, 'total')) ?>,
            backgroundColor: ['#2a5298','#ff9800','#4caf50','#9c27b0','#f44336','#00bcd4','#795548','#607d8b']
        }]
    },
    options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
}));
<?php endif; ?>

<?php if (!empty($topEmployees)): ?>
window.chartsReady.push(new Chart(document.getElementById('topEmployeesChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_column($topEmployees, 'name'), JSON_UNESCAPED_UNICODE) ?>,
        datasets: [{
            label: 'إجمالي الاقتطاعات (دج)',
            data: <?= json_encode(array_column($topEmployees, 'total')) ?>,
            backgroundColor: ['rgba(244,67,54,0.8)','rgba(255,152,0,0.8)','rgba(76,175,80,0.8)','rgba(33,150,243,0.8)','rgba(156,39,176,0.8)'],
            borderRadius: 8
        }]
    },
    options: { indexAxis: 'y', responsive: true, plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true } } }
}));
<?php endif; ?>
</script>
<?php endif; ?>

<!-- سكربت لوحة التحكم -->
<script src="assets/js/dashboard.js"></script>

<?php include 'includes/footer.php'; ?>