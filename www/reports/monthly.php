<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/security.php';

if (!function_exists('getMonthNameArabic')) {
    function getMonthNameArabic($month) {
        $months = [1=>'جانفي',2=>'فيفري',3=>'مارس',4=>'أفريل',5=>'ماي',6=>'جوان',7=>'جويلية',8=>'أوت',9=>'سبتمبر',10=>'أكتوبر',11=>'نوفمبر',12=>'ديسمبر'];
        return $months[(int)$month] ?? '';
    }
}

// ============================================================
// معالجة POST (تسديد فردي أو كلي)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRFToken();

    $year = (int)($_POST['year'] ?? date('Y'));
    $month = (int)($_POST['month'] ?? date('m'));
    $source_filter = (int)($_POST['source_filter'] ?? 0);
    $employee_filter = (int)($_POST['employee_filter'] ?? 0);

    // --- تسديد قسط واحد ---
    if (isset($_POST['pay_single']) && isset($_POST['installment_id'])) {
        $installment_id = (int)$_POST['installment_id'];

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                SELECT mi.*, d.is_loan, d.id as deduction_id
                FROM monthly_installments mi
                JOIN deductions d ON mi.deduction_id = d.id
                WHERE mi.id = ? AND mi.is_paid = 0
            ");
            $stmt->execute([$installment_id]);
            $inst = $stmt->fetch();

            if (!$inst) {
                throw new Exception('القسط غير موجود أو تم سداده مسبقاً');
            }

            $update = $pdo->prepare("UPDATE monthly_installments SET is_paid = 1, paid_date = datetime('now') WHERE id = ?");
            $update->execute([$installment_id]);

            if ($inst['is_loan']) {
                $amount = $inst['amount'];
                $stmtBudget = $pdo->prepare("
                    UPDATE social_budget 
                    SET remaining_budget = remaining_budget + ?
                    WHERE id = (SELECT id FROM social_budget ORDER BY year DESC LIMIT 1)
                ");
                $stmtBudget->execute([$amount]);

                $stmtTrans = $pdo->prepare("
                    INSERT INTO budget_transactions (reference_id, type, amount, description, is_deduct, transaction_date)
                    VALUES (?, 'installment', ?, ?, 0, datetime('now'))
                ");
                $stmtTrans->execute([
                    $inst['deduction_id'],
                    $amount,
                    "استرجاع سلفة (قسط شهر " . getMonthNameArabic($month) . " " . $year . ")"
                ]);
            }

            $pdo->commit();
            $_SESSION['toast'] = ['message' => '✅ تم تسديد القسط بنجاح', 'type' => 'success', 'duration' => 3000];
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['toast'] = ['message' => '❌ ' . $e->getMessage(), 'type' => 'error', 'duration' => 5000];
        }

        header("Location: monthly.php?year=$year&month=$month&source_id=$source_filter&employee_id=$employee_filter");
        exit;
    }

    // --- تسديد الكل ---
    if (isset($_POST['pay_all'])) {
        try {
            $pdo->beginTransaction();

            $sql = "
                SELECT mi.id, mi.amount, mi.month, mi.year, d.is_loan, d.id as deduction_id
                FROM monthly_installments mi
                JOIN deductions d ON mi.deduction_id = d.id
                WHERE mi.year = ? AND mi.month = ? AND mi.is_paid = 0
            ";
            $params = [$year, $month];
            if ($source_filter > 0) {
                $sql .= " AND mi.source_id = ?";
                $params[] = $source_filter;
            }
            if ($employee_filter > 0) {
                $sql .= " AND mi.employee_id = ?";
                $params[] = $employee_filter;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $installments = $stmt->fetchAll();

            if (empty($installments)) {
                throw new Exception('لا توجد أقساط غير مدفوعة لهذا الشهر');
            }

            $count = 0;
            $total_refunded = 0;

            foreach ($installments as $inst) {
                $update = $pdo->prepare("UPDATE monthly_installments SET is_paid = 1, paid_date = datetime('now') WHERE id = ?");
                $update->execute([$inst['id']]);

                if ($inst['is_loan']) {
                    $amount = $inst['amount'];
                    $total_refunded += $amount;

                    $stmtBudget = $pdo->prepare("
                        UPDATE social_budget 
                        SET remaining_budget = remaining_budget + ?
                        WHERE id = (SELECT id FROM social_budget ORDER BY year DESC LIMIT 1)
                    ");
                    $stmtBudget->execute([$amount]);

                    $stmtTrans = $pdo->prepare("
                        INSERT INTO budget_transactions (reference_id, type, amount, description, is_deduct, transaction_date)
                        VALUES (?, 'installment', ?, ?, 0, datetime('now'))
                    ");
                    $stmtTrans->execute([
                        $inst['deduction_id'],
                        $amount,
                        "استرجاع سلفة (تسديد الكل – شهر " . getMonthNameArabic($month) . " " . $year . ")"
                    ]);
                }
                $count++;
            }

            $pdo->commit();
            $_SESSION['toast'] = [
                'message' => "✅ تم تسديد $count قسطاً" . ($total_refunded > 0 ? " (إعادة $total_refunded دج للميزانية)" : ""),
                'type' => 'success',
                'duration' => 3000
            ];
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['toast'] = ['message' => '❌ ' . $e->getMessage(), 'type' => 'error', 'duration' => 5000];
        }

        header("Location: monthly.php?year=$year&month=$month&source_id=$source_filter&employee_id=$employee_filter");
        exit;
    }
}

// ============================================================
// جلب البيانات وعرض التقرير
// ============================================================
$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : date('m');
$source_id = isset($_GET['source_id']) ? (int)$_GET['source_id'] : 0;
$employee_id = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;

$month_name_ar = getMonthNameArabic($month);
$report_ym = sprintf("%04d-%02d", $year, $month);

$sources = $pdo->query("SELECT id, name FROM sources ORDER BY name")->fetchAll();
$employees = $pdo->query("SELECT id, name, category FROM employees ORDER BY name")->fetchAll();

$sql = "SELECT 
            mi.id as installment_id,
            mi.amount as monthly_amount,
            mi.is_paid,
            e.id as employee_id,
            e.name as employee_name,
            e.category,
            s.name as source_name,
            s.id as source_id,
            d.is_loan,
            d.credit_balance,
            (SELECT MIN(ep.payment_date) FROM early_payments ep WHERE ep.deduction_id = d.id AND ep.is_reversed = 0) as first_early_payment_date,
            'regular' as type
        FROM monthly_installments mi
        JOIN employees e ON mi.employee_id = e.id
        JOIN sources s ON mi.source_id = s.id
        JOIN deductions d ON mi.deduction_id = d.id
        WHERE mi.year = :year AND mi.month = :month
          AND mi.is_postponed = 0
        ";
$params = [':year' => $year, ':month' => $month];
if ($source_id > 0) { $sql .= " AND mi.source_id = :source_id"; $params[':source_id'] = $source_id; }
if ($employee_id > 0) { $sql .= " AND mi.employee_id = :employee_id"; $params[':employee_id'] = $employee_id; }
$sql .= " ORDER BY e.name ASC";

$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->execute();
$installments = $stmt->fetchAll();

function getEffectiveAmount($item, $report_ym) {
    if ($item['type'] == 'djezzy') return $item['monthly_amount'];
    $monthly = $item['monthly_amount'];
    $pay_date = $item['first_early_payment_date'];
    if (!empty($pay_date)) {
        $pay_ym = substr($pay_date, 0, 7);
        if ($pay_ym == $report_ym) {
            return $item['credit_balance'];
        }
        $next_ym = date('Y-m', strtotime($pay_date . ' +1 month'));
        if ($next_ym == $report_ym) {
            $remaining = $monthly - $item['credit_balance'];
            return $remaining < 0 ? 0 : $remaining;
        }
    }
    return $monthly;
}

$permanent = []; $contract = [];
foreach ($installments as $it) {
    if ($it['category'] == 'Permanent') $permanent[] = $it;
    else $contract[] = $it;
}
usort($permanent, fn($a,$b)=>strcmp($a['employee_name'],$b['employee_name']));
usort($contract, fn($a,$b)=>strcmp($a['employee_name'],$b['employee_name']));

$totalPermanent = array_sum(array_map(fn($it)=>getEffectiveAmount($it,$report_ym), $permanent));
$totalContract = array_sum(array_map(fn($it)=>getEffectiveAmount($it,$report_ym), $contract));
$grandTotal = $totalPermanent + $totalContract;

$csrf_token = generateCSRFToken();

// حساب عدد الأقساط غير المدفوعة لتسديد الكل
$unpaid_count = 0;
foreach ($installments as $it) {
    if ($it['is_paid'] == 0) $unpaid_count++;
}

include '../includes/header.php';
?>

<style>
    .report-container { direction: rtl; max-width: 1400px; margin: 0 auto; padding: 20px; }
    .report-header { background: linear-gradient(135deg,#1e3c72,#2a5298); color: white; padding: 15px; border-radius: 10px; text-align: center; margin-bottom: 20px; }
    .filters { background: #f8f9fa; border-radius: 20px; padding: 15px; margin-bottom: 20px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
    .filter-group { display: flex; align-items: center; gap: 5px; background: white; padding: 5px 10px; border-radius: 8px; border: 1px solid #ddd; }
    .filter-group label { font-weight: bold; margin: 0; }
    .filters select, .filters button, .filters a { padding: 8px 15px; border-radius: 8px; border: 1px solid #ccc; font-size: 14px; text-decoration: none; display: inline-block; cursor: pointer; }
    .btn-primary { background: #2a5298; color: white; border: none; }
    .btn-success { background: #28a745; color: white; border: none; }
    .btn-pay-all { background: #007bff; color: white; border: none; padding: 8px 20px; border-radius: 30px; cursor: pointer; font-weight: bold; }
    .btn-pay-all:hover { background: #0069d9; }
    .section-title { font-size: 18px; font-weight: bold; margin: 20px 0 10px; padding-right: 10px; border-right: 4px solid #2a5298; }
    .data-table { width: 100%; border-collapse: collapse; background: white; margin-bottom: 20px; font-size: 14px; }
    .data-table th, .data-table td { border: 1px solid #ddd; padding: 8px; text-align: center; }
    .data-table th { background: #2a5298; color: white; }
    .total-row { background: #ffd700; font-weight: bold; }
    .badge-loan { background: #ff9800; color: white; padding: 4px 10px; border-radius: 20px; display: inline-block; }
    .badge-normal { background: #28a745; color: white; padding: 4px 10px; border-radius: 20px; display: inline-block; }
    .status-active { background: #d4edda; color: #155724; padding: 4px 8px; border-radius: 20px; display: inline-block; }
    .status-paid { background: #cce5ff; color: #004085; padding: 4px 8px; border-radius: 20px; display: inline-block; }
    .btn-pay { background: #28a745; color: white; padding: 4px 12px; border-radius: 20px; border: none; cursor: pointer; font-size: 12px; }
    .btn-pay:hover { background: #218838; }
    .btn-pay-disabled { background: #6c757d; color: white; padding: 4px 12px; border-radius: 20px; border: none; font-size: 12px; cursor: not-allowed; }
    .paid-row { background: #f0f8ff; }
    .paid-row td { background: #f0f8ff; }

    /* مودال مشترك */
    .modal-overlay {
        display: none;
        position: fixed;
        top: 0; left: 0; width: 100%; height: 100%;
        background: rgba(0,0,0,0.5);
        z-index: 9999;
        justify-content: center;
        align-items: center;
    }
    .modal-overlay.active { display: flex; }
    .modal-box {
        background: white;
        padding: 30px;
        border-radius: 20px;
        max-width: 500px;
        width: 90%;
        box-shadow: 0 10px 40px rgba(0,0,0,0.3);
        text-align: center;
    }
    .modal-box h3 { margin-top: 0; }
    .modal-box .actions { display: flex; gap: 10px; justify-content: center; margin-top: 20px; }
    .btn-confirm { background: #28a745; color: white; padding: 10px 25px; border: none; border-radius: 30px; cursor: pointer; font-weight: bold; }
    .btn-confirm:hover { background: #218838; }
    .btn-cancel-modal { background: #6c757d; color: white; padding: 10px 25px; border: none; border-radius: 30px; cursor: pointer; font-weight: bold; }
    .btn-cancel-modal:hover { background: #5a6268; }
    .modal-box .text-muted { color: #6c757d; font-size: 14px; }
</style>

<div class="report-container">
    <div class="report-header">
        <h2>📅 التقرير الشهري للاقتطاعات</h2>
        <h3><?= $month_name_ar . ' ' . $year ?></h3>
    </div>
    
    <div class="filters">
        <form method="GET" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center; width:100%;">
            <div class="filter-group"><label>السنة:</label><select name="year">
                <?php for($y=2020; $y<=date('Y')+1; $y++): ?>
                    <option value="<?=$y?>" <?=$y==$year?'selected':''?>><?=$y?></option>
                <?php endfor; ?>
            </select></div>
            <div class="filter-group"><label>الشهر:</label><select name="month">
                <?php for($m=1; $m<=12; $m++): ?>
                    <option value="<?=$m?>" <?=$m==$month?'selected':''?>><?=getMonthNameArabic($m)?></option>
                <?php endfor; ?>
            </select></div>
            <div class="filter-group"><label>المصدر:</label><select name="source_id">
                <option value="0">جميع المصادر</option>
                <?php foreach($sources as $src): ?>
                    <option value="<?=$src['id']?>" <?=($source_id==$src['id'])?'selected':''?>><?=htmlspecialchars($src['name'])?></option>
                <?php endforeach; ?>
            </select></div>
            <div class="filter-group"><label>الموظف:</label><select name="employee_id">
                <option value="0">جميع الموظفين</option>
                <?php foreach($employees as $emp): ?>
                    <option value="<?=$emp['id']?>" <?=($employee_id==$emp['id'])?'selected':''?>><?=htmlspecialchars($emp['name'])?></option>
                <?php endforeach; ?>
            </select></div>
            <button type="submit" class="btn-primary">🔍 عرض</button>
            <a href="?year=<?=$year?>&month=<?=$month?>&source_id=<?=$source_id?>&employee_id=<?=$employee_id?>&print=1" target="_blank" class="btn-success">🖨️ طباعة</a>
        </form>
    </div>

    <?php if (empty($installments)): ?>
        <div style="background:#f8d7da; padding:20px; text-align:center;">⚠️ لا توجد بيانات للشهر والفلاتر المحددة</div>
    <?php else: ?>
        <!-- زر تسديد الكل (يفتح المودال) -->
        <?php if ($unpaid_count > 0): ?>
            <button type="button" class="btn-pay-all" onclick="openPayAllModal(<?= $unpaid_count ?>)">
                💰 تسديد الكل (<?= $unpaid_count ?> قسط)
            </button>
        <?php else: ?>
            <button type="button" class="btn-pay-all" style="background:#6c757d; cursor:not-allowed;" disabled>
                ✅ جميع الأقساط مدفوعة
            </button>
        <?php endif; ?>

        <div class="section-title">👔 الموظفون الدائمون</div>
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>الموظف</th>
                    <th>المصدر</th>
                    <th>المبلغ (دج)</th>
                    <th>النوع</th>
                    <th>الحالة</th>
                    <th>تسديد</th>
                </tr>
            </thead>
            <tbody>
            <?php if(empty($permanent)): ?>
                <tr><td colspan="7" style="text-align:center;">لا توجد بيانات للدائمين</td></tr>
            <?php else: $i=1; foreach($permanent as $it):
                $eff = getEffectiveAmount($it, $report_ym);
                $loanBadge = $it['is_loan'] ? '<span class="badge-loan">💰 سلفة</span>' : '<span class="badge-normal">📌 اقتطاع</span>';
                $isPaid = $it['is_paid'] == 1;
                $rowClass = $isPaid ? 'paid-row' : '';
                $statusText = $isPaid ? '✅ مدفوع' : '✅ نشط';
                $statusClass = $isPaid ? 'status-paid' : 'status-active';
                $modalData = [
                    'installment_id' => $it['installment_id'],
                    'employee_name' => $it['employee_name'],
                    'month' => $month_name_ar,
                    'amount' => number_format($eff, 2)
                ];
            ?>
            <tr class="<?= $rowClass ?>">
                <td><?= $i++ ?></td>
                <td><?= htmlspecialchars($it['employee_name']) ?></td>
                <td><?= htmlspecialchars($it['source_name']) ?></td>
                <td><?= number_format($eff, 2) ?> دج</td>
                <td><?= $loanBadge ?></td>
                <td><span class="<?= $statusClass ?>"><?= $statusText ?></span></td>
                <td>
                    <?php if (!$isPaid): ?>
                        <button type="button" class="btn-pay" onclick="openPayModal(<?= $modalData['installment_id'] ?>, '<?= htmlspecialchars($modalData['employee_name']) ?>', '<?= $modalData['month'] ?>', '<?= $modalData['amount'] ?>')">
                            💰 تسديد
                        </button>
                    <?php else: ?>
                        <span class="btn-pay-disabled">✔ تم</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <tr class="total-row">
                <td colspan="3"><strong>الإجمالي</strong></td>
                <td colspan="4"><strong><?= number_format($totalPermanent, 2) ?> دج</strong></td>
            </tr>
            <?php endif; ?>
            </tbody>
        </table>

        <div class="section-title">👕 الموظفون المتعاقدون</div>
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>الموظف</th>
                    <th>المصدر</th>
                    <th>المبلغ (دج)</th>
                    <th>النوع</th>
                    <th>الحالة</th>
                    <th>تسديد</th>
                </tr>
            </thead>
            <tbody>
            <?php if(empty($contract)): ?>
                <tr><td colspan="7" style="text-align:center;">لا توجد بيانات للمتعاقدين</td></tr>
            <?php else: $i=1; foreach($contract as $it):
                $eff = getEffectiveAmount($it, $report_ym);
                $loanBadge = $it['is_loan'] ? '<span class="badge-loan">💰 سلفة</span>' : '<span class="badge-normal">📌 اقتطاع</span>';
                $isPaid = $it['is_paid'] == 1;
                $rowClass = $isPaid ? 'paid-row' : '';
                $statusText = $isPaid ? '✅ مدفوع' : '✅ نشط';
                $statusClass = $isPaid ? 'status-paid' : 'status-active';
                $modalData = [
                    'installment_id' => $it['installment_id'],
                    'employee_name' => $it['employee_name'],
                    'month' => $month_name_ar,
                    'amount' => number_format($eff, 2)
                ];
            ?>
            <tr class="<?= $rowClass ?>">
                <td><?= $i++ ?></td>
                <td><?= htmlspecialchars($it['employee_name']) ?></td>
                <td><?= htmlspecialchars($it['source_name']) ?></td>
                <td><?= number_format($eff, 2) ?> دج</td>
                <td><?= $loanBadge ?></td>
                <td><span class="<?= $statusClass ?>"><?= $statusText ?></span></td>
                <td>
                    <?php if (!$isPaid): ?>
                        <button type="button" class="btn-pay" onclick="openPayModal(<?= $modalData['installment_id'] ?>, '<?= htmlspecialchars($modalData['employee_name']) ?>', '<?= $modalData['month'] ?>', '<?= $modalData['amount'] ?>')">
                            💰 تسديد
                        </button>
                    <?php else: ?>
                        <span class="btn-pay-disabled">✔ تم</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <tr class="total-row">
                <td colspan="3"><strong>الإجمالي</strong></td>
                <td colspan="4"><strong><?= number_format($totalContract, 2) ?> دج</strong></td>
            </tr>
            <?php endif; ?>
            </tbody>
        </table>

        <div style="margin-top:20px; padding:12px; background:#ff9800; border-radius:8px; text-align:center; font-weight:bold;">
            💰 الإجمالي العام للشهر: <?= number_format($grandTotal, 2) ?> دج
        </div>
    <?php endif; ?>
</div>

<!-- ============================================================ -->
<!-- مودال تسديد قسط فردي -->
<div id="payModal" class="modal-overlay">
    <div class="modal-box">
        <h3>💰 تأكيد التسديد</h3>
        <p>هل أنت متأكد من تسديد قسط <strong id="modalEmployee"></strong> للشهر <strong id="modalMonth"></strong> بقيمة <strong id="modalAmount"></strong> دج؟</p>
        <form method="POST" id="payForm">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <input type="hidden" name="installment_id" id="modalInstallmentId">
            <input type="hidden" name="year" value="<?= $year ?>">
            <input type="hidden" name="month" value="<?= $month ?>">
            <input type="hidden" name="source_filter" value="<?= $source_id ?>">
            <input type="hidden" name="employee_filter" value="<?= $employee_id ?>">
            <div class="actions">
                <button type="button" class="btn-cancel-modal" onclick="closePayModal()">إلغاء</button>
                <button type="submit" name="pay_single" class="btn-confirm">💳 تأكيد التسديد</button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================ -->
<!-- مودال تسديد الكل -->
<div id="payAllModal" class="modal-overlay">
    <div class="modal-box">
        <h3 style="color: #007bff;">💰 تأكيد تسديد الكل</h3>
        <p>سيتم تسديد جميع الأقساط غير المدفوعة للشهر <strong><?= $month_name_ar ?></strong>.</p>
        <p><strong>عدد الأقساط:</strong> <span id="payAllCount">0</span></p>
        <p class="text-muted">سيتم إعادة مبالغ السلف إلى الميزانية تلقائياً.</p>
        <form method="POST" id="payAllForm">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <input type="hidden" name="pay_all" value="1">
            <input type="hidden" name="year" value="<?= $year ?>">
            <input type="hidden" name="month" value="<?= $month ?>">
            <input type="hidden" name="source_filter" value="<?= $source_id ?>">
            <input type="hidden" name="employee_filter" value="<?= $employee_id ?>">
            <div class="actions">
                <button type="button" class="btn-cancel-modal" onclick="closePayAllModal()">إلغاء</button>
                <button type="submit" class="btn-confirm" style="background:#007bff;">💳 تأكيد الكل</button>
            </div>
        </form>
    </div>
</div>

<script>
    // دوال المودال الفردي
    function openPayModal(installmentId, employeeName, month, amount) {
        document.getElementById('modalInstallmentId').value = installmentId;
        document.getElementById('modalEmployee').textContent = employeeName;
        document.getElementById('modalMonth').textContent = month;
        document.getElementById('modalAmount').textContent = amount;
        document.getElementById('payModal').classList.add('active');
    }

    function closePayModal() {
        document.getElementById('payModal').classList.remove('active');
    }

    // دوال مودال تسديد الكل
    function openPayAllModal(count) {
        document.getElementById('payAllCount').textContent = count;
        document.getElementById('payAllModal').classList.add('active');
    }

    function closePayAllModal() {
        document.getElementById('payAllModal').classList.remove('active');
    }

    // إغلاق المودالات عند النقر خارج المحتوى
    document.getElementById('payModal').addEventListener('click', function(e) {
        if (e.target === this) closePayModal();
    });
    document.getElementById('payAllModal').addEventListener('click', function(e) {
        if (e.target === this) closePayAllModal();
    });
</script>

<?php include '../includes/footer.php'; ?>