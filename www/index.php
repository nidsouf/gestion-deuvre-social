<?php
/**
 * index.php - لوحة التحكم الرئيسية
 */
ob_start(); // ← منع إرسال الـ Headers قبل الأوان
session_start();
require_once __DIR__ . '/includes/auth_check.php';
require_once 'config/database.php';
require_once 'includes/functions.php';

// ========== معالجة طلبات الإشعارات (GET) ==========
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['mark_read']) && is_numeric($_GET['mark_read'])) {
        $id = (int)$_GET['mark_read'];
        $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND (user_id IS NULL OR user_id = ?)")->execute([$id, $_SESSION['user_id']]);
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    }
    if (isset($_GET['mark_all_read'])) {
        $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id IS NULL OR user_id = ?")->execute([$_SESSION['user_id']]);
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    }
}

// ========== إصلاح جدول الإشعارات ==========
$cols = $pdo->query("PRAGMA table_info(notifications)")->fetchAll(PDO::FETCH_COLUMN, 1);
if (!in_array('title', $cols)) $pdo->exec("ALTER TABLE notifications ADD COLUMN title TEXT DEFAULT ''");
if (!in_array('type', $cols)) $pdo->exec("ALTER TABLE notifications ADD COLUMN type TEXT DEFAULT 'info'");
if (!in_array('is_read', $cols)) $pdo->exec("ALTER TABLE notifications ADD COLUMN is_read INTEGER DEFAULT 0");

// ========== السنة المحددة من الفلتر ==========
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'nav';

