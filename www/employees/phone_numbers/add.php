<?php
/**
 * employees/phone_numbers/add.php - إضافة رقم هاتف جديد
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

$employees = $pdo->query("SELECT id, name FROM employees ORDER BY name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRFToken();

    $employeeId = (int)$_POST['employee_id'];
    $phoneNumber = trim($_POST['phone_number']);
    $monthlyAmount = (float)$_POST['monthly_amount'];

    $errors = [];
    if (!$employeeId) $errors[] = 'الموظف مطلوب';
    if (empty($phoneNumber)) $errors[] = 'رقم الهاتف مطلوب';
    if ($monthlyAmount < 0) $errors[] = 'المبلغ يجب أن يكون موجباً';

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // إضافة الرقم
            $result = addEmployeePhone($pdo, $employeeId, $phoneNumber, $monthlyAmount);
            if ($result) {
                // مزامنة اقتطاع الهاتف للموظف
                syncEmployeePhoneDeduction($pdo, $employeeId);
                
                $pdo->commit();
                setToast('✅ تم إضافة الرقم وتحديث الاقتطاع بنجاح', 'success');
                header('Location: index.php');
                exit;
            } else {
                throw new Exception('فشلت إضافة الرقم');
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            setToast('❌ حدث خطأ أثناء الإضافة: ' . $e->getMessage(), 'error');
        }
    } else {
        setToast('⚠️ ' . implode(' - ', $errors), 'warning');
    }
}

$pageTitle = 'إضافة رقم هاتف';
require_once '../../includes/header.php';
?>

<link rel="stylesheet" href="/assets/css/header.css">
<link rel="stylesheet" href="../../assets/css/employee_phones.css">

<div class="container mt-4">
    <div class="card">
        <div class="card-header bg-primary text-white">
            <h4 class="mb-0">➕ إضافة رقم هاتف جديد</h4>
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
                                    <option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group mb-3">
                            <label>رقم الهاتف</label>
                            <input type="text" name="phone_number" class="form-control" placeholder="مثال: 0782268426" required>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group mb-3">
                            <label>المبلغ الشهري (دج)</label>
                            <input type="number" step="0.01" name="monthly_amount" class="form-control" value="0" required>
                            <small class="text-muted">المبلغ الذي سيُقتطع شهرياً لهذا الرقم</small>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary">💾 حفظ</button>
                <a href="index.php" class="btn btn-secondary">إلغاء</a>
            </form>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>