<?php
/**
 * postpone_period.php - تعديل فترة الاقتطاع (تمديد أو تقليص)
 */
ob_start();
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';

// ============================================================
// دالة أسماء الأشهر
// ============================================================
if (!function_exists('getMonthNameArabic')) {
    function getMonthNameArabic($m) {
        $names = [
            1 => 'جانفي', 2 => 'فيفري', 3 => 'مارس', 4 => 'أفريل',
            5 => 'ماي', 6 => 'جوان', 7 => 'جويلية', 8 => 'أوت',
            9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر'
        ];
        return $names[(int)$m] ?? $m;
    }
}

// ============================================================
// التحقق من المعرف
// ============================================================
// ✅ اقبل id أو deduction_id
$id = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_GET['deduction_id']) ? (int)$_GET['deduction_id'] : 0);
if ($id <= 0) {
    $_SESSION['toast'] = ['message' => 'معرف غير صالح', 'type' => 'error'];
    header("Location: list.php");
    exit;
}

// ============================================================
// جلب بيانات الاقتطاع
// ============================================================
$stmt = $pdo->prepare("
    SELECT d.*, e.name as employee_name, e.category as employee_category,
           s.name as source_name
    FROM deductions d
    JOIN employees e ON d.employee_id = e.id
    JOIN sources s ON d.source_id = s.id
    WHERE d.id = ?
");
$stmt->execute([$id]);
$deduction = $stmt->fetch();

if (!$deduction) {
    $_SESSION['toast'] = ['message' => 'الاقتطاع غير موجود', 'type' => 'error'];
    header("Location: list.php");
    exit;
}

// ============================================================
// معالجة POST
// ============================================================
$message = '';
$messageType = '';
$previewMode = false;
$previewData = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRFToken();
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    // ---------- معاينة ----------
    if ($action === 'preview') {
        $extraMonths = (int)($_POST['extra_months'] ?? 0);
        $newStartDate = $_POST['new_start_date'] ?? $deduction['start_date'];
        
        if ($extraMonths != 0) {
            // حساب التاريخ الجديد
            $currentEnd = new DateTime($deduction['end_date']);
            if ($extraMonths > 0) {
                $currentEnd->modify("+$extraMonths months");
            } else {
                $absMonths = abs($extraMonths);
                $currentEnd->modify("-$absMonths months");
            }
            $newEndDate = $currentEnd->format('Y-m-d');
            $newTotalMonths = max(1, (int)$deduction['total_months'] + $extraMonths);
            
            // حساب عدد الأشهر الفعلية بين البداية والنهاية
            $start = new DateTime($newStartDate);
            $end = new DateTime($newEndDate);
            $diff = $start->diff($end);
            $monthsCount = ($diff->y * 12) + $diff->m + 1;
            
            // حساب المبلغ الشهري الجديد
            $totalAmount = $deduction['monthly_amount'] * $deduction['total_months'];
            $newMonthlyAmount = $newTotalMonths > 0 ? round($totalAmount / $newTotalMonths, 2) : $deduction['monthly_amount'];
            
            $previewData = [
                'new_start_date' => $newStartDate,
                'new_end_date' => $newEndDate,
                'new_total_months' => $newTotalMonths,
                'new_monthly_amount' => $newMonthlyAmount,
                'extra_months' => $extraMonths,
                'current_total_amount' => $totalAmount,
                'months_count_diff' => $monthsCount
            ];
            $previewMode = true;
        } else {
            $message = '⚠️ لم تُحدد أي تغييرات';
            $messageType = 'warning';
        }
    }
    
    // ---------- تنفيذ التعديل ----------
    elseif ($action === 'apply') {
        $extraMonths = (int)($_POST['extra_months'] ?? 0);
        $newStartDate = $_POST['new_start_date'] ?? $deduction['start_date'];
        
        if ($extraMonths == 0) {
            $message = '⚠️ لا توجد تغييرات لتطبيقها';
            $messageType = 'warning';
        } else {
            try {
                $pdo->beginTransaction();
                
                // حساب التواريخ الجديدة
                $currentEnd = new DateTime($deduction['end_date']);
                if ($extraMonths > 0) {
                    $currentEnd->modify("+$extraMonths months");
                } else {
                    $absMonths = abs($extraMonths);
                    $currentEnd->modify("-$absMonths months");
                }
                $newEndDate = $currentEnd->format('Y-m-d');
                $newTotalMonths = max(1, (int)$deduction['total_months'] + $extraMonths);
                
                // المبلغ الإجمالي ثابت
                $totalAmount = $deduction['monthly_amount'] * $deduction['total_months'];
                $newMonthlyAmount = round($totalAmount / $newTotalMonths, 2);
                
                // معالجة الأقساط غير المدفوعة
                $stmtUnpaid = $pdo->prepare("
                    SELECT id FROM monthly_installments 
                    WHERE deduction_id = ? AND is_paid = 0
                    ORDER BY year, month
                ");
                $stmtUnpaid->execute([$id]);
                $unpaidInstallments = $stmtUnpaid->fetchAll();
                $unpaidCount = count($unpaidInstallments);
                
                if ($extraMonths > 0) {
                    // إضافة أقساط جديدة
                    $lastInst = $pdo->prepare("
                        SELECT year, month FROM monthly_installments
                        WHERE deduction_id = ? AND is_paid = 0
                        ORDER BY year DESC, month DESC LIMIT 1
                    ");
                    $lastInst->execute([$id]);
                    $last = $lastInst->fetch();
                    
                    if ($last) {
                        $y = (int)$last['year'];
                        $m = (int)$last['month'];
                    } else {
                        $startDt = new DateTime($newStartDate);
                        $y = (int)$startDt->format('Y');
                        $m = (int)$startDt->format('n');
                    }
                    
                    $insertInst = $pdo->prepare("
                        INSERT INTO monthly_installments 
                            (deduction_id, employee_id, source_id, year, month, amount, is_paid, is_postponed)
                        VALUES (?, ?, ?, ?, ?, ?, 0, 0)
                    ");
                    
                    for ($i = 0; $i < $extraMonths; $i++) {
                        $m++;
                        if ($m > 12) { $m = 1; $y++; }
                        $insertInst->execute([
                            $id, $deduction['employee_id'], $deduction['source_id'],
                            $y, $m, $newMonthlyAmount
                        ]);
                    }
                } elseif ($extraMonths < 0) {
                    // حذف أقساط من النهاية
                    $absMonths = abs($extraMonths);
                    $toDelete = array_slice(array_reverse($unpaidInstallments), 0, $absMonths);
                    
                    $delStmt = $pdo->prepare("DELETE FROM monthly_installments WHERE id = ?");
                    foreach ($toDelete as $inst) {
                        $delStmt->execute([$inst['id']]);
                    }
                }
                
                // تحديث الاقتطاع
                $update = $pdo->prepare("
                    UPDATE deductions 
                    SET start_date = ?,
                        end_date = ?,
                        total_months = ?,
                        monthly_amount = ?,
                        remaining_months = ?
                    WHERE id = ?
                ");
                $update->execute([
                    $newStartDate,
                    $newEndDate,
                    $newTotalMonths,
                    $newMonthlyAmount,
                    max(0, $newTotalMonths - (int)($deduction['paid_months'] ?? 0)),
                    $id
                ]);
                
                $pdo->commit();
                
                $message = "✅ تم تعديل الفترة بنجاح! عدد الأشهر الجديد: $newTotalMonths";
                $messageType = 'success';
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $message = '❌ خطأ: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    }
}

// ============================================================
// جلب إحصائيات الأقساط
// ============================================================
$stmtStats = $pdo->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN is_paid = 1 THEN 1 ELSE 0 END) as paid,
        SUM(CASE WHEN is_paid = 0 AND is_postponed = 0 THEN 1 ELSE 0 END) as future,
        SUM(CASE WHEN is_postponed = 1 THEN 1 ELSE 0 END) as postponed
    FROM monthly_installments
    WHERE deduction_id = ?
");
$stmtStats->execute([$id]);
$stats = $stmtStats->fetch();

$csrf_token = generateCSRFToken();
include '../includes/header.php';
?>

<style>
    .period-container { max-width: 900px; margin: 0 auto; padding: 20px; direction: rtl; }
    .period-card { background: white; border-radius: 20px; padding: 25px; margin-bottom: 25px; box-shadow: 0 2px 15px rgba(0,0,0,0.08); }
    .period-card h3 { color: #17a2b8; border-right: 4px solid #17a2b8; padding-right: 12px; margin-bottom: 20px; }
    .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 20px; }
    .info-item { background: #f8f9fa; padding: 12px 18px; border-radius: 12px; text-align: center; }
    .info-item .label { font-size: 12px; color: #888; display: block; margin-bottom: 5px; }
    .info-item .value { font-size: 18px; font-weight: 700; color: #17a2b8; }
    .form-group { margin-bottom: 20px; }
    .form-group label { display: block; font-weight: 700; margin-bottom: 8px; color: #333; }
    .form-group input, .form-group select { width: 100%; padding: 12px 15px; border: 2px solid #e1e8ed; border-radius: 10px; font-size: 14px; font-family: inherit; }
    .form-group input:focus { outline: none; border-color: #17a2b8; }
    .help-text { font-size: 12px; color: #666; margin-top: 5px; }
    .btn { padding: 12px 30px; border: none; border-radius: 25px; cursor: pointer; font-weight: 600; font-size: 14px; text-decoration: none; display: inline-block; transition: all 0.2s; }
    .btn-primary { background: #17a2b8; color: white; }
    .btn-primary:hover { background: #138496; }
    .btn-success { background: #28a745; color: white; }
    .btn-success:hover { background: #218838; }
    .btn-secondary { background: #6c757d; color: white; }
    .btn-secondary:hover { background: #5a6268; }
    .btn-danger { background: #dc3545; color: white; }
    .message-box { padding: 15px 20px; border-radius: 12px; margin-bottom: 20px; font-weight: 600; }
    .message-success { background: #d4edda; color: #155724; border-right: 5px solid #28a745; }
    .message-error { background: #f8d7da; color: #721c24; border-right: 5px solid #dc3545; }
    .message-warning { background: #fff3cd; color: #856404; border-right: 5px solid #ffc107; }
    .preview-box { background: #e3f2fd; padding: 20px; border-radius: 15px; margin: 20px 0; border-right: 5px solid #2196f3; }
    .preview-box h4 { color: #1565c0; margin-bottom: 15px; }
    .preview-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; }
    .preview-item { background: white; padding: 12px; border-radius: 10px; text-align: center; }
    .preview-item .label { font-size: 11px; color: #666; margin-bottom: 3px; }
    .preview-item .value { font-size: 16px; font-weight: 700; color: #1565c0; }
    .preview-item .value.success { color: #28a745; }
    .preview-item .value.danger { color: #dc3545; }
    .actions-row { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 20px; }
</style>

<div class="period-container">

    <?php if ($message): ?>
        <div class="message-box message-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <!-- معلومات الاقتطاع -->
    <div class="period-card">
        <h3>⏰ تعديل فترة الاقتطاع - <?= htmlspecialchars($deduction['employee_name']) ?></h3>
        
        <div class="info-grid">
            <div class="info-item">
                <span class="label">المصدر</span>
                <span class="value"><?= htmlspecialchars($deduction['source_name']) ?></span>
            </div>
            <div class="info-item">
                <span class="label">المبلغ الشهري الحالي</span>
                <span class="value"><?= number_format($deduction['monthly_amount'], 2) ?> دج</span>
            </div>
            <div class="info-item">
                <span class="label">عدد الأشهر الحالي</span>
                <span class="value"><?= $deduction['total_months'] ?> شهر</span>
            </div>
            <div class="info-item">
                <span class="label">المبلغ الإجمالي</span>
                <span class="value"><?= number_format($deduction['monthly_amount'] * $deduction['total_months'], 2) ?> دج</span>
            </div>
        </div>
        
        <div class="info-grid">
            <div class="info-item">
                <span class="label">تاريخ البداية</span>
                <span class="value" style="font-size:14px;"><?= date('d/m/Y', strtotime($deduction['start_date'])) ?></span>
            </div>
            <div class="info-item">
                <span class="label">تاريخ النهاية</span>
                <span class="value" style="font-size:14px;"><?= date('d/m/Y', strtotime($deduction['end_date'])) ?></span>
            </div>
            <div class="info-item">
                <span class="label">أقساط مدفوعة</span>
                <span class="value" style="color:#28a745;"><?= $stats['paid'] ?? 0 ?></span>
            </div>
            <div class="info-item">
                <span class="label">أقساط متبقية</span>
                <span class="value" style="color:#ff9800;"><?= ($stats['future'] ?? 0) + ($stats['postponed'] ?? 0) ?></span>
            </div>
        </div>
    </div>

    <!-- نموذج التعديل -->
    <div class="period-card">
        <h3>⚙️ تعديل الفترة</h3>
        
        <div class="message-box message-warning">
            ⚠️ <strong>تنبيه:</strong> تمديد الفترة يقلل المبلغ الشهري (المبلغ الإجمالي ثابت).
            تقليص الفترة يزيد المبلغ الشهري. الأقساط المدفوعة لا تتأثر.
        </div>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            
            <div class="form-group">
                <label>📅 تاريخ البداية</label>
                <input type="date" name="new_start_date" value="<?= htmlspecialchars($deduction['start_date']) ?>">
                <div class="help-text">التاريخ الذي يبدأ فيه الاقتطاع</div>
            </div>
            
            <div class="form-group">
                <label>➕ عدد الأشهر الإضافية</label>
                <input type="number" name="extra_months" id="extraMonths" 
                       value="0" min="-<?= min(11, (int)($stats['future'] ?? 1)) ?>" max="24"
                       placeholder="مثال: 3 لتمديد 3 أشهر، -2 لتقليص شهرين">
                <div class="help-text">
                    • قيمة <strong>موجبة</strong> = تمديد الفترة (تقليل المبلغ الشهري)<br>
                    • قيمة <strong>سالبة</strong> = تقليص الفترة (زيادة المبلغ الشهري)<br>
                    • القيمة <strong>0</strong> = لا تغيير
                </div>
            </div>
            
            <div class="actions-row">
                <button type="submit" name="action" value="preview" class="btn btn-primary">
                    👁️ معاينة النتيجة
                </button>
                <a href="view.php?id=<?= $id ?>" class="btn btn-secondary">
                    🔙 إلغاء
                </a>
            </div>
        </form>
    </div>

    <!-- المعاينة -->
    <?php if ($previewMode && !empty($previewData)): ?>
    <div class="period-card">
        <h3>👁️ معاينة التغييرات</h3>
        
        <div class="preview-box">
            <h4>📊 مقارنة قبل/بعد</h4>
            <div class="preview-grid">
                <div class="preview-item">
                    <div class="label">عدد الأشهر (قبل)</div>
                    <div class="value"><?= $deduction['total_months'] ?></div>
                </div>
                <div class="preview-item">
                    <div class="label">عدد الأشهر (بعد)</div>
                    <div class="value success"><?= $previewData['new_total_months'] ?></div>
                </div>
                <div class="preview-item">
                    <div class="label">المبلغ الشهري (قبل)</div>
                    <div class="value"><?= number_format($deduction['monthly_amount'], 2) ?> دج</div>
                </div>
                <div class="preview-item">
                    <div class="label">المبلغ الشهري (بعد)</div>
                    <div class="value success"><?= number_format($previewData['new_monthly_amount'], 2) ?> دج</div>
                </div>
                <div class="preview-item">
                    <div class="label">تاريخ النهاية (قبل)</div>
                    <div class="value" style="font-size:13px;"><?= date('d/m/Y', strtotime($deduction['end_date'])) ?></div>
                </div>
                <div class="preview-item">
                    <div class="label">تاريخ النهاية (بعد)</div>
                    <div class="value success" style="font-size:13px;"><?= date('d/m/Y', strtotime($previewData['new_end_date'])) ?></div>
                </div>
                <div class="preview-item">
                    <div class="label">إجمالي المبلغ</div>
                    <div class="value"><?= number_format($previewData['current_total_amount'], 2) ?> دج</div>
                </div>
                <div class="preview-item">
                    <div class="label">التغيير</div>
                    <div class="value <?= $previewData['extra_months'] > 0 ? 'success' : 'danger' ?>">
                        <?= $previewData['extra_months'] > 0 ? '+' : '' ?><?= $previewData['extra_months'] ?> شهر
                    </div>
                </div>
            </div>
        </div>
        
        <div class="message-box message-warning">
            ⚠️ <strong>تأكيد:</strong> سيتم تطبيق التغييرات على قاعدة البيانات. هذه العملية قابلة للتراجع يدوياً.
        </div>
        
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <input type="hidden" name="extra_months" value="<?= $previewData['extra_months'] ?>">
            <input type="hidden" name="new_start_date" value="<?= htmlspecialchars($previewData['new_start_date']) ?>">
            
            <div class="actions-row">
                <button type="submit" name="action" value="apply" class="btn btn-success">
                    ✅ تأكيد التطبيق
                </button>
                <a href="postpone_period.php?id=<?= $id ?>" class="btn btn-secondary">
                    🔙 رجوع
                </a>
            </div>
        </form>
    </div>
    <?php endif; ?>

</div>

<?php include '../includes/footer.php'; ?>