// ========== استعلام واحد مجمع للإحصائيات (محسّن) ==========
$stmt = $pdo->prepare("
    SELECT
        (SELECT COUNT(*) FROM employees) AS total_employees,
        (SELECT COUNT(*) FROM sources) AS total_sources,
        (SELECT COUNT(*) FROM deductions) AS total_deductions,
        (SELECT COUNT(eg.id) FROM employee_grants eg) AS total_grants,
        (SELECT COUNT(*) FROM deductions WHERE is_loan = 1 AND end_date >= date('now')) AS active_loans,
        (SELECT COALESCE(AVG(monthly_amount * total_months), 0) FROM deductions WHERE strftime('%Y', start_date) = :year) AS avg_monthly_deduction,
        (SELECT COALESCE(SUM(g.amount), 0) FROM employee_grants eg JOIN grants g ON eg.grant_id = g.id WHERE strftime('%Y', eg.grant_date) = :year) AS total_grants_this_year,
        (SELECT COALESCE(SUM(monthly_amount), 0) FROM deductions WHERE end_date >= date('now')) AS total_regular_monthly,
        (SELECT COALESCE(SUM(monthly_amount), 0) FROM employee_phone_numbers WHERE is_active = 1) AS total_djezy,
        (SELECT COUNT(*) FROM monthly_installments WHERE is_paid = 0 AND is_postponed = 0 AND strftime('%Y', year || '-' || month || '-01') <= date('now')) AS overdue_installments
");
$stmt->execute([':year' => (string)$year]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

$totalEmployees        = $stats['total_employees'];
$totalSources          = $stats['total_sources'];
$totalDeductions       = $stats['total_deductions'];
$totalGrants           = $stats['total_grants'];
$activeLoans           = $stats['active_loans'];
$avgMonthlyDeduction   = $stats['avg_monthly_deduction'];
$totalGrantsThisYear   = $stats['total_grants_this_year'];
$totalRegularMonthly   = $stats['total_regular_monthly'];
$totalDjezy            = $stats['total_djezy'];
$overdueInstallments   = $stats['overdue_installments'];
$totalMonthlyAll = $totalRegularMonthly + $totalDjezy;

// ========== حساب إجمالي المبالغ المقتطعة خلال السنة المحددة ==========
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
$totalAmountForYear = 0;

foreach ($deductionsForYear as $ded) {
    $monthly = (float)$ded['monthly_amount'];
    $dedStart = new DateTime($ded['start_date']);
    $dedEnd   = new DateTime($ded['end_date']);

    $overlapStart = max($yearStartDT, $dedStart);
    $overlapEnd   = min($yearEndDT, $dedEnd);

    if ($overlapStart <= $overlapEnd) {
        $months = 0;
        $tempStart = clone $overlapStart;
        while ($tempStart <= $overlapEnd) {
            $months++;
            $tempStart->modify('+1 month');
        }
        if ($months < 0) $months = 0;
        $totalAmountForYear += $monthly * $months;
    }
}

// ========== الميزانية للسنة المحددة ==========
$budgetRow = $pdo->prepare("
    SELECT initial_budget, remaining_budget
    FROM social_budget
    WHERE year = :year
    ORDER BY id DESC LIMIT 1
");
$budgetRow->execute([':year' => $year]);
$budget = $budgetRow->fetch();
$initialBudget = $budget['initial_budget'] ?? 1;
$remainingBudget = $budget['remaining_budget'] ?? 0;
$spentPercent = $initialBudget > 0 ? min(100, round((($initialBudget - $remainingBudget) / $initialBudget) * 100)) : 0;

// ========== إجمالي الاسترجاعات لهذه السنة ==========
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(amount), 0)
    FROM budget_transactions
    WHERE is_deduct = 0 AND strftime('%Y', transaction_date) = :year
");
$stmt->execute([':year' => (string)$year]);
$totalRefunds = $stmt->fetchColumn();

// ========== نسبة المنح إلى الاقتطاعات ==========
$grantRatio = ($totalAmountForYear > 0) ? round(($totalGrantsThisYear / $totalAmountForYear) * 100, 1) : 0;

// ========== الرسوم البيانية (مع فلتر السنة) ==========
// 1. الاقتطاعات الشهرية
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

// 2. توزيع المصادر (مع فلتر السنة)
$sourceDeductions = $pdo->prepare("
    SELECT s.name, COALESCE(SUM(d.monthly_amount * d.total_months), 0) AS total
    FROM deductions d
    JOIN sources s ON d.source_id = s.id
    WHERE strftime('%Y', d.start_date) = :year
    GROUP BY s.id, s.name
    ORDER BY total DESC
");
$sourceDeductions->execute([':year' => (string)$year]);
$sourceDeductions = $sourceDeductions->fetchAll(PDO::FETCH_ASSOC);

// 3. أعلى 5 موظفين (مع فلتر السنة)
$topEmployees = $pdo->prepare("
    SELECT e.name, COALESCE(SUM(d.monthly_amount * d.total_months), 0) AS total
    FROM deductions d
    JOIN employees e ON d.employee_id = e.id
    WHERE strftime('%Y', d.start_date) = :year
    GROUP BY e.id, e.name
    ORDER BY total DESC
    LIMIT 5
");
$topEmployees->execute([':year' => (string)$year]);
$topEmployees = $topEmployees->fetchAll(PDO::FETCH_ASSOC);

// ========== آخر المعاملات (مع فلتر السنة) ==========
$recentTransactions = $pdo->prepare("
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
    WHERE strftime('%Y', bt.transaction_date) = :year
    ORDER BY bt.transaction_date DESC
    LIMIT 6
");
$recentTransactions->execute([':year' => (string)$year]);
$recentTransactions = $recentTransactions->fetchAll(PDO::FETCH_ASSOC);

// ========== آخر المحاضر ==========
$recentMinutes = $pdo->query("
    SELECT meeting_date, meeting_number, content
    FROM meeting_minutes
    ORDER BY meeting_date DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// ========== الإشعارات ==========
$stmtNotif = $pdo->prepare("
    SELECT id, title, message, type, is_read, created_at
    FROM notifications
    WHERE user_id IS NULL OR user_id = ?
    ORDER BY created_at DESC
    LIMIT 10
");
$stmtNotif->execute([$_SESSION['user_id']]);
$notificationsList = $stmtNotif->fetchAll();

// ========== قائمة السنوات للفلاتر ==========
$availableYears = $pdo->query("
    SELECT DISTINCT CAST(strftime('%Y', start_date) AS INTEGER) AS y
    FROM deductions
    ORDER BY y DESC
")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array($year, $availableYears)) {
    $availableYears[] = $year;
    rsort($availableYears);
}

// ========== عدد شيكات السنة المحددة ==========
$chequeCount = 0;
$stmtCheque = $pdo->prepare("SELECT COUNT(*) FROM source_payments WHERE strftime('%Y', cheque_date) = :year");
$stmtCheque->execute([':year' => (string)$year]);
$chequeCount = $stmtCheque->fetchColumn();

include 'includes/header.php';
?>
<link rel="stylesheet" href="../assets/css/dashboard.css">

<div class="section">
    <div class="section-header">
        <h2>📊 لوحة التحكم الرئيسية</h2>
        <div style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
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
            <a href="payments/list.php" class="nav-card"><i class="fas fa-money-bill-wave"></i><span>تسيير الشيكات</span></a>
            <a href="regulations.php" class="nav-card"><i class="fas fa-book"></i><span>القوانين الداخلية</span></a>
            <a href="backup.php" class="nav-card"><i class="fas fa-database"></i><span>النسخ الاحتياطي</span></a>
            <a href="settings.php" class="nav-card"><i class="fas fa-sliders-h"></i><span>الإعدادات</span></a>
        </div>
    </div>

    <!-- ========== تبويب 2: نظرة عامة ========== -->
    <div id="tab-overview" class="tab-content <?= $active_tab == 'overview' ? 'active' : '' ?>">
        <div class="stats-grid">
            <div class="stat-card employees"><span class="icon">👥</span><h3>الموظفون</h3><div class="number"><?= number_format($totalEmployees) ?></div></div>
            <div class="stat-card sources"><span class="icon">🏦</span><h3>المصادر</h3><div class="number"><?= number_format($totalSources) ?></div></div>
            <div class="stat-card deductions"><span class="icon">📋</span><h3>الاقتطاعات</h3><div class="number"><?= number_format($totalDeductions) ?></div></div>
            <div class="stat-card amount"><span class="icon">💰</span><h3>إجمالي المبالغ المقتطعة</h3><div class="number"><?= number_format($totalAmountForYear, 2) ?> <small>دج</small></div><div class="sub">للسنة <?= $year ?></div></div>
            <div class="stat-card grants"><span class="icon">🎁</span><h3>منح الموظفين</h3><div class="number"><?= number_format($totalGrants) ?></div><div class="sub">عدد المنح المسجلة</div></div>
            <div class="stat-card year-grant"><span class="icon">🗓️</span><h3>منح هذا العام</h3><div class="number"><?= number_format($totalGrantsThisYear, 2) ?> <small>دج</small></div><div class="sub">للسنة <?= $year ?></div></div>
            <div class="stat-card loans"><span class="icon">💳</span><h3>سلف نشطة</h3><div class="number"><?= number_format($activeLoans) ?></div></div>
            <div class="stat-card avg"><span class="icon">📉</span><h3>متوسط الاقتطاع السنوي</h3><div class="number"><?= number_format($avgMonthlyDeduction, 2) ?> <small>دج</small></div><div class="sub">للسنة <?= $year ?></div></div>
            <div class="stat-card refunds"><span class="icon">🔄</span><h3>استرجاعات السلف</h3><div class="number"><?= number_format($totalRefunds, 2) ?> <small>دج</small></div><div class="sub">للسنة <?= $year ?></div></div>
            <div class="stat-card djezy"><span class="icon">📱</span><h3>اقتطاعات جيزي</h3><div class="number"><?= number_format($totalDjezy, 2) ?> <small>دج</small></div><div class="sub">شهرياً</div></div>
            <div class="stat-card total-monthly"><span class="icon">💰</span><h3>إجمالي الاقتطاعات الشهرية</h3><div class="number"><?= number_format($totalMonthlyAll, 2) ?> <small>دج</small></div><div class="sub">(عادي + جيزي)</div></div>
            <div class="stat-card overdue"><span class="icon">⏰</span><h3>الأقساط المتأخرة</h3><div class="number"><?= number_format($overdueInstallments) ?></div><div class="sub">غير مدفوعة ومؤجلة</div></div>
            <div class="stat-card budget"><span class="icon">📊</span><h3>الميزانية المتبقية</h3><div class="number" style="color: <?= $remainingBudget >= 0 ? '#00897b' : '#e53935' ?>"><?= number_format($remainingBudget, 2) ?> <small>دج</small></div>
                <div class="budget-bar-wrap"><div class="budget-bar-fill" style="width: <?= $spentPercent ?>%; background: <?= $spentPercent < 70 ? '#00bcd4' : ($spentPercent < 90 ? '#ff9800' : '#f44336') ?>;"></div></div>
                <div class="sub">تم إنفاق <?= $spentPercent ?>% من الميزانية (سنة <?= $year ?>)</div>
            </div>
            <div class="stat-card cheques"><span class="icon">🧾</span><h3>الشيكات المسجلة</h3><div class="number"><?= number_format($chequeCount) ?></div><div class="sub">للسنة <?= $year ?></div></div>
        </div>
        <div class="stats-grid" style="margin-bottom: 20px; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
            <div class="stat-card" style="border-bottom-color: #9c27b0; background: #faf0ff;">
                <span class="icon">📊</span>
                <h3>نسبة المنح للاقتطاعات</h3>
                <div class="number"><?= $grantRatio ?>%</div>
                <div class="sub">للسنة <?= $year ?></div>
            </div>
        </div>
    </div>

    <!-- ========== تبويب 3: الرسوم البيانية ========== -->
    <div id="tab-charts" class="tab-content <?= $active_tab == 'charts' ? 'active' : '' ?>">
        <div class="charts-row">
            <div class="chart-container"><h4>📈 الاقتطاعات الشهرية – <?= (int)$year ?></h4><?php if (array_sum($monthlyTotals) > 0): ?><canvas id="monthlyChart" style="height: 300px;"></canvas><?php else: ?><div class="no-data" style="text-align:center; padding:50px;">لا توجد بيانات لهذه السنة</div><?php endif; ?></div>
            <div class="chart-container"><h4>🥧 توزيع الاقتطاعات حسب المصدر – <?= (int)$year ?></h4><?php if (!empty($sourceDeductions)): ?><canvas id="sourceChart" style="height: 300px;"></canvas><?php else: ?><div class="no-data" style="text-align:center; padding:50px;">لا توجد بيانات لهذه السنة</div><?php endif; ?></div>
        </div>
        <div class="chart-container"><h4>🏆 أعلى 5 موظفين في الاقتطاعات – <?= (int)$year ?></h4><?php if (!empty($topEmployees)): ?><canvas id="topEmployeesChart" style="height: 300px;"></canvas><?php else: ?><div class="no-data" style="text-align:center; padding:50px;">لا توجد بيانات لهذه السنة</div><?php endif; ?></div>
    </div>

    <!-- ========== تبويب 4: آخر المعاملات ========== -->
    <div id="tab-transactions" class="tab-content <?= $active_tab == 'transactions' ? 'active' : '' ?>">
        <div class="section">
            <div class="section-header"><h3>🧾 آخر المعاملات على الميزانية – <?= (int)$year ?></h3><a href="budget/report.php?year=<?= $year ?>" class="btn-sm">عرض الكل ←</a></div>
            <div style="overflow-x:auto;">
                <table class="data-table">
                    <thead><tr><th>التاريخ</th><th>النوع</th><th>الوصف</th><th>المبلغ</th></tr></thead>
                    <tbody>
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
                            <tr><td colspan="4"><div class="no-data">لا توجد معاملات حديثة لهذه السنة</div></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ========== تبويب 5: آخر المحاضر ========== -->
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
            <?php else: ?>
                <div class="no-data">لا توجد محاضر مسجلة بعد</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ========== تبويب 6: الإشعارات ========== -->
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
                    <div class="no-data" style="text-align:center; padding:50px;">لا توجد إشعارات جديدة</div>
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

<!-- ========== تذييل الصفحة مع الرسوم البيانية ========== -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
// ========== تبديل التبويبات ==========
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

// ========== الإشعارات (AJAX) ==========
document.querySelectorAll('.mark-read-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        let id = this.dataset.id;
        let itemDiv = this.closest('.notification-item');
        fetch(`index.php?mark_read=${id}`)
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    itemDiv.classList.remove('unread');
                    this.remove();
                }
            })
            .catch(() => {});
    });
});

document.getElementById('markAllReadBtn')?.addEventListener('click', function() {
    fetch('index.php?mark_all_read=1')
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                document.querySelectorAll('.notification-item').forEach(item => {
                    item.classList.remove('unread');
                    let btn = item.querySelector('.mark-read-btn');
                    if (btn) btn.remove();
                });
            }
        })
        .catch(() => {});
});

// ========== الرسوم البيانية ==========
<?php if (array_sum($monthlyTotals) > 0): ?>
new Chart(document.getElementById('monthlyChart'), {
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
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: { legend: { position: 'bottom' } },
        scales: { y: { beginAtZero: true } }
    }
});
<?php endif; ?>

<?php if (!empty($sourceDeductions)): ?>
new Chart(document.getElementById('sourceChart'), {
    type: 'doughnut',
    data: {
        labels: <?= json_encode(array_column($sourceDeductions, 'name'), JSON_UNESCAPED_UNICODE) ?>,
        datasets: [{
            data: <?= json_encode(array_column($sourceDeductions, 'total')) ?>,
            backgroundColor: ['#2a5298','#ff9800','#4caf50','#9c27b0','#f44336','#00bcd4','#795548','#607d8b']
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { position: 'bottom' } }
    }
});
<?php endif; ?>

<?php if (!empty($topEmployees)): ?>
new Chart(document.getElementById('topEmployeesChart'), {
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
    options: {
        indexAxis: 'y',
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { x: { beginAtZero: true } }
    }
});
<?php endif; ?>
</script>

<?php include 'includes/footer.php'; ?>