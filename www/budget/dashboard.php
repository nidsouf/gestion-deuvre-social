<?php
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';

// الحصول على السنة المحددة (افتراضي 2026)
$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');

// محاولة الحصول على الميزانية الإجمالية من جدول social_budget
// نبحث عن حقل total_budget أو total_amount، أو نستخدم remaining_budget + المصروفات
$initial_budget = 0;

// 1. هل يوجد عمود total_budget؟ جرب قراءة هيكل الجدول
$columns = $pdo->query("PRAGMA table_info(social_budget)")->fetchAll(PDO::FETCH_COLUMN, 1);
if (in_array('total_budget', $columns)) {
    $stmt = $pdo->prepare("SELECT total_budget FROM social_budget WHERE year = ? ORDER BY year DESC LIMIT 1");
    $stmt->execute([$year]);
    $row = $stmt->fetch();
    if ($row) $initial_budget = (float)$row['total_budget'];
}

// 2. إذا لم يجد، حاول الحصول على المبلغ الأولي من حقل initial_budget إن وجد
if ($initial_budget == 0 && in_array('initial_budget', $columns)) {
    $stmt = $pdo->prepare("SELECT initial_budget FROM social_budget WHERE year = ? ORDER BY year DESC LIMIT 1");
    $stmt->execute([$year]);
    $row = $stmt->fetch();
    if ($row) $initial_budget = (float)$row['initial_budget'];
}

// 3. إذا لم يجد، نحسب الميزانية الإجمالية = (إجمالي المصروفات - إجمالي الاسترجاعات) + الرصيد المتبقي الحالي
if ($initial_budget == 0) {
    // جلب آخر رصيد متبقي (remaining_budget) من جدول social_budget
    $stmt = $pdo->prepare("SELECT remaining_budget FROM social_budget ORDER BY year DESC LIMIT 1");
    $stmt->execute();
    $last = $stmt->fetch();
    $current_remaining = $last ? (float)$last['remaining_budget'] : 0;
    
    // حساب إجمالي المصروفات (السلف + المنح) لهذه السنة
    $stmt_exp = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM budget_transactions WHERE is_deduct = 1 AND strftime('%Y', transaction_date) = ?");
    $stmt_exp->execute([$year]);
    $total_expenses_year = (float)$stmt_exp->fetchColumn();
    
    // حساب إجمالي الاسترجاعات (is_deduct = 0) لهذه السنة
    $stmt_ref = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM budget_transactions WHERE is_deduct = 0 AND strftime('%Y', transaction_date) = ?");
    $stmt_ref->execute([$year]);
    $total_refunds_year = (float)$stmt_ref->fetchColumn();
    
    // الميزانية الإجمالية = الرصيد المتبقي + المصروفات - الاسترجاعات
    $initial_budget = $current_remaining + $total_expenses_year - $total_refunds_year;
    
    // إذا كانت النتيجة صفر أو سالبة، استخدم قيمة افتراضية
    if ($initial_budget <= 0) $initial_budget = 1000000; // 1,000,000 دج افتراضياً
}

// استعلامات دقيقة للإحصائيات (كما في السابق)
$stmt_loans = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM budget_transactions WHERE type = 'loan' AND is_deduct = 1 AND strftime('%Y', transaction_date) = ?");
$stmt_loans->execute([$year]);
$total_loans = (float) $stmt_loans->fetchColumn();

$stmt_grants = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM budget_transactions WHERE type = 'grant' AND is_deduct = 1 AND description NOT LIKE '%استرجاع%' AND strftime('%Y', transaction_date) = ?");
$stmt_grants->execute([$year]);
$total_grants = (float) $stmt_grants->fetchColumn();

$stmt_refunds = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM budget_transactions WHERE is_deduct = 0 AND description LIKE '%استرجاع%' AND strftime('%Y', transaction_date) = ?");
$stmt_refunds->execute([$year]);
$total_refunds = (float) $stmt_refunds->fetchColumn();

$total_expenses = $total_loans + $total_grants;
$remaining_budget = $initial_budget - $total_expenses + $total_refunds;

// نسبة الاستهلاك
$percentage_used = ($initial_budget > 0) ? min(100, round(($total_expenses / $initial_budget) * 100, 1)) : 0;

// آخر 10 عمليات
$transactions_sql = "SELECT transaction_date, amount, type, description, is_deduct FROM budget_transactions WHERE strftime('%Y', transaction_date) = ? ORDER BY transaction_date DESC LIMIT 10";
$stmt_trans = $pdo->prepare($transactions_sql);
$stmt_trans->execute([$year]);
$recent_transactions = $stmt_trans->fetchAll();

include '../includes/header.php';
?>

