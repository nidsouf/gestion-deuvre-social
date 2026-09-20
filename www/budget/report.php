<?php
/**
 * budget/report.php - تقرير الميزانية المفصل
 * مع Popover لعرض تفاصيل إضافية عند تمرير الماوس
 */
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';
require_once '../includes/budget_helpers.php';

$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
$type = isset($_GET['type']) ? $_GET['type'] : 'all';

// جلب المعاملات مع بيانات إضافية
$transactions = getBudgetTransactionsWithDetails($pdo, ['year' => $year, 'type' => $type, 'limit' => 1000]);
$years = getBudgetYears($pdo);

// حساب الإجماليات
$totalDebit = 0;
$totalCredit = 0;
foreach ($transactions as $t) {
    if ($t['is_deduct']) {
        $totalDebit += $t['amount'];
    } else {
        $totalCredit += $t['amount'];
    }
}
$net = $totalCredit - $totalDebit;

include '../includes/header.php';
?>

<style>
    /* ============================================================
       تحسين مظهر Popover (أكثر وضوحاً وتبايناً)
    ============================================================ */
    .popover {
        max-width: 360px !important;
        border-radius: 14px !important;
        box-shadow: 0 10px 40px rgba(0,0,0,0.2) !important;
        border: 1px solid #d0d7de !important;
        background: #ffffff !important;
        font-family: 'Cairo', sans-serif !important;
    }
    .popover-header {
        background: linear-gradient(135deg, #1E5A4A, #2E7D64) !important;
        color: #ffffff !important;
        border-radius: 14px 14px 0 0 !important;
        padding: 12px 18px !important;
        font-weight: 700 !important;
        font-size: 15px !important;
        border: none !important;
        letter-spacing: 0.3px;
    }
    .popover-body {
        padding: 16px 20px !important;
        font-size: 14px !important;
        line-height: 1.9 !important;
        color: #1a1a2e !important;
        background: #ffffff !important;
        border-radius: 0 0 14px 14px !important;
    }
    .popover-body strong {
        color: #1E5A4A !important;
        font-weight: 700;
    }
    .popover-body .detail-row {
        display: flex;
        justify-content: space-between;
        padding: 4px 0;
        border-bottom: 1px solid #eef2f5;
    }
    .popover-body .detail-row:last-child {
        border-bottom: none;
    }
    .popover-body .label {
        font-weight: 600;
        color: #4a5568;
        font-size: 13px;
    }
    .popover-body .value {
        font-weight: 700;
        color: #1a1a2e;
        font-size: 14px;
    }
    .popover-body .value.highlight {
        color: #1E5A4A;
    }
    .popover-body .value.debit {
        color: #c0392b;
    }
    .popover-body .value.credit {
        color: #27ae60;
    }

    .popover .popover-arrow::after {
        border-top-color: #ffffff !important;
    }
    .popover .popover-arrow::before {
        border-top-color: #d0d7de !important;
    }

    .tr-has-details {
        cursor: help;
        transition: background 0.2s;
    }
    .tr-has-details:hover {
        background: #f0f7f4 !important;
    }
    .tr-has-details .info-icon {
        display: inline-block;
        width: 20px;
        height: 20px;
        background: #1E5A4A;
        color: #fff;
        border-radius: 50%;
        text-align: center;
        line-height: 20px;
        font-size: 12px;
        font-weight: 700;
        margin-left: 6px;
        opacity: 0.7;
        transition: opacity 0.2s;
    }
    .tr-has-details:hover .info-icon {
        opacity: 1;
        transform: scale(1.05);
    }

    .badge-type {
        display: inline-block;
        padding: 3px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        color: #fff;
    }
    .badge-type.grant {
        background: #2E7D64;
    }
    .badge-type.loan {
        background: #E67E22;
    }
    .badge-type.installment {
        background: #2980B9;
    }
    .badge-type.other {
        background: #95a5a6;
    }
</style>

<div class="budget-container">
    <h2>📊 تقرير الميزانية - سنة <?= $year ?></h2>

    <!-- الفلاتر -->
    <div class="filters">
        <form method="GET" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
            <div class="filter-group">
                <label>السنة</label>
                <select name="year">
                    <?php foreach ($years as $y): ?>
                        <option value="<?= $y ?>" <?= $y == $year ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>النوع</label>
                <select name="type">
                    <option value="all" <?= $type == 'all' ? 'selected' : '' ?>>الكل</option>
                    <option value="grant" <?= $type == 'grant' ? 'selected' : '' ?>>منح</option>
                    <option value="loan" <?= $type == 'loan' ? 'selected' : '' ?>>سلف</option>
                    <option value="installment" <?= $type == 'installment' ? 'selected' : '' ?>>أقساط</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">🔍 عرض</button>
            <a href="report.php?year=<?= $year ?>&type=<?= $type ?>&print=1" target="_blank" class="btn btn-success">🖨️ طباعة</a>
        </form>
    </div>

    <!-- ملخص سريع -->
    <div style="display:flex; gap:20px; flex-wrap:wrap; margin-bottom:20px;">
        <div style="background:#f8f9fa; padding:15px; border-radius:10px; flex:1;">
            <strong>💸 إجمالي الخصم (الصرف):</strong> <?= formatCurrency($totalDebit) ?>
        </div>
        <div style="background:#f8f9fa; padding:15px; border-radius:10px; flex:1;">
            <strong>🔄 إجمالي الإضافة (الاسترجاعات):</strong> <?= formatCurrency($totalCredit) ?>
        </div>
        <?php 
        $balance = $net;
        $balanceText = $balance > 0 ? '✅ فائض (استرجاع أكبر من الصرف)' : ($balance < 0 ? '⚠️ عجز (صرف أكبر من الاسترجاع)' : '⚖️ متوازن');
        ?>
        <div style="background:#e3f2fd; padding:15px; border-radius:10px; flex:1;">
            <strong>⚖️ الميزان المالي:</strong> <?= formatCurrency($net) ?>
            <br><small style="color:#666;"><?= $balanceText ?></small>
        </div>
    </div>

    <!-- الجدول -->
    <div style="overflow-x:auto;">
        <table class="data-table" id="transactionsTable">
            <thead>
                <tr>
                    <th>#</th>
                    <th>التاريخ</th>
                    <th>النوع</th>
                    <th>الوصف</th>
                    <th>المبلغ (دج)</th>
                    <th>اتجاه</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($transactions)): ?>
                    <tr><td colspan="6" style="text-align:center; padding:30px;">لا توجد معاملات</td></tr>
                <?php else: 
                    $i = 1; 
                    foreach ($transactions as $t): 
                        // تجهيز بيانات Popover
                        $employeeName = isset($t['employee_name']) && !empty($t['employee_name']) 
                            ? htmlspecialchars($t['employee_name']) 
                            : 'غير معروف';
                        $refId = isset($t['ref_id']) && $t['ref_id'] > 0 ? $t['ref_id'] : '—';
                        $recordedAt = date('d/m/Y H:i', strtotime($t['transaction_date']));
                        $amountFormatted = number_format($t['amount'], 2) . ' دج';
                        $typeLabel = $t['type_label'] ?? $t['type'] ?? 'أخرى';
                        $direction = $t['is_deduct'] ? 'خصم' : 'إضافة';
                        $directionClass = $t['is_deduct'] ? 'debit' : 'credit';
                        
                        // بناء محتوى Popover
                        $popoverContent = "
                            <div class='detail-row'>
                                <span class='label'>👤 الموظف</span>
                                <span class='value highlight'>{$employeeName}</span>
                            </div>
                            <div class='detail-row'>
                                <span class='label'>🔢 المرجع</span>
                                <span class='value'>{$refId}</span>
                            </div>
                            <div class='detail-row'>
                                <span class='label'>📅 التسجيل</span>
                                <span class='value'>{$recordedAt}</span>
                            </div>
                            <div class='detail-row'>
                                <span class='label'>💰 المبلغ</span>
                                <span class='value {$directionClass}'>{$amountFormatted}</span>
                            </div>
                            <div class='detail-row'>
                                <span class='label'>📋 النوع</span>
                                <span class='value'>{$typeLabel}</span>
                            </div>
                            <div class='detail-row'>
                                <span class='label'>🔄 الاتجاه</span>
                                <span class='value'>{$direction}</span>
                            </div>
                        ";
                ?>
                        <tr 
                            class="tr-has-details"
                            data-bs-toggle="popover"
                            data-bs-trigger="hover"
                            data-bs-placement="top"
                            data-bs-html="true"
                            data-bs-title="📋 تفاصيل العملية #<?= $t['id'] ?>"
                            data-bs-content="<?= htmlspecialchars($popoverContent) ?>"
                        >
                            <td>
                                <span class="info-icon">i</span>
                                <?= $i++ ?>
                            </td>
                            <td><?= date('d/m/Y H:i', strtotime($t['transaction_date'])) ?></td>
                            <td>
                                <span class="badge-type <?= $t['type'] ?>">
                                    <?= $t['type_label'] ?? $t['type'] ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($t['description'] ?? '') ?></td>
                            <td class="<?= $t['is_deduct'] ? 'debit' : 'credit' ?>">
                                <?= $t['is_deduct'] ? '−' : '+' ?> <?= formatCurrency($t['amount']) ?>
                            </td>
                            <td><?= $direction ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div style="margin-top:20px;">
        <a href="dashboard.php?year=<?= $year ?>" class="btn btn-primary">🔙 العودة للوحة التحكم</a>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
    popoverTriggerList.map(function (popoverTriggerEl) {
        return new bootstrap.Popover(popoverTriggerEl, {
            trigger: 'hover',
            placement: 'top',
            html: true,
            delay: { show: 200, hide: 100 }
        });
    });
});
</script>

<?php include '../includes/footer.php'; ?>