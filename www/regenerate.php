<?php
// regenerate.php - إعادة توليد أقساط لاقتطاع معين

require_once 'config/database.php';
require_once 'includes/functions.php';

// معرف الاقتطاع الذي تريد إعادة توليد أقساطه (غيّر الرقم حسب الحاجة)
$deduction_id = 726;

// تشغيل التوليد (بدون حذف الأقساط الموجودة)
$result = regenerateMonthlyInstallments($deduction_id, false);

if ($result) {
    echo "✅ تم إعادة توليد الأقساط للاقتطاع رقم $deduction_id بنجاح.";
} else {
    echo "❌ فشل في إعادة التوليد. تأكد من وجود الاقتطاع.";
}

// عرض الأقساط الحالية للتحقق
echo "<hr><h3>الأقساط الحالية للاقتطاع $deduction_id</h3>";
$stmt = $pdo->prepare("SELECT id, year, month, amount, is_paid, is_postponed FROM monthly_installments WHERE deduction_id = ? ORDER BY year, month");
$stmt->execute([$deduction_id]);
$installments = $stmt->fetchAll();

if (empty($installments)) {
    echo "لا توجد أقساط.";
} else {
    echo "<table border='1' cellpadding='5' style='border-collapse:collapse;'>";
    echo "<tr><th>ID</th><th>السنة</th><th>الشهر</th><th>المبلغ</th><th>مدفوع</th><th>مؤجل</th></tr>";
    foreach ($installments as $inst) {
        echo "<tr>";
        echo "<td>{$inst['id']}</td>";
        echo "<td>{$inst['year']}</td>";
        echo "<td>{$inst['month']}</td>";
        echo "<td>{$inst['amount']}</td>";
        echo "<td>" . ($inst['is_paid'] ? 'نعم' : 'لا') . "</td>";
        echo "<td>" . ($inst['is_postponed'] ? 'نعم' : 'لا') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}