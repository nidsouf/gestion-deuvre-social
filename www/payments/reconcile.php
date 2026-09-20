<?php
ob_start();
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';

$year      = isset($_GET['year'])      ? (int)$_GET['year']      : (int)date('Y');
$month     = isset($_GET['month'])     ? (int)$_GET['month']     : 0;
$quarter   = isset($_GET['quarter'])   ? (int)$_GET['quarter']   : 0;
$source_id = isset($_GET['source_id']) ? (int)$_GET['source_id'] : 0;

$sources = $pdo->query("SELECT id, name FROM sources ORDER BY name")->fetchAll();

$period_start = null;
$period_end   = null;
$period_name  = '';
$filter_quarter = 0;
$is_saadine = ($source_id === 1);

if ($quarter > 0) {
    $q_start_month = ($quarter - 1) * 3 + 1;
    $period_start  = sprintf("%04d-%02d-01", $year, $q_start_month);
    $period_end    = date("Y-m-t", strtotime("$year-" . ($q_start_month + 2) . "-01"));
    $period_name   = "الربع $quarter - سنة $year";
    $filter_quarter = $quarter;
} elseif ($month > 0) {
    $period_start = sprintf("%04d-%02d-01", $year, $month);
    $period_end   = date("Y-m-t", strtotime($period_start));
    $period_name  = date('F Y', strtotime($period_start));
} else {
    $period_start = "$year-01-01";
    $period_end   = "$year-12-31";
    $period_name  = "السنة الكاملة $year";
}

$due  = 0.0;
$paid = 0.0;
$paid_all = 0.0;      // إجمالي كل الشيكات (بما فيها غير المطابقة)
$paid_deduction = 0.0; // الشيكات المطابقة فقط
$source_name = '';
$cheques_detail = [];

