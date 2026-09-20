<?php
/**
 * requests/add.php - تقديم طلب جديد
 */
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';

$employees = $pdo->query("SELECT id, name FROM employees ORDER BY name")->fetchAll();
$grants = $pdo->query("SELECT id, name FROM grants ORDER BY name")->fetchAll();

$pageTitle = 'تقديم طلب جديد';
include '../includes/header.php';
?>
<link rel="stylesheet" href="../assets/css/requests.css">

<div class="container mt-4">
    <div class="card">
        <div class="card-header bg-primary text-white">
            <h4 class="mb-0">📝 تقديم طلب جديد</h4>
        </div>
        <div class="card-body">
            <form method="POST" action="save_request.php">
                <?= csrfField() ?>

                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group mb-3">
                            <label>الموظف</label>
                            <select name="employee_id" class="form-control" required>
                                <option value="">اختر الموظف</option>
                                <?php foreach ($employees as $emp): ?>
                                    <option value="<?= $emp['id'] ?>" <?= ($_SESSION['employee_id'] ?? 0) == $emp['id'] ? 'selected' : '' ?>><?= htmlspecialchars($emp['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group mb-3">
                            <label>نوع الطلب</label>
                            <select name="request_type" class="form-control" id="requestType" required onchange="toggleFields()">
                                <option value="">-- اختر --</option>
                                <option value="loan">سلفة</option>
                                <option value="grant">منحة</option>
                                <option value="deduction">اقتطاع</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="form-group mb-3" id="grantTypeDiv" style="display:none;">
                    <label>نوع المنحة</label>
                    <select name="grant_id" class="form-control">
                        <option value="">-- اختر --</option>
                        <?php foreach ($grants as $g): ?>
                            <option value="<?= $g['id'] ?>"><?= htmlspecialchars($g['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group mb-3">
                    <label>عنوان الطلب</label>
                    <input type="text" name="title" class="form-control" placeholder="مثال: طلب سلفة لتجديد الأثاث" required>
                </div>

                <div class="form-group mb-3">
                    <label>وصف الطلب</label>
                    <textarea name="description" class="form-control" rows="4" placeholder="سبب الطلب بالتفصيل"></textarea>
                </div>

                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group mb-3">
                            <label>المبلغ المطلوب (دج)</label>
                            <input type="number" step="0.01" name="requested_amount" class="form-control" required>
                        </div>
                    </div>
                    <div class="col-md-4" id="monthsField">
                        <div class="form-group mb-3">
                            <label>عدد الأشهر</label>
                            <input type="number" name="requested_months" class="form-control" value="1" min="1">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group mb-3">
                            <label>تاريخ الطلب</label>
                            <input type="date" name="request_date" class="form-control" value="<?= date('Y-m-d') ?>">
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary">💾 تقديم الطلب</button>
                <a href="index.php" class="btn btn-secondary">إلغاء</a>
            </form>
        </div>
    </div>
</div>

<script>
function toggleFields() {
    const type = document.getElementById('requestType').value;
    document.getElementById('grantTypeDiv').style.display = (type === 'grant') ? 'block' : 'none';
    document.getElementById('monthsField').style.display = (type === 'grant') ? 'none' : 'block';
}
toggleFields();
</script>

<?php include '../includes/footer.php'; ?>