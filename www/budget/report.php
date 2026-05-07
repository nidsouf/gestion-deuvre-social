<?php
require_once '../config/database.php';
include '../includes/header.php';

$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
$type = isset($_GET['type']) ? $_GET['type'] : 'all';

// ========== جلب المعاملات مع التواريخ بصيغة SQLite ==========
$query = "
    SELECT 
        bt.*
    FROM budget_transactions bt
    WHERE strftime('%Y', bt.transaction_date) = ?
";

if ($type != 'all') {
    $query .= " AND bt.type = ?";
}

$query .= " ORDER BY bt.transaction_date ASC";

$stmt = $pdo->prepare($query);
if ($type != 'all') {
    $stmt->execute([(string)$year, $type]);
} else {
    $stmt->execute([(string)$year]);
}
$transactions = $stmt->fetchAll();

// ========== إضافة التفاصيل الوصفية باستخدام PHP ==========
foreach ($transactions as &$t) {
    $details = '';
    if ($t['type'] == 'grant') {
        // جلب اسم المنحة ومبلغها
        $grantStmt = $pdo->prepare("SELECT name, amount FROM grants WHERE id = ?");
        $grantStmt->execute([$t['reference_id']]);
        $grant = $grantStmt->fetch();
        if ($grant) {
            $details = "منحة: " . htmlspecialchars($grant['name']) . " - قيمتها " . number_format($grant['amount'], 2) . " دج";
        } else {
            $details = "منحة (غير محددة)";
        }
    } 
    elseif ($t['type'] == 'loan') {
        // جلب تفاصيل السلفة
        $loanStmt = $pdo->prepare("
            SELECT d.id, d.monthly_amount, d.total_months, e.name 
            FROM deductions d
            JOIN employees e ON d.employee_id = e.id
            WHERE d.id = ?
        ");
        $loanStmt->execute([$t['reference_id']]);
        $loan = $loanStmt->fetch();
        if ($loan) {
            $totalAmount = $loan['monthly_amount'] * $loan['total_months'];
            $details = "سلفة رقم {$loan['id']} للموظف {$loan['name']} - المبلغ الإجمالي " . number_format($totalAmount, 2) . " دج";
        } else {
            $details = "سلفة (رقم المعاملة {$t['reference_id']}) - بياناتها غير متوفرة";
        }
    } 
    elseif ($t['type'] == 'installment') {
        // جلب تفاصيل القسط
        $installStmt = $pdo->prepare("
            SELECT d.id, d.monthly_amount, e.name 
            FROM deductions d
            JOIN employees e ON d.employee_id = e.id
            WHERE d.id = ?
        ");
        $installStmt->execute([$t['reference_id']]);
        $install = $installStmt->fetch();
        if ($install) {
            $details = "قسط سلفة رقم {$install['id']} للموظف {$install['name']} - قسط شهري " . number_format($install['monthly_amount'], 2) . " دج";
        } else {
            $details = "قسط (رقم المعاملة {$t['reference_id']}) - بيانات غير متوفرة";
        }
    }
    
    $t['details'] = $details;
    
    // نص مختصر للعرض في الجدول
    if ($t['type'] == 'grant') $t['short_desc'] = 'منحة';
    elseif ($t['type'] == 'loan') $t['short_desc'] = 'سلفة جديدة';
    else $t['short_desc'] = 'قسط مردود';
}
unset($t);

// ========== حساب الرصيد المتراكم ==========
$balance = 0;
$budgetStmt = $pdo->prepare("SELECT initial_budget FROM social_budget WHERE year = ? ORDER BY id DESC LIMIT 1");
$budgetStmt->execute([$year]);
$budgetRow = $budgetStmt->fetch();
$balance = $budgetRow ? $budgetRow['initial_budget'] : 0;

// ========== إحصائيات ==========
$totalGrants = 0;
$totalLoans = 0;
$totalInstallments = 0;
foreach ($transactions as $t) {
    if ($t['type'] == 'grant') $totalGrants += $t['amount'];
    elseif ($t['type'] == 'loan') $totalLoans += $t['amount'];
    elseif ($t['type'] == 'installment') $totalInstallments += $t['amount'];
}
?>

<style>
    .report-container {
        background: white;
        border-radius: 24px;
        padding: 25px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    }
    h2 { color: #1e3c72; margin-bottom: 20px; }
    .filters {
        background: #f8f9fa;
        padding: 15px;
        border-radius: 16px;
        margin-bottom: 25px;
        display: flex;
        gap: 15px;
        flex-wrap: wrap;
        align-items: center;
    }
    .stats-cards {
        display: flex;
        gap: 20px;
        margin-bottom: 30px;
        flex-wrap: wrap;
    }
    .stat-card {
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
        padding: 15px 20px;
        border-radius: 20px;
        min-width: 180px;
        text-align: center;
    }
    .stat-card.grants { background: linear-gradient(135deg, #dc3545, #b02a37); }
    .stat-card.loans { background: linear-gradient(135deg, #fd7e14, #e8590c); }
    .stat-card.installments { background: linear-gradient(135deg, #28a745, #1e7e34); }
    .stat-card .value { font-size: 24px; font-weight: bold; }
    table {
        width: 100%;
        border-collapse: collapse;
    }
    th, td {
        padding: 12px;
        text-align: center;
        border-bottom: 1px solid #ddd;
    }
    th { background: #2a5298; color: white; }
    tr:hover { background: #f1f1f1; }
    .btn {
        padding: 8px 16px;
        border-radius: 20px;
        text-decoration: none;
        margin: 0 5px;
    }
    .btn-primary { background: #2a5298; color: white; }
    .tooltip-row {
        cursor: help;
        position: relative;
    }
    .tooltip-row:hover::after {
        content: attr(data-tooltip);
        position: absolute;
        background: #1e3c72;
        color: white;
        padding: 6px 12px;
        border-radius: 8px;
        font-size: 12px;
        white-space: nowrap;
        top: -30px;
        left: 0;
        z-index: 1000;
        box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        max-width: 300px;
        white-space: normal;
        word-break: break-word;
    }
    .badge {
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: bold;
        display: inline-block;
    }
    .badge-grant { background: #dc3545; color: white; }
    .badge-loan { background: #fd7e14; color: white; }
    .badge-installment { background: #28a745; color: white; }
</style>

<div class="report-container">
    <h2>📊 تقرير الميزانية - سنة <?= $year ?></h2>

    <div class="filters">
        <form method="GET" style="display: flex; gap: 15px;">
            <select name="year">
                <?php for($y = 2020; $y <= date('Y'); $y++): ?>
                    <option value="<?= $y ?>" <?= $y == $year ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
            <select name="type">
                <option value="all" <?= $type == 'all' ? 'selected' : '' ?>>الكل</option>
                <option value="grant" <?= $type == 'grant' ? 'selected' : '' ?>>🎁 منح</option>
                <option value="loan" <?= $type == 'loan' ? 'selected' : '' ?>>💰 سلف</option>
                <option value="installment" <?= $type == 'installment' ? 'selected' : '' ?>>🔄 أقساط</option>
            </select>
            <button type="submit" class="btn btn-primary">🔍 عرض</button>
        </form>
    </div>

    <div class="stats-cards">
        <div class="stat-card grants"><div>🎁 إجمالي المنح</div><div class="value"><?= number_format($totalGrants, 2) ?> دج</div></div>
        <div class="stat-card loans"><div>💰 إجمالي السلف</div><div class="value"><?= number_format($totalLoans, 2) ?> دج</div></div>
        <div class="stat-card installments"><div>🔄 إجمالي الأقساط المردودة</div><div class="value"><?= number_format($totalInstallments, 2) ?> دج</div></div>
    </div>

    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>التاريخ</th>
                <th>النوع</th>
                <th>الوصف</th>
                <th>المبلغ (دج)</th>
                <th>النوع</th>
                <th>الرصيد بعد العملية</th>
            </tr>
        </thead>
        <tbody>
            <?php $i = 1; foreach($transactions as $t): 
                if ($t['is_deduct']) {
                    $balance -= $t['amount'];
                    $sign = '-';
                    $color = 'red';
                } else {
                    $balance += $t['amount'];
                    $sign = '+';
                    $color = 'green';
                }
                
                $tooltipText = $t['details'];
                $rowClass = $tooltipText ? 'tooltip-row' : '';
            ?>
            <tr class="<?= $rowClass ?>" <?= $tooltipText ? "data-tooltip=\"".htmlspecialchars($tooltipText)."\"" : '' ?>>
                <td><?= $i++ ?></td>
                <td><?= date('d/m/Y H:i', strtotime($t['transaction_date'])) ?></td>
                <td>
                    <?php
                        $badgeClass = '';
                        $typeText = '';
                        if ($t['type'] == 'grant') { $badgeClass = 'badge-grant'; $typeText = 'منحة'; }
                        elseif ($t['type'] == 'loan') { $badgeClass = 'badge-loan'; $typeText = 'سلفة'; }
                        else { $badgeClass = 'badge-installment'; $typeText = 'قسط مردود'; }
                    ?>
                    <span class="badge <?= $badgeClass ?>"><?= $typeText ?></span>
                </td>
                <td><?= htmlspecialchars($t['short_desc']) ?>  </td>
                <td style="color: <?= $color ?>; font-weight: bold;"><?= $sign ?> <?= number_format($t['amount'], 2) ?> دج </td>
                <td><?= $t['is_deduct'] ? '🧾 خصم' : '➕ إضافة' ?>  </td>
                <td><strong><?= number_format($balance, 2) ?> دج</strong> </td>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php include '../includes/footer.php'; ?>