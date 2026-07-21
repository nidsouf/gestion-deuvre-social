<?php
/**
 * budget/simulation.php - محاكاة الميزانية المستقبلية (محسّن)
 */
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';
require_once '../includes/budget_helpers.php';

// جلب الميزانية الحالية
$stmt = $pdo->query("SELECT remaining_budget FROM social_budget ORDER BY year DESC LIMIT 1");
$currentBudget = $stmt->fetchColumn() ?: 0;

// حساب متوسط قيم السلف والمنح من البيانات الفعلية
$avgLoan = $pdo->query("SELECT COALESCE(AVG(amount), 50000) FROM budget_transactions WHERE type = 'loan' AND is_deduct = 1")->fetchColumn();
$avgGrant = $pdo->query("SELECT COALESCE(AVG(amount), 10000) FROM budget_transactions WHERE type = 'grant' AND is_deduct = 1")->fetchColumn();

$defaultLoanValue = round($avgLoan / 1000) * 1000; // تقريب لألف
$defaultGrantValue = round($avgGrant / 1000) * 1000;

$simulatedBudget = $currentBudget;
$changes = [];
$scenario = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $newLoans = (int)$_POST['new_loans'];
    $loanAmount = (float)$_POST['loan_amount'];
    $newGrants = (int)$_POST['new_grants'];
    $grantAmount = (float)$_POST['grant_amount'];
    $extraBudget = (float)$_POST['extra_budget'];
    $deductBudget = (float)$_POST['deduct_budget'];
    $scenario = $_POST['scenario'];

    // التحقق
    if (($scenario == 'loans' || $scenario == 'all') && ($newLoans < 0 || $loanAmount <= 0)) {
        $error = "يرجى إدخال عدد سلفات صحيح وقيمة سلفة أكبر من صفر.";
    } elseif (($scenario == 'grants' || $scenario == 'all') && ($newGrants < 0 || $grantAmount <= 0)) {
        $error = "يرجى إدخال عدد منح صحيح وقيمة منحة أكبر من صفر.";
    } elseif (($scenario == 'increase' && $extraBudget < 0) || ($scenario == 'decrease' && $deductBudget < 0)) {
        $error = "يرجى إدخال مبلغ موجب.";
    } else {
        $simulatedBudget = $currentBudget;
        if ($scenario == 'loans' || $scenario == 'all') {
            $total = $newLoans * $loanAmount;
            $simulatedBudget -= $total;
            $changes[] = "🔻 إضافة $newLoans سلفة بقيمة " . formatCurrency($loanAmount) . " لكل (إجمالي " . formatCurrency($total) . ")";
        }
        if ($scenario == 'grants' || $scenario == 'all') {
            $total = $newGrants * $grantAmount;
            $simulatedBudget -= $total;
            $changes[] = "🎁 إضافة $newGrants منحة بقيمة " . formatCurrency($grantAmount) . " لكل (إجمالي " . formatCurrency($total) . ")";
        }
        if ($scenario == 'increase' || $scenario == 'all') {
            $simulatedBudget += $extraBudget;
            $changes[] = "➕ إضافة " . formatCurrency($extraBudget) . " إلى الميزانية";
        }
        if ($scenario == 'decrease' || $scenario == 'all') {
            $simulatedBudget -= $deductBudget;
            $changes[] = "➖ خصم " . formatCurrency($deductBudget) . " من الميزانية";
        }
    }
}

$diff = $simulatedBudget - $currentBudget;
$diffPercent = $currentBudget > 0 ? round(($diff / $currentBudget) * 100, 1) : 0;

include '../includes/header.php';
?>
<link rel="stylesheet" href="../assets/css/budget.css">

