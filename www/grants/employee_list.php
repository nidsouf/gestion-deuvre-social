<?php
/**
 * grants/employee_list.php - قائمة المنح الموزعة على الموظفين
 * مع إصلاح المنح الثابتة التي current_amount = 0
 */
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';

// ============================================================
// معالجة طلبات POST
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRFToken();
    
    // تحديث يدوي
    if (isset($_POST['update_grant'])) {
    $grant_id = (int)$_POST['grant_id'];
    $new_amount = (float)$_POST['new_amount'];
    
    if ($new_amount <= 0) {
        // إذا كانت القيمة صفرية، نأخذ stored_amount الحالية
        $stmt = $pdo->prepare("SELECT amount FROM employee_grants WHERE id = ?");
        $stmt->execute([$grant_id]);
        $current_stored = $stmt->fetchColumn();
        if ($current_stored > 0) {
            $new_amount = $current_stored;
        } else {
            $_SESSION['toast'] = ['message' => '⚠️ لا يمكن تحديث المنحة بقيمة صفرية', 'type' => 'warning', 'duration' => 3000];
            header("Location: employee_list.php");
            exit;
        }
    }
    
    try {
        $pdo->beginTransaction();
        
        // 1. تحديث المنحة الموزعة
        $stmt = $pdo->prepare("UPDATE employee_grants SET amount = ? WHERE id = ?");
        $stmt->execute([$new_amount, $grant_id]);
        
        // 2. تحديث المنحة الأصلية (grants) بنفس القيمة (إذا كانت منحة ثابتة)
        //    نتحقق من أن المنحة من نوع fixed و current_amount مختلف
        $checkGrant = $pdo->prepare("
            SELECT g.id, g.calculation_type 
            FROM employee_grants eg
            JOIN grants g ON eg.grant_id = g.id
            WHERE eg.id = ?
        ");
        $checkGrant->execute([$grant_id]);
        $grantInfo = $checkGrant->fetch();
        if ($grantInfo && $grantInfo['calculation_type'] == 'fixed') {
            $updateGrant = $pdo->prepare("UPDATE grants SET amount = ? WHERE id = ?");
            $updateGrant->execute([$new_amount, $grantInfo['id']]);
        }
        
        $pdo->commit();
        $_SESSION['toast'] = ['message' => '✅ تم تحديث المنحة بنجاح', 'type' => 'success', 'duration' => 3000];
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['toast'] = ['message' => '❌ خطأ: ' . $e->getMessage(), 'type' => 'error', 'duration' => 5000];
    }
    header("Location: employee_list.php");
    exit;
}
    
    // إعادة حساب
    if (isset($_POST['recalc_grant'])) {
        $grant_id = (int)$_POST['grant_id'];
        try {
            $stmt = $pdo->prepare("
                SELECT eg.invoice_amount, g.percentage_value, g.max_amount
                FROM employee_grants eg
                JOIN grants g ON eg.grant_id = g.id
                WHERE eg.id = ?
            ");
            $stmt->execute([$grant_id]);
            $data = $stmt->fetch();
            if ($data && $data['invoice_amount'] > 0) {
                $new_amount = ($data['invoice_amount'] * $data['percentage_value'] / 100);
                if ($data['max_amount'] > 0 && $new_amount > $data['max_amount']) {
                    $new_amount = $data['max_amount'];
                }
                $update = $pdo->prepare("UPDATE employee_grants SET amount = ? WHERE id = ?");
                $update->execute([$new_amount, $grant_id]);
                $_SESSION['toast'] = ['message' => '✅ تم إعادة حساب المنحة: ' . number_format($new_amount, 2) . ' دج', 'type' => 'success', 'duration' => 3000];
            } else {
                $_SESSION['toast'] = ['message' => '⚠️ لا توجد قيمة فاتورة لحساب المنحة', 'type' => 'warning', 'duration' => 3000];
            }
        } catch (Exception $e) {
            $_SESSION['toast'] = ['message' => '❌ خطأ: ' . $e->getMessage(), 'type' => 'error', 'duration' => 5000];
        }
        header("Location: employee_list.php");
        exit;
    }
    
    // حذف
    if (isset($_POST['delete_grant'])) {
        $grant_id = (int)$_POST['grant_id'];
        try {
            $stmt = $pdo->prepare("DELETE FROM employee_grants WHERE id = ?");
            $stmt->execute([$grant_id]);
            $_SESSION['toast'] = ['message' => '✅ تم حذف المنحة بنجاح', 'type' => 'success', 'duration' => 3000];
        } catch (Exception $e) {
            $_SESSION['toast'] = ['message' => '❌ خطأ أثناء الحذف: ' . $e->getMessage(), 'type' => 'error', 'duration' => 5000];
        }
        header("Location: employee_list.php");
        exit;
    }
}

// ============================================================
// جلب البيانات
// ============================================================
include '../includes/header.php';

$grant_filter = isset($_GET['grant_id']) ? (int)$_GET['grant_id'] : 0;
$search = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';

$grantsList = $pdo->query("SELECT id, name FROM grants ORDER BY name")->fetchAll();

$sql = "
    SELECT 
        eg.id, 
        eg.grant_date, 
        eg.notes as grant_notes,
        eg.amount as stored_amount,
        eg.invoice_amount,
        e.name as employee_name, 
        e.category,
        g.name as grant_name,
        g.amount as current_amount,
        g.calculation_type,
        g.percentage_value,
        g.max_amount
    FROM employee_grants eg
    JOIN employees e ON eg.employee_id = e.id
    JOIN grants g ON eg.grant_id = g.id
    WHERE 1=1
";

$params = [];
if ($grant_filter > 0) {
    $sql .= " AND eg.grant_id = :grant_id";
    $params[':grant_id'] = $grant_filter;
}
if ($search) {
    $sql .= " AND e.name LIKE :search";
    $params[':search'] = "%$search%";
}
$sql .= " ORDER BY eg.grant_date DESC, e.name ASC";

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$grants = $stmt->fetchAll();

$totalAmount = 0;
foreach ($grants as $g) {
    $displayAmount = ($g['stored_amount'] > 0) ? $g['stored_amount'] : $g['current_amount'];
    $totalAmount += $displayAmount;
}
$totalCount = count($grants);
$csrf_token = generateCSRFToken();

// عرض رسائل Toast
if (isset($_SESSION['toast'])) {
    echo '<div class="toast-container">';
    echo '<div class="toast-item toast-' . $_SESSION['toast']['type'] . '">' . $_SESSION['toast']['message'] . '</div>';
    echo '</div>';
    echo '<script>
        setTimeout(function() {
            document.querySelector(".toast-container").style.display = "none";
        }, ' . ($_SESSION['toast']['duration'] ?? 4000) . ');
    </script>';
    unset($_SESSION['toast']);
}
?>

<style>
    * { box-sizing: border-box; }
    body { font-family: 'Segoe UI', Tahoma, Arial, sans-serif; background: #f0f4f8; }
    .container { max-width: 1400px; margin: 0 auto; padding: 20px; }
    .stats-card { background: #e3f2fd; padding: 15px; border-radius: 12px; margin-bottom: 20px; text-align: center; }
    .stats-number { font-size: 24px; font-weight: bold; color: #2a5298; }
    .filters { background: #f0f2f5; padding: 15px; border-radius: 10px; margin-bottom: 20px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
    .filter-group { display: flex; align-items: center; gap: 5px; background: white; padding: 5px 10px; border-radius: 8px; border: 1px solid #ddd; }
    .data-table { width: 100%; border-collapse: collapse; background: white; font-size: 14px; }
    .data-table th, .data-table td { border: 1px solid #ddd; padding: 8px; text-align: center; }
    .data-table th { background: #2a5298; color: white; }
    .total-row { background: #ffd700; font-weight: bold; }
    .btn-sm { background: #2a5298; color: white; padding: 5px 12px; border-radius: 20px; text-decoration: none; font-size: 12px; }
    .btn-reset { background: #6c757d; }
    .btn-back { background: #17a2b8; margin-bottom: 15px; display: inline-block; }
    .btn-delete { background: #dc3545; color: white; padding: 4px 12px; border-radius: 20px; border: none; cursor: pointer; font-size: 12px; }
    .btn-delete:hover { background: #c82333; }
    .btn-update { background: #ff9800; color: white; padding: 4px 12px; border-radius: 20px; border: none; cursor: pointer; font-size: 12px; }
    .btn-update:hover { background: #e68900; }
    .btn-recalc { background: #17a2b8; color: white; padding: 4px 12px; border-radius: 20px; border: none; cursor: pointer; font-size: 12px; }
    .btn-recalc:hover { background: #138496; }
    .btn-set-value { background: #6c757d; color: white; padding: 4px 12px; border-radius: 20px; border: none; cursor: pointer; font-size: 12px; }
    .btn-set-value:hover { background: #5a6268; }
    .badge-percentage { background: #ff9800; color: white; padding: 2px 8px; border-radius: 10px; font-size: 11px; }
    .badge-warning { background: #ffc107; color: #333; padding: 2px 8px; border-radius: 10px; font-size: 11px; }
    .badge-info { background: #17a2b8; color: white; padding: 2px 8px; border-radius: 10px; font-size: 11px; }
    .outdated { background: #fff3cd; }
    .outdated td { background: #fff3cd; }
    
    .toast-container { position: fixed; top: 20px; right: 20px; z-index: 99999; max-width: 400px; }
    .toast-item { padding: 15px 20px; border-radius: 10px; color: white; font-weight: bold; margin-bottom: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.2); animation: slideIn 0.5s; }
    .toast-success { background: #28a745; }
    .toast-error { background: #dc3545; }
    .toast-warning { background: #ffc107; color: #333; }
    @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
    
    .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; justify-content: center; align-items: center; }
    .modal-overlay.active { display: flex; }
    .modal-box { background: white; padding: 30px; border-radius: 20px; max-width: 450px; width: 90%; box-shadow: 0 10px 40px rgba(0,0,0,0.3); text-align: center; }
    .modal-box h3 { margin-top: 0; color: #dc3545; }
    .modal-box .actions { display: flex; gap: 10px; justify-content: center; margin-top: 20px; }
    .btn-confirm { background: #dc3545; color: white; padding: 10px 25px; border: none; border-radius: 30px; cursor: pointer; }
    .btn-cancel-modal { background: #6c757d; color: white; padding: 10px 25px; border: none; border-radius: 30px; cursor: pointer; }
</style>

<div class="container">
    <a href="list.php" class="btn-sm btn-back">🔙 العودة إلى أنواع المنح</a>
    <h2>🎁 منح الموظفين (<?= $totalCount ?> منحة) - إجمالي المبلغ: <?= number_format($totalAmount, 2) ?> دج</h2>
    
    <div class="filters">
        <form method="GET" style="display: flex; gap: 10px; flex-wrap: wrap; width: 100%;">
            <div class="filter-group">
                <label>🏷️ نوع المنحة:</label>
                <select name="grant_id">
                    <option value="0">جميع الأنواع</option>
                    <?php foreach ($grantsList as $g): ?>
                        <option value="<?= $g['id'] ?>" <?= ($grant_filter == $g['id']) ? 'selected' : '' ?>><?= escape($g['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>🔍 بحث:</label>
                <input type="text" name="search" value="<?= escape($search) ?>" placeholder="اسم الموظف...">
            </div>
            <button type="submit" class="btn-sm">بحث</button>
            <?php if ($grant_filter > 0 || $search): ?>
                <a href="employee_list.php" class="btn-sm btn-reset">إلغاء الفلتر</a>
            <?php endif; ?>
        </form>
    </div>
    
    <div style="overflow-x: auto;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>الموظف</th>
                    <th>نوع المنحة</th>
                    <th>المبلغ (دج)</th>
                    <th>قيمة الفاتورة</th>
                    <th>تاريخ المنح</th>
                    <th>السبب</th>
                    <th>الحالة</th>
                    <th>الإجراءات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($grants)): ?>
                    <tr><td colspan="9" style="text-align:center;">لا توجد منح مسجلة</td></tr>
                <?php else: ?>
                    <?php $i = 1; foreach ($grants as $g): 
                        $displayAmount = ($g['stored_amount'] > 0) ? $g['stored_amount'] : $g['current_amount'];
                        $isPercentage = ($g['calculation_type'] == 'percentage');
                        $isFixed = !$isPercentage;
                        
                        // ============================================================
                        // تحديد حالة المنحة والإجراءات المناسبة
                        // ============================================================
                        if ($isPercentage) {
                            // المنح النسبية
                            $isOutdated = false;
                            $statusText = ($g['current_amount'] > 0) ? '✅ محدث' : '🧮 محسوبة من الفاتورة';
                            $statusClass = ($g['current_amount'] > 0) ? 'success' : 'info';
                            $rowClass = '';
                            $showUpdate = false;
                            $showUndefinedMsg = false;
                            $updateValue = 0;
                            $showRecalc = ($g['invoice_amount'] > 0);
                        } else {
                            // المنح الثابتة
                            $isOutdated = ($g['stored_amount'] > 0 && abs($g['stored_amount'] - $g['current_amount']) > 0.01);
                            $rowClass = $isOutdated ? 'outdated' : '';
                            $showRecalc = false;
                            
                            if ($isOutdated) {
                                if ($g['current_amount'] > 0) {
                                    // الحالة العادية: قيمة المنحة محددة، نعرض زر تحديث
                                    $showUpdate = true;
                                    $showUndefinedMsg = false;
                                    $updateValue = $g['current_amount'];
                                    $statusText = '⚠️ غير محدث';
                                    $statusClass = 'warning';
                                } else {
                                    // الحالة الخاصة: current_amount = 0، نعرض زر تحديث بقيمة stored_amount
                                    $showUpdate = true;
                                    $showUndefinedMsg = false;
                                    $updateValue = $g['stored_amount']; // نستخدم القيمة المخزنة
                                    $statusText = '⚠️ غير محدث';
                                    $statusClass = 'warning';
                                }
                            } else {
                                $showUpdate = false;
                                $showUndefinedMsg = false;
                                $statusText = '✅ محدث';
                                $statusClass = 'success';
                            }
                        }
                    ?>
                        <tr class="<?= $rowClass ?>">
                            <td><?= $i++ ?></td>
                            <td><?= escape($g['employee_name']) ?><br><small>(<?= $g['category'] == 'Permanent' ? 'دائم' : 'متعاقد' ?>)</small></td>
                            <td><?= escape($g['grant_name']) ?>
                                <?php if ($isPercentage): ?>
                                    <span class="badge-percentage">نسبة</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= number_format($displayAmount, 2) ?> دج
                                <?php if ($isOutdated): ?>
                                    <br><small class="badge-warning">قيمة قديمة: <?= number_format($g['stored_amount'], 2) ?> دج</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($isPercentage && $g['invoice_amount'] > 0): ?>
                                    <?= number_format($g['invoice_amount'], 2) ?> دج
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td><?= date('d/m/Y', strtotime($g['grant_date'])) ?></td>
                            <td><?= escape($g['grant_notes'] ?? '-') ?></td>
                            <td>
                                <?php if ($statusClass == 'warning'): ?>
                                    <span class="badge-warning"><?= $statusText ?></span>
                                <?php elseif ($statusClass == 'info'): ?>
                                    <span class="badge-info"><?= $statusText ?></span>
                                <?php else: ?>
                                    <span style="color:green;"><?= $statusText ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($showUpdate): ?>
                                    <form method="POST" style="display:inline;" novalidate>
                                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                        <input type="hidden" name="grant_id" value="<?= $g['id'] ?>">
                                        <input type="hidden" name="new_amount" value="<?= $updateValue ?>">
                                        <button type="submit" name="update_grant" class="btn-update">
                                            <?= ($g['current_amount'] > 0) ? '🔄 تحديث' : '🔧 تعيين القيمة' ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                                
                                <?php if ($showRecalc): ?>
                                    <form method="POST" style="display:inline;" novalidate>
                                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                        <input type="hidden" name="grant_id" value="<?= $g['id'] ?>">
                                        <button type="submit" name="recalc_grant" class="btn-recalc">🧮 إعادة حساب</button>
                                    </form>
                                <?php endif; ?>
                                
                                <button class="btn-delete" onclick="openDeleteModal(<?= $g['id'] ?>, '<?= addslashes($g['employee_name']) ?>')">🗑️ حذف</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr class="total-row">
                    <td colspan="3"><strong>الإجمالي</strong></td>
                    <td><strong><?= number_format($totalAmount, 2) ?> دج</strong></td>
                    <td colspan="5"></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<!-- مودال تأكيد الحذف -->
<div id="deleteModal" class="modal-overlay">
    <div class="modal-box">
        <h3>⚠️ تأكيد الحذف</h3>
        <p>هل أنت متأكد من حذف منحة <strong id="deleteEmployeeName"></strong>؟</p>
        <form method="POST" id="deleteForm" novalidate>
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
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
    function openDeleteModal(grantId, employeeName) {
        document.getElementById('deleteGrantId').value = grantId;
        document.getElementById('deleteEmployeeName').textContent = employeeName;
        document.getElementById('deleteModal').classList.add('active');
    }
    function closeDeleteModal() {
        document.getElementById('deleteModal').classList.remove('active');
    }
    document.getElementById('deleteModal').addEventListener('click', function(e) {
        if (e.target === this) closeDeleteModal();
    });
</script>

<?php include '../includes/footer.php'; ?>