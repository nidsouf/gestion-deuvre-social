<?php
/**
 * postpone_installment.php - إدارة تأجيل الأقساط الفردية
 */
ob_start();
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';

// تعطيل CSP مؤقتاً للسماح بتحميل المكتبات (يمكن إزالته بعد التطوير)
header("Content-Security-Policy: default-src * 'unsafe-inline' 'unsafe-eval'; script-src * 'unsafe-inline' 'unsafe-eval'; style-src * 'unsafe-inline';");

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    $_SESSION['toast'] = ['message' => 'معرف الاقتطاع غير صالح', 'type' => 'error', 'duration' => 3000];
    header("Location: list.php");
    exit;
}

// جلب بيانات الاقتطاع
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
    $_SESSION['toast'] = ['message' => 'الاقتطاع غير موجود', 'type' => 'error', 'duration' => 3000];
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
    $action = $_POST['action'] ?? '';

    // تأجيل قسط
if ($action === 'postpone' && isset($_POST['installment_id'])) {
    $installment_id = (int)$_POST['installment_id'];
    $reason = trim($_POST['reason'] ?? '');

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
        // ===== المنطق الجديد: أول شهر فارغ =====
        // 1. جلب آخر قسط موجود (غير مدفوع وغير مؤجل)
        $stmtLast = $pdo->prepare("
            SELECT year, month FROM monthly_installments
            WHERE deduction_id = ? AND is_paid = 0 AND is_postponed = 0
            ORDER BY year DESC, month DESC LIMIT 1
        ");
        $stmtLast->execute([$id]);
        $last = $stmtLast->fetch();

        if ($last) {
            $lastYear = (int)$last['year'];
            $lastMonth = (int)$last['month'];
            // إضافة شهر واحد
            $newMonth = $lastMonth + 1;
            $newYear = $lastYear;
            if ($newMonth > 12) {
                $newMonth = 1;
                $newYear++;
            }
        } else {
            // إذا لم يوجد أي قسط (حالة نادرة)، نستخدم تاريخ البداية
            $startDate = new DateTime($deduction['start_date']);
            $newYear = (int)$startDate->format('Y');
            $newMonth = (int)$startDate->format('m');
        }

        // 2. تحديث end_date ليكون نهاية الشهر الجديد
        $newEndDate = date('Y-m-t', strtotime("$newYear-$newMonth-01"));

        try {
            $pdo->beginTransaction();

            // تحديث القسط إلى مؤجل
            $update = $pdo->prepare("
                UPDATE monthly_installments 
                SET is_postponed = 1, postponed_from_month = ? 
                WHERE id = ?
            ");
            $update->execute([
                sprintf("%04d-%02d", $installment['year'], $installment['month']),
                $installment_id
            ]);

            // إدراج قسط جديد في الشهر الفارغ
            $insert = $pdo->prepare("
                INSERT INTO monthly_installments 
                    (deduction_id, employee_id, source_id, year, month, amount, is_paid, is_postponed, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 0, 0, datetime('now'))
            ");
            $insert->execute([
                $deduction['id'],
                $deduction['employee_id'],
                $deduction['source_id'],
                $newYear,
                $newMonth,
                $installment['amount']
            ]);

            // تحديث تاريخ النهاية
            $updateDed = $pdo->prepare("UPDATE deductions SET end_date = ? WHERE id = ?");
            $updateDed->execute([$newEndDate, $id]);

            // تسجيل التأجيل
            $stmtPost = $pdo->prepare("
                INSERT INTO installment_postponements 
                    (installment_id, deduction_id, employee_id, original_month, new_month, reason, postponed_by, postponed_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now'))
            ");
            $stmtPost->execute([
                $installment_id,
                $deduction['id'],
                $deduction['employee_id'],
                sprintf("%04d-%02d", $installment['year'], $installment['month']),
                sprintf("%04d-%02d", $newYear, $newMonth),
                $reason,
                $_SESSION['user_id']
            ]);

            $pdo->commit();

            $message = '✅ تم تأجيل قسط شهر ' . getMonthNameArabic($installment['month']) . ' ' . $installment['year'] . ' إلى ' . getMonthNameArabic($newMonth) . ' ' . $newYear;
            $messageType = 'success';

        } catch (Exception $e) {
            $pdo->rollBack();
            $message = '❌ حدث خطأ: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

    // ============================================================
    // إلغاء تأجيل قسط (تم إصلاح خطأ ORDER BY)
    // ============================================================
    elseif ($action === 'undo_postpone' && isset($_POST['postponement_id'])) {
        $postponement_id = (int)$_POST['postponement_id'];

        $stmtPost = $pdo->prepare("
            SELECT ip.*, mi.deduction_id
            FROM installment_postponements ip
            JOIN monthly_installments mi ON ip.installment_id = mi.id
            WHERE ip.id = ?
        ");
        $stmtPost->execute([$postponement_id]);
        $postponement = $stmtPost->fetch();

        if (!$postponement || $postponement['deduction_id'] != $id) {
            $message = '⚠️ سجل التأجيل غير موجود أو لا يخص هذا الاقتطاع';
            $messageType = 'warning';
        } else {
            try {
                $pdo->beginTransaction();

                // 1. إعادة القسط الأصلي
                $update = $pdo->prepare("
                    UPDATE monthly_installments 
                    SET is_postponed = 0, postponed_from_month = NULL 
                    WHERE id = ?
                ");
                $update->execute([$postponement['installment_id']]);

                // 2. حذف القسط الجديد (بدون ORDER BY)
                $newMonthParts = explode('-', $postponement['new_month']);
                $delStmt = $pdo->prepare("
                    DELETE FROM monthly_installments 
                    WHERE deduction_id = ? AND year = ? AND month = ? 
                      AND is_paid = 0 AND is_postponed = 0
                ");
                $delStmt->execute([
                    $id,
                    (int)$newMonthParts[0],
                    (int)$newMonthParts[1]
                ]);

                // 3. إعادة تاريخ النهاية
                $oldEnd = new DateTime($deduction['end_date']);
                $oldEnd->modify('-1 month');
                $newEndDate = $oldEnd->format('Y-m-t');
                $updateDed = $pdo->prepare("UPDATE deductions SET end_date = ? WHERE id = ?");
                $updateDed->execute([$newEndDate, $id]);

                // 4. حذف سجل التأجيل
                $delPost = $pdo->prepare("DELETE FROM installment_postponements WHERE id = ?");
                $delPost->execute([$postponement_id]);

                $pdo->commit();

                $message = '✅ تم إلغاء تأجيل القسط وعودة المدة إلى السابق';
                $messageType = 'success';

            } catch (Exception $e) {
                $pdo->rollBack();
                $message = '❌ حدث خطأ أثناء الإلغاء: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    }
}

// ============================================================
// جلب البيانات للعرض
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

$stmtPost = $pdo->prepare("
    SELECT 
        ip.*,
        mi.year as original_year,
        mi.month as original_month,
        mi.amount
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

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تأجيل الأقساط - <?= htmlspecialchars($deduction['employee_name']) ?></title>
    <style>
        /* (أنماط CSS كما في الملف السابق - اختصاراً للطول) */
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Cairo', sans-serif; background: #f0f4f8; }
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        .card { background: white; border-radius: 20px; padding: 25px; margin-bottom: 25px; box-shadow: 0 2px 15px rgba(0,0,0,0.08); }
        .card-title { font-size: 20px; font-weight: 700; margin-bottom: 15px; color: #2a5298; border-right: 4px solid #2a5298; padding-right: 12px; }
        .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; }
        .info-item { background: #f8f9fa; padding: 12px 18px; border-radius: 12px; }
        .info-item .label { font-size: 12px; color: #888; }
        .info-item .value { font-size: 18px; font-weight: 700; color: #2a5298; }
        .table-wrapper { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { padding: 12px 10px; text-align: center; border-bottom: 1px solid #eee; }
        th { background: #2a5298; color: white; font-weight: 600; }
        tr:hover { background: #f8f9fa; }
        .btn { padding: 8px 20px; border: none; border-radius: 25px; cursor: pointer; font-weight: 600; font-size: 13px; transition: all 0.3s; }
        .btn-postpone { background: #ff9800; color: white; }
        .btn-postpone:hover { background: #e68900; }
        .btn-undo { background: #dc3545; color: white; }
        .btn-undo:hover { background: #c82333; }
        .btn-back { background: #6c757d; color: white; text-decoration: none; display: inline-block; padding: 10px 25px; border-radius: 25px; }
        .btn-back:hover { background: #5a6268; }
        .badge { padding: 4px 12px; border-radius: 20px; font-size: 12px; display: inline-block; }
        .badge-postponed { background: #ffc107; color: #333; }
        .badge-active { background: #28a745; color: white; }
        .message-box { padding: 15px 20px; border-radius: 12px; margin-bottom: 20px; font-weight: 600; }
        .message-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .message-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .message-warning { background: #fff3cd; color: #856404; border: 1px solid #ffc107; }
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; justify-content: center; align-items: center; }
        .modal-overlay.active { display: flex; }
        .modal-box { background: white; padding: 30px; border-radius: 20px; max-width: 500px; width: 90%; box-shadow: 0 10px 40px rgba(0,0,0,0.3); }
        .modal-box h3 { margin-top: 0; color: #2a5298; }
        .modal-box textarea { width: 100%; height: 80px; padding: 10px; border: 1px solid #ddd; border-radius: 10px; resize: vertical; font-family: inherit; margin-top: 5px; }
        .modal-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px; }
        .btn-confirm { background: #ff9800; color: white; padding: 10px 25px; border: none; border-radius: 25px; cursor: pointer; font-weight: 600; }
        .btn-cancel { background: #6c757d; color: white; padding: 10px 25px; border: none; border-radius: 25px; cursor: pointer; font-weight: 600; }
        .empty-state { text-align: center; padding: 30px; color: #999; font-size: 16px; }
        @media (max-width: 768px) {
            .info-grid { grid-template-columns: 1fr; }
            th, td { padding: 8px 5px; font-size: 12px; }
            .container { padding: 10px; }
        }
    </style>
</head>
<body>
<div class="container">

    <?php if ($message): ?>
        <div class="message-box message-<?= $messageType ?>">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <h2 class="card-title">⏰ إدارة تأجيل الأقساط - <?= htmlspecialchars($deduction['employee_name']) ?></h2>
        <div class="info-grid">
            <div class="info-item"><div class="label">المصدر</div><div class="value"><?= htmlspecialchars($deduction['source_name']) ?></div></div>
            <div class="info-item"><div class="label">المبلغ الشهري</div><div class="value"><?= number_format($deduction['monthly_amount'], 2) ?> دج</div></div>
            <div class="info-item"><div class="label">تاريخ النهاية الحالي</div><div class="value"><?= date('d/m/Y', strtotime($deduction['end_date'])) ?></div></div>
            <div class="info-item"><div class="label">عدد الأشهر المتبقية</div><div class="value"><?= $deduction['total_months'] ?> شهر</div></div>
        </div>
    </div>

    <?php if (!empty($postponements)): ?>
        <div class="card">
            <h3 class="card-title">📌 الأقساط المؤجلة</h3>
            <div class="table-wrapper">
                <table>
                    <thead><tr><th>#</th><th>القسط الأصلي</th><th>تم تأجيله إلى</th><th>السبب</th><th>تاريخ التأجيل</th><th>إجراء</th></tr></thead>
                    <tbody>
                        <?php $i = 1; foreach ($postponements as $p): ?>
                            <tr>
                                <td><?= $i++ ?></td>
                                <td><?= getMonthNameArabic($p['original_month']) . ' ' . $p['original_year'] ?></td>
                                <td><?= getMonthNameArabic((int)substr($p['new_month'], 5, 2)) . ' ' . substr($p['new_month'], 0, 4) ?></td>
                                <td><?= htmlspecialchars($p['reason'] ?: 'بدون سبب') ?></td>
                                <td><?= date('d/m/Y H:i', strtotime($p['postponed_at'])) ?></td>
                                <td>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                        <input type="hidden" name="action" value="undo_postpone">
                                        <input type="hidden" name="postponement_id" value="<?= $p['id'] ?>">
                                        <button type="submit" class="btn btn-undo" onclick="return confirm('هل أنت متأكد من إلغاء تأجيل هذا القسط؟')">🗑️ إلغاء التأجيل</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <div class="card">
        <h3 class="card-title">📋 الأقساط المستقبلية (اختر قسطاً لتأجيله)</h3>
        <?php if (empty($futureInstallments)): ?>
            <div class="empty-state">🎯 لا توجد أقساط مستقبلية قابلة للتأجيل.</div>
        <?php else: ?>
            <div class="table-wrapper">
                <table>
                    <thead><tr><th>#</th><th>الشهر</th><th>السنة</th><th>المبلغ (دج)</th><th>الحالة</th><th>الإجراء</th></tr></thead>
                    <tbody>
                        <?php $i = 1; foreach ($futureInstallments as $inst): ?>
                            <tr>
                                <td><?= $i++ ?></td>
                                <td><?= getMonthNameArabic($inst['month']) ?></td>
                                <td><?= $inst['year'] ?></td>
                                <td><?= number_format($inst['amount'], 2) ?></td>
                                <td><span class="badge badge-active">✅ قادم</span></td>
                                <td>
                                    <button class="btn btn-postpone" onclick="openPostponeModal(<?= $inst['id'] ?>, '<?= getMonthNameArabic($inst['month']) . ' ' . $inst['year'] ?>')">
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

    <div style="margin-top: 20px; text-align: center;">
        <a href="list.php" class="btn-back">🔙 العودة إلى القائمة</a>
    </div>
</div>

<!-- مودال تأجيل -->
<div id="postponeModal" class="modal-overlay">
    <div class="modal-box">
        <h3>📅 تأجيل القسط</h3>
        <p><strong>القسط:</strong> <span id="installmentInfo"></span></p>
        <form method="POST" id="postponeForm">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <input type="hidden" name="action" value="postpone">
            <input type="hidden" name="installment_id" id="installmentIdInput">
            <div style="margin: 15px 0;">
                <label for="reason" style="display:block; font-weight:600; margin-bottom:5px;">سبب التأجيل (اختياري):</label>
                <textarea name="reason" id="reason" placeholder="اذكر سبب التأجيل ..."></textarea>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-cancel" onclick="closeModal()">إلغاء</button>
                <button type="submit" class="btn-confirm">تأكيد التأجيل</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openPostponeModal(installmentId, info) {
        document.getElementById('installmentIdInput').value = installmentId;
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