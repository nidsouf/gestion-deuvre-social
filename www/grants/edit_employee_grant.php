<?php
/**
 * grants/edit_employee_grant.php - تعديل منحة موظف
 */
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';
require_once '../includes/common_helpers.php';

// ============================================================
// التحقق من الصلاحية
// ============================================================
if (!in_array($_SESSION['role'] ?? '', ['admin', 'manager', 'committee'])) {
    setToast('⚠️ غير مصرح لك بهذه الصفحة', 'warning');
    header('Location: employee_list.php');
    exit;
}

// ============================================================
// جلب معرف المنحة
// ============================================================
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    setToast('⚠️ معرف غير صالح', 'warning');
    header('Location: employee_list.php');
    exit;
}

// ============================================================
// جلب بيانات المنحة
// ============================================================
$stmt = $pdo->prepare("
    SELECT 
        eg.id,
        eg.employee_id,
        eg.grant_id,
        eg.grant_date,
        eg.amount,
        eg.invoice_amount,
        eg.notes,
        e.name as employee_name,
        e.category as employee_category,
        g.name as grant_name,
        g.calculation_type,
        g.amount as default_amount,
        g.percentage_value,
        g.max_amount
    FROM employee_grants eg
    JOIN employees e ON eg.employee_id = e.id
    JOIN grants g ON eg.grant_id = g.id
    WHERE eg.id = ?
");
$stmt->execute([$id]);
$grant = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$grant) {
    setToast('⚠️ المنحة غير موجودة', 'warning');
    header('Location: employee_list.php');
    exit;
}

// ============================================================
// معالجة POST (الحفظ)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRFToken();
    
    $amount = (float)$_POST['amount'];
    $invoice_amount = (float)($_POST['invoice_amount'] ?? 0);
    $grant_date = $_POST['grant_date'] ?? date('Y-m-d');
    $notes = trim($_POST['notes'] ?? '');
    
    $errors = [];
    if ($amount <= 0) $errors[] = 'المبلغ يجب أن يكون موجباً';
    if (empty($grant_date)) $errors[] = 'تاريخ المنح مطلوب';
    
    // التحقق من صحة التاريخ
    $d = DateTime::createFromFormat('Y-m-d', $grant_date);
    if (!$d || $d->format('Y-m-d') !== $grant_date) {
        $errors[] = 'تاريخ المنح غير صالح';
    }
    
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // تحديث المنحة
            $stmt = $pdo->prepare("
                UPDATE employee_grants 
                SET amount = ?, 
                    invoice_amount = ?, 
                    grant_date = ?, 
                    notes = ?
                WHERE id = ?
            ");
            $stmt->execute([$amount, $invoice_amount, $grant_date, $notes, $id]);
            
            // تسجيل في سجل التدقيق
            if (function_exists('audit')) {
                audit(
                    'EMPLOYEE_GRANT_UPDATED',
                    "تعديل منحة {$grant['grant_name']} للموظف {$grant['employee_name']} - القيمة الجديدة: " . number_format($amount, 2) . " دج"
                );
            }
            
            $pdo->commit();
            
            setToast('✅ تم تعديل المنحة بنجاح', 'success');
            header('Location: employee_list.php');
            exit;
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("Edit employee grant error: " . $e->getMessage());
            $errors[] = 'حدث خطأ: ' . $e->getMessage();
        }
    }
    
    if (!empty($errors)) {
        setToast('⚠️ ' . implode(' - ', $errors), 'warning');
    }
}

$csrf_token = generateCSRFToken();
$pageTitle = 'تعديل منحة موظف';
include '../includes/header.php';
?>

<style>
    .edit-container {
        max-width: 700px;
        margin: 30px auto;
        padding: 0 15px;
    }
    
    .edit-card {
        background: white;
        border-radius: 16px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        overflow: hidden;
    }
    
    .edit-header {
        background: linear-gradient(135deg, #1E5A4A, #2E7D64);
        color: white;
        padding: 20px 25px;
    }
    
    .edit-header h2 {
        margin: 0;
        font-size: 20px;
    }
    
    .edit-header p {
        margin: 5px 0 0 0;
        opacity: 0.9;
        font-size: 14px;
    }
    
    .edit-body {
        padding: 25px;
    }
    
    /* معلومات الموظف والمنحة */
    .info-box {
        background: #f0f7f4;
        border-right: 4px solid #1E5A4A;
        padding: 15px 20px;
        border-radius: 10px;
        margin-bottom: 25px;
    }
    
    .info-box .info-row {
        display: flex;
        justify-content: space-between;
        padding: 6px 0;
        border-bottom: 1px dotted #ccc;
    }
    
    .info-box .info-row:last-child {
        border-bottom: none;
    }
    
    .info-box .info-label {
        font-weight: 700;
        color: #4a5568;
        font-size: 13px;
    }
    
    .info-box .info-value {
        font-weight: 600;
        color: #1a1a2e;
    }
    
    /* نموذج */
    .form-group {
        margin-bottom: 20px;
    }
    
    .form-group label {
        display: block;
        font-weight: 700;
        margin-bottom: 8px;
        color: #2c3e50;
        font-size: 14px;
    }
    
    .form-group input,
    .form-group textarea {
        width: 100%;
        padding: 12px 15px;
        border: 2px solid #e1e8ed;
        border-radius: 10px;
        font-size: 14px;
        transition: border-color 0.2s;
        font-family: inherit;
    }
    
    .form-group input:focus,
    .form-group textarea:focus {
        outline: none;
        border-color: #1E5A4A;
        box-shadow: 0 0 0 3px rgba(30, 90, 74, 0.1);
    }
    
    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 15px;
    }
    
    .help-text {
        font-size: 12px;
        color: #6c757d;
        margin-top: 5px;
    }
    
    /* أزرار */
    .action-buttons {
        display: flex;
        gap: 10px;
        margin-top: 25px;
    }
    
    .btn-save {
        flex: 1;
        background: linear-gradient(135deg, #1E5A4A, #2E7D64);
        color: white;
        padding: 14px;
        border: none;
        border-radius: 10px;
        font-size: 15px;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.2s;
    }
    
    .btn-save:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(30, 90, 74, 0.3);
    }
    
    .btn-cancel {
        flex: 1;
        background: #6c757d;
        color: white;
        padding: 14px;
        border: none;
        border-radius: 10px;
        font-size: 15px;
        font-weight: 700;
        cursor: pointer;
        text-align: center;
        text-decoration: none;
        transition: all 0.2s;
    }
    
    .btn-cancel:hover {
        background: #5a6268;
        color: white;
    }
    
    /* تحذير */
    .warning-box {
        background: #fff3cd;
        border: 1px solid #ffc107;
        border-radius: 10px;
        padding: 12px 15px;
        margin-bottom: 20px;
        font-size: 13px;
        color: #856404;
    }
    
    @media (max-width: 600px) {
        .form-row {
            grid-template-columns: 1fr;
        }
        .action-buttons {
            flex-direction: column;
        }
    }
