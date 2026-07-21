<?php
session_start();
require_once 'config/database.php';

$deduction_id = 746;

// 1. جعل الأقساط المدفوعة (جوان وجويلية) is_paid = 1
$pdo->prepare("UPDATE monthly_installments SET is_paid = 1 WHERE deduction_id = ? AND year = 2026 AND month IN (6,7)")->execute([$deduction_id]);
echo "✅ تم تعيين جوان وجويلية كمدفوعين.<br>";

// 2. تحديث total_months = 3 (لأن قسطين تم سدادهما)
$pdo->prepare("UPDATE deductions SET total_months = 3, end_date = '2026-10-01', credit_balance = 0 WHERE id = ?")->execute([$deduction_id]);
echo "✅ تم ضبط total_months = 3, end_date = 2026-10-01, credit_balance = 0.<br>";

// 3. حذف أي أقساط زائدة (غير المدفوعة والتي ليست في أوت، سبتمبر، أكتوبر)
$delete = $pdo->prepare("
    DELETE FROM monthly_installments 
    WHERE deduction_id = ? AND is_paid = 0 
      AND (year != 2026 OR month NOT IN (8,9,10))
");
$delete->execute([$deduction_id]);
echo "🗑️ تم حذف الأقساط الزائدة (إن وجدت).<br>";

// 4. التأكد من أن أوت = 20,000 (إذا لم يكن كذلك، نحدثه)
$pdo->prepare("UPDATE monthly_installments SET amount = 20000 WHERE deduction_id = ? AND year = 2026 AND month = 8 AND is_paid = 0")->execute([$deduction_id]);
echo "💰 تم ضبط مبلغ أوت إلى 20,000 دج.<br>";

// 5. عرض النتيجة النهائية
echo "<br><strong>الأقساط النهائية:</strong><br>";
$stmt = $pdo->prepare("SELECT year, month, amount, is_paid FROM monthly_installments WHERE deduction_id = ? ORDER BY year, month");
$stmt->execute([$deduction_id]);
$total_remaining = 0;
echo "<table border='1' cellpadding='5' style='border-collapse:collapse;'>";
echo "<tr><th>الشهر</th><th>السنة</th><th>المبلغ (دج)</th><th>الحالة</th></tr>";
while ($row = $stmt->fetch()) {
    $status = $row['is_paid'] ? '✅ مدفوع' : '❌ غير مدفوع';
    echo "<tr><td>{$row['month']}</td><td>{$row['year']}</td><td>{$row['amount']}</td><td>$status</td></tr>";
    if (!$row['is_paid']) $total_remaining += $row['amount'];
}
echo "</table>";
echo "<br><strong>المبلغ الإجمالي المتبقي:</strong> $total_remaining دج<br>";

echo "<br><a href='deductions/view.php?id=$deduction_id' target='_blank'>🔙 العودة إلى التفاصيل</a>";