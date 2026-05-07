<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

require_once '../config/database.php';
include '../includes/header.php';

// استقبال البارامترات
$year      = isset($_GET['year'])      ? (int)$_GET['year']      : (int)date('Y');
$month     = isset($_GET['month'])     ? (int)$_GET['month']     : 0;
$quarter   = isset($_GET['quarter'])   ? (int)$_GET['quarter']   : 0;
$source_id = isset($_GET['source_id']) ? (int)$_GET['source_id'] : 0;

$sources = $pdo->query("SELECT id, name FROM sources ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$is_saadine = ($source_id === 1);

// ====================== تحديد الفترة ======================
$period_start = null;
$period_end   = null;
$period_name  = '';
$filter_quarter = 0;

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
$source_name = '';

if ($source_id > 0 && $period_start) {

    // جلب اسم المصدر
    $stmt = $pdo->prepare("SELECT name FROM sources WHERE id = ?");
    $stmt->execute([$source_id]);
    $source_name = $stmt->fetchColumn() ?: 'غير معروف';

    // === حساب الاقتطاعات المستحقة ===
    $stmt = $pdo->prepare("
        SELECT monthly_amount, start_date, end_date 
        FROM deductions 
        WHERE source_id = :source_id 
          AND start_date <= :period_end 
          AND end_date   >= :period_start
    ");
    $stmt->execute([
        ':source_id'   => $source_id,
        ':period_start'=> $period_start,
        ':period_end'  => $period_end
    ]);
    $deductions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $periodStartDT = new DateTime($period_start);
    $periodEndDT   = new DateTime($period_end);

    foreach ($deductions as $ded) {
        $monthly  = (float)$ded['monthly_amount'];
        $dedStart = new DateTime($ded['start_date']);
        $dedEnd   = new DateTime($ded['end_date']);

        $overlapStart = max($periodStartDT, $dedStart);
        $overlapEnd   = min($periodEndDT, $dedEnd);

        if ($overlapStart <= $overlapEnd) {
            $interval = $overlapStart->diff($overlapEnd);
            $months   = ($interval->y * 12) + $interval->m + 1;
            if ($months < 1) $months = 1;
            $due += $monthly * $months;
        }
    }

    // === حساب المبالغ المسلمة ===
    if ($is_saadine && $filter_quarter > 0) {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) AS total_paid 
            FROM source_payments 
            WHERE source_id = :source_id 
              AND quarter = :quarter
              AND strftime('%Y', cheque_date) = :year_str
        ");
        $stmt->execute([
            ':source_id' => $source_id,
            ':quarter'   => $filter_quarter,
            ':year_str'  => (string)$year
        ]);
    } else {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) AS total_paid 
            FROM source_payments 
            WHERE source_id = :source_id 
              AND cheque_date BETWEEN :start_date AND :end_date
        ");
        $stmt->execute([
            ':source_id'  => $source_id,
            ':start_date' => $period_start,
            ':end_date'   => $period_end
        ]);
    }
    $paid = (float)$stmt->fetchColumn();
}
?>

