<?php
session_start();
// =============================================
// تسجيل الخروج التلقائي بعد 10 دقائق من الخمول
// =============================================
require_once __DIR__ . '/includes/auth_check.php';
require_once 'config/database.php';
require_once 'includes/functions.php';
include 'includes/header.php';

// ========== السنة المحددة من الفلتر ==========
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

// ========== الإحصائيات الأساسية (لا تعتمد على السنة) ==========
$statsRow = $pdo->query("
    SELECT
        (SELECT COUNT(*) FROM employees)                                        AS total_employees,
        (SELECT COUNT(*) FROM sources)                                          AS total_sources,
        (SELECT COUNT(*) FROM deductions)                                       AS total_deductions,
        (SELECT COUNT(eg.id)
            FROM employee_grants eg
            JOIN employees e ON eg.employee_id = e.id
            JOIN grants g    ON eg.grant_id    = g.id)                          AS total_grants,
        (SELECT COUNT(*) FROM deductions
            WHERE is_loan = 1 AND end_date >= date('now'))                      AS active_loans,
        (SELECT COALESCE(AVG(monthly_amount * total_months), 0)
            FROM deductions
            WHERE start_date >= date('now', '-1 year'))                         AS avg_monthly_deduction,
        (SELECT COALESCE(SUM(g.amount), 0)
            FROM employee_grants eg
            JOIN grants g ON eg.grant_id = g.id
            WHERE strftime('%Y', eg.grant_date) = strftime('%Y', 'now'))        AS total_grants_this_year
")->fetch(PDO::FETCH_ASSOC);

$totalEmployees        = $statsRow['total_employees'];
$totalSources          = $statsRow['total_sources'];
$totalDeductions       = $statsRow['total_deductions'];
$totalGrants           = $statsRow['total_grants'];
$activeLoans           = $statsRow['active_loans'];
$avgMonthlyDeduction   = $statsRow['avg_monthly_deduction'];
$totalGrantsThisYear   = $statsRow['total_grants_this_year'];

// ========== حساب إجمالي المبالغ المقتطعة خلال السنة المحددة فقط ==========
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

$availableYears = $pdo->query("
    SELECT DISTINCT CAST(strftime('%Y', start_date) AS INTEGER) AS y
    FROM deductions
    ORDER BY y DESC
")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array($year, $availableYears)) {
    $availableYears[] = $year;
    rsort($availableYears);
}
?>

<style>
*, *::before, *::after { box-sizing: border-box; }

/* ===== أزرار الروابط السريعة ===== */
.quick-links {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    gap: 12px;
    margin-bottom: 30px;
    background: #fff;
    border-radius: 20px;
    padding: 15px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.03);
}
.quick-link-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    background: #f8f9fc;
    border: 1px solid #eef2f6;
    border-radius: 40px;
    padding: 10px 12px;
    text-decoration: none;
    font-size: 14px;
    font-weight: 500;
    color: #1e3c72;
    transition: all 0.2s;
    text-align: center;
}
.quick-link-btn:hover {
    background: #e9edf4;
    transform: translateY(-2px);
    border-color: #cbd5e1;
    box-shadow: 0 2px 6px rgba(0,0,0,0.05);
}
.quick-link-btn span { font-size: 1.2rem; }

@media (max-width: 700px) {
    .quick-links { grid-template-columns: repeat(2, 1fr); }
    .quick-link-btn { font-size: 12px; padding: 8px 6px; }
}

