<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../login.php"); exit; }
require_once '../config/database.php';
require_once '../includes/functions.php';

$year = 2026;
$month = 5;
$startDate = "$year-$month-01";
$endDate = date('Y-m-t', strtotime($startDate));

echo "<h2>اختبار استعلام التقرير الشهري (مايو 2026)</h2>";
echo "الفترة: $startDate → $endDate<br><br>";

// نفس الاستعلام المستخدم في monthly.php
$sql = "
    SELECT COUNT(*) as total
    FROM deductions d
    JOIN employees e ON d.employee_id = e.id
    JOIN sources s ON d.source_id = s.id
    WHERE d.start_date <= :endDate 
      AND d.end_date >= :startDate
";
$stmt = $pdo->prepare($sql);
$stmt->execute([':startDate' => $startDate, ':endDate' => $endDate]);
$count = $stmt->fetchColumn();
echo "<strong>عدد الاقتطاعات التي تتداخل مع مايو 2026 (بدون فلتر مصدر): $count</strong><br>";

// جلب أول 10 سجلات من djezzy للتأكد
$sql2 = "
    SELECT d.id, e.name, s.name as source, d.start_date, d.end_date
    FROM deductions d
    JOIN employees e ON d.employee_id = e.id
    JOIN sources s ON d.source_id = s.id
    WHERE s.name = 'djezzy'
      AND d.start_date <= :endDate 
      AND d.end_date >= :startDate
    LIMIT 10
";
$stmt2 = $pdo->prepare($sql2);
$stmt2->execute([':startDate' => $startDate, ':endDate' => $endDate]);
$djezzy = $stmt2->fetchAll();
echo "<br>🔹 أول 10 اقتطاعات من مصدر djezzy في مايو 2026:<br>";
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>ID</th><th>الموظف</th><th>المصدر</th><th>تاريخ البداية</th><th>تاريخ النهاية</th></tr>";
foreach ($djezzy as $row) {
    echo "<tr><td>{$row['id']}</td><td>{$row['name']}</td><td>{$row['source']}</td><td>{$row['start_date']}</td><td>{$row['end_date']}</td></tr>";
}
echo "</table>";

// عرض محتوى $_GET (للتأكد من أن year و month يصلان)
echo "<br><strong>محتويات \$_GET الحالية:</strong> <pre>" . print_r($_GET, true) . "</pre>";
?>