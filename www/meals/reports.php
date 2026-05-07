<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
require_once '../config/database.php';
require_once '../includes/functions.php';

// جلب بيانات الوجبات الشهرية حسب الفلتر
$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : 0;

$monthlyRecords = [];
if ($month > 0) {
    $stmt = $pdo->prepare("
        SELECT m.*, e.name as employee_name, e.category 
        FROM meal_records m
        JOIN employees e ON m.employee_id = e.id
        WHERE m.year = ? AND m.month = ?
        ORDER BY e.name
    ");
    $stmt->execute([$year, $month]);
    $monthlyRecords = $stmt->fetchAll();
}

// جلب بيانات الثلاثيات
$trimesters = $pdo->query("SELECT * FROM meal_trimesters ORDER BY year DESC, trimester_number DESC")->fetchAll();

include '../includes/header.php';
?>

<style>
    .report-container { direction: rtl; padding: 20px; max-width: 1200px; margin: auto; }
    .filters { background: #f0f2f5; padding: 15px; border-radius: 10px; margin-bottom: 20px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
    .data-table { width: 100%; border-collapse: collapse; background: white; margin-bottom: 30px; }
    .data-table th, .data-table td { border: 1px solid #ddd; padding: 8px; text-align: center; }
    .data-table th { background: #2a5298; color: white; }
    .section-title { font-size: 18px; font-weight: bold; margin-top: 30px; margin-bottom: 10px; border-right: 4px solid #2a5298; padding-right: 10px; }
</style>

<div class="report-container">
    <h2>📊 تقارير وجبات المطعم</h2>

    <!-- الفلتر الشهري -->
    <div class="filters">
        <form method="GET">
            <select name="year">
                <?php for($y=2020;$y<=date('Y')+1;$y++): ?>
                    <option value="<?=$y?>" <?=$y==$year?'selected':''?>><?=$y?></option>
                <?php endfor; ?>
            </select>
            <select name="month">
                <option value="0">-- اختر شهر لعرض التفاصيل --</option>
                <?php for($m=1;$m<=12;$m++): ?>
                    <option value="<?=$m?>" <?=$m==$month?'selected':''?>><?=date('F',mktime(0,0,0,$m,1))?></option>
                <?php endfor; ?>
            </select>
            <button type="submit" class="btn-primary">عرض التقرير الشهري</button>
        </form>
    </div>

    <?php if($month > 0 && !empty($monthlyRecords)): ?>
        <div class="section-title">📅 تفاصيل وجبات شهر <?= date('F Y', mktime(0,0,0,$month,1,$year)) ?></div>
        <table class="data-table">
            <thead><tr><th>#</th><th>الموظف</th><th>الفئة</th><th>عدد الوجبات</th><th>القيمة الإجمالية (دج)</th></tr></thead>
            <tbody>
                <?php $totalMonth = 0; $i=1; foreach($monthlyRecords as $r): $totalMonth += $r['total_amount']; ?>
                <tr>
                    <td><?= $i++ ?></td>
                    <td><?= htmlspecialchars($r['employee_name']) ?></td>
                    <td><?= ($r['category']=='Permanent')?'دائم':'متعاقد' ?></td>
                    <td><?= $r['meal_count'] ?></td>
                    <td><?= number_format($r['total_amount'],2) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot><tr class="total-row"><td colspan="4"><strong>إجمالي الشهر</strong></td><td><strong><?= number_format($totalMonth,2) ?> دج</strong></td></tr></tfoot>
        </table>
    <?php elseif($month>0): ?>
        <p>⚠️ لا توجد بيانات للشهر المحدد.</p>
    <?php endif; ?>

    <!-- قائمة الثلاثيات -->
    <div class="section-title">📆 الثلاثيات المسجلة</div>
    <?php if(count($trimesters)==0): ?>
        <p>⚠️ لا توجد ثلاثيات بعد. قم بإنشاء ثلاثي جديد من صفحة "تأكيد الاقتطاع الثلاثي".</p>
    <?php else: ?>
        <table class="data-table">
            <thead><tr><th>#</th><th>السنة</th><th>الثلاثي</th><th>الفترة</th><th>إجمالي الوجبات</th><th>المبلغ الإجمالي (دج)</th><th>نصف المبلغ (دج)</th><th>الحالة</th><th>تاريخ التسديد</th></tr></thead>
            <tbody>
                <?php foreach($trimesters as $idx=>$t): ?>
                <tr>
                    <td><?= $idx+1 ?></td>
                    <td><?= $t['year'] ?></td>
                    <td><?= $t['trimester_number'] ?></td>
                    <td><?= date('d/m/Y',strtotime($t['start_date'])) ?> - <?= date('d/m/Y',strtotime($t['end_date'])) ?></td>
                    <td><?= $t['total_meals'] ?></td>
                    <td><?= number_format($t['total_amount'],2) ?></td>
                    <td><?= number_format($t['half_amount'],2) ?></td>
                    <td><?= ($t['status']=='deducted')?'تم الاقتطاع':'معلق' ?></td>
                    <td><?= $t['updated_at']?date('d/m/Y',strtotime($t['updated_at'])):'-' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php include '../includes/footer.php'; ?>