<div class="budget-container">
    <h2>🔮 محاكاة الميزانية المستقبلية</h2>

    <?php if ($error): ?>
        <div style="background:#f8d7da; color:#721c24; padding:12px; border-radius:10px; margin-bottom:20px;">⚠️ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div style="display:flex; gap:20px; flex-wrap:wrap; margin-bottom:25px;">
        <div style="flex:1; background:#f0f2f5; padding:15px; border-radius:16px; text-align:center;">
            <div>💰 الميزانية الحالية</div>
            <div style="font-size:24px; font-weight:bold; color:#2a5298;"><?= formatCurrency($currentBudget) ?></div>
        </div>
        <div style="flex:1; background:#f0f2f5; padding:15px; border-radius:16px; text-align:center;">
            <div>📊 متوسط قيمة السلفة</div>
            <div style="font-size:24px; font-weight:bold; color:#fd7e14;"><?= formatCurrency($defaultLoanValue) ?></div>
        </div>
        <div style="flex:1; background:#f0f2f5; padding:15px; border-radius:16px; text-align:center;">
            <div>🎁 متوسط قيمة المنحة</div>
            <div style="font-size:24px; font-weight:bold; color:#28a745;"><?= formatCurrency($defaultGrantValue) ?></div>
        </div>
    </div>

    <div style="background:white; border-radius:20px; padding:25px; box-shadow:0 2px 10px rgba(0,0,0,0.05); margin-bottom:25px;">
        <form method="POST">
            <div style="margin-bottom:15px;">
                <label>📌 اختر السيناريو</label>
                <select name="scenario" class="form-control" id="scenarioSelect">
                    <option value="all" <?= $scenario == 'all' ? 'selected' : '' ?>>🌍 كل التغييرات معاً</option>
                    <option value="loans" <?= $scenario == 'loans' ? 'selected' : '' ?>>💰 سلفات فقط</option>
                    <option value="grants" <?= $scenario == 'grants' ? 'selected' : '' ?>>🎁 منح فقط</option>
                    <option value="increase" <?= $scenario == 'increase' ? 'selected' : '' ?>>➕ زيادة الميزانية</option>
                    <option value="decrease" <?= $scenario == 'decrease' ? 'selected' : '' ?>>➖ خصم من الميزانية</option>
                </select>
            </div>

            <div id="loansFields" class="scenario-fields" style="display:flex; gap:20px; flex-wrap:wrap;">
                <div style="flex:1;">
                    <label>💰 عدد السلفات الجديدة</label>
                    <input type="number" name="new_loans" value="1" min="0" step="1" class="form-control">
                </div>
                <div style="flex:1;">
                    <label>💵 قيمة السلفة (دج)</label>
                    <input type="number" name="loan_amount" value="<?= $defaultLoanValue ?>" step="1000" min="0" class="form-control">
                </div>
            </div>

            <div id="grantsFields" class="scenario-fields" style="display:flex; gap:20px; flex-wrap:wrap;">
                <div style="flex:1;">
                    <label>🎁 عدد المنح الجديدة</label>
                    <input type="number" name="new_grants" value="1" min="0" step="1" class="form-control">
                </div>
                <div style="flex:1;">
                    <label>💵 قيمة المنحة (دج)</label>
                    <input type="number" name="grant_amount" value="<?= $defaultGrantValue ?>" step="1000" min="0" class="form-control">
                </div>
            </div>

            <div id="increaseFields" class="scenario-fields" style="display:none;">
                <div style="flex:1;">
                    <label>➕ إضافة مبلغ إلى الميزانية (دج)</label>
                    <input type="number" name="extra_budget" value="0" step="10000" min="0" class="form-control">
                </div>
            </div>

            <div id="decreaseFields" class="scenario-fields" style="display:none;">
                <div style="flex:1;">
                    <label>➖ خصم مبلغ من الميزانية (دج)</label>
                    <input type="number" name="deduct_budget" value="0" step="10000" min="0" class="form-control">
                </div>
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%; padding:12px; margin-top:15px;">🔮 حساب الميزانية المتوقعة</button>
        </form>
    </div>

    <?php if ($_SERVER['REQUEST_METHOD'] == 'POST' && !$error): ?>
    <div style="background:white; border-radius:20px; padding:25px; box-shadow:0 2px 10px rgba(0,0,0,0.05);">
        <h4>📊 نتيجة المحاكاة</h4>
        <div style="display:flex; gap:20px; flex-wrap:wrap; margin-top:15px;">
            <div style="flex:1; background:linear-gradient(135deg,#2a5298,#1e3c72); color:white; padding:20px; border-radius:16px; text-align:center;">
                <strong>💰 الميزانية الحالية</strong>
                <div style="font-size:28px; font-weight:bold;"><?= formatCurrency($currentBudget) ?></div>
            </div>
            <div style="flex:1; background:linear-gradient(135deg,#28a745,#1e7e34); color:white; padding:20px; border-radius:16px; text-align:center;">
                <strong>🔮 الميزانية بعد المحاكاة</strong>
                <div style="font-size:28px; font-weight:bold;"><?= formatCurrency($simulatedBudget) ?></div>
            </div>
            <div style="flex:1; background:<?= $diff < 0 ? 'linear-gradient(135deg,#dc3545,#b02a37)' : 'linear-gradient(135deg,#ffc107,#e0a800)' ?>; color:<?= $diff < 0 ? 'white' : '#333' ?>; padding:20px; border-radius:16px; text-align:center;">
                <strong>📉 الفرق</strong>
                <div style="font-size:28px; font-weight:bold;"><?= $diff >= 0 ? '+' : '' ?><?= formatCurrency($diff) ?></div>
                <div>(<?= $diffPercent ?>%)</div>
            </div>
        </div>

        <?php if (!empty($changes)): ?>
        <div style="background:#f8f9fa; padding:15px; border-radius:12px; margin-top:20px;">
            <strong>📝 التغييرات المطبقة:</strong>
            <ul>
                <?php foreach($changes as $change): ?>
                    <li><?= $change ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <div style="margin-top:20px; padding:15px; background:#e3f2fd; border-radius:12px;">
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
    const scenario = document.getElementById('scenarioSelect');
    const loansDiv = document.getElementById('loansFields');
    const grantsDiv = document.getElementById('grantsFields');
    const increaseDiv = document.getElementById('increaseFields');
    const decreaseDiv = document.getElementById('decreaseFields');

    function updateFields() {
        const val = scenario.value;
        loansDiv.style.display = 'flex';
        grantsDiv.style.display = 'flex';
        increaseDiv.style.display = 'none';
        decreaseDiv.style.display = 'none';
        if (val === 'loans') { grantsDiv.style.display = 'none'; increaseDiv.style.display = 'none'; decreaseDiv.style.display = 'none'; }
        else if (val === 'grants') { loansDiv.style.display = 'none'; increaseDiv.style.display = 'none'; decreaseDiv.style.display = 'none'; }
        else if (val === 'increase') { loansDiv.style.display = 'none'; grantsDiv.style.display = 'none'; decreaseDiv.style.display = 'none'; increaseDiv.style.display = 'flex'; }
        else if (val === 'decrease') { loansDiv.style.display = 'none'; grantsDiv.style.display = 'none'; increaseDiv.style.display = 'none'; decreaseDiv.style.display = 'flex'; }
    }
    scenario.addEventListener('change', updateFields);
    updateFields();
</script>

<?php include '../includes/footer.php'; ?>