<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/common_helpers.php';

$message = '';
$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : date('m');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_records'])) {
    foreach ($_POST['meal_count'] as $emp_id => $count) {
        $count = (int)$count;
        if ($count > 0) {
            $total = $count * 25;
            $stmt = $pdo->prepare("INSERT OR REPLACE INTO meal_records (employee_id, year, month, meal_count, total_amount, updated_at) VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)");
            $stmt->execute([$emp_id, $year, $month, $count, $total]);
        } else {
            $stmt = $pdo->prepare("DELETE FROM meal_records WHERE employee_id = ? AND year = ? AND month = ?");
            $stmt->execute([$emp_id, $year, $month]);
        }
    }
    setToast('✅ تم حفظ بيانات وجبات شهر ' . getMonthNameArabic($month) . ' ' . $year . ' بنجاح.', 'success');
    redirectTo('index.php', ['year' => $year, 'month' => $month]);
}

$employees = $pdo->query("SELECT id, name, category FROM employees ORDER BY name")->fetchAll();
$existing = [];
$stmt = $pdo->prepare("SELECT employee_id, meal_count FROM meal_records WHERE year = ? AND month = ?");
$stmt->execute([$year, $month]);
foreach ($stmt->fetchAll() as $row) { $existing[$row['employee_id']] = $row['meal_count']; }

include '../includes/header.php';
?>
<link rel="stylesheet" href="../assets/css/meals.css">

<div class="meals-container">
    <div class="meals-header">
        <h2>🍽️ تسجيل وجبات المطعم - الشهرية</h2>
    </div>

    <div class="filter-section">
        <form method="GET" class="filter-form">
            <div class="filter-group"><label>📅 السنة:</label><select name="year"><?php for($y=2020;$y<=date('Y')+1;$y++): ?><option value="<?=$y?>" <?=$y==$year?'selected':''?>><?=$y?></option><?php endfor; ?></select></div>
            <div class="filter-group"><label>📆 الشهر:</label><select name="month"><?php for($m=1;$m<=12;$m++): ?><option value="<?=$m?>" <?=$m==$month?'selected':''?>><?=getMonthNameArabic($m)?></option><?php endfor; ?></select></div>
            <button type="submit" class="btn-filter">تحديد</button>
        </form>
    </div>

    <?php if ($message): ?><div style="background:#d4edda; padding:10px; margin-bottom:15px; border-radius:8px;"><?= $message ?></div><?php endif; ?>

    <form method="POST">
        <table class="meals-table">
            <thead><tr><th>#</th><th>الموظف</th><th>الفئة</th><th>عدد الوجبات (25 دج)</th><th>الإجمالي (دج)</th></tr></thead>
            <tbody>
                <?php $i=1; foreach($employees as $emp): $count = $existing[$emp['id']] ?? 0; $total = $count * 25; ?>
                <tr>
                    <td><?= $i++ ?></td>
                    <td><?= htmlspecialchars($emp['name']) ?></td>
                    <td><?= $emp['category'] == 'Permanent' ? 'دائم' : 'متعاقد' ?></td>
                    <td><input type="number" name="meal_count[<?= $emp['id'] ?>]" value="<?= $count ?>" class="meal-count-input" min="0" step="1"> وجبة</td>
                    <td><span id="total_<?= $emp['id'] ?>"><?= number_format($total, 2) ?></span> دج</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div style="margin-top:20px; text-align:center;"><button type="submit" name="save_records" class="btn-save">💾 حفظ الوجبات</button></div>
    </form>
</div>

<script>
document.querySelectorAll('.meal-count-input').forEach(input => {
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