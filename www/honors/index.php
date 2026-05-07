<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
require_once '../config/database.php';
include '../includes/header.php';

// جلب المكرمين مع بيانات الموظف
$honorees = $pdo->query("
    SELECT h.*, e.name as employee_name, e.category
    FROM labor_day_honorees h
    JOIN employees e ON h.employee_id = e.id
    ORDER BY h.year DESC, e.name ASC
")->fetchAll();

// إحصائيات
$totalHonorees = count($honorees);
$currentYear = date('Y');
$currentYearHonorees = $pdo->prepare("SELECT COUNT(*) FROM labor_day_honorees WHERE year = ?");
$currentYearHonorees->execute([$currentYear]);
$currentYearCount = $currentYearHonorees->fetchColumn();
?>

<style>
    .honors-container { direction: rtl; padding: 20px; max-width: 1200px; margin: auto; }
    .stats-grid { display: flex; gap: 20px; margin-bottom: 25px; flex-wrap: wrap; }
    .stat-card { background: white; border-radius: 20px; padding: 20px; text-align: center; flex: 1; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
    .stat-number { font-size: 32px; font-weight: bold; color: #2a5298; }
    .btn-add { background: #28a745; color: white; padding: 10px 20px; border-radius: 30px; text-decoration: none; display: inline-block; margin-bottom: 20px; }
    .data-table { width: 100%; border-collapse: collapse; background: white; }
    .data-table th, .data-table td { border: 1px solid #ddd; padding: 10px; text-align: center; vertical-align: middle; }
    .data-table th { background: #2a5298; color: white; }
    .action-buttons { display: flex; gap: 8px; justify-content: center; flex-wrap: wrap; }
    .btn-edit { background: #ffc107; color: #000; padding: 5px 12px; border-radius: 20px; text-decoration: none; font-size: 12px; }
    .btn-delete { background: #dc3545; color: white; padding: 5px 12px; border-radius: 20px; text-decoration: none; font-size: 12px; }
    .btn-print { background: #17a2b8; color: white; padding: 5px 12px; border-radius: 20px; text-decoration: none; font-size: 12px; }
    .btn-edit:hover, .btn-delete:hover, .btn-print:hover { opacity: 0.85; }
</style>

<div class="honors-container">
    <h2>🎖️ المكرمون في عيد العمال</h2>

    <div class="stats-grid">
        <div class="stat-card">
            <div>🏆 إجمالي المكرمين</div>
            <div class="stat-number"><?= $totalHonorees ?></div>
        </div>
        <div class="stat-card">
            <div>📅 العام الحالي (<?= $currentYear ?>)</div>
            <div class="stat-number"><?= $currentYearCount ?></div>
        </div>
    </div>

    <a href="add.php" class="btn-add">➕ تكريم موظف جديد</a>

    <table class="data-table">
        <thead>
            <tr>
                <th>#</th>
                <th>الموظف</th>
                <th>السنة</th>
                <th>تاريخ التكريم</th>
                <th>نوع الجائزة</th>
                <th>القيمة (دج)</th>
                <th>سبب التكريم</th>
                <th>الإجراءات</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($honorees)): ?>
                <tr><td colspan="8" style="text-align:center;">لا يوجد مكرمون بعد</td></tr>
            <?php else: ?>
                <?php $i = 1; foreach ($honorees as $h): ?>
                <tr>
                    <td><?= $i++ ?></td>
                    <td><?= htmlspecialchars($h['employee_name']) ?><br><small>(<?= $h['category'] == 'Permanent' ? 'دائم' : 'متعاقد' ?>)</small></td>
                    <td><?= $h['year'] ?></td>
                    <td><?= date('d/m/Y', strtotime($h['honor_date'])) ?></td>
                    <td><?= htmlspecialchars($h['prize_type']) ?></td>
                    <td><?= number_format($h['prize_value'], 2) ?> دج</td>
                    <td><?= htmlspecialchars($h['reason']) ?></td>
                    <td class="action-buttons">
                        <a href="edit.php?id=<?= $h['id'] ?>" class="btn-edit">✏️ تعديل</a>
                        <a href="certificate.php?id=<?= $h['id'] ?>" target="_blank" class="btn-print">🖨️ شهادة</a>
                        <a href="confirm_delete.php?id=<?= $h['id'] ?>" class="btn-delete">🗑️ حذف</a> 
                        </td>               
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php include '../includes/footer.php'; ?>