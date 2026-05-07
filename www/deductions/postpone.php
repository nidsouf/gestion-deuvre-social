<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

require_once '../config/database.php';
require_once '../includes/functions.php';

$id = $_GET['id'] ?? 0;

$stmt = $pdo->prepare("
    SELECT 
        d.*,
        e.name as employee_name,
        s.name as source_name
    FROM deductions d
    JOIN employees e ON d.employee_id = e.id
    JOIN sources s ON d.source_id = s.id
    WHERE d.id = ?
");
$stmt->execute([$id]);
$deduction = $stmt->fetch();

if (!$deduction) {
    $_SESSION['toast'] = ['message' => 'الاقتطاع غير موجود', 'type' => 'error', 'duration' => 3000];
    header("Location: list.php");
    exit;
}

// حفظ البيانات الأصلية (أول مرة فقط)
if (!isset($_SESSION['original_deduction_' . $id])) {
    $_SESSION['original_deduction_' . $id] = [
        'start_date' => $deduction['start_date'],
        'end_date' => $deduction['end_date'],
        'monthly_amount' => $deduction['monthly_amount'],
        'total_months' => $deduction['total_months']
    ];
}

$originalTotalAmount = $deduction['monthly_amount'] * $deduction['total_months'];

// ========== معالجة POST ==========
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action == 'postpone') {
        $postponeMonths = (int)($_POST['postpone_months'] ?? 0);
        if ($postponeMonths >= 1 && $postponeMonths <= 12) {
            $newEnd = date('Y-m-d', strtotime($deduction['end_date'] . " + $postponeMonths months"));
            $update = $pdo->prepare("UPDATE deductions SET end_date = ? WHERE id = ?");
            if ($update->execute([$newEnd, $id])) {
                $_SESSION['toast'] = ['message' => "✅ تم تأجيل الاقتطاع {$postponeMonths} شهراً. التاريخ الجديد: " . date('d/m/Y', strtotime($newEnd)), 'type' => 'success', 'duration' => 4000];
                header("Location: list.php");
                exit;
            } else {
                $_SESSION['toast'] = ['message' => '❌ حدث خطأ أثناء تأجيل الاقتطاع', 'type' => 'error', 'duration' => 5000];
                header("Location: postpone.php?id=$id");
                exit;
            }
        } else {
            $_SESSION['toast'] = ['message' => '❌ الرجاء إدخال عدد أشهر صحيح (1 إلى 12)', 'type' => 'warning', 'duration' => 4000];
            header("Location: postpone.php?id=$id");
            exit;
        }
    } 
    elseif ($action == 'advance') {
        $months = (int)($_POST['advance_months'] ?? 0);
        if ($months >= 1 && $months <= 12) {
            $newTotalMonths = $deduction['total_months'] - $months;
            if ($newTotalMonths < 1) {
                $_SESSION['toast'] = ['message' => '⚠️ لا يمكن تقديم الاقتطاع أكثر من ' . ($deduction['total_months'] - 1) . ' أشهر', 'type' => 'warning', 'duration' => 4000];
                header("Location: postpone.php?id=$id");
                exit;
            }
            $newMonthlyAmount = round($originalTotalAmount / $newTotalMonths, 0);
            $newEnd = date('Y-m-d', strtotime($deduction['end_date'] . " - $months months"));
            
            $today = date('Y-m-d');
            if ($newEnd < $today) {
                $_SESSION['toast'] = ['message' => '⚠️ لا يمكن تقديم الاقتطاع أكثر من تاريخ اليوم', 'type' => 'warning', 'duration' => 4000];
                header("Location: postpone.php?id=$id");
                exit;
            }
            
            $update = $pdo->prepare("UPDATE deductions SET end_date = ?, total_months = ?, monthly_amount = ? WHERE id = ?");
            if ($update->execute([$newEnd, $newTotalMonths, $newMonthlyAmount, $id])) {
                $_SESSION['toast'] = ['message' => "✅ تم تقديم الاقتطاع {$months} شهراً. المبلغ الشهري الجديد: " . number_format($newMonthlyAmount, 0) . " دج", 'type' => 'success', 'duration' => 4000];
                header("Location: list.php");
                exit;
            } else {
                $_SESSION['toast'] = ['message' => '❌ حدث خطأ أثناء تقديم الاقتطاع', 'type' => 'error', 'duration' => 5000];
                header("Location: postpone.php?id=$id");
                exit;
            }
        } else {
            $_SESSION['toast'] = ['message' => '❌ الرجاء إدخال عدد أشهر صحيح (1 إلى 12)', 'type' => 'warning', 'duration' => 4000];
            header("Location: postpone.php?id=$id");
            exit;
        }
    } 
    elseif ($action == 'cancel') {
        $original = $_SESSION['original_deduction_' . $id];
        $update = $pdo->prepare("UPDATE deductions SET start_date = ?, end_date = ?, total_months = ?, monthly_amount = ? WHERE id = ?");
        if ($update->execute([$original['start_date'], $original['end_date'], $original['total_months'], $original['monthly_amount'], $id])) {
            $_SESSION['toast'] = ['message' => '✅ تم إلغاء التعديل والعودة إلى البيانات الأصلية', 'type' => 'success', 'duration' => 3000];
            header("Location: list.php");
            exit;
        } else {
            $_SESSION['toast'] = ['message' => '❌ حدث خطأ أثناء إلغاء التعديل', 'type' => 'error', 'duration' => 5000];
            header("Location: postpone.php?id=$id");
            exit;
        }
    }
}

