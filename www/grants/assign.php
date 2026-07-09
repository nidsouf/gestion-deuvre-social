<?php
/**
 * grants/assign.php - توزيع منحة على موظف (مع دعم النسبة المئوية)
 * تم التعديل لحساب المبلغ الصحيح وتسجيله في الميزانية
 */
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';

$employees = $pdo->query("SELECT id, name FROM employees ORDER BY name")->fetchAll();
$grants = $pdo->query("SELECT id, name, amount, calculation_type, percentage_value, max_amount FROM grants ORDER BY name")->fetchAll();

$error = '';
$success = '';

// ============================================================
// معالجة POST
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRFToken();
    
    if (isRateLimited('grant_assign', 20, 3600)) {
        $error = '⚠️ لقد تجاوزت عدد المحاولات المسموحة.';
    } else {
        $employee_id = (int)$_POST['employee_id'];
        $grant_id = (int)$_POST['grant_id'];
        $grant_date = sanitizeInput($_POST['grant_date'] ?? '');
        $notes = sanitizeInput($_POST['notes'] ?? '');
        $invoice_amount = (float)($_POST['invoice_amount'] ?? 0);
        
        // جلب بيانات المنحة
        $grantInfo = $pdo->prepare("SELECT * FROM grants WHERE id = ?");
        $grantInfo->execute([$grant_id]);
        $grant = $grantInfo->fetch();
        
        if (!$grant) {
            $error = '⚠️ نوع المنحة غير موجود';
        } elseif ($employee_id <= 0 || $grant_id <= 0 || empty($grant_date)) {
            $error = '⚠️ جميع الحقول المطلوبة يجب أن تكون صحيحة';
        } elseif (!validateDate($grant_date)) {
            $error = '⚠️ صيغة تاريخ المنح غير صحيحة';
        } else {
            // ========== حساب مبلغ المنحة ==========
            $grant_amount = 0;
            if ($grant['calculation_type'] == 'fixed') {
                $grant_amount = $grant['amount'];
            } else { // percentage
                if ($invoice_amount <= 0) {
                    $error = '⚠️ يجب إدخال قيمة الفاتورة للمنح النسبية';
                } else {
                    $grant_amount = ($invoice_amount * $grant['percentage_value']) / 100;
                    if ($grant['max_amount'] > 0 && $grant_amount > $grant['max_amount']) {
                        $grant_amount = $grant['max_amount'];
                    }
                }
            }
            
            if (empty($error)) {
                try {
                    $pdo->beginTransaction();
                    
                    // جلب اسم الموظف للتسجيل
                    $emp_name = $pdo->prepare("SELECT name FROM employees WHERE id = ?");
                    $emp_name->execute([$employee_id]);
                    $employee_name = $emp_name->fetchColumn();
                    
                    // ========== 1. إدراج المنحة في employee_grants ==========
                    $stmt = $pdo->prepare("
                        INSERT INTO employee_grants 
                            (employee_id, grant_id, grant_date, notes, amount, invoice_amount) 
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$employee_id, $grant_id, $grant_date, $notes, $grant_amount, $invoice_amount]);
                    $grant_id_inserted = $pdo->lastInsertId();
                    
                    // ========== 2. تسجيل العملية في budget_transactions ==========
                    // المبلغ الفعلي للمنحة (وليس القيمة الثابتة من جدول grants)
                    $stmtTrans = $pdo->prepare("
                        INSERT INTO budget_transactions 
                            (reference_id, type, amount, description, is_deduct, transaction_date) 
                        VALUES (?, 'grant', ?, ?, 1, datetime('now'))
                    ");
                    $stmtTrans->execute([
                        $grant_id_inserted,
                        $grant_amount,
                        "منحة: " . $grant['name'] . " - الموظف: " . $employee_name
                    ]);
                    
                    // ========== 3. تحديث social_budget.remaining_budget ==========
                    // خصم مبلغ المنحة من الميزانية المتبقية
                    $stmtBudget = $pdo->prepare("
                        UPDATE social_budget 
                        SET remaining_budget = remaining_budget - ?
                        WHERE id = (SELECT id FROM social_budget ORDER BY year DESC LIMIT 1)
                    ");
                    $stmtBudget->execute([$grant_amount]);
                    
                    // ========== تسجيل التدقيق والإشعارات ==========
                    audit('GRANT_ASSIGNED', "Grant '{$grant['name']}' assigned to $employee_name (amount: $grant_amount)");
                    addNotification('منحة جديدة', "تم توزيع منحة {$grant['name']} بقيمة " . number_format($grant_amount, 2) . " دج للموظف $employee_name", null, 'success');
                    
                    $pdo->commit();
                    
                    $_SESSION['toast'] = [
                        'message' => '✅ تم توزيع المنحة بنجاح (المبلغ: ' . number_format($grant_amount, 2) . ' دج)',
                        'type' => 'success',
                        'duration' => 3000
                    ];
                    header("Location: employee_list.php");
                    exit;
                    
                } catch (Exception $e) {
                    $pdo->rollBack();
                    error_log("Grant assign error: " . $e->getMessage());
                    $error = '❌ حدث خطأ أثناء توزيع المنحة: ' . $e->getMessage();
                }
            }
        }
    }
}

$csrf_token = generateCSRFToken();
include '../includes/header.php';
?>

