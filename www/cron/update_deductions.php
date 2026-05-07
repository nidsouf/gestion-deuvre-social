<?php
// cron/update_deductions.php
// قم بتشغيل هذا الملف يدوياً (عبر متصفح أو CLI) أو بواسطة مهمة مجدولة.
// سيحسب الاقتطاعات الشهرية الحالية ويخزنها في جدول monthly_deductions_log.

require_once '../config/database.php';

function logMessage($msg) {
    $logFile = __DIR__ . '/../logs/deductions_cron.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $msg\n", FILE_APPEND);
}

logMessage("بدء التحديث التلقائي للاقتطاعات");

// السنة والشهر الحاليان
$year = date('Y');
$month = date('m');
$year_month = sprintf("%04d-%02d", $year, $month);
$startDate = "$year-$month-01";
$endDate = date('Y-m-t', strtotime($startDate));

// 1. حساب اقتطاعات Djezzy (من جدول employee_phone_numbers)
$stmt = $pdo->prepare("
    SELECT e.id as employee_id, e.name, COALESCE(SUM(epn.monthly_amount), 0) as total_monthly
    FROM employees e
    JOIN employee_phone_numbers epn ON e.id = epn.employee_id AND epn.is_active = 1
    GROUP BY e.id
    HAVING total_monthly > 0
");
$stmt->execute();
$djezzyDeductions = $stmt->fetchAll();

// 2. تسجيل النتائج في جدول monthly_deductions_log (أنشئه أولاً)
$pdo->exec("CREATE TABLE IF NOT EXISTS monthly_deductions_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    year INTEGER NOT NULL,
    month INTEGER NOT NULL,
    employee_id INTEGER NOT NULL,
    deduction_type TEXT NOT NULL,
    amount REAL NOT NULL,
    calculated_at TEXT DEFAULT CURRENT_TIMESTAMP
)");

foreach ($djezzyDeductions as $d) {
    $stmtIns = $pdo->prepare("INSERT INTO monthly_deductions_log (year, month, employee_id, deduction_type, amount) VALUES (?, ?, ?, 'djezzy', ?)");
    $stmtIns->execute([$year, $month, $d['employee_id'], $d['total_monthly']]);
    logMessage("تم تسجيل اقتطاع Djezzy للموظف {$d['name']} - المبلغ: {$d['total_monthly']}");
}

// 3. يمكنك أيضاً حساب الاقتطاعات الأخرى (سعدين، إلخ) إذا أردت.

logMessage("انتهى التحديث التلقائي بنجاح");
echo "تم تحديث الاقتطاعات. راجع logs/deductions_cron.log";
?>