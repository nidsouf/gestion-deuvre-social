<?php
/**
 * employees/list.php - قائمة الموظفين (محسّنة بالأمان والإشعارات)
 */
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';
include '../includes/header.php';

// معالجة البحث
$search = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';

// استعلام جلب الموظفين
$sql = "SELECT * FROM employees WHERE 1=1";
$params = [];

if ($search) {
    $sql .= " AND name LIKE :search";
    $params[':search'] = "%$search%";
}
$sql .= " ORDER BY name ASC";

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$employees = $stmt->fetchAll();

// إحصائيات
$totalEmployees = count($employees);
$totalPermanent = $pdo->query("SELECT COUNT(*) FROM employees WHERE category = 'Permanent'")->fetchColumn();
$totalContract = $pdo->query("SELECT COUNT(*) FROM employees WHERE category = 'Contract'")->fetchColumn();
?>

<style>
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }
    .stat-card {
        background: white;
        border-radius: 20px;
        padding: 20px;
        text-align: center;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        border-bottom: 3px solid;
    }
    .stat-card.total { border-bottom-color: #2a5298; }
    .stat-card.permanent { border-bottom-color: #28a745; }
    .stat-card.contract { border-bottom-color: #ff9800; }
    .stat-card .number { font-size: 28px; font-weight: 700; }
    .filters {
        background: white;
        border-radius: 20px;
        padding: 15px;
        margin-bottom: 20px;
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        align-items: center;
    }
    .filters input {
        flex: 1;
        padding: 8px 15px;
        border: 1px solid #ddd;
        border-radius: 30px;
    }
    .data-table {
        width: 100%;
        border-collapse: collapse;
        background: white;
    }
    .data-table th, .data-table td {
        border: 1px solid #ddd;
        padding: 12px;
        text-align: center;
    }
    .btn-view-deductions {
    background: #17a2b8;
    color: white;
    padding: 4px 10px;
    border-radius: 15px;
    text-decoration: none;
    font-size: 12px;
    display: inline-block;
    margin: 0 2px;
}
.btn-view-deductions:hover {
    background: #138496;
}
    .data-table th {
        background: #2a5298;
        color: white;
    }
    .btn-add {
        background: #28a745;
        color: white;
        padding: 8px 20px;
        border-radius: 30px;
        text-decoration: none;
        margin-bottom: 20px;
        display: inline-block;
    }
    .btn-edit { background: #ffc107; color: #000; padding: 4px 12px; border-radius: 20px; text-decoration: none; }
    .btn-delete { background: #dc3545; color: white; padding: 4px 12px; border-radius: 20px; text-decoration: none; }
</style>

<div style="max-width: 1200px; margin: 0 auto;">
    <h2 style="margin-bottom: 20px;">👥 قائمة الموظفين</h2>
    
    <div class="stats-grid">
        <div class="stat-card total">
            <h3>إجمالي الموظفين</h3>
            <div class="number"><?= number_format($totalEmployees) ?></div>
        </div>
        <div class="stat-card permanent">
            <h3>👔 دائم</h3>
            <div class="number"><?= number_format($totalPermanent) ?></div>
        </div>
        <div class="stat-card contract">
            <h3>👕 متعاقد</h3>
            <div class="number"><?= number_format($totalContract) ?></div>
        </div>
    </div>
    
    <div class="filters">
        <form method="GET" style="display: flex; gap: 10px; width: 100%;">
            <input type="text" name="search" placeholder="🔍 بحث باسم الموظف..." value="<?= escape($search) ?>">
            <button type="submit" class="btn-sm">بحث</button>
            <?php if ($search): ?>
                <a href="list.php" class="btn-sm" style="background:#6c757d;">إلغاء</a>
            <?php endif; ?>
        </form>
    </div>
    
    <a href="add.php" class="btn-add">➕ إضافة موظف جديد</a>
    
    <div style="overflow-x: auto;">
        <table class="data-table">
            <thead>
                <tr><th>#</th><th>الاسم</th><th>التصنيف</th><th>تاريخ التوظيف</th><th>الإجراءات</th></tr>
            </thead>
            <tbody>
                <?php if (empty($employees)): ?>
                    <tr><td colspan="5" style="text-align:center;">لا توجد بيانات مطابقة</span></small></td>
                <?php else: ?>
                    <?php $i=1; foreach ($employees as $emp): ?>
                        <tr>
                            <td><?= $i++ ?> <small>(ID:<?= $emp['id'] ?>)</small>
                            <td><?= escape($emp['name']) ?>
                            <td><?= $emp['category'] == 'Permanent' ? 'دائم' : 'متعاقد' ?>
                            <td><?= $emp['hire_date'] ? date('d/m/Y', strtotime($emp['hire_date'])) : '—' ?>
                            <td class="action-buttons">
                                <a href="edit.php?id=<?= $emp['id'] ?>" class="btn-edit">✏️ تعديل</a>
                                <a href="deductions.php?id=<?= $emp['id'] ?>" class="btn-view-deductions">📋 عرض الاقتطاعات</a>
                                <a href="delete.php?id=<?= $emp['id'] ?>" class="btn-delete" onclick="return confirm('هل أنت متأكد من حذف هذا الموظف؟')">🗑️ حذف</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include '../includes/footer.php'; ?>