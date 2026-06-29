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
    header("Location: import.php");
    exit;
}

if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] != UPLOAD_ERR_OK) {
    $_SESSION['toast'] = ['message' => '❌ يرجى اختيار ملف صحيح', 'type' => 'error', 'duration' => 3000];
    header("Location: import.php");
    exit;
}

$file = $_FILES['csv_file'];
$extension = pathinfo($file['name'], PATHINFO_EXTENSION);
if (strtolower($extension) != 'csv') {
    $_SESSION['toast'] = ['message' => '❌ الملف يجب أن يكون بصيغة CSV', 'type' => 'error', 'duration' => 3000];
    header("Location: import.php");
    exit;
}

$handle = fopen($file['tmp_name'], 'r');
if (!$handle) {
    $_SESSION['toast'] = ['message' => '❌ فشل قراءة الملف', 'type' => 'error', 'duration' => 3000];
    header("Location: import.php");
    exit;
}

$headers = fgetcsv($handle, 0, ',');
if (!$headers) {
    $_SESSION['toast'] = ['message' => '❌ الملف فارغ أو غير صحيح', 'type' => 'error', 'duration' => 3000];
    fclose($handle);
    header("Location: import.php");
    exit;
}

$expectedColumns = ['code', 'last_name', 'first_name', 'type', 'price'];
$columnMap = [];
foreach ($headers as $index => $col) {
    $colClean = trim($col);
    if (strpos($colClean, 'رقم') !== false || strpos($colClean, 'code') !== false) $columnMap['code'] = $index;
    elseif (strpos($colClean, 'لقب') !== false || strpos($colClean, 'nom') !== false || strpos($colClean, 'last') !== false) $columnMap['last_name'] = $index;
    elseif (strpos($colClean, 'اسم') !== false || strpos($colClean, 'prenom') !== false || strpos($colClean, 'first') !== false) $columnMap['first_name'] = $index;
    elseif (strpos($colClean, 'نوع') !== false || strpos($colClean, 'type') !== false) $columnMap['type'] = $index;
    elseif (strpos($colClean, 'سعر') !== false || strpos($colClean, 'price') !== false) $columnMap['price'] = $index;
}

if (!isset($columnMap['code']) || !isset($columnMap['last_name']) || !isset($columnMap['first_name'])) {
    $_SESSION['toast'] = ['message' => '❌ الملف لا يحتوي على الأعمدة المطلوبة', 'type' => 'error', 'duration' => 3000];
    fclose($handle);
    header("Location: import.php");
    exit;
}

$imported = 0;
$updated = 0;
$beneficiaries = [];

while (($row = fgetcsv($handle, 0, ',')) !== false) {
    if (empty(array_filter($row))) continue;
    
    $code = trim($row[$columnMap['code']] ?? '');
    $last_name = trim($row[$columnMap['last_name']] ?? '');
    $first_name = trim($row[$columnMap['first_name']] ?? '');
    $type = isset($columnMap['type']) ? trim($row[$columnMap['type']]) : 'موظف';
    $price = isset($columnMap['price']) ? (float)str_replace(',', '.', trim($row[$columnMap['price']])) : 250;
    
    if (empty($code) || empty($last_name) || empty($first_name)) continue;
    
    $category = ($type == 'متربص' || $type == 'trainee') ? 'trainee' : 'employee';
    
    $beneficiaries[] = [
        'code' => $code,
        'last_name' => $last_name,
        'first_name' => $first_name,
        'type' => $category,
        'price' => $price
    ];
}
fclose($handle);

if (empty($beneficiaries)) {
    $_SESSION['toast'] = ['message' => '⚠️ لم يتم العثور على بيانات صالحة', 'type' => 'warning', 'duration' => 3000];
    header("Location: import.php");
    exit;
}

try {
    $pdo->beginTransaction();
    
    foreach ($beneficiaries as $ben) {
        $stmt = $pdo->prepare("SELECT id FROM meal_beneficiaries WHERE code = ?");
        $stmt->execute([$ben['code']]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            $update = $pdo->prepare("
                UPDATE meal_beneficiaries 
                SET last_name = ?, first_name = ?, type = ?, price_per_meal = ?, is_active = 1, updated_at = datetime('now')
                WHERE id = ?
            ");
            $update->execute([$ben['last_name'], $ben['first_name'], $ben['type'], $ben['price'], $existing['id']]);
            $updated++;
        } else {
            $insert = $pdo->prepare("
                INSERT INTO meal_beneficiaries (code, last_name, first_name, type, price_per_meal, is_active, created_at)
                VALUES (?, ?, ?, ?, ?, 1, datetime('now'))
            ");
            $insert->execute([$ben['code'], $ben['last_name'], $ben['first_name'], $ben['type'], $ben['price']]);
            $imported++;
        }
    }
    
    $pdo->commit();
    
    if (function_exists('audit')) {
        audit('MEAL_IMPORT', "استيراد {$imported} مستفيد جديد وتحديث {$updated} مستفيد من CSV");
    }
    
    $_SESSION['toast'] = [
        'message' => "✅ تم الاستيراد بنجاح: {$imported} جديد، {$updated} محدث",
        'type' => 'success',
        'duration' => 5000
    ];
} catch (Exception $e) {
    $pdo->rollBack();
    $_SESSION['toast'] = ['message' => '❌ خطأ: ' . $e->getMessage(), 'type' => 'error', 'duration' => 5000];
}

header("Location: import.php");
exit;
?>