<?php
/**
 * postpone_installment.php - تأجيل قسط فردي (نقل إلى نهاية المدة)
 */
ob_start();
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';

// ============================================================
// دالة مساعدة لأسماء الأشهر
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
// التحقق من معرف الاقتطاع
// ============================================================
// ✅ اقبل id أو deduction_id
$id = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_GET['deduction_id']) ? (int)$_GET['deduction_id'] : 0);
if ($id <= 0) {
    $_SESSION['toast'] = ['message' => 'معرف الاقتطاع غير صالح', 'type' => 'error'];
    header("Location: list.php");
    exit;
}

// ============================================================
// جلب بيانات الاقتطاع
// ============================================================
$stmt = $pdo->prepare("
    SELECT d.*, e.name as employee_name, s.name as source_name
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRFToken();
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    // ---------- تأجيل قسط ----------
    if ($action === 'postpone' && isset($_POST['installment_id'])) {
        $installment_id = (int)$_POST['installment_id'];
        $reason = trim(isset($_POST['reason']) ? $_POST['reason'] : '');

        $stmtInst = $pdo->prepare("
            SELECT * FROM monthly_installments 
            WHERE id = ? AND deduction_id = ? AND is_paid = 0 AND is_postponed = 0
        ");
        $stmtInst->execute([$installment_id, $id]);
        $installment = $stmtInst->fetch();

        if (!$installment) {
            $message = '⚠️ القسط غير موجود أو غير قابل للتأجيل';
            $messageType = 'warning';
        } else {
            // جلب آخر قسط مستقبلي
            $stmtLast = $pdo->prepare("
                SELECT year, month 
                FROM monthly_installments
                WHERE deduction_id = ? 
                  AND is_paid = 0 
                  AND is_postponed = 0
                ORDER BY year DESC, month DESC 
                LIMIT 1
            ");
            $stmtLast->execute([$id]);
            $last = $stmtLast->fetch();

            if ($last) {
                $newYear = (int)$last['year'];
                $newMonth = (int)$last['month'] + 1;
                if ($newMonth > 12) {
                    $newMonth = 1;
                    $newYear++;
                }
            } else {
                $start = strtotime($deduction['start_date']);
                $newYear = (int)date('Y', $start);
                $newMonth = (int)date('m', $start);
            }

            try {
                $pdo->beginTransaction();

                // تحديث القسط
                $update = $pdo->prepare("
                    UPDATE monthly_installments 
                    SET year = ?, month = ?, is_postponed = 1
                    WHERE id = ?
                ");
                $update->execute([$newYear, $newMonth, $installment_id]);

                // تحديث الاقتطاع
                $newEndDate = date('Y-m-t', strtotime("$newYear-$newMonth-01"));
                $updateDed = $pdo->prepare("
                    UPDATE deductions 
                    SET end_date = ?,
                        total_months = total_months + 1,
                        remaining_months = remaining_months + 1
                    WHERE id = ?
                ");
                $updateDed->execute([$newEndDate, $id]);

                // تسجيل التأجيل
                $originalMonth = sprintf("%04d-%02d", $installment['year'], $installment['month']);
                $newMonthStr = sprintf("%04d-%02d", $newYear, $newMonth);

                $stmtPost = $pdo->prepare("
                    INSERT INTO installment_postponements 
                        (installment_id, deduction_id, employee_id, original_month, new_month, reason, postponed_by, postponed_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now'))
                ");
                $stmtPost->execute([
                    $installment_id,
                    $id,
                    $deduction['employee_id'],
                    $originalMonth,
                    $newMonthStr,
                    $reason,
                    $_SESSION['user_id']
                ]);

                $pdo->commit();

                $monthName = getMonthNameArabic($newMonth);
                $message = "✅ تم نقل القسط إلى $monthName $newYear";
                $messageType = 'success';

            } catch (Exception $e) {
                $pdo->rollBack();
                $message = '❌ خطأ: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    }

    // ---------- إلغاء تأجيل قسط ----------
    elseif ($action === 'undo_postpone' && isset($_POST['postponement_id'])) {
        $postponement_id = (int)$_POST['postponement_id'];

        $stmtPost = $pdo->prepare("
            SELECT ip.*, mi.deduction_id, mi.year as current_year, mi.month as current_month
            FROM installment_postponements ip
            JOIN monthly_installments mi ON ip.installment_id = mi.id
            WHERE ip.id = ?
        ");
        $stmtPost->execute([$postponement_id]);
        $postponement = $stmtPost->fetch();

        if (!$postponement || $postponement['deduction_id'] != $id) {
            $message = '⚠️ سجل التأجيل غير موجود';
            $messageType = 'warning';
        } else {
            try {
                $pdo->beginTransaction();

                // إعادة القسط لموقعه الأصلي
                $origParts = explode('-', $postponement['original_month']);
                $update = $pdo->prepare("
                    UPDATE monthly_installments 
                    SET year = ?, month = ?, is_postponed = 0
                    WHERE id = ?
                ");
                $update->execute([(int)$origParts[0], (int)$origParts[1], $postponement['installment_id']]);

                // حذف أي قسط مكرر
                $newParts = explode('-', $postponement['new_month']);
                $delStmt = $pdo->prepare("
                    DELETE FROM monthly_installments 
                    WHERE deduction_id = ? AND year = ? AND month = ? 
                      AND id != ? AND is_paid = 0 AND is_postponed = 0
                ");
                $delStmt->execute([$id, (int)$newParts[0], (int)$newParts[1], $postponement['installment_id']]);

                // تحديث عدد الأشهر
                $updateDed = $pdo->prepare("
                    UPDATE deductions 
                    SET total_months = total_months - 1,
                        remaining_months = remaining_months - 1
                    WHERE id = ?
                ");
                $updateDed->execute([$id]);

                // إعادة حساب end_date
                $stmtLast = $pdo->prepare("
                    SELECT year, month FROM monthly_installments
                    WHERE deduction_id = ? AND is_paid = 0 AND is_postponed = 0
                    ORDER BY year DESC, month DESC LIMIT 1
                ");
                $stmtLast->execute([$id]);
                $last = $stmtLast->fetch();
                $newEndDate = $last ? date('Y-m-t', strtotime($last['year'] . '-' . $last['month'] . '-01')) : $deduction['start_date'];

                $updateEnd = $pdo->prepare("UPDATE deductions SET end_date = ? WHERE id = ?");
                $updateEnd->execute([$newEndDate, $id]);

                // حذف سجل التأجيل
                $delPost = $pdo->prepare("DELETE FROM installment_postponements WHERE id = ?");
                $delPost->execute([$postponement_id]);

                $pdo->commit();
                $message = '✅ تم إلغاء التأجيل';
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
// جلب الأقساط للعرض
// ============================================================
$stmt = $pdo->prepare("
    SELECT id, year, month, amount, is_paid, is_postponed
    FROM monthly_installments
    WHERE deduction_id = ? AND is_paid = 0
    ORDER BY year, month
");
$stmt->execute([$id]);
$allInstallments = $stmt->fetchAll();

$futureInstallments = [];
$postponedInstallments = [];

foreach ($allInstallments as $inst) {
    if ($inst['is_postponed']) {
        $postponedInstallments[] = $inst;
    } else {
        $futureInstallments[] = $inst;
    }
}

// جلب سجل التأجيلات
$stmtPost = $pdo->prepare("
    SELECT ip.*, mi.amount
    FROM installment_postponements ip
    JOIN monthly_installments mi ON ip.installment_id = mi.id
    WHERE mi.deduction_id = ?
    ORDER BY ip.postponed_at DESC
");
$stmtPost->execute([$id]);
$postponements = $stmtPost->fetchAll();

$csrf_token = generateCSRFToken();
include '../includes/header.php';
?>

<style>
    .postpone-container { max-width: 1200px; margin: 0 auto; padding: 20px; direction: rtl; }
    .postpone-card { background: white; border-radius: 20px; padding: 25px; margin-bottom: 25px; box-shadow: 0 2px 15px rgba(0,0,0,0.08); }
    .postpone-card h3 { color: #2a5298; border-right: 4px solid #2a5298; padding-right: 12px; margin-bottom: 20px; }
    .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; }
    .info-item { background: #f8f9fa; padding: 12px 18px; border-radius: 12px; }
    .info-item .label { font-size: 12px; color: #888; display: block; }
    .info-item .value { font-size: 18px; font-weight: 700; color: #2a5298; }
    .data-table { width: 100%; border-collapse: collapse; font-size: 14px; }
    .data-table th, .data-table td { padding: 12px 10px; text-align: center; border-bottom: 1px solid #eee; }
    .data-table th { background: #2a5298; color: white; }
    .data-table tr:hover { background: #f8f9fa; }
    .btn-sm { padding: 6px 16px; border: none; border-radius: 20px; cursor: pointer; font-weight: 600; font-size: 12px; text-decoration: none; display: inline-block; }
    .btn-postpone { background: #ff9800; color: white; }
    .btn-postpone:hover { background: #e68900; }
    .btn-undo { background: #dc3545; color: white; }
    .btn-undo:hover { background: #c82333; }
    .btn-back { background: #6c757d; color: white; padding: 10px 25px; border-radius: 25px; text-decoration: none; display: inline-block; }
    .badge { padding: 4px 12px; border-radius: 20px; font-size: 12px; }
    .badge-active { background: #28a745; color: white; }
    .message-box { padding: 15px 20px; border-radius: 12px; margin-bottom: 20px; font-weight: 600; }
    .message-success { background: #d4edda; color: #155724; border-right: 5px solid #28a745; }
    .message-error { background: #f8d7da; color: #721c24; border-right: 5px solid #dc3545; }
    .message-warning { background: #fff3cd; color: #856404; border-right: 5px solid #ffc107; }
    .empty-state { text-align: center; padding: 30px; color: #999; }
    .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; justify-content: center; align-items: center; }
    .modal-overlay.active { display: flex; }
    .modal-box { background: white; padding: 30px; border-radius: 20px; max-width: 500px; width: 90%; box-shadow: 0 10px 40px rgba(0,0,0,0.3); }
    .modal-box textarea { width: 100%; height: 80px; padding: 10px; border: 1px solid #ddd; border-radius: 10px; resize: vertical; font-family: inherit; margin-top: 5px; }
    .modal-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px; }
</style>

<div class="postpone-container">

    <?php if ($message): ?>
        <div class="message-box message-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <!-- معلومات الاقتطاع -->
    <div class="postpone-card">
        <h3>⏰ إدارة تأجيل الأقساط - <?= htmlspecialchars($deduction['employee_name']) ?></h3>
        <div class="info-grid">
            <div class="info-item">
                <span class="label">المصدر</span>
                <span class="value"><?= htmlspecialchars($deduction['source_name']) ?></span>
            </div>
            <div class="info-item">
                <span class="label">المبلغ الشهري</span>
                <span class="value"><?= number_format($deduction['monthly_amount'], 2) ?> دج</span>
            </div>
            <div class="info-item">
                <span class="label">تاريخ النهاية الحالي</span>
                <span class="value"><?= date('d/m/Y', strtotime($deduction['end_date'])) ?></span>
            </div>
            <div class="info-item">
                <span class="label">عدد الأشهر المتبقية</span>
                <span class="value"><?= $deduction['remaining_months'] ?> شهر</span>
            </div>
        </div>
    </div>

    <!-- الأقساط المؤجلة -->
    <?php if (!empty($postponements)): ?>
    <div class="postpone-card">
        <h3>📌 الأقساط المؤجلة</h3>
        <div style="overflow-x:auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>القسط الأصلي</th>
                        <th>تم تأجيله إلى</th>
                        <th>السبب</th>
                        <th>تاريخ التأجيل</th>
                        <th>إجراء</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $i = 1; foreach ($postponements as $p): 
                        $origParts = explode('-', $p['original_month']);
                        $newParts = explode('-', $p['new_month']);
                        $origText = getMonthNameArabic((int)$origParts[1]) . ' ' . $origParts[0];
                        $newText = getMonthNameArabic((int)$newParts[1]) . ' ' . $newParts[0];
                    ?>
                    <tr>
                        <td><?= $i++ ?></td>
                        <td><?= htmlspecialchars($origText) ?></td>
                        <td><?= htmlspecialchars($newText) ?></td>
                        <td><?= htmlspecialchars($p['reason'] ?: 'بدون سبب') ?></td>
                        <td><?= date('d/m/Y H:i', strtotime($p['postponed_at'])) ?></td>
                        <td>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                <input type="hidden" name="action" value="undo_postpone">
                                <input type="hidden" name="postponement_id" value="<?= $p['id'] ?>">
                                <button type="submit" class="btn-sm btn-undo" onclick="return confirm('هل أنت متأكد من إلغاء التأجيل؟')">🗑️ إلغاء</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- الأقساط المستقبلية -->
    <div class="postpone-card">
        <h3>📋 الأقساط المستقبلية (اختر قسطاً لتأجيله)</h3>
        <?php if (empty($futureInstallments)): ?>
            <div class="empty-state">🎯 لا توجد أقساط مستقبلية قابلة للتأجيل.</div>
        <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>الشهر</th>
                            <th>السنة</th>
                            <th>المبلغ (دج)</th>
                            <th>الحالة</th>
                            <th>الإجراء</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 1; foreach ($futureInstallments as $inst): ?>
                        <tr>
                            <td><?= $i++ ?></td>
                            <td><?= getMonthNameArabic($inst['month']) ?></td>
                            <td><?= $inst['year'] ?></td>
                            <td><?= number_format($inst['amount'], 2) ?></td>
                            <td><span class="badge badge-active">✅ قادم</span></td>
                            <td>
                                <?php $infoText = getMonthNameArabic($inst['month']) . ' ' . $inst['year']; ?>
                                <button type="button" class="btn-sm btn-postpone" 
                                        onclick="openPostponeModal(<?= $inst['id'] ?>, '<?= htmlspecialchars($infoText, ENT_QUOTES) ?>')">
                                    📅 تأجيل
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div style="margin-top:20px; text-align:center;">
        <a href="view.php?id=<?= $id ?>" class="btn-back">🔙 العودة للتفاصيل</a>
        <a href="list.php" class="btn-back" style="background:#17a2b8;">📋 قائمة الاقتطاعات</a>
    </div>
</div>

<!-- Modal تأجيل -->
<div id="postponeModal" class="modal-overlay">
    <div class="modal-box">
        <h3 style="color:#ff9800;">📅 تأجيل القسط</h3>
        <p style="margin: 15px 0;"><strong>القسط:</strong> <span id="installmentInfo"></span></p>
        <p style="font-size:14px; color:#666;">
            سيتم نقل هذا القسط إلى أول شهر متاح بعد نهاية المدة الحالية.
        </p>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <input type="hidden" name="action" value="postpone">
            <input type="hidden" name="installment_id" id="installmentIdInput">
            <div style="margin:15px 0;">
                <label style="display:block; font-weight:600; margin-bottom:5px;">سبب التأجيل (اختياري):</label>
                <textarea name="reason" id="reason" placeholder="اذكر سبب التأجيل..."></textarea>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-sm" style="background:#6c757d; color:white; padding:10px 25px;" onclick="closeModal()">إلغاء</button>
                <button type="submit" class="btn-sm" style="background:#ff9800; color:white; padding:10px 25px; font-weight:600;">تأكيد التأجيل</button>
            </div>
        </form>
    </div>
</div>

<script>
function openPostponeModal(id, info) {
    document.getElementById('installmentIdInput').value = id;
    document.getElementById('installmentInfo').textContent = info;
    document.getElementById('postponeModal').classList.add('active');
    document.getElementById('reason').value = '';
}

function closeModal() {
    document.getElementById('postponeModal').classList.remove('active');
}

document.getElementById('postponeModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>

<?php include '../includes/footer.php'; ?>