<style>
    .reconcile-card {
        background: white;
        border-radius: 20px;
        padding: 25px;
        margin-bottom: 25px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.06);
    }
    .result-box { 
        background: #f8f9fa; 
        border-radius: 16px; 
        padding: 30px; 
        text-align: center; 
        margin-top: 25px; 
        border: 1px solid #e9ecef;
    }
    .result-number { font-size: 34px; font-weight: bold; margin: 10px 0; }
    .diff-positive { color: #28a745; }
    .diff-negative { color: #dc3545; }
    .diff-zero     { color: #17a2b8; }
</style>

<div class="section">
    <div class="section-header">
        <h3>📊 مطابقة المبالغ المسلمة مع اقتطاعات الميزانية 
            <?php if ($is_saadine): ?> <small style="color:#007bff;">(نظام الثلاثي - سعدين)</small> <?php endif; ?>
        </h3>
    </div>

    <!-- ====================== الفورم (الفلاتر) ====================== -->
    <form method="GET" class="reconcile-card">
        <div style="display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end;">
            
            <div class="form-group" style="flex: 1; min-width: 220px;">
                <label><strong>📁 المصدر</strong></label>
                <select name="source_id" required class="form-control">
                    <option value="">-- اختر المصدر --</option>
                    <?php foreach ($sources as $src): ?>
                        <option value="<?= $src['id'] ?>" <?= $source_id == $src['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($src['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="flex: 1; min-width: 120px;">
                <label><strong>📅 السنة</strong></label>
                <select name="year" class="form-control">
                    <?php for ($y = 2020; $y <= date('Y') + 2; $y++): ?>
                        <option value="<?= $y ?>" <?= $y == $year ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>

            <div class="form-group" style="flex: 1; min-width: 140px;">
                <label><strong>📆 الشهر</strong></label>
                <select name="month" class="form-control">
                    <option value="0" <?= $month == 0 ? 'selected' : '' ?>>-- السنة كاملة --</option>
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= $m == $month ? 'selected' : '' ?>>
                            <?= date('F', mktime(0,0,0,$m,1)) ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>

            <div class="form-group" style="flex: 1; min-width: 140px;">
                <label><strong>📆 الربع</strong></label>
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
                <button type="submit" class="btn btn-primary">📊 عرض المطابقة</button>
            </div>
        </div>
        
        <small style="color:#666; display:block; margin-top:10px;">
            ملاحظة: عند اختيار ربع، يتم تجاهل الشهر. وعند اختيار شهر، يتم تجاهل الربع.
        </small>
    </form>

    <!-- ====================== نتيجة المطابقة ====================== -->
    <?php if ($source_id > 0 && $period_start): ?>
        <div class="result-box">
            <h4>📅 الفترة: <strong><?= htmlspecialchars($period_name) ?></strong></h4>
            <h4>📁 المصدر: <strong><?= htmlspecialchars($source_name) ?></strong></h4>
            
            <?php if ($is_saadine && $quarter > 0): ?>
                <p style="color:#007bff; margin:15px 0;"><strong>تم الحساب حسب رقم الربع <?= $quarter ?> (نظام الثلاثي)</strong></p>
            <?php endif; ?>
            
            <hr>
            
            <div style="display: flex; justify-content: space-around; flex-wrap: wrap; gap: 40px; margin: 25px 0;">
                <div>
                    <strong>💰 الاقتطاعات المستحقة</strong>
                    <div class="result-number"><?= number_format($due, 2) ?> دج</div>
                </div>
                <div>
                    <strong>💵 المبالغ المسلمة</strong>
                    <div class="result-number"><?= number_format($paid, 2) ?> دج</div>
                </div>
                <div>
                    <strong>📉 الفرق</strong>
                    <?php 
                    $diff = $paid - $due;
                    $class = $diff > 0 ? 'diff-positive' : ($diff < 0 ? 'diff-negative' : 'diff-zero');
                    ?>
                    <div class="result-number <?= $class ?>">
                        <?= number_format($diff, 2) ?> دج
                    </div>
                </div>
            </div>

            <div style="font-size: 16px; font-weight: 500;">
                <?php if (abs($diff) < 0.01): ?>
                    ✅ <span style="color:#28a745;">المبالغ متطابقة تماماً</span>
                <?php elseif ($diff > 0): ?>
                    ⚠️ المبالغ المسلمة أكثر من المستحق بـ <strong><?= number_format($diff, 2) ?> دج</strong>
                <?php else: ?>
                    ⚠️ المبالغ المسلمة أقل من المستحق بـ <strong><?= number_format(abs($diff), 2) ?> دج</strong>
                <?php endif; ?>
            </div>
        </div>
    <?php elseif ($source_id == 0): ?>
        <div style="background:#fff3cd; color:#856404; padding:20px; border-radius:12px; text-align:center;">
            ⚠️ يرجى اختيار المصدر أولاً
        </div>
    <?php endif; ?>
</div>

<?php include '../includes/footer.php'; ?>