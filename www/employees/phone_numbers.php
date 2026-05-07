<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
require_once '../config/database.php';
include '../includes/header.php';

$employee_id = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;
if (!$employee_id) {
    header("Location: list.php");
    exit;
}

$stmt = $pdo->prepare("SELECT name FROM employees WHERE id = ?");
$stmt->execute([$employee_id]);
$employee = $stmt->fetch();
if (!$employee) {
    header("Location: list.php");
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM employee_phone_numbers WHERE employee_id = ? ORDER BY is_active DESC, id ASC");
$stmt->execute([$employee_id]);
$phones = $stmt->fetchAll();

$totalMonthly = array_sum(array_column($phones, 'monthly_amount'));
$activeCount = count(array_filter($phones, fn($p) => $p['is_active'] == 1));
?>

<style>
    .phones-container { direction: rtl; padding: 20px; max-width: 800px; margin: auto; }
    .stats-card { background: #e3f2fd; padding: 15px; border-radius: 12px; margin-bottom: 20px; text-align: center; }
    .stats-number { font-size: 24px; font-weight: bold; color: #2a5298; }
    .data-table { width: 100%; border-collapse: collapse; background: white; }
    .data-table th, .data-table td { border: 1px solid #ddd; padding: 10px; text-align: center; }
    .data-table th { background: #2a5298; color: white; }
    .badge-active { background: #28a745; color: white; padding: 4px 8px; border-radius: 20px; font-size: 12px; }
    .badge-inactive { background: #dc3545; color: white; padding: 4px 8px; border-radius: 20px; font-size: 12px; }
    .btn-add { background: #28a745; color: white; padding: 10px 20px; border-radius: 30px; text-decoration: none; display: inline-block; margin-bottom: 20px; }
    .btn-back { background: #6c757d; color: white; padding: 10px 20px; border-radius: 30px; text-decoration: none; display: inline-block; margin-bottom: 20px; }
    .btn-toggle { padding: 4px 12px; border-radius: 20px; text-decoration: none; font-size: 12px; }
    .btn-toggle-active { background: #ffc107; color: #000; }
    .btn-toggle-inactive { background: #28a745; color: white; }
    .btn-delete { color: #dc3545; text-decoration: none; margin-right: 10px; }
</style>

<div class="phones-container">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2>📱 أرقام هاتف (Djezzy) للموظف: <?= htmlspecialchars($employee['name']) ?></h2>
        <a href="edit.php?id=<?= $employee_id ?>" class="btn-back">🔙 رجوع للموظف</a>
    </div>

    <div class="stats-card">
        <div>💰 إجمالي الاقتطاع الشهري (Djezzy)</div>
        <div class="stats-number"><?= number_format($totalMonthly, 2) ?> دج</div>
        <div>📱 الأرقام النشطة: <?= $activeCount ?> / <?= count($phones) ?></div>
    </div>

    <a href="add_phone_number.php?employee_id=<?= $employee_id ?>" class="btn-add">➕ إضافة رقم هاتف</a>

    <table class="data-table">
        <thead>
            <tr><th>#</th><th>رقم الهاتف</th><th>القيمة الشهرية (دج)</th><th>الحالة</th><th>تاريخ الإضافة</th><th>الإجراءات</th></tr>
        </thead>
        <tbody>
            <?php if (empty($phones)): ?>
                <tr><td colspan="6" style="text-align:center;">لا توجد أرقام هاتف مسجلة</span></small></td>
            <?php else: ?>
                <?php $i=1; foreach ($phones as $p): ?>
                <tr>
                    <td><?= $i++ ?> </span></small>
                    <td><?= htmlspecialchars($p['phone_number']) ?> </span></small>
                    <td><?= number_format($p['monthly_amount'], 2) ?> دج</span></small>
                    <td><?= $p['is_active'] ? '<span class="badge-active">✅ نشط</span>' : '<span class="badge-inactive">❌ غير نشط</span>' ?> </span></small>
                    <td><?= date('d/m/Y', strtotime($p['created_at'])) ?> </span></small>
                    <td>
                        <a href="toggle_phone_status.php?id=<?= $p['id'] ?>&employee_id=<?= $employee_id ?>" class="btn-toggle <?= $p['is_active'] ? 'btn-toggle-active' : 'btn-toggle-inactive' ?>">
                            <?= $p['is_active'] ? '🔴 إلغاء' : '🟢 تفعيل' ?>
                        </a>
                        <a href="delete_phone_number.php?id=<?= $p['id'] ?>&employee_id=<?= $employee_id ?>" onclick="return confirm('هل أنت متأكد؟')" class="btn-delete">🗑️ حذف</a>
                    </span></small>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php include '../includes/footer.php'; ?>