<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
require_once '../config/database.php';
require_once '../includes/functions.php';

$message = '';
$trimester_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// جلب قائمة الثلاثيات غير المقتطعة (pending)
$pendingTrimesters = $pdo->query("SELECT * FROM meal_trimesters WHERE status = 'pending' ORDER BY year, trimester_number")->fetchAll();

// إنشاء ثلاثي جديد بناءً على الشهر الحالي أو يدوياً
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_trimester'])) {
    $year = (int)$_POST['year'];
    $trimester = (int)$_POST['trimester'];
    // تحديد بداية ونهاية الثلاثي
    if ($trimester == 1) { $start_m = 1; $end_m = 3; }
    elseif ($trimester == 2) { $start_m = 4; $end_m = 6; }
    elseif ($trimester == 3) { $start_m = 7; $end_m = 9; }
    else { $start_m = 10; $end_m = 12; }
    $start_date = "$year-$start_m-01";
    $end_date = date("Y-m-t", strtotime("$year-$end_m-01"));
    
    // حساب إجمالي الوجبات والمبالغ من جدول meal_records للأشهر الثلاثة
    $stmt = $pdo->prepare("SELECT SUM(meal_count) as total_meals, SUM(total_amount) as total_amount FROM meal_records WHERE year = ? AND month BETWEEN ? AND ?");
    $stmt->execute([$year, $start_m, $end_m]);
    $sum = $stmt->fetch();
    $total_meals = $sum['total_meals'] ?? 0;
    $total_amount = $sum['total_amount'] ?? 0;
    $half_amount = $total_amount / 2;
    
    // إدراج الثلاثي
    $insert = $pdo->prepare("INSERT INTO meal_trimesters (trimester_number, year, start_date, end_date, total_meals, total_amount, half_amount, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')");
    $insert->execute([$trimester, $year, $start_date, $end_date, $total_meals, $total_amount, $half_amount]);
    $message = "✅ تم إنشاء الثلاثي بنجاح. الآن يمكنك تأكيد الاقتطاع.";
    // إعادة تحميل القائمة
    $pendingTrimesters = $pdo->query("SELECT * FROM meal_trimesters WHERE status = 'pending' ORDER BY year, trimester_number")->fetchAll();
}