// ========== عرض الصفحة ==========
include '../includes/header.php';

$today = date('Y-m-d');
$daysRemaining = (strtotime($deduction['end_date']) - strtotime($today)) / (60 * 60 * 24);
$hasOriginalDates = isset($_SESSION['original_deduction_' . $id]) && 
                    ($_SESSION['original_deduction_' . $id]['end_date'] != $deduction['end_date']);
?>

<style>
    .postpone-container { max-width: 800px; margin: 0 auto; }
    .info-card { background: #f0f2f5; padding: 20px; border-radius: 20px; margin-bottom: 25px; }
    .info-card h3 { color: #1b5e20; margin-bottom: 15px; }
    .info-table { width: 100%; border-collapse: collapse; }
    .info-table td { padding: 8px; border-bottom: 1px solid #ddd; }
    .info-table td:first-child { font-weight: bold; width: 40%; }
    .alert { padding: 12px; border-radius: 12px; margin-bottom: 20px; }
    .alert-success { background: #d4edda; color: #155724; }
    .alert-error { background: #f8d7da; color: #721c24; }
    .alert-warning { background: #fff3cd; color: #856404; }
    .option-card { background: white; border-radius: 16px; padding: 20px; margin-bottom: 20px; border: 1px solid #e0e0e0; }
    .option-title { font-size: 18px; font-weight: bold; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 2px solid #2a5298; }
    .form-group { margin-bottom: 15px; }
    .form-group label { display: block; font-weight: bold; margin-bottom: 5px; }
    .form-group select { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 12px; }
    .btn { padding: 10px 20px; border-radius: 12px; border: none; cursor: pointer; font-weight: bold; }
    .btn-postpone { background: #ff9800; color: white; }
    .btn-advance { background: #2196f3; color: white; }
    .btn-cancel { background: #dc3545; color: white; }
    .btn-secondary { background: #6c757d; color: white; }
    .highlight { background: #fff3cd; padding: 10px; border-radius: 8px; margin-bottom: 15px; }
</style>

<div class="postpone-container">
    <h2 style="margin-bottom: 20px;">⏰ تعديل فترة الاقتطاع</h2>

    <div class="info-card">
        <h3>📋 بيانات الاقتطاع الحالي</h3>
        <table class="info-table">
            <tr><td>👤 الموظف</td><td><?= htmlspecialchars($deduction['employee_name']) ?></td></tr>
            <tr><td>📁 المصدر</td><td><?= htmlspecialchars($deduction['source_name']) ?></td></tr>
            <tr><td>💰 المبلغ الشهري</td><td><strong><?= number_format($deduction['monthly_amount'], 2) ?> دج</strong></td></tr>
            <tr><td>💰 المبلغ الكلي</td><td><?= number_format($originalTotalAmount, 2) ?> دج</td></tr>
            <tr><td>📊 عدد الأشهر</td><td><?= $deduction['total_months'] ?> شهر</td></tr>
            <tr><td>📅 تاريخ البداية</td><td><?= date('d/m/Y', strtotime($deduction['start_date'])) ?></td></tr>
            <tr><td>📅 تاريخ النهاية</td><td><?= date('d/m/Y', strtotime($deduction['end_date'])) ?></td></tr>
            <tr><td>🔔 الحالة</td><td>
                <?php if($daysRemaining < 0): ?>
                    <span style="color: red;">⚠️ منتهي منذ <?= abs(round($daysRemaining)) ?> يوماً</span>
                <?php elseif($daysRemaining < 30): ?>
                    <span style="color: orange;">⏰ ينتهي بعد <?= round($daysRemaining) ?> يوماً</span>
                <?php else: ?>
                    <span style="color: green;">✅ نشط (متبقي <?= round($daysRemaining) ?> يوم)</span>
                <?php endif; ?>
            </td></tr>
            <?php if($hasOriginalDates): ?>
            <tr style="background: #e3f2fd;">
                <td>📌 البيانات الأصلية</td>
                <td>
                    المبلغ الشهري: <strong><?= number_format($_SESSION['original_deduction_' . $id]['monthly_amount'], 2) ?> دج</strong><br>
                    عدد الأشهر: <strong><?= $_SESSION['original_deduction_' . $id]['total_months'] ?> شهر</strong><br>
                    تاريخ النهاية: <?= date('d/m/Y', strtotime($_SESSION['original_deduction_' . $id]['end_date'])) ?>
                </td>
            </tr>
            <?php endif; ?>
        </table>
    </div>

    <!-- تأجيل -->
    <div class="option-card">
        <div class="option-title">⏸️ تأجيل الاقتطاع (توقف مؤقت)</div>
        <div class="highlight">
            💡 <strong>آلية التأجيل:</strong><br>
            • لن يتم الاقتطاع خلال فترة التأجيل<br>
            • يضاف وقت التأجيل إلى تاريخ النهاية<br>
            • يبقى المبلغ الشهري والمبلغ الكلي كما هما
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="postpone">
            <div class="form-group">
                <label>مدة التأجيل (أشهر):</label>
                <select name="postpone_months" required>
                    <option value="">اختر عدد الأشهر</option>
                    <?php for($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>"><?= $m ?> شهر<?= $m > 1 ? 'اً' : '' ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-postpone">⏸️ تأجيل الاقتطاع</button>
        </form>
    </div>

    <!-- تقديم -->
    <div class="option-card">
        <div class="option-title">⏪ تقديم الاقتطاع (تقليص المدة)</div>
        <div class="highlight" style="background:#e3f2fd;">
            💡 عند التقديم: يقل عدد الأشهر، ويزداد المبلغ الشهري (نفس المبلغ الكلي).<br>
            ✅ يتم تقريب المبلغ الشهري إلى أقرب عدد صحيح (بدون كسور).
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="advance">
            <div class="form-group">
                <label>عدد أشهر التقديم:</label>
                <select name="advance_months" required>
                    <option value="">اختر عدد الأشهر</option>
                    <?php for($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>"><?= $m ?> شهر<?= $m > 1 ? 'اً' : '' ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-advance">⏪ تقديم الاقتطاع</button>
        </form>
    </div>

    <!-- إلغاء التعديل -->
    <?php if($hasOriginalDates): ?>
    <div class="option-card" style="border-color: #dc3545;">
        <div class="option-title" style="border-bottom-color: #dc3545;">🔄 إلغاء التعديل</div>
        <form method="POST">
            <input type="hidden" name="action" value="cancel">
            <p>العودة إلى البيانات الأصلية:</p>
            <ul>
                <li>المبلغ الشهري: <strong><?= number_format($_SESSION['original_deduction_' . $id]['monthly_amount'], 2) ?> دج</strong></li>
                <li>عدد الأشهر: <strong><?= $_SESSION['original_deduction_' . $id]['total_months'] ?> شهر</strong></li>
                <li>تاريخ النهاية: <strong><?= date('d/m/Y', strtotime($_SESSION['original_deduction_' . $id]['end_date'])) ?></strong></li>
            </ul>
            <button type="submit" class="btn btn-cancel" onclick="return confirm('هل أنت متأكد من إلغاء التعديل؟')">🔄 إلغاء التعديل</button>
        </form>
    </div>
    <?php endif; ?>

    <div style="text-align: center; margin-top: 20px;">
        <a href="list.php" class="btn btn-secondary">🔙 العودة إلى القائمة</a>
    </div>
</div>

<?php include '../includes/footer.php'; ?>