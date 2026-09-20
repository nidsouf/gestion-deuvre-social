<?php
/**
 * employees/phone_numbers/edit.php - تعديل رقم هاتف
 */

session_start();
require_once '../../includes/auth_check.php';
require_once '../../config/database.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';
require_once '../../includes/employee_phone_helpers.php';

if (!in_array($_SESSION['role'] ?? '', ['admin', 'manager', 'committee'])) {
    setToast('⚠️ غير مصرح لك بهذه الصفحة', 'warning');
    header('Location: index.php');
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    setToast('⚠️ رقم الهاتف غير صالح', 'warning');
    header('Location: index.php');
    exit;
}

$phone = getEmployeePhoneDetails($pdo, $id);
if (!$phone) {
    setToast('⚠️ الرقم غير موجود', 'warning');
    header('Location: index.php');
    exit;
}

$employees = $pdo->query("SELECT id, name FROM employees ORDER BY name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRFToken();

    $employeeId = (int)$_POST['employee_id'];
    $phoneNumber = trim($_POST['phone_number']);
    $monthlyAmount = (float)$_POST['monthly_amount'];
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    $errors = [];
    if (!$employeeId) $errors[] = 'الموظف مطلوب';
    if (empty($phoneNumber)) $errors[] = 'رقم الهاتف مطلوب';
    if ($monthlyAmount < 0) $errors[] = 'المبلغ يجب أن يكون موجباً';

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            $result = updateEmployeePhone($pdo, $id, $employeeId, $phoneNumber, $monthlyAmount, $isActive);
            if ($result) {
                // مزامنة اقتطاع الهاتف للموظف
                syncEmployeePhoneDeduction($pdo, $employeeId);
                
                $pdo->commit();
                setToast('✅ تم تعديل الرقم وتحديث الاقتطاع بنجاح', 'success');
                header('Location: index.php');
                exit;
            } else {
                throw new Exception('فشل التعديل');
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            setToast('❌ حدث خطأ أثناء التعديل: ' . $e->getMessage(), 'error');
        }
    } else {
        setToast('⚠️ ' . implode(' - ', $errors), 'warning');
    }
}

$pageTitle = 'تعديل رقم هاتف';
require_once '../../includes/header.php';
?>

<link rel="stylesheet" href="/assets/css/header.css">
<link rel="stylesheet" href="../../assets/css/employee_phones.css">

<div class="container mt-4">
    <div class="card">
        <div class="card-header bg-warning text-dark">
            <h4 class="mb-0">✏️ تعديل رقم هاتف</h4>
        </div>
        <div class="card-body">
            <form method="POST">
                <?= csrfField() ?>

                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group mb-3">
                            <label>الموظف</label>
                            <select name="employee_id" class="form-control" required>
                                <option value="">اختر الموظف</option>
                                <?php foreach ($employees as $emp): ?>
                                    <option value="<?= $emp['id'] ?>" <?= $emp['id'] == $phone['employee_id'] ? 'selected' : '' ?>><?= htmlspecialchars($emp['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group mb-3">
                            <label>رقم الهاتف</label>
                            <input type="text" name="phone_number" class="form-control" value="<?= htmlspecialchars($phone['phone_number']) ?>" required>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group mb-3">
                            <label>المبلغ الشهري (دج)</label>
                            <input type="number" step="0.01" name="monthly_amount" class="form-control" value="<?= $phone['monthly_amount'] ?>" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group mb-3">
                            <label>الحالة</label>
                            <div class="form-check mt-2">
                                <input type="checkbox" name="is_active" class="form-check-input" id="isActive" <?= $phone['is_active'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="isActive">نشط</label>
                            </div>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary">💾 حفظ التعديلات</button>
                <a href="index.php" class="btn btn-secondary">إلغاء</a>
            </form>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>