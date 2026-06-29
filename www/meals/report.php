<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
require_once '../config/database.php';
require_once '../includes/functions.php';

$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : date('m');
$view = isset($_GET['view']) ? $_GET['view'] : 'monthly';

$month_year = sprintf("%04d-%02d", $year, $month);

// ========== 1. البيانات الشهرية (من جدول meal_records) ==========
$stmt = $pdo->prepare("
    SELECT 
        mr.*,
        e.name as employee_name,
        e.category
    FROM meal_records mr
    JOIN employees e ON mr.employee_id = e.id
    WHERE mr.year = ? AND mr.month = ?
    ORDER BY e.name ASC
");
$stmt->execute([$year, $month]);
$monthlyRecords = $stmt->fetchAll();

$totalMealsMonth = array_sum(array_column($monthlyRecords, 'meal_count'));
$totalAmountMonth = array_sum(array_column($monthlyRecords, 'total_amount'));

// ========== عدد المستفيدين ==========
$stmtBeneficiaries = $pdo->prepare("SELECT COUNT(DISTINCT employee_id) FROM meal_records WHERE year = ? AND month = ? AND meal_count > 0");
$stmtBeneficiaries->execute([$year, $month]);
$totalBeneficiaries = $stmtBeneficiaries->fetchColumn() ?: 0;

// ========== 2. الثلاثيات ==========
$trimesters = $pdo->query("
    SELECT * FROM meal_trimesters 
    WHERE year = ? 
    ORDER BY trimester_number ASC
")->fetchAll(PDO::FETCH_ASSOC);

// ========== 3. التحقق من وجود نوع المنحة (بدون description) ==========
$stmtGrantType = $pdo->query("SELECT id FROM grants WHERE name = 'منحة وجبات المطعم'");
$grantType = $stmtGrantType->fetch();
if (!$grantType) {
    $pdo->exec("INSERT INTO grants (name, amount) VALUES ('منحة وجبات المطعم', 0)");
    $grantTypeId = $pdo->lastInsertId();
} else {
    $grantTypeId = $grantType['id'];
}

include '../includes/header.php';
?>

<style>
    .report-container { direction: rtl; max-width: 1200px; margin: 0 auto; }
    .report-header { background: linear-gradient(135deg, #1e3c72, #2a5298); color: white; padding: 15px; border-radius: 10px; text-align: center; margin-bottom: 20px; }
    .stats-grid { display: flex; gap: 20px; flex-wrap: wrap; margin-bottom: 30px; }
    .stat-card { background: white; border-radius: 20px; padding: 20px; text-align: center; flex: 1; min-width: 150px; border-bottom: 3px solid; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
    .stat-card .number { font-size: 28px; font-weight: 700; }
    .stat-card.meals { border-bottom-color: #28a745; }
    .stat-card.amount { border-bottom-color: #2a5298; }
    .stat-card.employees { border-bottom-color: #fd7e14; }
    .filters { background: #f8f9fa; padding: 15px; border-radius: 10px; margin-bottom: 20px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
    .data-table { width: 100%; border-collapse: collapse; background: white; border-radius: 15px; overflow: hidden; }
    .data-table th, .data-table td { border: 1px solid #ddd; padding: 10px; text-align: center; }
    .data-table th { background: #2a5298; color: white; }
    .total-row { background: #f0f0f0; font-weight: bold; }
    .section-title { font-size: 18px; font-weight: bold; margin: 25px 0 10px; border-right: 4px solid #2a5298; padding-right: 10px; }
    .btn-print { background: #17a2b8; color: white; padding: 8px 20px; border-radius: 30px; text-decoration: none; display: inline-block; }
    .btn-print:hover { background: #138496; }
    .btn-secondary { background: #6c757d; color: white; padding: 8px 20px; border-radius: 30px; text-decoration: none; display: inline-block; }
    .badge-status { padding: 4px 10px; border-radius: 20px; font-size: 12px; display: inline-block; }
    .badge-deducted { background: #28a745; color: white; }
    .badge-pending { background: #ffc107; color: #333; }
</style>

<div class="report-container">
    <div class="report-header">
        <h2>🍽️ تقرير وجبات المطعم</h2>
        <h3><?= getMonthNameArabic($month) . ' ' . $year ?></h3>
    </div>

    <!-- الإحصائيات -->
    <div class="stats-grid">
        <div class="stat-card meals"><div>🍽️ إجمالي الوجبات</div><div class="number"><?= number_format($totalMealsMonth) ?></div></div>
        <div class="stat-card amount"><div>💰 إجمالي المبلغ</div><div class="number"><?= number_format($totalAmountMonth, 2) ?> دج</div></div>
        <div class="stat-card employees"><div>👥 عدد المستفيدين</div><div class="number"><?= number_format($totalBeneficiaries) ?></div></div>
    </div>

    <!-- الفلاتر -->
    <div class="filters">
        <form method="GET" style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center; width: 100%;">
            <div class="filter-group"><label>📅 السنة:</label><select name="year"><?php for($y=2020;$y<=date('Y')+1;$y++): ?><option value="<?=$y?>" <?=$y==$year?'selected':''?>><?=$y?></option><?php endfor; ?></select></div>
            <div class="filter-group"><label>📆 الشهر:</label><select name="month"><?php for($m=1;$m<=12;$m++): ?><option value="<?=$m?>" <?=$m==$month?'selected':''?>><?=getMonthNameArabic($m)?></option><?php endfor; ?></select></div>
            <button type="submit" class="btn-primary">🔍 عرض</button>
            <a href="?year=<?=$year?>&month=<?=$month?>&print=1" target="_blank" class="btn-print">🖨️ طباعة</a>
            <a href="import_monthly.php" class="btn-secondary">📥 استيراد تقرير</a>
        </form>
    </div>

    <!-- جدول البيانات الشهرية -->
    <div class="section-title">📅 تفاصيل وجبات الشهر</div>
    <?php if (empty($monthlyRecords)): ?>
        <div style="background:#f8d7da; padding:20px; text-align:center;">⚠️ لا توجد بيانات للشهر المحدد. قم باستيراد تقرير CSV أولاً.</div>
    <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>الموظف</th>
                    <th>الفئة</th>
                    <th>عدد الوجبات</th>
                    <th>سعر الوجبة (دج)</th>
                    <th>المبلغ الإجمالي (دج)</th>
                </tr>
            </thead>
            <tbody>
                <?php $i=1; foreach ($monthlyRecords as $row): ?>
                <tr>
                    <td><?= $i++ ?></td>
                    <td><?= htmlspecialchars($row['employee_name']) ?></td>
                    <td><?= $row['category'] == 'Permanent' ? 'دائم' : 'متعاقد' ?></td>
                    <td><?= $row['meal_count'] ?></td>
                    <td><?= number_format($row['price_per_meal'] ?? 25, 2) ?></td>
                    <td><?= number_format($row['total_amount'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td colspan="3"><strong>الإجمالي</strong></td>
                    <td><strong><?= number_format($totalMealsMonth) ?></strong></td>
                    <td></td>
                    <td><strong><?= number_format($totalAmountMonth, 2) ?> دج</strong></td>
                </tr>
            </tbody>
        </table>
    <?php endif; ?>

    <!-- الثلاثيات -->
    <div class="section-title">📆 الثلاثيات المسجلة</div>
    <?php if (empty($trimesters)): ?>
        <p>⚠️ لا توجد ثلاثيات مسجلة.</p>
    <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>السنة</th>
                    <th>الثلاثي</th>
                    <th>الفترة</th>
                    <th>إجمالي الوجبات</th>
                    <th>المبلغ الإجمالي (دج)</th>
                    <th>نصف المبلغ (دج)</th>
                    <th>الحالة</th>
                </tr>
            </thead>
            <tbody>
                <?php $i=1; foreach ($trimesters as $t): ?>
                <tr>
                    <td><?= $i++ ?></td>
                    <td><?= $t['year'] ?></td>
                    <td><?= $t['trimester_number'] ?></td>
                    <td><?= date('d/m/Y', strtotime($t['start_date'])) ?> - <?= date('d/m/Y', strtotime($t['end_date'])) ?></td>
                    <td><?= $t['total_meals'] ?></td>
                    <td><?= number_format($t['total_amount'], 2) ?></td>
                    <td><?= number_format($t['half_amount'], 2) ?></td>
                    <td>
                        <span class="badge-status <?= $t['status'] == 'deducted' ? 'badge-deducted' : 'badge-pending' ?>">
                            <?= $t['status'] == 'deducted' ? '✅ تم الاقتطاع' : '⏳ معلق' ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <!-- زر توليد المنحة -->
    <div style="margin-top: 20px; text-align: center;">
        <button class="btn-generate" data-href="generate_grant.php?month=<?= date('m') ?>&year=<?= date('Y') ?>">
    🎁 توليد المنحة
</button>
        <a href="process_trimester.php" class="btn btn-primary" style="background:#2a5298; color:white; padding:10px 25px; border-radius:30px; text-decoration:none;">📊 معالجة الثلاثيات</a>
        <a href="export_excel.php?month=<?= $month ?>&year=<?= $year ?>" class="btn-export" style="background:#28a745; color:white; padding:8px 20px; border-radius:30px; text-decoration:none; display:inline-block;">📤 تصدير Excel</a>
    </div>
    
</div>

<script>
    // مؤشر تحميل عند الضغط على زر توليد المنحة
    document.querySelectorAll('.btn-generate').forEach(btn => {
        btn.addEventListener('click', function(e) {
            const originalText = this.innerHTML;
            this.innerHTML = '⏳ جاري التوليد...';
            this.disabled = true;
            // استخدام data-href للتوجيه
            const href = this.getAttribute('data-href') || this.getAttribute('href');
            if (href) {
                setTimeout(() => {
                    window.location.href = href;
                }, 1000);
            }
        });
    });
</script>

<?php include '../includes/footer.php'; ?>

<?php include '../includes/footer.php'; ?>