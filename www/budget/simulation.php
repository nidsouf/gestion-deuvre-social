<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
require_once '../config/database.php';
require_once '../includes/functions.php';
include '../includes/header.php';

// جلب الميزانية الحالية
$stmt = $pdo->query("SELECT remaining_budget FROM social_budget ORDER BY year DESC LIMIT 1");
$currentBudget = $stmt->fetchColumn();
$currentBudget = $currentBudget ?: 0;

// ========== قيم افتراضية ثابتة (منطقية) ==========
$defaultLoanValue = 50000;   // قيمة السلفة الافتراضية (دج)
$defaultGrantValue = 10000;  // قيمة المنحة الافتراضية (دج)

// إحصائيات إضافية (تُعرض فقط وليس للمحاكاة)
$stmt = $pdo->query("SELECT COUNT(*) FROM deductions WHERE is_loan = 1 AND end_date >= date('now')");
$activeLoans = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) FROM employee_grants WHERE strftime('%Y', grant_date) = strftime('%Y', 'now')");
$grantsThisYear = $stmt->fetchColumn();

// معالجة المحاكاة
$simulatedBudget = $currentBudget;
$changes = [];
$scenario = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // دالة لتنظيف الأرقام
    function cleanNumber($input) {
        $cleaned = preg_replace('/[^0-9.-]/', '', $input);
        return floatval($cleaned);
    }
    
    $newLoans = (int)cleanNumber($_POST['new_loans'] ?? 0);
    $loanAmount = cleanNumber($_POST['loan_amount'] ?? $defaultLoanValue);
    $newGrants = (int)cleanNumber($_POST['new_grants'] ?? 0);
    $grantAmount = cleanNumber($_POST['grant_amount'] ?? $defaultGrantValue);
    $extraBudget = cleanNumber($_POST['extra_budget'] ?? 0);
    $deductBudget = cleanNumber($_POST['deduct_budget'] ?? 0);
    $scenario = $_POST['scenario'] ?? 'all';
    
    // التحقق من صحة القيم
    if (($scenario == 'loans' || $scenario == 'all') && ($newLoans < 0 || $loanAmount <= 0)) {
        $error = "يرجى إدخال عدد سلفات صحيح وقيمة سلفة أكبر من صفر.";
    } elseif (($scenario == 'grants' || $scenario == 'all') && ($newGrants < 0 || $grantAmount <= 0)) {
        $error = "يرجى إدخال عدد منح صحيح وقيمة منحة أكبر من صفر.";
    } elseif (($scenario == 'increase' && $extraBudget < 0) || ($scenario == 'decrease' && $deductBudget < 0)) {
        $error = "يرجى إدخال مبلغ موجب للزيادة أو الخصم.";
    } else {
        $simulatedBudget = $currentBudget;
        
        if ($scenario == 'loans' || $scenario == 'all') {
            $totalLoans = $newLoans * $loanAmount;
            $simulatedBudget -= $totalLoans;
            $changes[] = "🔻 إضافة $newLoans سلفة جديدة بقيمة " . number_format($loanAmount, 2) . " دج لكل منها (إجمالي " . number_format($totalLoans, 2) . " دج)";
        }
        
        if ($scenario == 'grants' || $scenario == 'all') {
            $totalGrants = $newGrants * $grantAmount;
            $simulatedBudget -= $totalGrants;
            $changes[] = "🎁 إضافة $newGrants منحة جديدة بقيمة " . number_format($grantAmount, 2) . " دج لكل منها (إجمالي " . number_format($totalGrants, 2) . " دج)";
        }
        
        if ($scenario == 'increase' || $scenario == 'all') {
            $simulatedBudget += $extraBudget;
            $changes[] = "➕ إضافة مبلغ " . number_format($extraBudget, 2) . " دج إلى الميزانية";
        }
        
        if ($scenario == 'decrease' || $scenario == 'all') {
            $simulatedBudget -= $deductBudget;
            $changes[] = "➖ خصم مبلغ " . number_format($deductBudget, 2) . " دج من الميزانية";
        }
    }
}

$diff = $simulatedBudget - $currentBudget;
$diffPercent = $currentBudget > 0 ? round(($diff / $currentBudget) * 100, 1) : 0;
?>

