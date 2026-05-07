<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
require_once '../config/database.php';
require_once '../includes/functions.php';

$message = '';
$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : date('m');

// معالجة إدخال وجبات شهرية (إضافة أو تعديل)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_records'])) {
    foreach ($_POST['meal_count'] as $emp_id => $count) {
        $count = (int)$count;
        if ($count > 0) {
            $total = $count * 25;
            // استخدام INSERT OR REPLACE
            $stmt = $pdo->prepare("INSERT OR REPLACE INTO meal_records (employee_id, year, month, meal_count, total_amount, updated_at) 
                                   VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)");
            $stmt->execute([$emp_id, $year, $month, $count, $total]);
        } else {
            // إذا كان العدد صفر، يمكن حذف السجل إن وجد
            $stmt = $pdo->prepare("DELETE FROM meal_records WHERE employee_id = ? AND year = ? AND month = ?");
            $stmt->execute([$emp_id, $year, $month]);
        }
    }
    $message = "✅ تم حفظ بيانات وجبات شهر $year-$month بنجاح.";
}

// جلب جميع الموظفين (بدون فلتر status لتفادي الخطأ)
$employees = $pdo->query("SELECT id, name, category FROM employees ORDER BY name")->fetchAll();

// جلب السجلات المسجلة مسبقاً لهذا الشهر
$existing = [];
$stmt = $pdo->prepare("SELECT employee_id, meal_count FROM meal_records WHERE year = ? AND month = ?");
$stmt->execute([$year, $month]);
foreach ($stmt->fetchAll() as $row) {
    $existing[$row['employee_id']] = $row['meal_count'];
}

include '../includes/header.php';
?>

<style>
    .meal-container { direction: rtl; padding: 20px; max-width: 1200px; margin: auto; }
    .filters { background: #f0f2f5; padding: 15px; border-radius: 10px; margin-bottom: 20px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
    .filter-group { background: white; padding: 5px 10px; border-radius: 8px; border: 1px solid #ddd; display: flex; align-items: center; gap: 5px; }
    .meal-table { width: 100%; border-collapse: collapse; background: white; }
    .meal-table th, .meal-table td { border: 1px solid #ddd; padding: 10px; text-align: center; vertical-align: middle; }
    .meal-table th { background: #2a5298; color: white; }
    .btn-save { background: #28a745; color: white; padding: 10px 20px; border: none; border-radius: 8px; cursor: pointer; font-size: 16px; }
    .meal-count-input { width: 80px; padding: 5px; text-align: center; }
</style>

<div class="meal-container">
    <h2>🍽️ تسجيل وجبات المطعم - الشهرية</h2>
    <div class="filters">
        <form method="GET">
            <div class="filter-group"><label>📅 السنة:</label><select name="year"><?php for($y=2020;$y<=date('Y')+1;$y++): ?><option value="<?=$y?>" <?=$y==$year?'selected':''?>><?=$y?></option><?php endfor; ?></select></div>
            <div class="filter-group"><label>📆 الشهر:</label><select name="month"><?php for($m=1;$m<=12;$m++): ?><option value="<?=$m?>" <?=$m==$month?'selected':''?>><?=date('F',mktime(0,0,0,$m,1))?></option><?php endfor; ?></select></div>
            <button type="submit" class="btn-primary">تحديد</button>
        </form>
    </div>

    <?php if($message): ?>
        <div style="background:#d4edda; padding:10px; margin-bottom:15px; border-radius:8px;"><?= $message ?></div>
    <?php endif; ?>

    <form method="POST">
        <table class="meal-table">
            <thead>
                <tr><th>#</th><th>الموظف</th><th>الفئة</th><th>عدد الوجبات (قيمة الوجبة 25 دج)</th><th>الإجمالي (دج)</th></tr>
            </thead>
            <tbody>
                <?php $i=1; foreach($employees as $emp): 
                    $count = isset($existing[$emp['id']]) ? $existing[$emp['id']] : 0;
                    $total = $count * 25;
                ?>
                <tr>
                    <td><?= $i++ ?></td>
                    <td><?= htmlspecialchars($emp['name']) ?></td>
                    <td><?= ($emp['category'] == 'Permanent') ? 'دائم' : 'متعاقد' ?></td>
                    <td><input type="number" name="meal_count[<?= $emp['id'] ?>]" value="<?= $count ?>" class="meal-count-input" min="0" step="1"> وجبة</span></small></td>
                    <td><span id="total_<?= $emp['id'] ?>"><?= number_format($total, 2) ?></span> دج</span></small></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div style="margin-top: 20px; text-align: center;">
            <button type="submit" name="save_records" class="btn-save">💾 حفظ الوجبات</button>
        </div>
    </form>
</div>

<script>
    // تحديث الإجمالي تلقائياً عند تغيير عدد الوجبات
    const inputs = document.querySelectorAll('.meal-count-input');
    inputs.forEach(input => {
        input.addEventListener('input', function() {
            let count = parseInt(this.value) || 0;
            let total = count * 25;
            let row = this.closest('tr');
            let totalSpan = row.querySelector('td:last-child span');
            if (totalSpan) totalSpan.innerText = total.toFixed(2);
        });
    });
</script>

<?php include '../includes/footer.php'; ?>