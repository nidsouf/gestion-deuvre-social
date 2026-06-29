<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';

if (!isset($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
    $_SESSION['toast'] = ['message' => '⚠️ طلب غير مصرح به', 'type' => 'error', 'duration' => 3000];
    header("Location: import_monthly.php");
    exit;
}

if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] != UPLOAD_ERR_OK) {
    $_SESSION['toast'] = ['message' => '❌ يرجى اختيار ملف صحيح', 'type' => 'error', 'duration' => 3000];
    header("Location: import_monthly.php");
    exit;
}

$file = $_FILES['csv_file'];
if (!str_ends_with(strtolower($file['name']), '.csv')) {
    $_SESSION['toast'] = ['message' => '❌ الملف يجب أن يكون بصيغة CSV', 'type' => 'error', 'duration' => 3000];
    header("Location: import_monthly.php");
    exit;
}

$year = (int)$_POST['year'];
$month = (int)$_POST['month'];
$month_year = sprintf("%04d-%02d", $year, $month);

$handle = fopen($file['tmp_name'], 'r');
if (!$handle) {
    $_SESSION['toast'] = ['message' => '❌ فشل قراءة الملف', 'type' => 'error', 'duration' => 3000];
    header("Location: import_monthly.php");
    exit;
}

// ========== إنشاء جدول meal_records إذا لم يكن موجوداً ==========
$pdo->exec("
    CREATE TABLE IF NOT EXISTS meal_records (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        employee_id INTEGER NOT NULL,
        year INTEGER NOT NULL,
        month INTEGER NOT NULL,
        meal_count INTEGER DEFAULT 0,
        total_amount REAL DEFAULT 0,
        price_per_meal REAL DEFAULT 0,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (employee_id) REFERENCES employees(id)
    )
");

// ========== التأكد من وجود عمود price_per_meal ==========
try {
    $pdo->query("SELECT price_per_meal FROM meal_records LIMIT 1");
} catch (PDOException $e) {
    $pdo->exec("ALTER TABLE meal_records ADD COLUMN price_per_meal REAL DEFAULT 0");
}

// قراءة العناوين
$headers = fgetcsv($handle, 0, ';');
if (!$headers) {
    $_SESSION['toast'] = ['message' => '❌ الملف فارغ أو غير صحيح', 'type' => 'error', 'duration' => 3000];
    fclose($handle);
    header("Location: import_monthly.php");
    exit;
}

// تحديد أعمدة التقرير
$map = [];
foreach ($headers as $index => $col) {
    $colClean = trim($col);
    if (strpos($colClean, 'رقم التسجيل') !== false) $map['code'] = $index;
    elseif (strpos($colClean, 'اللقب') !== false) $map['last_name'] = $index;
    elseif (strpos($colClean, 'الاسم') !== false) $map['first_name'] = $index;
    elseif (strpos($colClean, 'النوع') !== false) $map['type'] = $index;
    elseif (strpos($colClean, 'عدد الوجبات') !== false) $map['total_meals'] = $index;
    elseif (strpos($colClean, 'حاضر') !== false) $map['present'] = $index;
    elseif (strpos($colClean, 'غائب') !== false) $map['absent'] = $index;
    elseif (strpos($colClean, 'سعر الوجبة') !== false) $map['price'] = $index;
    elseif (strpos($colClean, 'المبلغ المستحق') !== false) $map['amount'] = $index;
}

if (!isset($map['code']) || !isset($map['total_meals']) || !isset($map['price'])) {
    $_SESSION['toast'] = ['message' => '❌ الملف لا يحتوي على الأعمدة المطلوبة (رقم التسجيل، عدد الوجبات، سعر الوجبة)', 'type' => 'error', 'duration' => 3000];
    fclose($handle);
    header("Location: import_monthly.php");
    exit;
}

$imported = 0;
$updated = 0;
$errors = [];
$notFound = [];

while (($row = fgetcsv($handle, 0, ';')) !== false) {
    if (empty(array_filter($row)) || strpos(implode('', $row), 'الإجمالي العام') !== false) continue;
    
    $code = trim($row[$map['code']] ?? '');
    $total_meals = (int)($row[$map['total_meals']] ?? 0);
    $price = (float)str_replace(',', '.', trim($row[$map['price']] ?? 0));
    $amount = (float)str_replace(',', '.', trim($row[$map['amount']] ?? 0));
    $last_name = trim($row[$map['last_name']] ?? '');
    $first_name = trim($row[$map['first_name']] ?? '');
    
    if (empty($code)) continue;
    
    // 1. البحث عن المستفيد باستخدام code (رقم التسجيل)
    $stmt = $pdo->prepare("SELECT id FROM meal_beneficiaries WHERE code = ?");
    $stmt->execute([$code]);
    $beneficiary = $stmt->fetch();
    
    if (!$beneficiary) {
        // 2. إذا لم يوجد، حاول البحث عن الموظف باستخدام code في جدول employees
        $stmtEmp = $pdo->prepare("SELECT id FROM employees WHERE id = ?");
        $stmtEmp->execute([$code]);
        $employee = $stmtEmp->fetch();
        
        if (!$employee) {
            // 3. إذا لم يوجد، حاول البحث بالاسم واللقب
            $stmtEmp = $pdo->prepare("
                SELECT id FROM employees 
                WHERE name LIKE ? OR name LIKE ? 
                ORDER BY id LIMIT 1
            ");
            $searchName = "%$last_name%$first_name%";
            $searchName2 = "%$first_name%$last_name%";
            $stmtEmp->execute([$searchName, $searchName2]);
            $employee = $stmtEmp->fetch();
            
            if (!$employee) {
                $notFound[] = "رقم $code ($first_name $last_name) غير موجود";
                continue;
            }
        }
        $employee_id = $employee['id'];
    } else {
        // البحث عن الموظف المرتبط بهذا المستفيد
        $stmtEmp = $pdo->prepare("SELECT id FROM employees WHERE id = ?");
        $stmtEmp->execute([$beneficiary['id']]);
        $employee = $stmtEmp->fetch();
        if (!$employee) {
            $notFound[] = "رقم $code ($first_name $last_name) غير مرتبط بموظف";
            continue;
        }
        $employee_id = $employee['id'];
    }
    
    // حساب المبلغ الإجمالي إذا لم يكن موجوداً
    if ($amount == 0 && $total_meals > 0) {
        $amount = $total_meals * $price;
    }
    
    // إدراج أو تحديث
    $stmtCheck = $pdo->prepare("SELECT id FROM meal_records WHERE employee_id = ? AND year = ? AND month = ?");
    $stmtCheck->execute([$employee_id, $year, $month]);
    $exists = $stmtCheck->fetch();
    
    if ($exists) {
        $update = $pdo->prepare("
            UPDATE meal_records 
            SET meal_count = ?, total_amount = ?, price_per_meal = ?, updated_at = CURRENT_TIMESTAMP
            WHERE employee_id = ? AND year = ? AND month = ?
        ");
        $update->execute([$total_meals, $amount, $price, $employee_id, $year, $month]);
        $updated++;
    } else {
        $insert = $pdo->prepare("
            INSERT INTO meal_records (employee_id, year, month, meal_count, total_amount, price_per_meal, created_at)
            VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ");
        $insert->execute([$employee_id, $year, $month, $total_meals, $amount, $price]);
        $imported++;
    }
}
fclose($handle);

// تسجيل العملية
if (function_exists('audit')) {
    audit('MEAL_MONTHLY_IMPORT', "استيراد تقرير وجبات لشهر {$month}/{$year}: {$imported} جديد، {$updated} محدث");
}

$message = "✅ تم استيراد التقرير بنجاح: {$imported} سجل جديد، {$updated} سجل محدث";
if (!empty($notFound)) {
    $message .= " ⚠️ " . count($notFound) . " موظف غير موجود: " . implode(', ', array_slice($notFound, 0, 5));
    if (count($notFound) > 5) $message .= " ...";
}
$_SESSION['toast'] = [
    'message' => $message,
    'type' => 'success',
    'duration' => 6000
];

header("Location: import_monthly.php");
exit;
?>