<style>
    .simulation-card {
        background: white;
        border-radius: 20px;
        padding: 25px;
        margin-bottom: 25px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    }
    .budget-current {
        background: linear-gradient(135deg, #2a5298, #1e3c72);
        color: white;
        padding: 20px;
        border-radius: 16px;
        text-align: center;
    }
    .budget-simulated {
        background: linear-gradient(135deg, #28a745, #1e7e34);
        color: white;
        padding: 20px;
        border-radius: 16px;
        text-align: center;
    }
    .budget-diff {
        background: linear-gradient(135deg, #ffc107, #e0a800);
        color: #333;
        padding: 20px;
        border-radius: 16px;
        text-align: center;
    }
    .budget-diff.negative {
        background: linear-gradient(135deg, #dc3545, #b02a37);
        color: white;
    }
    .change-list {
        background: #f8f9fa;
        padding: 15px;
        border-radius: 12px;
        margin-top: 15px;
    }
    .form-group {
        margin-bottom: 15px;
    }
    .form-group label {
        display: block;
        font-weight: bold;
        margin-bottom: 5px;
    }
    .form-group input, .form-group select {
        width: 100%;
        padding: 10px;
        border: 1px solid #ddd;
        border-radius: 8px;
    }
    .btn-primary {
        background: #2a5298;
        color: white;
        padding: 12px 25px;
        border: none;
        border-radius: 30px;
        cursor: pointer;
        font-weight: bold;
        width: 100%;
    }
    .stats-row {
        display: flex;
        gap: 20px;
        margin-bottom: 25px;
        flex-wrap: wrap;
    }
    .stat-box {
        flex: 1;
        background: #f0f2f5;
        padding: 15px;
        border-radius: 16px;
        text-align: center;
    }
    .stat-box .value {
        font-size: 24px;
        font-weight: bold;
        color: #2a5298;
    }
    .error-message {
        background: #f8d7da;
        color: #721c24;
        padding: 12px;
        border-radius: 12px;
        margin-bottom: 20px;
    }
    @media (max-width: 700px) {
        .stats-row { flex-direction: column; }
    }
</style>

<div class="section">
    <div class="section-header">
        <h3 class="section-title">🔮 محاكاة الميزانية المستقبلية</h3>
    </div>

    <?php if ($error): ?>
        <div class="error-message">⚠️ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="stats-row">
        <div class="stat-box">
            <div>💰 الميزانية الحالية</div>
            <div class="value"><?= number_format($currentBudget, 2) ?> دج</div>
        </div>
        <div class="stat-box">
            <div>📋 سلفات نشطة حالياً</div>
            <div class="value"><?= $activeLoans ?></div>
        </div>
        <div class="stat-box">
            <div>🎁 منح هذا العام</div>
            <div class="value"><?= $grantsThisYear ?></div>
        </div>
        <div class="stat-box">
            <div>💡 القيم الافتراضية</div>
            <div class="value">سلفة: <?= number_format($defaultLoanValue, 0) ?> دج<br>منحة: <?= number_format($defaultGrantValue, 0) ?> دج</div>
        </div>
    </div>

    <div class="simulation-card">
        <form method="POST">
            <div class="stats-row">
                <div class="form-group" style="flex:1;">
                    <label>📌 اختر السيناريو</label>
                    <select name="scenario" class="form-control">
                        <option value="all" <?= $scenario == 'all' ? 'selected' : '' ?>>🌍 كل التغييرات معاً</option>
                        <option value="loans" <?= $scenario == 'loans' ? 'selected' : '' ?>>💰 سلفات فقط</option>
                        <option value="grants" <?= $scenario == 'grants' ? 'selected' : '' ?>>🎁 منح فقط</option>
                        <option value="increase" <?= $scenario == 'increase' ? 'selected' : '' ?>>➕ زيادة الميزانية</option>
                        <option value="decrease" <?= $scenario == 'decrease' ? 'selected' : '' ?>>➖ خصم من الميزانية</option>
                    </select>
                </div>
            </div>

            <div class="stats-row" id="loansFields">
                <div class="form-group" style="flex:1;">
                    <label>💰 عدد السلفات الجديدة</label>
                    <input type="number" name="new_loans" value="1" min="0" step="1">
                </div>
                <div class="form-group" style="flex:1;">
                    <label>💵 قيمة السلفة (دج)</label>
                    <input type="number" name="loan_amount" value="<?= $defaultLoanValue ?>" step="1000" min="0">
                </div>
            </div>

            <div class="stats-row" id="grantsFields">
                <div class="form-group" style="flex:1;">
                    <label>🎁 عدد المنح الجديدة</label>
                    <input type="number" name="new_grants" value="1" min="0" step="1">
                </div>
                <div class="form-group" style="flex:1;">
                    <label>💵 قيمة المنحة (دج)</label>
                    <input type="number" name="grant_amount" value="<?= $defaultGrantValue ?>" step="1000" min="0">
                </div>
            </div>

            <div class="stats-row" id="increaseFields">
                <div class="form-group" style="flex:1;">
                    <label>➕ إضافة مبلغ إلى الميزانية (دج)</label>
                    <input type="number" name="extra_budget" value="0" step="10000" min="0">
                </div>
            </div>

            <div class="stats-row" id="decreaseFields">
                <div class="form-group" style="flex:1;">
                    <label>➖ خصم مبلغ من الميزانية (دج)</label>
                    <input type="number" name="deduct_budget" value="0" step="10000" min="0">
                </div>
            </div>

            <button type="submit" class="btn-primary">🔮 حساب الميزانية المتوقعة</button>
        </form>
    </div>

    <?php if ($_SERVER['REQUEST_METHOD'] == 'POST' && !$error): ?>
    <div class="simulation-card">
        <h4>📊 نتيجة المحاكاة</h4>
        
        <div class="stats-row">
            <div class="budget-current">
                <strong>💰 الميزانية الحالية</strong>
                <div style="font-size: 28px; font-weight: bold;"><?= number_format($currentBudget, 2) ?> دج</div>
            </div>
            <div class="budget-simulated">
                <strong>🔮 الميزانية بعد المحاكاة</strong>
                <div style="font-size: 28px; font-weight: bold;"><?= number_format($simulatedBudget, 2) ?> دج</div>
            </div>
            <div class="budget-diff <?= $diff < 0 ? 'negative' : '' ?>">
                <strong>📉 الفرق</strong>
                <div style="font-size: 28px; font-weight: bold;"><?= $diff >= 0 ? '+' : '' ?><?= number_format($diff, 2) ?> دج</div>
                <div>(<?= $diffPercent ?>%)</div>
            </div>
        </div>

        <?php if (!empty($changes)): ?>
        <div class="change-list">
            <strong>📝 التغييرات المطبقة:</strong>
            <ul>
                <?php foreach($changes as $change): ?>
                    <li><?= $change ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <div style="margin-top: 20px; padding: 15px; background: #e3f2fd; border-radius: 12px;">
            <strong>💡 التوصية:</strong>
            <?php if ($simulatedBudget < 0): ?>
                ⚠️ الميزانية ستصبح سالبة! يرجى تقليل النفقات أو زيادة الميزانية.
            <?php elseif ($simulatedBudget < $currentBudget * 0.2): ?>
                ⚠️ الميزانية المتبقية أقل من 20% من الحالية. ينصح بالترشيد.
            <?php elseif ($simulatedBudget > $currentBudget): ?>
                ✅ الميزانية زادت. يمكن صرف المزيد من المنح أو السلفات.
            <?php else: ?>
                ✅ الميزانية لا تزال آمنة. استمر بحذر.
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
    const scenario = document.querySelector('select[name="scenario"]');
    const loansDiv = document.getElementById('loansFields');
    const grantsDiv = document.getElementById('grantsFields');
    const increaseDiv = document.getElementById('increaseFields');
    const decreaseDiv = document.getElementById('decreaseFields');

    function updateFields() {
        const val = scenario.value;
        loansDiv.style.display = 'flex';
        grantsDiv.style.display = 'flex';
        increaseDiv.style.display = 'flex';
        decreaseDiv.style.display = 'flex';
        
        if (val == 'loans') {
            grantsDiv.style.display = 'none';
            increaseDiv.style.display = 'none';
            decreaseDiv.style.display = 'none';
        } else if (val == 'grants') {
            loansDiv.style.display = 'none';
            increaseDiv.style.display = 'none';
            decreaseDiv.style.display = 'none';
        } else if (val == 'increase') {
            loansDiv.style.display = 'none';
            grantsDiv.style.display = 'none';
            decreaseDiv.style.display = 'none';
        } else if (val == 'decrease') {
            loansDiv.style.display = 'none';
            grantsDiv.style.display = 'none';
            increaseDiv.style.display = 'none';
        }
    }
    scenario.addEventListener('change', updateFields);
    updateFields();
</script>

<?php include '../includes/footer.php'; ?>