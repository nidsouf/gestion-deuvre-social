<?php
/**
 * grants/list.php - قائمة أنواع المنح الاجتماعية
 * مع إصلاح زر الحذف باستخدام مودال تأكيد
 * وتوحيد الأنماط في ملف CSS خارجي
 */
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';
require_once '../includes/common_helpers.php';

// ============================================================
// معالجة POST
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRFToken();

    if (isset($_POST['add_grant'])) {
        $name = sanitizeInput($_POST['name'] ?? '');
        $calculation_type = $_POST['calculation_type'] ?? 'fixed';
        $amount = (float)$_POST['amount'];
        $percentage_value = (float)$_POST['percentage_value'];
        $max_amount = (float)$_POST['max_amount'];

        $errors = [];
        if (empty($name)) $errors[] = 'اسم المنحة مطلوب';
        if ($calculation_type == 'fixed') {
            if ($amount <= 0) $errors[] = 'المبلغ يجب أن يكون موجباً';
            $percentage_value = 0;
            $max_amount = 0;
        } else {
            if ($percentage_value <= 0 || $percentage_value > 100) $errors[] = 'النسبة المئوية يجب أن تكون بين 1 و 100';
            if ($max_amount < 0) $errors[] = 'الحد الأقصى يجب أن يكون موجباً';
            $amount = 0;
        }

        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO grants (name, amount, calculation_type, percentage_value, max_amount, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, datetime('now'), datetime('now'))
                ");
                $stmt->execute([$name, $amount, $calculation_type, $percentage_value, $max_amount]);
                setToast('✅ تم إضافة نوع المنحة بنجاح', 'success');
            } catch (Exception $e) {
                setToast('❌ حدث خطأ: ' . $e->getMessage(), 'error');
            }
        } else {
            setToast('⚠️ ' . implode(' - ', $errors), 'warning');
        }
        redirectTo('list.php');
        exit;
    }

    if (isset($_POST['delete_grant'])) {
        $grant_id = (int)$_POST['grant_id'];
        try {
            $check = $pdo->prepare("SELECT id FROM employee_grants WHERE grant_id = ? LIMIT 1");
            $check->execute([$grant_id]);
            if ($check->fetch()) {
                setToast('⚠️ لا يمكن حذف هذا النوع لأنه مستخدم في منح موزعة', 'warning');
            } else {
                $stmt = $pdo->prepare("DELETE FROM grants WHERE id = ?");
                $stmt->execute([$grant_id]);
                setToast('✅ تم حذف نوع المنحة بنجاح', 'success');
            }
        } catch (Exception $e) {
            setToast('❌ حدث خطأ أثناء الحذف: ' . $e->getMessage(), 'error');
        }
        redirectTo('list.php');
        exit;
    }
}

// ============================================================
// جلب البيانات
// ============================================================
$grants = $pdo->query("SELECT * FROM grants ORDER BY name")->fetchAll();
$totalGrants = count($grants);
$totalAmount = array_sum(array_column($grants, 'amount'));

$csrf_token = generateCSRFToken();

include '../includes/header.php';
?>
<link rel="stylesheet" href="../assets/css/grants.css">

<?php if (hasToast()) { $toast = getToast(); ?>
    <div class="toast-container">
        <div class="toast-item toast-<?= $toast['type'] ?>"><?= $toast['message'] ?></div>
    </div>
    <script>
        setTimeout(function() {
            document.querySelector('.toast-container').style.display = 'none';
        }, <?= $toast['duration'] ?? 4000 ?>);
    </script>
<?php } ?>

