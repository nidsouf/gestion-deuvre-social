<?php
require_once 'config/database.php';

echo "<h2>فحص الاقتطاعات الخاصة بمصدر djezzy</h2>";

// جلب جميع الاقتطاعات مع تواريخها ومصادرها
$sql = "
    SELECT d.id, e.name as employee, s.name as source, d.start_date, d.end_date, d.monthly_amount
    FROM deductions d
    JOIN employees e ON d.employee_id = e.id
    JOIN sources s ON d.source_id = s.id
    ORDER BY d.id
";
$stmt = $pdo->query($sql);
$deductions = $stmt->fetchAll();

echo "<table border='1' cellpadding='5' dir='rtl'>";
echo "<tr><th>ID</th><th>الموظف</th><th>المصدر</th><th>تاريخ البداية</th><th>تاريخ النهاية</th><th>المبلغ</th></tr>";

$found = false;
foreach ($deductions as $d) {
    // عرض كل الاقتطاعات
    echo "<tr>
            <td>{$d['id']}</td>
            <td>{$d['employee']}</td>
            <td>{$d['source']}</td>
            <td>{$d['start_date']}</td>
            <td>{$d['end_date']}</td>
            <td>{$d['monthly_amount']}</td>
          </tr>";
    if (stripos($d['source'], 'djezzy') !== false) $found = true;
}
echo "</table>";

if (!$found) {
    echo "<p style='color:red'>⚠️ لم يتم العثور على أي مصدر باسم 'djezzy' في جدول sources. تحقق من الاسم بالضبط.</p>";
} else {
    echo "<p style='color:green'>✅ تم العثور على مصدر djezzy.</p>";
}

// اختبار شرط التاريخ لشهر مايو 2026
$testStart = '2026-05-01';
$testEnd = '2026-05-31';
$testSql = "
    SELECT COUNT(*) as count
    FROM deductions d
    JOIN sources s ON d.source_id = s.id
    WHERE s.name = 'djezzy'
      AND d.start_date <= '$testEnd'
      AND d.end_date >= '$testStart'
";
$count = $pdo->query($testSql)->fetchColumn();
echo "<p>عدد الاقتطاعات لمصدر djezzy التي تتداخل مع مايو 2026 = <strong>$count</strong></p>";
?>