</style>

<div class="edit-container">
    <div class="edit-card">
        
        <!-- رأس الصفحة -->
        <div class="edit-header">
            <h2>✏️ تعديل منحة موظف</h2>
            <p>المنحة #<?= $grant['id'] ?> - <?= htmlspecialchars($grant['grant_name']) ?></p>
        </div>
        
        <div class="edit-body">
            
            <!-- معلومات الموظف والمنحة -->
            <div class="info-box">
                <div class="info-row">
                    <span class="info-label">👤 الموظف:</span>
                    <span class="info-value"><?= htmlspecialchars($grant['employee_name']) ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">📋 الفئة:</span>
                    <span class="info-value">
                        <?= $grant['employee_category'] == 'Permanent' ? '👔 دائم' : '👕 متعاقد' ?>
                    </span>
                </div>
                <div class="info-row">
                    <span class="info-label">🎁 نوع المنحة:</span>
                    <span class="info-value"><?= htmlspecialchars($grant['grant_name']) ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">💰 القيمة الافتراضية:</span>
                    <span class="info-value">
                        <?php if ($grant['calculation_type'] == 'fixed'): ?>
                            <?= number_format($grant['default_amount'], 2) ?> دج
                        <?php else: ?>
                            <?= $grant['percentage_value'] ?>% (حد أقصى: <?= number_format($grant['max_amount'], 2) ?> دج)
                        <?php endif; ?>
                    </span>
                </div>
            </div>
            
            <div class="warning-box">
                ⚠️ <strong>ملاحظة:</strong> هذا التعديل لا يؤثر على الميزانية (لأن المنحة صُرفت مسبقاً).
                لتعديل الميزانية، استخدم صفحة "الميزانية" مباشرة.
            </div>
            
            <!-- النموذج -->
            <form method="POST" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                
                <!-- المبلغ -->
                <div class="form-group">
                    <label>💰 المبلغ الفعلي (دج) <span style="color: red;">*</span></label>
                    <input type="number" 
                           step="0.01" 
                           name="amount" 
                           value="<?= htmlspecialchars($grant['amount'] ?: $grant['default_amount']) ?>" 
                           required
                           min="0.01">
                    <div class="help-text">المبلغ الفعلي المصروف للموظف</div>
                </div>
                
                <!-- مبلغ الفاتورة (اختياري) -->
                <div class="form-group">
                    <label>🧾 مبلغ الفاتورة (دج)</label>
                    <input type="number" 
                           step="0.01" 
                           name="invoice_amount" 
                           value="<?= htmlspecialchars($grant['invoice_amount'] ?? 0) ?>"
                           min="0">
                    <div class="help-text">اختياري — يُستخدم لحساب المنح النسبية</div>
                </div>
                
                <!-- تاريخ المنح + التاريخ -->
                <div class="form-row">
                    <div class="form-group">
                        <label>📅 تاريخ المنح <span style="color: red;">*</span></label>
                        <input type="date" 
                               name="grant_date" 
                               value="<?= htmlspecialchars($grant['grant_date']) ?>" 
                               required>
                    </div>
                </div>
                
                <!-- ملاحظات -->
                <div class="form-group">
                    <label>📝 ملاحظات</label>
                    <textarea name="notes" rows="3" placeholder="أي ملاحظات إضافية..."><?= htmlspecialchars($grant['notes'] ?? '') ?></textarea>
                </div>
                
                <!-- أزرار -->
                <div class="action-buttons">
                    <button type="submit" class="btn-save">💾 حفظ التعديلات</button>
                    <a href="employee_list.php" class="btn-cancel">🔙 إلغاء</a>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>