// معالجة تأكيد الاقتطاع لثلاثي محدد
if (isset($_POST['confirm_deduction']) && isset($_POST['trimester_id'])) {
    $tid = (int)$_POST['trimester_id'];
    // جلب بيانات الثلاثي
    $stmt = $pdo->prepare("SELECT * FROM meal_trimesters WHERE id = ? AND status = 'pending'");
    $stmt->execute([$tid]);
    $tri = $stmt->fetch();
    if ($tri) {
        // 1. إنشاء سجل اقتطاع في جدول deductions (اختياري - سنربطه مع الموظفين؟ ملاحظة: الاقتطاع يكون من راتب الموظف ولكن نحتاج إلى توزيع المبلغ على الموظفين؟ حسب وصفك: الاقتطاع يكون مجموع وجبات الموظف × 25، ونصفه تسده اللجنة.
        // لكن النظام الحالي للاقتطاعات مرتبط بموظف ومصدر. هنا الاقتطاع جماعي؟ الأفضل أن نقوم بإنشاء اقتطاع لكل موظف على حدة؟ لكن قد يكون معقداً.
        // حسب طلبك: "يتم اقتطاع مجموعها من راتبه كل ثلاثي". يعني كل موظف له مبلغ محدد. لذلك يجب أن نرجع إلى تفاصيل meal_records لكل موظف.
        // سنقوم بعمل حلقة لكل موظف لديه وجبات في الثلاثي، وننشئ له سجل اقتطاع منفصل في جدول deductions.
        
        $months_range = [];
        if ($tri['trimester_number'] == 1) $months_range = [1,2,3];
        elseif ($tri['trimester_number'] == 2) $months_range = [4,5,6];
        elseif ($tri['trimester_number'] == 3) $months_range = [7,8,9];
        else $months_range = [10,11,12];
        
        // جلب جميع سجلات meal_records للموظفين في تلك الأشهر والسنة
        $placeholders = implode(',', array_fill(0, count($months_range), '?'));
        $sql = "SELECT employee_id, SUM(meal_count) as total_meals, SUM(total_amount) as total_amount 
                FROM meal_records 
                WHERE year = ? AND month IN ($placeholders)
                GROUP BY employee_id";
        $params = array_merge([$tri['year']], $months_range);
        $stmtEmp = $pdo->prepare($sql);
        $stmtEmp->execute($params);
        $employeeTotals = $stmtEmp->fetchAll();
        
        // إنشاء مصدر جديد "وجبات المطعم" إذا لم يكن موجوداً
        $sourceStmt = $pdo->prepare("SELECT id FROM sources WHERE name = 'وجبات المطعم'");
        $sourceStmt->execute();
        $source = $sourceStmt->fetch();
        if (!$source) {
            $pdo->exec("INSERT INTO sources (name, description, is_loan) VALUES ('وجبات المطعم', 'اقتطاع شهري مقابل وجبات المطعم', 0)");
            $source_id = $pdo->lastInsertId();
        } else {
            $source_id = $source['id'];
        }
        
        // لكل موظف، ننشئ سجل اقتطاع جديد
        $deduction_ids = [];
        foreach ($employeeTotals as $emp) {
            $employee_id = $emp['employee_id'];
            $total_meal_amount = $emp['total_amount']; // المبلغ الإجمالي للوجبات (عدد الوجبات × 25)
            // الاقتطاع الشهري (لكن حسب الطلب يخصم مرة واحدة في الثلاثي، لذا يمكن جعله قسط واحد)
            $monthly_amount = $total_meal_amount; // يخصم دفعة واحدة
            $start_date = $tri['start_date'];
            $end_date = $tri['end_date'];
            $stmtIns = $pdo->prepare("INSERT INTO deductions (employee_id, source_id, monthly_amount, total_amount, remaining_amount, installments_count, installments_paid, start_date, end_date, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'نشط', 'اقتطاع وجبات مطعم - الثلاثي')");
            $stmtIns->execute([$employee_id, $source_id, $monthly_amount, $total_meal_amount, $total_meal_amount, 1, 0, $start_date, $end_date]);
            $deduction_ids[] = $pdo->lastInsertId();
        }
        
        // 2. تحديث حالة الثلاثي إلى 'deducted' وتسجيل تاريخ
        $update = $pdo->prepare("UPDATE meal_trimesters SET status = 'deducted', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $update->execute([$tid]);
        
        // 3. تسجيل صرف نصف المبلغ من ميزانية اللجنة
        // نبحث عن budget_id للشهر الحالي (أول شهر من الثلاثي أو الشهر الحالي)
        $currentYearMonth = date('Y-m');
        $budgetStmt = $pdo->prepare("SELECT id FROM budget WHERE year = ? AND month = ?");
        $budgetStmt->execute([date('Y'), date('m')]);
        $budget = $budgetStmt->fetch();
        if ($budget) {
            $budget_id = $budget['id'];
            $half = $tri['half_amount'];
            // إدراج معاملة في budget_transactions
            $transStmt = $pdo->prepare("INSERT INTO budget_transactions (budget_id, type, reference_id, amount, date, description) VALUES (?, 'meal_subsidy', ?, ?, ?, 'تسديد نصف قيمة وجبات المطعم')");
            $transStmt->execute([$budget_id, $tid, -$half, date('Y-m-d')]);
            // تحديث remaining_budget في جدول budget (نخصم المبلغ)
            $pdo->prepare("UPDATE budget SET remaining_budget = remaining_budget - ?, total_grants = total_grants + ? WHERE id = ?")->execute([$half, $half, $budget_id]);
        } else {
            // إذا لم يوجد budget للشهر الحالي، يمكن إنشاؤه أو تسجيل خطأ
            $message .= " ⚠️ لم يتم العثور على ميزانية للشهر الحالي، لم يتم خصم المبلغ.";
        }
        
        $message = "✅ تم تأكيد الاقتطاع للثلاثي، وتم إنشاء سجلات الاقتطاعات للموظفين، وتم خصم نصف المبلغ من الميزانية.";
        // إعادة تحميل القائمة
        $pendingTrimesters = $pdo->query("SELECT * FROM meal_trimesters WHERE status = 'pending' ORDER BY year, trimester_number")->fetchAll();
    } else {
        $message = "⚠️ الثلاثي غير موجود أو تمت معالجته مسبقاً.";
    }
}

include '../includes/header.php';
?>

<style>
    .container { direction: rtl; padding: 20px; max-width: 1000px; margin: auto; }
    .card { background: #f8f9fa; border-radius: 12px; padding: 20px; margin-bottom: 25px; border: 1px solid #ddd; }
    .btn-confirm { background: #dc3545; color: white; border: none; padding: 8px 15px; border-radius: 20px; cursor: pointer; }
    .btn-create { background: #28a745; color: white; border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer; }
    .data-table { width: 100%; border-collapse: collapse; background: white; }
    .data-table th, .data-table td { border: 1px solid #ddd; padding: 8px; text-align: center; }
    .data-table th { background: #2a5298; color: white; }
</style>

<div class="container">
    <h2>🍽️ تأكيد الاقتطاع الثلاثي لوجبات المطعم</h2>
    <?php if($message): ?>
        <div style="background:#e9ecef; padding:10px; border-radius:8px; margin-bottom:15px;"><?= $message ?></div>
    <?php endif; ?>

    <!-- نموذج إنشاء ثلاثي جديد -->
    <div class="card">
        <h3>➕ إنشاء ثلاثي جديد</h3>
        <form method="POST">
            <select name="year" required>
                <?php for($y=2020;$y<=date('Y')+1;$y++): ?>
                    <option value="<?=$y?>" <?=$y==date('Y')?'selected':''?>><?=$y?></option>
                <?php endfor; ?>
            </select>
            <select name="trimester" required>
                <option value="1">الثلاثي الأول (جانفي-فيفري-مارس)</option>
                <option value="2">الثلاثي الثاني (أفريل-ماي-جوان)</option>
                <option value="3">الثلاثي الثالث (جويلية-أوت-سبتمبر)</option>
                <option value="4">الثلاثي الرابع (أكتوبر-نوفمبر-ديسمبر)</option>
            </select>
            <button type="submit" name="create_trimester" class="btn-create">إنشاء ثلاثي</button>
        </form>
    </div>

    <!-- قائمة الثلاثيات المعلقة -->
    <div class="card">
        <h3>⏳ ثلاثيات معلقة (لم يتم الاقتطاع بعد)</h3>
        <?php if(count($pendingTrimesters)==0): ?>
            <p>لا توجد ثلاثيات معلقة.</p>
        <?php else: ?>
            <table class="data-table">
                <thead><tr><th>السنة</th><th>الثلاثي</th><th>الفترة</th><th>إجمالي الوجبات</th><th>المبلغ الإجمالي</th><th>نصف المبلغ (تسدد اللجنة)</th><th>العملية</th></tr></thead>
                <tbody>
                    <?php foreach($pendingTrimesters as $t): ?>
                    <tr>
                        <td><?= $t['year'] ?></td>
                        <td><?= $t['trimester_number'] ?></td>
                        <td><?= date('d/m/Y',strtotime($t['start_date'])) ?> - <?= date('d/m/Y',strtotime($t['end_date'])) ?></td>
                        <td><?= $t['total_meals'] ?></td>
                        <td><?= number_format($t['total_amount'],2) ?> دج</td>
                        <td><?= number_format($t['half_amount'],2) ?> دج</td>
                        <td>
                            <form method="POST" onsubmit="return confirm('سيتم إنشاء اقتطاعات للموظفين وخصم نصف المبلغ من الميزانية. هل أنت متأكد؟')">
                                <input type="hidden" name="trimester_id" value="<?= $t['id'] ?>">
                                <button type="submit" name="confirm_deduction" class="btn-confirm">تأكيد الاقتطاع</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php include '../includes/footer.php'; ?>