if ($source_id > 0 && $period_start) {
    $stmt = $pdo->prepare("SELECT name FROM sources WHERE id = ?");
    $stmt->execute([$source_id]);
    $source_name = $stmt->fetchColumn() ?: 'غير معروف';

    // ============================================================
    // 1. حساب المستحق من الاقتطاعات
    // ============================================================
    $stmt = $pdo->prepare("
        SELECT monthly_amount, start_date, end_date 
        FROM deductions 
        WHERE source_id = :source_id 
          AND start_date <= :period_end 
          AND end_date >= :period_start
    ");
    $stmt->execute([
        ':source_id' => $source_id, 
        ':period_start' => $period_start, 
        ':period_end' => $period_end
    ]);
    $deductions = $stmt->fetchAll();

    $periodStartDT = new DateTime($period_start);
    $periodEndDT   = new DateTime($period_end);

    foreach ($deductions as $ded) {
        $monthly = (float)$ded['monthly_amount'];
        $dedStart = new DateTime($ded['start_date']);
        $dedEnd   = new DateTime($ded['end_date']);
        $overlapStart = max($periodStartDT, $dedStart);
        $overlapEnd   = min($periodEndDT, $dedEnd);
        if ($overlapStart <= $overlapEnd) {
            $interval = $overlapStart->diff($overlapEnd);
            $months = ($interval->y * 12) + $interval->m + 1;
            if ($months < 1) $months = 1;
            $due += $monthly * $months;
        }
    }

    // ============================================================
    // 2. حساب المدفوع من source_payments
    // ============================================================
    
    // أ. إجمالي جميع الشيكات
    if ($is_saadine && $filter_quarter > 0) {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount),0) 
            FROM source_payments 
            WHERE source_id = :source_id 
              AND quarter = :quarter 
              AND strftime('%Y', cheque_date) = :year
        ");
        $stmt->execute([
            ':source_id' => $source_id, 
            ':quarter' => $filter_quarter, 
            ':year' => (string)$year
        ]);
    } else {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount),0) 
            FROM source_payments 
            WHERE source_id = :source_id 
              AND cheque_date BETWEEN :start AND :end
        ");
        $stmt->execute([
            ':source_id' => $source_id, 
            ':start' => $period_start, 
            ':end' => $period_end
        ]);
    }
    $paid_all = (float)$stmt->fetchColumn();

    // ب. الشيكات المطابقة فقط (category = 'deduction')
    if ($is_saadine && $filter_quarter > 0) {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount),0) 
            FROM source_payments 
            WHERE source_id = :source_id 
              AND quarter = :quarter 
              AND strftime('%Y', cheque_date) = :year
              AND (category = 'deduction' OR category IS NULL OR category = '')
        ");
        $stmt->execute([
            ':source_id' => $source_id, 
            ':quarter' => $filter_quarter, 
            ':year' => (string)$year
        ]);
    } else {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount),0) 
            FROM source_payments 
            WHERE source_id = :source_id 
              AND cheque_date BETWEEN :start AND :end
              AND (category = 'deduction' OR category IS NULL OR category = '')
        ");
        $stmt->execute([
            ':source_id' => $source_id, 
            ':start' => $period_start, 
            ':end' => $period_end
        ]);
    }
    $paid_deduction = (float)$stmt->fetchColumn();
    
    // المبلغ المستخدم للمقارنة = الشيكات المطابقة فقط
    $paid = $paid_deduction;

    // ============================================================
    // 3. جلب تفصيل الشيكات في الفترة
    // ============================================================
    if ($is_saadine && $filter_quarter > 0) {
        $stmtChq = $pdo->prepare("
            SELECT id, cheque_number, cheque_date, amount, quarter, category, notes
            FROM source_payments 
            WHERE source_id = :source_id 
              AND quarter = :quarter 
              AND strftime('%Y', cheque_date) = :year
            ORDER BY 
                CASE WHEN category = 'deduction' OR category IS NULL THEN 0 ELSE 1 END,
                cheque_date
        ");
        $stmtChq->execute([
            ':source_id' => $source_id, 
            ':quarter' => $filter_quarter, 
            ':year' => (string)$year
        ]);
    } else {
        $stmtChq = $pdo->prepare("
            SELECT id, cheque_number, cheque_date, amount, quarter, category, notes
            FROM source_payments 
            WHERE source_id = :source_id 
              AND cheque_date BETWEEN :start AND :end
            ORDER BY 
                CASE WHEN category = 'deduction' OR category IS NULL THEN 0 ELSE 1 END,
                cheque_date
        ");
        $stmtChq->execute([
            ':source_id' => $source_id, 
            ':start' => $period_start, 
            ':end' => $period_end
        ]);
    }
    $cheques_detail = $stmtChq->fetchAll();
}

include '../includes/header.php';
?>

<style>
    .reconcile-card { 
        background: white; 
        border-radius: 20px; 
        padding: 25px; 
        margin-bottom: 25px; 
        box-shadow: 0 2px 10px rgba(0,0,0,0.05); 
    }
    .result-box { 
        background: #f8f9fa; 
        border-radius: 16px; 
        padding: 30px; 
        text-align: center; 
        margin-top: 25px; 
    }
    .diff-positive { color: #28a745; }
    .diff-negative { color: #dc3545; }
    .diff-zero { color: #17a2b8; }
    .result-number { 
        font-size: 22px; 
        font-weight: bold; 
        margin-top: 5px; 
    }
    .details-table { 
        width: 100%; 
        border-collapse: collapse; 
        margin-top: 15px; 
        font-size: 14px;
    }
    .details-table th { 
        background: #2a5298; 
        color: white; 
        padding: 10px 8px; 
        border: 1px solid #ddd; 
        text-align: center;
    }
    .details-table td { 
        padding: 8px; 
        border: 1px solid #ddd; 
        text-align: center;
    }
    .details-table tr:hover { background: #e3f2fd; }
    .details-table tr.row-matched { background: #e8f5e9; }
    .details-table tr.row-unmatched { background: #fff3e0; }
    .details-table tfoot tr { 
        background: #e8f5e9; 
        font-weight: bold; 
    }
    .badge-category {
        padding: 3px 10px;
        border-radius: 12px;
        color: white;
        font-size: 11px;
        font-weight: bold;
        display: inline-block;
    }
    .badge-deduction { background: #28a745; }
    .badge-purchase { background: #ff9800; }
    .badge-other { background: #6c757d; }
    
    .analysis-box {
        margin-top: 15px;
        padding: 15px;
        border-radius: 12px;
        background: #fff8e1;
        border-right: 4px solid #ff9800;
    }
    .analysis-box ul {
        margin: 8px 0;
        padding-right: 20px;
    }
    .analysis-box li { margin: 5px 0; }
</style>

<div class="section">
    <div class="section-header">
        <h3>📊 مطابقة المبالغ المسلمة مع اقتطاعات الميزانية</h3>
    </div>

    <form method="GET" class="reconcile-card">
        <div style="display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end;">
            <div class="form-group" style="flex:1;">
                <label>📁 المصدر</label>
                <select name="source_id" required class="form-control">
                    <option value="">-- اختر المصدر --</option>
                    <?php foreach($sources as $src): ?>
                        <option value="<?= $src['id'] ?>" <?= $source_id == $src['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($src['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="flex:1;">
                <label>📅 السنة</label>
                <select name="year" class="form-control">
                    <?php for($y = 2020; $y <= date('Y')+2; $y++): ?>
                        <option value="<?= $y ?>" <?= $y == $year ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="form-group" style="flex:1;">
                <label>📆 الشهر</label>
                <select name="month" class="form-control">
                    <option value="0" <?= $month == 0 ? 'selected' : '' ?>>-- السنة كاملة --</option>
                    <?php for($m=1;$m<=12;$m++): ?>
                        <option value="<?= $m ?>" <?= $m == $month ? 'selected' : '' ?>>
                            <?= date('F', mktime(0,0,0,$m,1)) ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="form-group" style="flex:1;">
                <label>📆 الربع</label>
                <select name="quarter" class="form-control">
                    <option value="0" <?= $quarter == 0 ? 'selected' : '' ?>>-- بدون ربع --</option>
                    <option value="1" <?= $quarter == 1 ? 'selected' : '' ?>>الربع الأول</option>
                    <option value="2" <?= $quarter == 2 ? 'selected' : '' ?>>الربع الثاني</option>
                    <option value="3" <?= $quarter == 3 ? 'selected' : '' ?>>الربع الثالث</option>
                    <option value="4" <?= $quarter == 4 ? 'selected' : '' ?>>الربع الرابع</option>
                </select>
            </div>
            <div class="form-group">
                <label>&nbsp;</label>
                <button type="submit" class="btn btn-primary">📊 عرض</button>
            </div>
        </div>
        <small>ملاحظة: عند اختيار ربع، يتم تجاهل الشهر.</small>
    </form>

    <?php if ($source_id > 0 && $period_start): ?>
        <!-- ============================================================
             صندوق النتيجة الرئيسي
        ============================================================ -->
        <div class="result-box">
            <h4>📅 الفترة: <strong><?= htmlspecialchars($period_name) ?></strong></h4>
            <h4>📁 المصدر: <strong><?= htmlspecialchars($source_name) ?></strong></h4>
            <hr>
            <div style="display: flex; justify-content: space-around; flex-wrap: wrap; gap: 40px;">
                <div>
                    <strong>💰 الاقتطاعات المستحقة</strong>
                    <div class="result-number"><?= number_format($due, 2) ?> دج</div>
                </div>
                <div>
                    <strong>💵 الشيكات المطابقة (اقتطاعات)</strong>
                    <div class="result-number"><?= number_format($paid_deduction, 2) ?> دج</div>
                </div>
                <div>
                    <strong>📉 الفرق</strong>
                    <?php 
                    $diff = $paid_deduction - $due; 
                    $class = $diff > 0 ? 'diff-positive' : ($diff < 0 ? 'diff-negative' : 'diff-zero'); 
                    ?>
                    <div class="result-number <?= $class ?>"><?= number_format($diff, 2) ?> دج</div>
                </div>
            </div>
            <div style="margin-top:15px;">
                <?php if (abs($diff) < 0.01): ?>
                    ✅ <span style="color:#28a745; font-weight:bold;">المبالغ متطابقة تماماً</span>
                <?php elseif ($diff > 0): ?>
                    ⚠️ الشيكات المطابقة أكثر من المستحق بـ <strong style="color:#dc3545;"><?= number_format($diff,2) ?> دج</strong>
                <?php else: ?>
                    ⚠️ الشيكات المطابقة أقل من المستحق بـ <strong style="color:#dc3545;"><?= number_format(abs($diff),2) ?> دج</strong>
                <?php endif; ?>
            </div>
            <?php if ($paid_all > $paid_deduction): ?>
                <div style="margin-top:15px; padding:10px; background:#fff3cd; border-radius:8px;">
                    <small>
                        ℹ️ إجمالي الشيكات المسلمة (بما فيها غير المطابقة): 
                        <strong><?= number_format($paid_all, 2) ?> دج</strong>
                        — منها <strong style="color:#ff9800;"><?= number_format($paid_all - $paid_deduction, 2) ?> دج</strong>
                        مشتريات/هدايا لا تدخل في المطابقة.
                    </small>
                </div>
            <?php endif; ?>
        </div>

        <!-- ============================================================
             تفصيل الشيكات
        ============================================================ -->
        <?php if (!empty($cheques_detail)): ?>
        <div class="reconcile-card" style="margin-top: 20px;">
            <h4>🔍 تفصيل الشيكات في الفترة المحددة</h4>
            <p style="color:#666; font-size:13px;">
                <span class="badge-category badge-deduction">🔗 اقتطاعات</span> = شيكات مطابقة (تُحسب في المقارنة)
                &nbsp;&nbsp;|&nbsp;&nbsp;
                <span class="badge-category badge-purchase">🛒 مشتريات</span> = لا تُحسب
                &nbsp;&nbsp;|&nbsp;&nbsp;
                <span class="badge-category badge-other">📦 أخرى</span> = لا تُحسب
            </p>
            <table class="details-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>رقم الشيك</th>
                        <th>التاريخ</th>
                        <th>الربع</th>
                        <th>التصنيف</th>
                        <th>المبلغ (دج)</th>
                        <th>ملاحظات / السبب</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $i = 1; 
                    $sumDeduction = 0;
                    $sumOther = 0;
                    foreach ($cheques_detail as $ch): 
                        $cat = $ch['category'] ?? 'deduction';
                        $isMatched = in_array($cat, ['deduction', null, '']);
                        $rowClass = $isMatched ? 'row-matched' : 'row-unmatched';
                        $badgeClass = $isMatched ? 'badge-deduction' : ($cat == 'purchase' ? 'badge-purchase' : 'badge-other');
                        $badgeLabel = $isMatched ? '🔗 اقتطاعات' : ($cat == 'purchase' ? '🛒 مشتريات' : '📦 أخرى');
                        
                        if ($isMatched) {
                            $sumDeduction += $ch['amount'];
                        } else {
                            $sumOther += $ch['amount'];
                        }
                    ?>
                        <tr class="<?= $rowClass ?>">
                            <td><?= $i++ ?></td>
                            <td><?= htmlspecialchars($ch['cheque_number'] ?? '-') ?></td>
                            <td><?= safeFormatDate($ch['cheque_date']) ?></td>
                            <td><?= $ch['quarter'] ? 'الربع '.$ch['quarter'] : '---' ?></td>
                            <td>
                                <span class="badge-category <?= $badgeClass ?>">
                                    <?= $badgeLabel ?>
                                </span>
                            </td>
                            <td style="font-weight:bold;"><?= number_format($ch['amount'], 2) ?></td>
                            <td style="text-align:right; color:#555;">
                                <?= htmlspecialchars($ch['notes'] ?? '—') ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="5" style="text-align:right;">إجمالي الشيكات المطابقة (اقتطاعات):</td>
                        <td style="color:#28a745;"><?= number_format($sumDeduction, 2) ?> دج</td>
                        <td></td>
                    </tr>
                    <?php if ($sumOther > 0): ?>
                    <tr>
                        <td colspan="5" style="text-align:right;">إجمالي الشيكات غير المطابقة (مشتريات/أخرى):</td>
                        <td style="color:#ff9800;"><?= number_format($sumOther, 2) ?> دج</td>
                        <td></td>
                    </tr>
                    <?php endif; ?>
                    <tr style="background:#e3f2fd;">
                        <td colspan="5" style="text-align:right;"><strong>الإجمالي الكلي:</strong></td>
                        <td colspan="2"><strong><?= number_format($sumDeduction + $sumOther, 2) ?> دج</strong></td>
                    </tr>
                </tfoot>
            </table>

            <!-- تحليل ذكي -->
            <div class="analysis-box">
                <strong>💡 تحليل المطابقة:</strong>
                <ul>
                    <li>الاقتطاعات المستحقة: <strong><?= number_format($due, 2) ?> دج</strong></li>
                    <li>الشيكات المطابقة: <strong style="color:#28a745;"><?= number_format($sumDeduction, 2) ?> دج</strong></li>
                    <li>
                        الفرق: 
                        <?php $finalDiff = $sumDeduction - $due; ?>
                        <?php if (abs($finalDiff) < 0.01): ?>
                            <strong style="color:#28a745;">✅ 0.00 دج (مطابقة تامة)</strong>
                        <?php elseif ($finalDiff > 0): ?>
                            <strong style="color:#dc3545;">+<?= number_format($finalDiff, 2) ?> دج</strong>
                        <?php else: ?>
                            <strong style="color:#dc3545;"><?= number_format($finalDiff, 2) ?> دج</strong>
                        <?php endif; ?>
                    </li>
                    <?php if ($sumOther > 0): ?>
                    <li>
                        الشيكات غير المطابقة (مشتريات/هدايا): 
                        <strong style="color:#ff9800;"><?= number_format($sumOther, 2) ?> دج</strong>
                        <small>(لا تدخل في حساب المطابقة)</small>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>

    <?php elseif ($source_id == 0): ?>
        <div style="background:#fff3cd; padding:20px; border-radius:12px;">
            ⚠️ يرجى اختيار المصدر أولاً
        </div>
    <?php endif; ?>
</div>

<?php
ob_end_flush();
include '../includes/footer.php';
?>