.year-filter { display: flex; align-items: center; gap: 10px; margin-bottom: 25px; flex-wrap: wrap; }
.year-filter label { font-weight: 600; color: #444; }
.year-filter select { padding: 8px 14px; border-radius: 10px; border: 1px solid #ddd; font-size: 15px; background: white; cursor: pointer; }
.year-filter select:focus { outline: none; border-color: #2a5298; }
.stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 20px; margin-bottom: 35px; }
.stat-card { background: white; border-radius: 20px; padding: 24px 20px; text-align: center; box-shadow: 0 4px 15px rgba(0,0,0,0.07); transition: transform 0.2s, box-shadow 0.2s; position: relative; overflow: hidden; }
.stat-card:hover { transform: translateY(-6px); box-shadow: 0 8px 25px rgba(0,0,0,0.12); }
.stat-card .icon { font-size: 32px; margin-bottom: 8px; display: block; }
.stat-card h3 { font-size: 14px; color: #777; margin: 0 0 6px; font-weight: 500; }
.stat-card .number { font-size: 28px; font-weight: 700; margin: 8px 0 4px; line-height: 1.2; }
.stat-card small { color: #999; font-size: 12px; }
.stat-card.employees { border-bottom: 5px solid #2a5298; }
.stat-card.sources { border-bottom: 5px solid #ff9800; }
.stat-card.deductions { border-bottom: 5px solid #28a745; }
.stat-card.amount { border-bottom: 5px solid #9c27b0; }
.stat-card.grants { border-bottom: 5px solid #dc3545; }
.stat-card.budget { border-bottom: 5px solid #00bcd4; }
.stat-card.loans { border-bottom: 5px solid #ff5722; }
.stat-card.avg { border-bottom: 5px solid #607d8b; }
.stat-card.year-grant { border-bottom: 5px solid #4caf50; }
.budget-bar-wrap { background: #f0f0f0; border-radius: 50px; height: 8px; margin-top: 10px; overflow: hidden; }
.budget-bar-fill { height: 100%; border-radius: 50px; transition: width 1s ease; }
.chart-container { background: white; padding: 25px; border-radius: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.07); margin-bottom: 25px; }
.chart-container h4 { text-align: center; margin: 0 0 20px; font-size: 16px; color: #333; }
.charts-row { display: flex; flex-wrap: wrap; gap: 20px; margin-bottom: 25px; }
.charts-row .chart-container { flex: 1; min-width: 280px; }
.no-data { text-align: center; padding: 50px 20px; color: #aaa; font-size: 15px; }
.no-data span { font-size: 40px; display: block; margin-bottom: 10px; }
.data-table { width: 100%; border-collapse: collapse; }
.data-table th { background: #f8f9fa; padding: 12px 16px; text-align: right; font-size: 13px; color: #555; border-bottom: 2px solid #eee; }
.data-table td { padding: 13px 16px; border-bottom: 1px solid #f0f0f0; font-size: 14px; vertical-align: middle; }
.data-table tr:last-child td { border-bottom: none; }
.data-table tr:hover td { background: #fafafa; }
.badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
.badge-grant { background: #fff3e0; color: #e65100; }
.badge-loan { background: #e8f5e9; color: #2e7d32; }
.badge-installment { background: #e3f2fd; color: #1565c0; }
.badge-default { background: #f5f5f5; color: #555; }
.section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px; }
.section-header h2, .section-header h3 { margin: 0; color: #2a5298; }
.btn-sm { padding: 7px 16px; background: #2a5298; color: white; border-radius: 10px; text-decoration: none; font-size: 13px; transition: background 0.2s; }
.btn-sm:hover { background: #1e3d73; }
@media (max-width: 600px) { .stat-card .number { font-size: 22px; } .charts-row { flex-direction: column; } }
</style>

<div class="section">
    <div class="section-header">
        <h2>📊 لوحة التحكم الرئيسية</h2>
        <form method="GET" class="year-filter">
            <label for="yearSelect">📅 السنة:</label>
            <select id="yearSelect" name="year" onchange="this.form.submit()">
                <?php foreach ($availableYears as $y): ?>
                    <option value="<?= (int)$y ?>" <?= $y == $year ? 'selected' : '' ?>><?= (int)$y ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <!-- ========== أزرار التنقل السريع (جميع وحدات النظام) ========== -->
    <div class="quick-links">
        <a href="employees/list.php" class="quick-link-btn"><span>👥</span> الموظفون</a>
        <a href="sources/list.php" class="quick-link-btn"><span>🏦</span> المصادر</a>
        <a href="deductions/list.php" class="quick-link-btn"><span>📋</span> الاقتطاعات</a>
        <a href="grants/list.php" class="quick-link-btn"><span>🎁</span> المنح</a>
        <a href="budget/dashboard.php" class="quick-link-btn"><span>💰</span> الميزانية</a>
        <a href="reports/annual.php" class="quick-link-btn"><span>📈</span> التقارير السنوية</a>
        <a href="reports/monthly.php" class="quick-link-btn"><span>📅</span> التقرير الشهري</a>
        <a href="/reports/meeting_minutes.php" class="quick-link-btn"><span>📝</span> تحرير المحضر الشهري</a>        <a href="/reports/quarterly.php" class="quick-link-btn"><span>📅</span> التقرير الثلاثي</a>
        <a href="meals/index.php" class="quick-link-btn"><span>🍽️</span> وجبات المطعم</a>
        <a href="meals/reports.php" class="quick-link-btn"><span>📊</span> تقارير الوجبات</a>
        <a href="meals/process_trimester.php" class="quick-link-btn"><span>🔄</span> معالجة الثلاثيات</a>
        <a href="/umrah/draw_list.php" class="quick-link-btn"><span>🕋</span> أوراق العمرة</a>
        <a href="honors/index.php" class="quick-link-btn"><span>🎖️</span> المكرمون (عيد العمال)</a>
        <a href="regulations.php" class="quick-link-btn"><span>📘</span> القوانين الداخلية</a>
        <a href="settings.php" class="quick-link-btn"><span>⚙️</span> الإعدادات</a>
    </div>

    <div class="stats-grid">
        <div class="stat-card employees"><span class="icon">👥</span><h3>الموظفون</h3><div class="number"><?= number_format($totalEmployees) ?></div></div>
        <div class="stat-card sources"><span class="icon">🏦</span><h3>المصادر</h3><div class="number"><?= number_format($totalSources) ?></div></div>
        <div class="stat-card deductions"><span class="icon">📋</span><h3>الاقتطاعات</h3><div class="number"><?= number_format($totalDeductions) ?></div></div>
        <div class="stat-card amount"><span class="icon">💰</span><h3>إجمالي المبالغ المقتطعة</h3><div class="number"><?= number_format($totalAmountForYear, 2) ?> <small>دج</small></div><small>للسنة <?= $year ?></small></div>
        <div class="stat-card grants"><span class="icon">🎁</span><h3>منح الموظفين</h3><div class="number"><?= number_format($totalGrants) ?></div><small>عدد المنح المسجلة</small></div>
        <div class="stat-card budget"><span class="icon">📊</span><h3>الميزانية المتبقية</h3><div class="number" style="color: <?= $remainingBudget >= 0 ? '#00897b' : '#e53935' ?>"><?= number_format($remainingBudget, 2) ?> <small>دج</small></div>
            <div class="budget-bar-wrap"><div class="budget-bar-fill" style="width: <?= $spentPercent ?>%; background: <?= $spentPercent < 70 ? '#00bcd4' : ($spentPercent < 90 ? '#ff9800' : '#f44336') ?>;"></div></div>
            <small>تم إنفاق <?= $spentPercent ?>% من الميزانية</small>
        </div>
    </div>

    <div class="stats-grid" style="margin-bottom: 40px;">
        <div class="stat-card year-grant"><span class="icon">🗓️</span><h3>منح هذا العام</h3><div class="number"><?= number_format($totalGrantsThisYear, 2) ?> <small>دج</small></div></div>
        <div class="stat-card loans"><span class="icon">💳</span><h3>سلف نشطة</h3><div class="number"><?= number_format($activeLoans) ?></div></div>
        <div class="stat-card avg"><span class="icon">📉</span><h3>متوسط الاقتطاع السنوي</h3><div class="number"><?= number_format($avgMonthlyDeduction, 2) ?> <small>دج</small></div></div>
    </div>

    <div class="charts-row">
        <div class="chart-container">
            <h4>📈 الاقتطاعات الشهرية – <?= (int)$year ?></h4>
            <?php if (array_sum($monthlyTotals) > 0): ?>
                <canvas id="monthlyChart"></canvas>
            <?php else: ?>
                <div class="no-data"><span>📭</span>لا توجد اقتطاعات مسجلة لعام <?= (int)$year ?></div>
            <?php endif; ?>
        </div>
        <div class="chart-container">
            <h4>🥧 توزيع الاقتطاعات حسب المصدر</h4>
            <?php if (!empty($sourceDeductions)): ?>
                <canvas id="sourceChart"></canvas>
            <?php else: ?>
                <div class="no-data"><span>📭</span>لا توجد بيانات للمصادر</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="chart-container">
        <h4>🏆 أعلى 5 موظفين في الاقتطاعات</h4>
        <?php if (!empty($topEmployees)): ?>
            <canvas id="topEmployeesChart"></canvas>
        <?php else: ?>
            <div class="no-data"><span>📭</span>لا توجد بيانات</div>
        <?php endif; ?>
    </div>

    <div class="section">
        <div class="section-header">
            <h3>🧾 آخر المعاملات على الميزانية</h3>
            <a href="budget/report.php" class="btn-sm">عرض الكل ←</a>
        </div>
        <div style="overflow-x:auto;">
            <table class="data-table">
                <thead><tr><th>التاريخ</th><th>النوع</th><th>الوصف</th><th style="text-align:left;">المبلغ</th></tr></thead>
                <tbody>
                    <?php if (!empty($recentTransactions)): ?>
                        <?php foreach ($recentTransactions as $trans): ?>
                            <tr>
                                <td><?= date('d/m/Y H:i', strtotime($trans['transaction_date'])) ?></td>
                                <td><span class="badge <?= htmlspecialchars($trans['type_class']) ?>"><?= htmlspecialchars($trans['type_ar']) ?></span></td>
                                <td><?= htmlspecialchars($trans['description'] ?? '—') ?></span></small></td>
                                <td style="text-align:left; font-weight:700; color:#2a5298;"><?= number_format((float)$trans['amount'], 2) ?> دج</span></small></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="4"><div class="no-data"><span>📭</span>لا توجد معاملات حديثة</div></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
Chart.defaults.font.family = 'Tajawal, Tahoma, Arial, sans-serif';
Chart.defaults.color = '#555';

<?php if (array_sum($monthlyTotals) > 0): ?>
new Chart(document.getElementById('monthlyChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($months, JSON_UNESCAPED_UNICODE) ?>,
        datasets: [{ label: 'إجمالي الاقتطاعات (دج)', data: <?= json_encode($monthlyTotals) ?>, backgroundColor: 'rgba(42, 82, 152, 0.8)', borderRadius: 8, borderSkipped: false }]
    },
    options: {
        responsive: true,
        plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: { label: ctx => ' ' + ctx.parsed.y.toLocaleString('ar-DZ') + ' دج' } } },
        scales: { y: { beginAtZero: true, ticks: { callback: val => val.toLocaleString('ar-DZ') + ' دج' } } }
    }
});
<?php endif; ?>

<?php if (!empty($sourceDeductions)): ?>
new Chart(document.getElementById('sourceChart'), {
    type: 'doughnut',
    data: {
        labels: <?= json_encode(array_column($sourceDeductions, 'name'), JSON_UNESCAPED_UNICODE) ?>,
        datasets: [{ data: <?= json_encode(array_column($sourceDeductions, 'total')) ?>, backgroundColor: ['#2a5298','#ff9800','#4caf50','#9c27b0','#f44336','#00bcd4','#795548'], borderWidth: 2, borderColor: '#fff' }]
    },
    options: { responsive: true, cutout: '60%', plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: { label: ctx => ' ' + ctx.parsed.toLocaleString('ar-DZ') + ' دج' } } } }
});
<?php endif; ?>

<?php if (!empty($topEmployees)): ?>
new Chart(document.getElementById('topEmployeesChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_column($topEmployees, 'name'), JSON_UNESCAPED_UNICODE) ?>,
        datasets: [{ label: 'إجمالي الاقتطاعات (دج)', data: <?= json_encode(array_column($topEmployees, 'total')) ?>, backgroundColor: ['rgba(244,67,54,0.8)','rgba(255,152,0,0.8)','rgba(76,175,80,0.8)','rgba(33,150,243,0.8)','rgba(156,39,176,0.8)'], borderRadius: 8, borderSkipped: false }]
    },
    options: { indexAxis: 'y', responsive: true, plugins: { legend: { display: false }, tooltip: { callbacks: { label: ctx => ' ' + ctx.parsed.x.toLocaleString('ar-DZ') + ' دج' } } }, scales: { x: { beginAtZero: true, ticks: { callback: val => val.toLocaleString('ar-DZ') + ' دج' } } } }
});
<?php endif; ?>
</script>

<?php include 'includes/footer.php'; ?>