<style>
    /* نفس الأنماط السابقة - احتفظ بها كما هي */
    .dashboard-container { max-width: 1200px; margin: 0 auto; }
    .stats-grid { display: flex; gap: 20px; flex-wrap: wrap; margin-bottom: 30px; }
    .stat-card { background: white; border-radius: 20px; padding: 20px; text-align: center; flex: 1; min-width: 180px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); border-bottom: 4px solid; transition: transform 0.2s; }
    .stat-card:hover { transform: translateY(-5px); }
    .stat-card.loans { border-bottom-color: #2a5298; }
    .stat-card.grants { border-bottom-color: #28a745; }
    .stat-card.refunds { border-bottom-color: #ffc107; }
    .stat-card.expenses { border-bottom-color: #dc3545; }
    .stat-card.remaining { border-bottom-color: #17a2b8; }
    .stat-card .number { font-size: 28px; font-weight: 700; margin: 10px 0; }
    .stat-card .label { font-size: 14px; color: #666; }
    .progress-section { background: white; border-radius: 20px; padding: 20px; margin-bottom: 30px; }
    .progress-bar-container { background: #e9ecef; border-radius: 30px; height: 25px; overflow: hidden; }
    .progress-bar { background: linear-gradient(90deg, #2a5298, #28a745); width: <?= $percentage_used ?>%; height: 100%; display: flex; align-items: center; justify-content: flex-end; padding-right: 10px; color: white; font-size: 12px; font-weight: bold; border-radius: 30px; }
    .transactions-table { width: 100%; border-collapse: collapse; background: white; border-radius: 20px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
    .transactions-table th, .transactions-table td { padding: 12px 15px; text-align: center; border-bottom: 1px solid #ddd; }
    .transactions-table th { background: #2a5298; color: white; }
    .badge { padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: bold; display: inline-block; }
    .badge-loan { background: #2a5298; color: white; }
    .badge-grant { background: #28a745; color: white; }
    .badge-refund { background: #ffc107; color: #333; }
</style>

<div class="dashboard-container">
    <h2 style="margin-bottom: 20px;">📊 لوحة تحكم الميزانية - سنة <?= $year ?></h2>
    
    <div class="stats-grid">
        <div class="stat-card loans">
            <div class="label">💰 السلف</div>
            <div class="number"><?= number_format($total_loans, 2) ?> دج</div>
        </div>
        <div class="stat-card grants">
            <div class="label">🎁 المنح الفعلية</div>
            <div class="number"><?= number_format($total_grants, 2) ?> دج</div>
        </div>
        <div class="stat-card refunds">
            <div class="label">🔄 استرجاعات السلف</div>
            <div class="number"><?= number_format($total_refunds, 2) ?> دج</div>
        </div>
        <div class="stat-card expenses">
            <div class="label">💸 إجمالي الصرف</div>
            <div class="number"><?= number_format($total_expenses, 2) ?> دج</div>
        </div>
        <div class="stat-card remaining">
            <div class="label">✅ الميزانية المتبقية</div>
            <div class="number"><?= number_format($remaining_budget, 2) ?> دج</div>
        </div>
    </div>

    <div class="progress-section">
        <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
            <span>الميزانية المستخدمة</span>
            <span><?= $percentage_used ?>%</span>
        </div>
        <div class="progress-bar-container">
            <div class="progress-bar" style="width: <?= $percentage_used ?>%;"><?= $percentage_used ?>%</div>
        </div>
        <div style="margin-top: 10px; color: #666; font-size: 14px;">
            الميزانية الإجمالية المقدرة: <?= number_format($initial_budget, 2) ?> دج
        </div>
    </div>

    <h3 style="margin: 25px 0 15px;">🕒 آخر العمليات</h3>
    <table class="transactions-table">
        <thead>
            <tr><th>التاريخ</th><th>المبلغ (دج)</th><th>النوع</th><th>الوصف</th><th>اتجاه</th></tr>
        </thead>
        <tbody>
            <?php if (empty($recent_transactions)): ?>
                <tr><td colspan="5" style="text-align:center;">لا توجد معاملات</td></tr>
            <?php else: ?>
                <?php foreach ($recent_transactions as $tr): 
                    $type_label = '';
                    if ($tr['type'] == 'loan') $type_label = '<span class="badge badge-loan">سلفة</span>';
                    else if ($tr['type'] == 'grant') {
                        if (strpos($tr['description'], 'استرجاع') !== false) $type_label = '<span class="badge badge-refund">استرجاع سلفة</span>';
                        else $type_label = '<span class="badge badge-grant">منحة</span>';
                    } else $type_label = '<span class="badge badge-loan">أخرى</span>';
                    $direction = $tr['is_deduct'] ? '🔻 صرف' : '🔺 إضافة';
                ?>
                <tr>
                    <td><?= date('d/m/Y H:i', strtotime($tr['transaction_date'])) ?></td>
                    <td><?= number_format($tr['amount'], 2) ?></td>
                    <td><?= $type_label ?></td>
                    <td><?= escape($tr['description']) ?></td>
                    <td><?= $direction ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php include '../includes/footer.php'; ?>