<div class="grants-container">
    <div class="grants-header">
        <h2>🎁 أنواع المنح الاجتماعية</h2>
    </div>
    
    <!-- الإحصائيات -->
    <div class="stats-grid">
        <div class="stat-card total">
            <div class="label">🎁 إجمالي أنواع المنح</div>
            <div class="number"><?= $totalGrants ?></div>
        </div>
        <div class="stat-card amount">
            <div class="label">💰 إجمالي القيم</div>
            <div class="number"><?= formatAmount($totalAmount) ?></div>
        </div>
    </div>
    
    <!-- نموذج الإضافة -->
    <div class="form-card">
        <h3>➕ إضافة نوع منحة جديد</h3>
        <form method="POST" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <div class="form-group">
                <label>اسم المنحة</label>
                <input type="text" name="name" required>
            </div>
            <div class="form-group">
                <label>نوع الحساب</label>
                <select name="calculation_type" id="calcType" onchange="toggleFields()">
                    <option value="fixed">💰 مبلغ ثابت</option>
                    <option value="percentage">📊 نسبة مئوية</option>
                </select>
            </div>
            <div id="fixedFields">
                <div class="form-group">
                    <label>المبلغ (دج)</label>
                    <input type="number" step="0.01" name="amount" value="0">
                </div>
            </div>
            <div id="percentageFields" style="display:none;">
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                    <div class="form-group">
                        <label>النسبة المئوية (%)</label>
                        <input type="number" step="0.1" name="percentage_value" value="30" min="1" max="100">
                    </div>
                    <div class="form-group">
                        <label>الحد الأقصى (دج)</label>
                        <input type="number" step="0.01" name="max_amount" value="25000">
                    </div>
                </div>
            </div>
            <button type="submit" name="add_grant" class="btn btn-primary">💾 إضافة</button>
        </form>
    </div>
    
    <!-- جدول الأنواع -->
    <div class="table-wrapper">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>اسم المنحة</th>
                    <th>نوع الحساب</th>
                    <th>القيمة</th>
                    <th>الحد الأقصى</th>
                    <th>الإجراءات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($grants)): ?>
                    <tr><td colspan="6" style="text-align:center;">لا توجد أنواع منح</td></tr>
                <?php else: ?>
                    <?php $i=1; foreach ($grants as $g): ?>
                        <tr>
                            <td><?= $i++ ?></td>
                            <td><?= htmlspecialchars($g['name']) ?></td>
                            <td>
                                <?php if ($g['calculation_type'] == 'fixed'): ?>
                                    <span class="badge-fixed">💰 ثابت</span>
                                <?php else: ?>
                                    <span class="badge-percentage">📊 نسبة مئوية</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($g['calculation_type'] == 'fixed'): ?>
                                    <?= formatAmount($g['amount']) ?>
                                <?php else: ?>
                                    <?= $g['percentage_value'] ?>%
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($g['calculation_type'] == 'percentage' && $g['max_amount'] > 0): ?>
                                    <?= formatAmount($g['max_amount']) ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td class="action-buttons">
                                <a href="edit.php?id=<?= $g['id'] ?>" class="btn-edit">✏️ تعديل</a>
                                <button class="btn-delete" onclick="openDeleteModal(<?= $g['id'] ?>, '<?= addslashes($g['name']) ?>')">🗑️ حذف</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- مودال تأكيد الحذف -->
<div id="deleteModal" class="modal-overlay">
    <div class="modal-box">
        <h3>⚠️ تأكيد الحذف</h3>
        <p>هل أنت متأكد من حذف نوع المنحة <strong id="deleteGrantName"></strong>؟</p>
        <form method="POST" id="deleteForm" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="grant_id" id="deleteGrantId">
            <input type="hidden" name="delete_grant" value="1">
            <div class="actions">
                <button type="button" class="btn-cancel-modal" onclick="closeDeleteModal()">إلغاء</button>
                <button type="submit" class="btn-confirm">🗑️ نعم، حذف</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openDeleteModal(grantId, grantName) {
        document.getElementById('deleteGrantId').value = grantId;
        document.getElementById('deleteGrantName').textContent = grantName;
        document.getElementById('deleteModal').classList.add('active');
    }

    function closeDeleteModal() {
        document.getElementById('deleteModal').classList.remove('active');
    }

    document.getElementById('deleteModal').addEventListener('click', function(e) {
        if (e.target === this) closeDeleteModal();
    });

    function toggleFields() {
        const type = document.getElementById('calcType').value;
        document.getElementById('fixedFields').style.display = type === 'fixed' ? 'block' : 'none';
        document.getElementById('percentageFields').style.display = type === 'percentage' ? 'block' : 'none';
    }
    toggleFields();
</script>

<?php include '../includes/footer.php'; ?>