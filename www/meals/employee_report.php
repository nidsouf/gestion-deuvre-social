<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
require_once '../config/database.php';
require_once '../includes/functions.php';

$employee_id = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;

// جلب قائمة الموظفين للقائمة المنسدلة
$employees = $pdo->query("SELECT id, name, category FROM employees ORDER BY name")->fetchAll();

$employee = null;
$records = [];
$total_meals = 0;
$total_grant = 0;

if ($employee_id > 0) {
    // جلب بيانات الموظف
    $stmt = $pdo->prepare("SELECT name, category FROM employees WHERE id = ?");
    $stmt->execute([$employee_id]);
    $employee = $stmt->fetch();
    
    if ($employee) {
        // ===== جلب سجل الوجبات (من meal_records) =====
        $stmt = $pdo->prepare("
            SELECT * FROM meal_records 
            WHERE employee_id = ? 
            ORDER BY year DESC, month DESC
        ");
        $stmt->execute([$employee_id]);
        $records = $stmt->fetchAll();
        
        // حساب الإجمالي من meal_records مباشرة
        $total_meals = array_sum(array_column($records, 'meal_count'));
        $total_amount = array_sum(array_column($records, 'total_amount'));
        
        // جلب المنح المصروفة (من meal_installments) إن وجدت
        $stmtGrant = $pdo->prepare("
            SELECT SUM(grant_amount) as total_grant 
            FROM meal_installments 
            WHERE employee_id = ? AND is_processed = 1
        ");
        $stmtGrant->execute([$employee_id]);
        $total_grant = $stmtGrant->fetchColumn() ?: 0;
        
        // إذا لم توجد منح في meal_installments، نستخدم نصف المبلغ كمنحة (افتراضي)
        if ($total_grant == 0 && $total_amount > 0) {
            $total_grant = $total_amount / 2;
        }
    }
}

include '../includes/header.php';
?>

<style>
    .record-container { max-width: 1000px; margin: 0 auto; }
    .employee-info { background: #e3f2fd; padding: 20px; border-radius: 15px; margin-bottom: 20px; }
    .stats-grid { display: flex; gap: 20px; flex-wrap: wrap; margin-bottom: 30px; }
    .stat-card { background: white; border-radius: 15px; padding: 15px; text-align: center; flex: 1; min-width: 120px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
    .stat-card .number { font-size: 24px; font-weight: 700; color: #2a5298; }
    .data-table { width: 100%; border-collapse: collapse; background: white; border-radius: 15px; overflow: hidden; }
    .data-table th, .data-table td { border: 1px solid #ddd; padding: 10px; text-align: center; }
    .data-table th { background: #2a5298; color: white; }
    .filter-box { background: #f8f9fa; padding: 15px; border-radius: 10px; margin-bottom: 20px; display: flex; gap: 15px; flex-wrap: wrap; align-items: center; }
    .filter-box select { padding: 8px 15px; border-radius: 20px; border: 1px solid #ccc; min-width: 200px; }
    .btn-primary { background: #2a5298; color: white; border: none; padding: 8px 20px; border-radius: 20px; cursor: pointer; }
    .btn-secondary { background: #6c757d; color: white; padding: 8px 20px; border-radius: 20px; text-decoration: none; display: inline-block; }
</style>

<div class="record-container">
    <h2>🍽️ سجل منح وجبات المطعم</h2>
    
    <!-- قائمة اختيار الموظف -->
    <div class="filter-box">
        <form method="GET" style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center; width: 100%;">
            <label style="font-weight: bold;">👤 اختيار الموظف:</label>
            <select name="employee_id" required>
                <option value="">-- اختر موظف --</option>
                <?php foreach ($employees as $emp): ?>
                    <option value="<?= $emp['id'] ?>" <?= $employee_id == $emp['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($emp['name']) ?> (<?= $emp['category'] == 'Permanent' ? 'دائم' : 'متعاقد' ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn-primary">🔍 عرض</button>
            <?php if ($employee_id > 0): ?>
                <a href="employee_report.php" class="btn-secondary">❌ إلغاء</a>
            <?php endif; ?>
        </form>
    </div>

    <?php if ($employee_id > 0 && $employee): ?>
        <!-- معلومات الموظف -->
        <div class="employee-info">
            <h3><?= htmlspecialchars($employee['name']) ?></h3>
            <p>الفئة: <?= $employee['category'] == 'Permanent' ? 'دائم' : 'متعاقد' ?></p>
        </div>

        <!-- إحصائيات -->
        <div class="stats-grid">
            <div class="stat-card"><div>🍽️ إجمالي الوجبات</div><div class="number"><?= number_format($total_meals) ?></div></div>
            <div class="stat-card"><div>💰 قيمة الوجبات</div><div class="number"><?= number_format($total_amount, 2) ?> دج</div></div>
            <div class="stat-card"><div>🎁 إجمالي المنح</div><div class="number"><?= number_format($total_grant, 2) ?> دج</div></div>
        </div>

        <!-- الجدول التفصيلي -->
        <?php if (empty($records)): ?>
            <div style="background:#f8d7da; padding:20px; text-align:center;">⚠️ لا توجد سجلات لهذا الموظف</div>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>الشهر</th>
                        <th>السنة</th>
                        <th>عدد الوجبات</th>
                        <th>قيمة الوجبات (دج)</th>
                        <th>المنحة (دج)</th>
                        <th>الحالة</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($records as $row): 
                        $grant_for_row = $row['total_amount'] / 2;
                    ?>
                    <tr>
                        <td><?= getMonthNameArabic($row['month']) ?></td>
                        <td><?= $row['year'] ?></td>
                        <td><?= $row['meal_count'] ?></td>
                        <td><?= number_format($row['total_amount'], 2) ?></td>
                        <td><?= number_format($grant_for_row, 2) ?></td>
                        <td>
                            <?php if ($row['meal_count'] > 0): ?>
                                <span style="color:#28a745;">✅ مستحق</span>
                            <?php else: ?>
                                <span style="color:#999;">⏳ بدون وجبات</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        
        <div style="margin-top: 20px;">
            <a href="report.php?month=<?= date('m') ?>&year=<?= date('Y') ?>" class="btn-secondary">🔙 العودة إلى التقرير العام</a>
            <a href="generate_grant.php?month=<?= date('m') ?>&year=<?= date('Y') ?>" class="btn btn-success" style="background:#28a745; color:white; padding:8px 20px; border-radius:20px; text-decoration:none; margin-left:10px;" onclick="return confirm('⚠️ توليد منح الوجبات لهذا الشهر؟')">🎁 توليد المنحة</a>
        </div>

    <?php elseif ($employee_id > 0 && !$employee): ?>
        <div style="background:#f8d7da; padding:20px; text-align:center;">❌ الموظف غير موجود</div>
    <?php else: ?>
        <div style="background:#e3f2fd; padding:20px; text-align:center; border-radius:10px;">
            <p>👆 اختر موظفاً من القائمة المنسدلة لعرض سجل منح الوجبات الخاص به.</p>
        </div>
    <?php endif; ?>
</div>

<?php include '../includes/footer.php'; ?>