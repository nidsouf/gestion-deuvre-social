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

// جلب البيانات
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
$records = $stmt->fetchAll();

// إعداد رؤوس CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="rapport_repas_' . $year . '-' . str_pad($month,2,'0',STR_PAD_LEFT) . '.csv"');

$output = fopen('php://output', 'w');
fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

// العناوين
fputcsv($output, [
    'الموظف',
    'الفئة',
    'عدد الوجبات',
    'سعر الوجبة (دج)',
    'المبلغ الإجمالي (دج)',
    'نصف المبلغ (دج)'
], ';');

// البيانات
foreach ($records as $row) {
    $half = $row['total_amount'] / 2;
    fputcsv($output, [
        $row['employee_name'],
        $row['category'] == 'Permanent' ? 'دائم' : 'متعاقد',
        $row['meal_count'],
        number_format($row['price_per_meal'] ?? 25, 2),
        number_format($row['total_amount'], 2),
        number_format($half, 2)
    ], ';');
}

// الإجمالي
$totalMeals = array_sum(array_column($records, 'meal_count'));
$totalAmount = array_sum(array_column($records, 'total_amount'));
$totalHalf = $totalAmount / 2;

fputcsv($output, [], ';');
fputcsv($output, [
    'الإجمالي',
    '',
    $totalMeals,
    '',
    number_format($totalAmount, 2),
    number_format($totalHalf, 2)
], ';');

fclose($output);
exit;
?>