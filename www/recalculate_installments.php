<?php
require_once 'config/database.php';

echo "<h2>🔄 إعادة حساب الأقساط المردودة</h2>";

$loans = $pdo->query("
    SELECT d.*, e.name as employee_name
    FROM deductions d
    JOIN employees e ON d.employee_id = e.id
    WHERE d.is_loan = 1
")->fetchAll();

$today = new DateTime();
$year = date('Y');
$count = 0;

foreach ($loans as $loan) {
    $start = new DateTime($loan['start_date']);
    
    // عدد الأقساط من بداية السلفة لحد النهاردة (ولكن ليس قبل أول السنة)
    $monthsPassed = ($today->diff($start)->y * 12) + $today->diff($start)->m + 1;
    
    // نحصر الأقساط في السنة الحالية فقط
    $startYear = $start->format('Y');
    if ($startYear < $year) {
        // السلفة قديمة: نحسب الأقساط من أول السنة الحالية
        $yearStart = new DateTime("$year-01-01");
        $monthsPassed = ($today->diff($yearStart)->y * 12) + $today->diff($yearStart)->m + 1;
    }
    
    if ($monthsPassed > $loan['total_months']) {
        $monthsPassed = $loan['total_months'];
    }
    
    if ($monthsPassed < 0) $monthsPassed = 0;
    
    // الأقساط المسجلة فعلاً في budget_transactions
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount),0) as paid_amount,
               COALESCE(SUM(1),0) as paid_months
        FROM budget_transactions
        WHERE reference_id = ? AND type = 'installment' AND YEAR(transaction_date) = ?
    ");
    $stmt->execute([$loan['id'], $year]);
    $recorded = $stmt->fetch();
    
    $expectedPaidMonths = $monthsPassed;
    $expectedPaidAmount = $expectedPaidMonths * $loan['monthly_amount'];
    
    if ($recorded['paid_months'] != $expectedPaidMonths) {
        echo "<p>🔄 سلفة {$loan['id']} ({$loan['employee_name']}): 
              مسجلة {$recorded['paid_months']} قسط، المفروض {$expectedPaidMonths} قسط.</p>";
        
        // حذف الأقساط القديمة لهذه السلفة في السنة الحالية
        $pdo->prepare("DELETE FROM budget_transactions WHERE reference_id = ? AND type = 'installment' AND YEAR(transaction_date) = ?")->execute([$loan['id'], $year]);
        
        // إضافة الأقساط الصحيحة
        for ($i = 1; $i <= $expectedPaidMonths; $i++) {
            $pdo->prepare("
                INSERT INTO budget_transactions (amount, type, reference_id, description, is_deduct, transaction_date)
                VALUES (?, 'installment', ?, 'أقساط مردودة (تلقائي)', 0, DATE_ADD(?, INTERVAL ? MONTH))
            ")->execute([
                $loan['monthly_amount'],
                $loan['id'],
                $loan['start_date'],
                $i
            ]);
        }
        $count++;
    }
}

echo "<h3>✅ تم تحديث $count سلفة</h3>";
echo "<p><a href='budget/dashboard.php'>👉 عرض الداشبورد</a></p>";
?>