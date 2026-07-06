<?php
session_start();

// =====================================================
// تسجيل الخروج التلقائي بعد 10 دقائق من الخمول
// =====================================================
require_once __DIR__ . '/includes/auth_check.php';
require_once 'config/database.php';
require_once 'includes/functions.php';
include 'includes/header.php';

// ==================== CONSTANTS ====================
const SAFE_YEAR_MIN = 2000;
const SAFE_YEAR_MAX = 2100;
const TOP_EMPLOYEES_LIMIT = 5;
const RECENT_MINUTES_LIMIT = 5;
const RECENT_TRANSACTIONS_LIMIT = 6;
const MAX_CONTENT_PREVIEW = 100;

// ==================== ERROR HANDLING ====================
$errors = [];
$currentYear = (int)date('Y');

try {
    // ========== Year Validation & Sanitization ==========
    $year = isset($_GET['year']) ? (int)$_GET['year'] : $currentYear;
    
    // Validate year range
    if ($year < SAFE_YEAR_MIN || $year > SAFE_YEAR_MAX) {
        $year = $currentYear;
    }
    
    // ========== Core Statistics (Year-Independent) ==========
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
    
    if (!$statsRow) {
        throw new Exception('فشل في جلب الإحصائيات الأساسية');
    }
    
    // Extract stats with null safety
    $totalEmployees      = (int)($statsRow['total_employees'] ?? 0);
    $totalSources        = (int)($statsRow['total_sources'] ?? 0);
    $totalDeductions     = (int)($statsRow['total_deductions'] ?? 0);
    $totalGrants         = (int)($statsRow['total_grants'] ?? 0);
    $activeLoans         = (int)($statsRow['active_loans'] ?? 0);
    $avgMonthlyDeduction = (float)($statsRow['avg_monthly_deduction'] ?? 0);
    $totalGrantsThisYear = (float)($statsRow['total_grants_this_year'] ?? 0);
    
    // ========== Additional Statistics (Djezy & Deductions) ==========
    $stmt = $pdo->query("SELECT COALESCE(SUM(monthly_amount), 0) FROM employee_phone_numbers WHERE is_active = 1");
    $totalDjezy = $stmt ? (float)$stmt->fetchColumn() : 0;
    
    $stmt = $pdo->query("SELECT COALESCE(SUM(monthly_amount), 0) FROM deductions WHERE end_date >= date('now')");
    $totalRegularMonthly = $stmt ? (float)$stmt->fetchColumn() : 0;
    
    $totalMonthlyAll = $totalRegularMonthly + $totalDjezy;
    
    // ========== Calculate Total Amount for Selected Year ==========
    $totalAmountForYear = 0;
    $yearStart = $year . '-01-01';
    $yearEnd   = $year . '-12-31';
    
    $stmt = $pdo->prepare("
        SELECT monthly_amount, start_date, end_date
        FROM deductions
        WHERE start_date <= :end_date AND end_date >= :start_date
    ");
    $stmt->execute([':start_date' => $yearStart, ':end_date' => $yearEnd]);
    $deductionsForYear = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    
    try {
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
    } catch (Exception $e) {
        error_log("Year calculation error: " . $e->getMessage());
        $totalAmountForYear = 0;
    }
    
    // ========== Calculate Remaining Budget ==========
    $budgetRow = $pdo->query("
        SELECT COALESCE((SELECT remaining_budget FROM social_budget ORDER BY year DESC LIMIT 1), 0) AS remaining_budget
    ")->fetch(PDO::FETCH_ASSOC);
    
    $remainingBudget = (float)($budgetRow['remaining_budget'] ?? 0);
    
    $initialBudget = $pdo->query("SELECT initial_budget FROM social_budget ORDER BY year DESC LIMIT 1")->fetchColumn();
    $initialBudget = $initialBudget ?: 1;
    $spentPercent = min(100, max(0, round((($initialBudget - $remainingBudget) / $initialBudget) * 100)));
    
    // ========== Chart Data ==========
    $monthlyDeductions = $pdo->prepare("
        SELECT CAST(strftime('%m', start_date) AS INTEGER) AS month,
               COALESCE(SUM(monthly_amount * total_months), 0) AS total
        FROM deductions
        WHERE strftime('%Y', start_date) = :year
        GROUP BY month
        ORDER BY month
    ");
    $monthlyDeductions->execute([':year' => (string)$year]);
    $monthlyDeductions = $monthlyDeductions->fetchAll(PDO::FETCH_ASSOC) ?: [];
    
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
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    
    $topEmployees = $pdo->query("
        SELECT e.name, COALESCE(SUM(d.monthly_amount * d.total_months), 0) AS total
        FROM deductions d
        JOIN employees e ON d.employee_id = e.id
        GROUP BY e.id, e.name
        ORDER BY total DESC
        LIMIT " . TOP_EMPLOYEES_LIMIT
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    
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
        LIMIT " . RECENT_TRANSACTIONS_LIMIT
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    
    $availableYears = $pdo->query("
        SELECT DISTINCT CAST(strftime('%Y', start_date) AS INTEGER) AS y
        FROM deductions
        ORDER BY y DESC
    ")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    
    if (!in_array($year, $availableYears)) {
        $availableYears[] = $year;
        rsort($availableYears);
    }
    
    $recentMinutes = $pdo->query("
        SELECT meeting_date, meeting_number, content 
        FROM meeting_minutes 
        ORDER BY meeting_date DESC 
        LIMIT " . RECENT_MINUTES_LIMIT
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    
} catch (Exception $e) {
    error_log("Dashboard Error: " . $e->getMessage());
    $errors[] = "حدث خطأ في تحميل لوحة التحكم. الرجاء المحاولة لاحقاً.";
}
?>

<style>
*, *::before, *::after { box-sizing: border-box; }

/* ===== Alerts ===== */
.alert {
    padding: 15px;
    margin-bottom: 20px;
    border-radius: 8px;
    border-left: 4px solid;
}
.alert-error {
    background-color: #f8d7da;
    border-color: #f5c6cb;
    color: #721c24;
}
.alert-error strong { font-weight: 600; }

/* ===== Quick Links ===== */
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
.quick-link-btn:active { transform: translateY(0); }
.quick-link-btn span { font-size: 1.2rem; }

@media (max-width: 700px) {
    .quick-links { grid-template-columns: repeat(2, 1fr); }
    .quick-link-btn { font-size: 12px; padding: 8px 6px; }
}

/* ===== Year Filter ===== */
.year-filter { 
    display: flex; 
    align-items: center; 
    gap: 10px; 
    margin-bottom: 25px; 
    flex-wrap: wrap; 
}
.year-filter label { 
    font-weight: 600; 
    color: #444; 
}
.year-filter select { 
    padding: 8px 14px; 
    border-radius: 10px; 
    border: 1px solid #ddd; 
    font-size: 15px; 
    background: white; 
    cursor: pointer;
    transition: border-color 0.2s;
}
.year-filter select:focus { 
    outline: none; 
    border-color: #2a5298; 
    box-shadow: 0 0 5px rgba(42, 82, 152, 0.3);
}

/* ===== Stats Grid ===== */
.stats-grid { 
    display: grid; 
    grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); 
    gap: 20px; 
    margin-bottom: 35px; 
}
.stat-card { 
    background: white; 
    border-radius: 20px; 
    padding: 24px 20px; 
    text-align: center; 
    box-shadow: 0 4px 15px rgba(0,0,0,0.07); 
    transition: transform 0.2s, box-shadow 0.2s; 
    position: relative;
}
.stat-card:hover { 
    transform: translateY(-6px); 
    box-shadow: 0 8px 25px rgba(0,0,0,0.12); 
}
.stat-card .icon { 
    font-size: 32px; 
    margin-bottom: 8px; 
    display: block; 
}
.stat-card h3 { 
    font-size: 14px; 
    color: #777; 
    margin: 0 0 6px; 
    font-weight: 500; 
}
.stat-card .number { 
    font-size: 28px; 
    font-weight: 700; 
    margin: 8px 0 4px; 
    line-height: 1.2; 
}
.stat-card small { 
    color: #999; 
    font-size: 12px; 
}
.stat-card.employees { border-bottom: 5px solid #2a5298; }
.stat-card.sources { border-bottom: 5px solid #ff9800; }
.stat-card.deductions { border-bottom: 5px solid #28a745; }
.stat-card.amount { border-bottom: 5px solid #9c27b0; }
.stat-card.grants { border-bottom: 5px solid #dc3545; }
.stat-card.budget { border-bottom: 5px solid #00bcd4; }
.stat-card.loans { border-bottom: 5px solid #ff5722; }
.stat-card.avg { border-bottom: 5px solid #607d8b; }
.stat-card.year-grant { border-bottom: 5px solid #4caf50; }
.stat-card.djezy { border-bottom: 5px solid #ff9800; }
.stat-card.total-monthly { border-bottom: 5px solid #9c27b0; }

/* ===== Budget Bar ===== */
.budget-bar-wrap { 
    background: #f0f0f0; 
    border-radius: 50px; 
    height: 8px; 
    margin-top: 10px; 
    overflow: hidden; 
}
.budget-bar-fill { 
    height: 100%; 
    border-radius: 50px; 
    transition: width 1s ease; 
}

/* ===== Charts ===== */
.chart-container { 
    background: white; 
    padding: 25px; 
    border-radius: 20px; 
    box-shadow: 0 4px 15px rgba(0,0,0,0.07); 
    margin-bottom: 25px; 
}
.chart-container h4 { 
    text-align: center; 
    margin: 0 0 20px; 
    font-size: 16px; 
    color: #333; 
}
.charts-row { 
    display: flex; 
    flex-wrap: wrap; 
    gap: 20px; 
    margin-bottom: 25px; 
}
.charts-row .chart-container { 
    flex: 1; 
    min-width: 280px; 
}

/* ===== No Data ===== */
.no-data { 
    text-align: center; 
    padding: 50px 20px; 
    color: #aaa; 
    font-size: 15px; 
}
.no-data span { 
    font-size: 40px; 
    display: block; 
    margin-bottom: 10px; 
}

/* ===== Tables ===== */
.data-table { 
    width: 100%; 
    border-collapse: collapse; 
}
.data-table th { 
    background: #f8f9fa; 
    padding: 12px 16px; 
    text-align: right; 
    font-size: 13px; 
    color: #555; 
    border-bottom: 2px solid #eee; 
}
.data-table td { 
    padding: 13px 16px; 
    border-bottom: 1px solid #f0f0f0; 
    font-size: 14px; 
    vertical-align: middle; 
}
.data-table tr:last-child td { border-bottom: none; }
.data-table tr:hover td { background: #fafafa; }

/* ===== Badges ===== */
.badge { 
    display: inline-block; 
    padding: 4px 12px; 
    border-radius: 20px; 
    font-size: 12px; 
    font-weight: 600; 
}
.badge-grant { background: #fff3e0; color: #e65100; }
.badge-loan { background: #e8f5e9; color: #2e7d32; }
.badge-installment { background: #e3f2fd; color: #1565c0; }
.badge-default { background: #f5f5f5; color: #555; }

/* ===== Section Header ===== */
.section-header { 
    display: flex; 
    justify-content: space-between; 
    align-items: center; 
    margin-bottom: 20px; 
    flex-wrap: wrap; 
    gap: 10px; 
}
.section-header h2, .section-header h3 { 
    margin: 0; 
    color: #2a5298; 
}

.btn-sm { 
    padding: 7px 16px; 
    background: #2a5298; 
    color: white; 
    border-radius: 10px; 
    text-decoration: none; 
    font-size: 13px; 
    transition: background 0.2s;
    border: none;
    cursor: pointer;
}
.btn-sm:hover { 
    background: #1e3d73; 
}
.btn-sm:active { 
    transform: scale(0.98); 
}

@media (max-width: 600px) { 
    .stat-card .number { font-size: 22px; } 
    .charts-row { flex-direction: column; }
    .section-header { flex-direction: column; align-items: flex-start; }
}

/* ========== Dark mode styles ========== */
body.dark-mode {
    background: #121212;
    color: #e0e0e0;
}
body.dark-mode .stat-card,
body.dark-mode .chart-container,
body.dark-mode .quick-links,
body.dark-mode .data-table th,
body.dark-mode .data-table td {
    background: #1e1e1e;
    color: #ddd;
}
body.dark-mode .data-table th {
    background: #2a2a2a;
}
body.dark-mode .btn-sm {
    background: #333;
}
.dark-mode-toggle {
    background: #2a5298;
    color: white;
    border: none;
    border-radius: 50%;
    width: 40px;
    height: 40px;
    cursor: pointer;
    margin-right: 10px;
    font-size: 18px;
}
</style>

<div class="section">
    <!-- ========== Error Messages ========== -->
    <?php if (!empty($errors)): ?>
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error">
                <strong>⚠️ خطأ:</strong> <?= htmlspecialchars($error) ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
    
    <div class="section-header">
        <h2>📊 لوحة التحكم الرئيسية</h2>
        <div style="display: flex; gap: 10px; align-items: center;">
            <form method="GET" class="year-filter" style="margin-bottom: 0;">
                <label for="yearSelect">📅 السنة:</label>
                <select id="yearSelect" name="year" onchange="this.form.submit()" aria-label="اختر السنة">
                    <?php foreach ($availableYears as $y): ?>
                        <option value="<?= (int)$y ?>" <?= $y == $year ? 'selected' : '' ?>><?= (int)$y ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
    </div>

    <!-- ========== Quick Links ========== -->
    <div class="quick-links" role="navigation" aria-label="روابط سريعة للقائمة الرئيسية">
        <a href="employees/list.php" class="quick-link-btn" title="عرض قائمة الموظفين"><span>👥</span> الموظفون</a>
        <a href="sources/list.php" class="quick-link-btn" title="عرض قائمة المصادر"><span>🏦</span> المصادر</a>
        <a href="deductions/list.php" class="quick-link-btn" title="عرض قائمة الاقتطاعات"><span>📋</span> الاقتطاعات</a>
        <a href="grants/list.php" class="quick-link-btn" title="عرض قائمة المنح"><span>🎁</span> المنح</a>
        <a href="budget/dashboard.php" class="quick-link-btn" title="عرض لوحة الميزانية"><span>💰</span> الميزانية</a>
        <a href="reports/annual.php" class="quick-link-btn" title="عرض التقارير السنوية"><span>📈</span> التقارير السنوية</a>
        <a href="reports/monthly.php" class="quick-link-btn" title="عرض التقرير الشهري"><span>📅</span> التقرير الشهري</a>
        <a href="reports/meeting_minutes.php" class="quick-link-btn" title="تحرير المحضر الشهري"><span>📝</span> محضر شهري</a>
        <a href="reports/quarterly.php" class="quick-link-btn" title="عرض التقرير الثلاثي"><span>📅</span> تقرير ثلاثي</a>
        <a href="meals/index.php" class="quick-link-btn" title="إدارة وجبات المطعم"><span>🍽️</span> الوجبات</a>
        <a href="meals/reports.php" class="quick-link-btn" title="عرض تقارير الوجبات"><span>📊</span> تقارير</a>
        <a href="meals/process_trimester.php" class="quick-link-btn" title="معالجة الثلاثيات"><span>🔄</span> معالجة</a>
        <a href="umrah/draw_list.php" class="quick-link-btn" title="أوراق العمرة"><span>🕋</span> العمرة</a>
        <a href="honors/index.php" class="quick-link-btn" title="المكرمون (عيد العمال)"><span>🎖️</span> مكرمون</a>
        <a href="regulations.php" class="quick-link-btn" title="عرض القوانين الداخلية"><span>📘</span> قوانين</a>
        <a href="settings.php" class="quick-link-btn" title="إعدادات النظام"><span>⚙️</span> إعدادات</a>
    </div>

    <!-- ========== Statistics ========== -->
    <div class="stats-grid" role="region" aria-label="إحصائيات النظام">
        <div class="stat-card employees"><span class="icon" aria-hidden="true">👥</span><h3>الموظفون</h3><div class="number"><?= htmlspecialchars(number_format($totalEmployees)) ?></div></div>
        <div class="stat-card sources"><span class="icon" aria-hidden="true">🏦</span><h3>المصادر</h3><div class="number"><?= htmlspecialchars(number_format($totalSources)) ?></div></div>
        <div class="stat-card deductions"><span class="icon" aria-hidden="true">📋</span><h3>الاقتطاعات</h3><div class="number"><?= htmlspecialchars(number_format($totalDeductions)) ?></div></div>
        <div class="stat-card amount"><span class="icon" aria-hidden="true">💰</span><h3>إجمالي المبالغ المقتطعة</h3><div class="number"><?= htmlspecialchars(number_format($totalAmountForYear, 2)) ?> <small>دج</small></div></div>
        <div class="stat-card grants"><span class="icon" aria-hidden="true">🎁</span><h3>منح الموظفين</h3><div class="number"><?= htmlspecialchars(number_format($totalGrants)) ?></div><small>عدد المنح المسجلة</small></div>
        <div class="stat-card budget"><span class="icon" aria-hidden="true">📊</span><h3>الميزانية المتبقية</h3><div class="number" style="color: <?= $remainingBudget >= 0 ? '#00897b' : '#e53935' ?>"><?= htmlspecialchars(number_format($remainingBudget, 2)) ?> <small>دج</small></div>
            <div class="budget-bar-wrap"><div class="budget-bar-fill" style="width: <?= htmlspecialchars($spentPercent) ?>%; background: <?= $spentPercent < 70 ? '#00bcd4' : ($spentPercent < 90 ? '#ff9800' : '#f44336') ?>"></div></div>
            <small>تم إنفاق <?= htmlspecialchars($spentPercent) ?>% من الميزانية</small>
        </div>
    </div>

    <div class="stats-grid" style="margin-bottom: 40px;">
        <div class="stat-card year-grant"><span class="icon" aria-hidden="true">🗓️</span><h3>منح هذا العام</h3><div class="number"><?= htmlspecialchars(number_format($totalGrantsThisYear, 2)) ?> <small>دج</small></div></div>
        <div class="stat-card loans"><span class="icon" aria-hidden="true">💳</span><h3>سلف نشطة</h3><div class="number"><?= htmlspecialchars(number_format($activeLoans)) ?></div></div>
        <div class="stat-card avg"><span class="icon" aria-hidden="true">📉</span><h3>متوسط الاقتطاع السنوي</h3><div class="number"><?= htmlspecialchars(number_format($avgMonthlyDeduction, 2)) ?> <small>دج</small></div></div>
        <div class="stat-card djezy"><span class="icon" aria-hidden="true">📱</span><h3>اقتطاعات جيزي (Djezy)</h3><div class="number"><?= htmlspecialchars(number_format($totalDjezy, 2)) ?> <small>دج</small></div><small>أرقام الهاتف النشطة</small></div>
        <div class="stat-card total-monthly"><span class="icon" aria-hidden="true">💰</span><h3>إجمالي الاقتطاعات الشهرية</h3><div class="number"><?= htmlspecialchars(number_format($totalMonthlyAll, 2)) ?> <small>دج</small></div></div>
    </div>

    <!-- ========== Charts ========== -->
    <div class="charts-row" role="region" aria-label="رسوم بيانية توضيحية">
        <div class="chart-container">
            <h4>📈 الاقتطاعات الشهرية – <?= htmlspecialchars($year) ?></h4>
            <?php if (array_sum($monthlyTotals) > 0): ?>
                <canvas id="monthlyChart" aria-label="رسم بياني للاقتطاعات الشهرية"></canvas>
            <?php else: ?>
                <div class="no-data"><span>📭</span>لا توجد اقتطاعات مسجلة لعام <?= htmlspecialchars($year) ?></div>
            <?php endif; ?>
        </div>
        <div class="chart-container">
            <h4>🥧 توزيع الاقتطاعات حسب المصدر</h4>
            <?php if (!empty($sourceDeductions)): ?>
                <canvas id="sourceChart" aria-label="توزيع الاقتطاعات حسب المصادر"></canvas>
            <?php else: ?>
                <div class="no-data"><span>📭</span>لا توجد بيانات للمصادر</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="chart-container">
        <h4>🏆 أعلى <?= TOP_EMPLOYEES_LIMIT ?> موظفين في الاقتطاعات</h4>
        <?php if (!empty($topEmployees)): ?>
            <canvas id="topEmployeesChart" aria-label="أعلى الموظفين في الاقتطاعات"></canvas>
        <?php else: ?>
            <div class="no-data"><span>📭</span>لا توجد بيانات</div>
        <?php endif; ?>
    </div>

    <!-- ========== Recent Minutes ========== -->
    <div class="section">
        <div class="section-header">
            <h3>📋 آخر المحاضر</h3>
            <a href="reports/meeting_minutes.php" class="btn-sm">عرض الكل ←</a>
        </div>
        <?php if (!empty($recentMinutes)): ?>
            <div style="overflow-x:auto;">
                <table class="data-table" role="table" aria-label="جدول آخر المحاضر">
                    <thead>
                        <tr><th scope="col">التاريخ</th><th scope="col">رقم الجلسة</th><th scope="col">المحتوى</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentMinutes as $minute): ?>
                            <tr>
                                <td><?= htmlspecialchars(date('d/m/Y', strtotime($minute['meeting_date']))) ?></td>
                                <td><?= htmlspecialchars($minute['meeting_number'] ?? '-') ?></td>
                                <td><?= htmlspecialchars(substr($minute['content'] ?? '', 0, MAX_CONTENT_PREVIEW)) ?><?= strlen($minute['content'] ?? '') > MAX_CONTENT_PREVIEW ? '...' : '' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="no-data"><span>📭</span>لا توجد محاضر مسجلة بعد</div>
        <?php endif; ?>
    </div>

    <!-- ========== Recent Budget Transactions ========== -->
    <div class="section">
        <div class="section-header">
            <h3>🧾 آخر المعاملات على الميزانية</h3>
            <a href="budget/report.php" class="btn-sm">عرض الكل ←</a>
        </div>
        <div style="overflow-x:auto;">
            <table class="data-table" role="table" aria-label="جدول آخر المعاملات">
                <thead>
                    <tr><th scope="col">التاريخ</th><th scope="col">النوع</th><th scope="col">الوصف</th><th scope="col" style="text-align:left;">المبلغ</th></tr>
                </thead>
                <tbody>
                    <?php if (!empty($recentTransactions)): ?>
                        <?php foreach ($recentTransactions as $trans): ?>
                            <tr>
                                <td><?= htmlspecialchars(date('d/m/Y H:i', strtotime($trans['transaction_date']))) ?></td>
                                <td><span class="badge <?= htmlspecialchars($trans['type_class']) ?>"><?= htmlspecialchars($trans['type_ar']) ?></span></td>
                                <td><?= htmlspecialchars($trans['description'] ?? '—') ?></td>
                                <td><?= htmlspecialchars(number_format((float)$trans['amount'], 2)) ?> دج</td>
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
        datasets: [{
            label: 'إجمالي الاقتطاعات (دج)',
            data: <?= json_encode($monthlyTotals) ?>,
            backgroundColor: 'rgba(42, 82, 152, 0.8)',
            borderRadius: 8,
            borderSkipped: false
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { position: 'bottom' },
            tooltip: { callbacks: { label: ctx => ' ' + ctx.parsed.y.toLocaleString('ar-DZ') + ' دج' } }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: { callback: val => val.toLocaleString('ar-DZ') + ' دج' }
            }
        }
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
            backgroundColor: ['#2a5298','#ff9800','#4caf50','#9c27b0','#f44336','#00bcd4','#795548'],
            borderWidth: 2,
            borderColor: '#fff'
        }]
    },
    options: {
        responsive: true,
        cutout: '60%',
        plugins: {
            legend: { position: 'bottom' },
            tooltip: { callbacks: { label: ctx => ' ' + ctx.parsed.toLocaleString('ar-DZ') + ' دج' } }
        }
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
            backgroundColor: ['rgba(244,67,54,0.8)','rgba(255,152,0,0.8)','rgba(76,175,80,0.8)','rgba(156,39,176,0.8)','rgba(42,82,152,0.8)']
        }]
    },
    options: {
        indexAxis: 'y',
        responsive: true,
        plugins: {
            legend: { display: false },
            tooltip: { callbacks: { label: ctx => ' ' + ctx.parsed.x.toLocaleString('ar-DZ') + ' دج' } }
        },
        scales: {
            x: {
                beginAtZero: true,
                ticks: { callback: val => val.toLocaleString('ar-DZ') + ' دج' }
            }
        }
    }
});
<?php endif; ?>
</script>

<?php include 'includes/footer.php'; ?>