<style>
    .form-container { max-width: 600px; margin: 30px auto; background: white; padding: 25px; border-radius: 20px; box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
    .form-group { margin-bottom: 20px; }
    .form-group label { display: block; font-weight: bold; margin-bottom: 8px; }
    .form-group select, .form-group input, .form-group textarea { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 12px; }
    .form-group .help-text { font-size: 12px; color: #888; margin-top: 5px; }
    .btn-save { width: 100%; background: #28a745; color: white; padding: 12px; border: none; border-radius: 30px; font-weight: bold; cursor: pointer; }
    .btn-save:hover { background: #218838; }
    .btn-back { display: inline-block; margin-bottom: 20px; background: #6c757d; color: white; padding: 8px 16px; border-radius: 30px; text-decoration: none; }
    .btn-back:hover { background: #5a6268; }
    .error-message { background: #f8d7da; color: #721c24; padding: 12px; border-radius: 10px; margin-bottom: 20px; }
    .success-message { background: #d4edda; color: #155724; padding: 12px; border-radius: 10px; margin-bottom: 20px; }
    .grant-info { background: #e3f2fd; padding: 12px; border-radius: 10px; margin: 10px 0; }
    .calculated-amount { font-size: 18px; font-weight: bold; color: #2a5298; }
</style>

<div class="form-container">
    <a href="list.php" class="btn-back">🔙 العودة إلى قائمة المنح</a>
    <h2>🎁 منح موظف</h2>
    
    <?php if ($error): ?>
        <div class="error-message"><?= escape($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="success-message"><?= escape($success) ?></div>
    <?php endif; ?>
    
    <form method="POST" id="assignForm" novalidate>
        <input type="hidden" name="csrf_token" value="<?= escape($csrf_token) ?>">
        
        <div class="form-group">
            <label>👤 الموظف</label>
            <select name="employee_id" required>
                <option value="">اختر الموظف</option>
                <?php foreach ($employees as $emp): ?>
                    <option value="<?= $emp['id'] ?>"><?= escape($emp['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="form-group">
            <label>🎁 نوع المنحة</label>
            <select name="grant_id" id="grantSelect" required onchange="updateGrantInfo()">
                <option value="">اختر المنحة</option>
                <?php foreach ($grants as $g): ?>
                    <option value="<?= $g['id'] ?>" 
                            data-type="<?= $g['calculation_type'] ?>"
                            data-percentage="<?= $g['percentage_value'] ?>"
                            data-max="<?= $g['max_amount'] ?>"
                            data-amount="<?= $g['amount'] ?>">
                        <?= escape($g['name']) ?> 
                        <?php if ($g['calculation_type'] == 'fixed'): ?>
                            (ثابت: <?= number_format($g['amount'], 2) ?> دج)
                        <?php else: ?>
                            (نسبة: <?= $g['percentage_value'] ?>%، حد أقصى: <?= number_format($g['max_amount'], 2) ?> دج)
                        <?php endif; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <!-- حقل الفاتورة (يظهر فقط للمنح النسبية) -->
        <div id="invoiceField" style="display:none;">
            <div class="form-group">
                <label>💰 قيمة الفاتورة (دج)</label>
                <input type="number" step="0.01" name="invoice_amount" id="invoiceAmount" value="0" min="0">
                <div class="help-text">سيتم حساب المنحة كنسبة مئوية من هذه القيمة</div>
            </div>
        </div>
        
        <!-- عرض حساب المنحة -->
        <div id="grantPreview" class="grant-info" style="display:none;">
            <p><strong>📊 حساب المنحة:</strong></p>
            <p>النسبة: <span id="previewPercentage">0</span>%</p>
            <p>الحد الأقصى: <span id="previewMax">0</span> دج</p>
            <p>المبلغ المحسوب: <span id="previewAmount" class="calculated-amount">0.00</span> دج</p>
        </div>
        
        <div class="form-group">
            <label>📅 تاريخ المنح</label>
            <input type="date" name="grant_date" value="<?= date('Y-m-d') ?>" required>
        </div>
        
        <div class="form-group">
            <label>📝 ملاحظات (اختياري)</label>
            <textarea name="notes" rows="3"></textarea>
        </div>
        
        <button type="submit" class="btn-save">💾 توزيع المنحة</button>
    </form>
</div>

<script>
    function updateGrantInfo() {
        const select = document.getElementById('grantSelect');
        const selected = select.options[select.selectedIndex];
        const type = selected.getAttribute('data-type');
        const percentage = parseFloat(selected.getAttribute('data-percentage')) || 0;
        const maxAmount = parseFloat(selected.getAttribute('data-max')) || 0;
        const fixedAmount = parseFloat(selected.getAttribute('data-amount')) || 0;
        
        const invoiceField = document.getElementById('invoiceField');
        const previewDiv = document.getElementById('grantPreview');
        const previewPercentage = document.getElementById('previewPercentage');
        const previewMax = document.getElementById('previewMax');
        const previewAmount = document.getElementById('previewAmount');
        const invoiceInput = document.getElementById('invoiceAmount');
        
        if (type === 'percentage') {
            invoiceField.style.display = 'block';
            previewDiv.style.display = 'block';
            previewPercentage.textContent = percentage;
            previewMax.textContent = maxAmount > 0 ? maxAmount.toFixed(2) : 'بدون حد';
            
            // حساب المبلغ عند تغيير قيمة الفاتورة
            invoiceInput.addEventListener('input', function() {
                const invoice = parseFloat(this.value) || 0;
                let amount = (invoice * percentage) / 100;
                if (maxAmount > 0 && amount > maxAmount) {
                    amount = maxAmount;
                }
                previewAmount.textContent = amount.toFixed(2);
            });
            // تشغيل الحساب الأولي
            invoiceInput.dispatchEvent(new Event('input'));
        } else {
            invoiceField.style.display = 'none';
            previewDiv.style.display = 'block';
            previewPercentage.textContent = '—';
            previewMax.textContent = '—';
            previewAmount.textContent = fixedAmount.toFixed(2);
        }
    }
    
    // استدعاء أولي
    document.addEventListener('DOMContentLoaded', updateGrantInfo);
</script>

<?php include '../includes/footer.php'; ?>