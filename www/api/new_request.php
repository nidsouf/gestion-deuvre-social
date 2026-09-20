<?php
/**
 * api/new_request.php - استقبال طلبات Google Forms
 * نسخة معدلة مع معرفات صحيحة للمفاتيح الخارجية
 */

ini_set('display_errors', 1);
ini_set('log_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/requests_helpers.php';

// ============================================================
// تعريف شرطي لـ auditLog
// ============================================================
if (!function_exists('auditLog')) {
    function auditLog($pdo, $action, $details = null) {
        error_log("AUDIT: $action - $details");
        return true;
    }
}

// ============================================================
// استقبال البيانات
// ============================================================
$input = file_get_contents('php://input');
$data = json_decode($input, true);

error_log("=== new_request.php called ===");
error_log("Input: " . $input);

if (!$data || empty($data['employee_name'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'بيانات غير صالحة']);
    exit;
}

// ============================================================
// دالة قراءة مرنة
// ============================================================
function getFieldValue($data, $fieldNames) {
    foreach ($fieldNames as $name) {
        if (isset($data[$name]) && !empty($data[$name])) {
            return $data[$name];
        }
        foreach ($data as $key => $value) {
            if (strcasecmp(trim($key), trim($name)) === 0) {
                return $value;
            }
        }
    }
    return '';
}

// ============================================================
// قراءة البيانات
// ============================================================
$employeeName = getFieldValue($data, ['الاسم واللقب', 'employee_name', 'name', 'full_name']);
$employeeNumber = getFieldValue($data, ['رقم التأجير', 'employee_number', 'account_number']);
$requestType = getFieldValue($data, ['نوع الطلب', 'request_type', 'type']);
$grantType = getFieldValue($data, ['نوع المنحة', 'grant_type', 'grant']);
$amount = (float) getFieldValue($data, ['المبلغ المطلوب', 'amount', 'value']);
$notes = getFieldValue($data, ['ملاحظات إضافية', 'notes', 'comment']);

error_log("📊 البيانات: اسم=$employeeName, نوع=$requestType, منحة=$grantType, مبلغ=$amount");

// ============================================================
// البحث عن الموظف
// ============================================================
function findEmployee($pdo, $name, $number = '') {
    if (!empty($number)) {
        $stmt = $pdo->prepare("SELECT id, name FROM employees WHERE account_number = ? LIMIT 1");
        $stmt->execute([$number]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result) return $result;
    }
    
    $stmt = $pdo->prepare("SELECT id, name FROM employees WHERE name COLLATE NOCASE = ? LIMIT 1");
    $stmt->execute([$name]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result) return $result;
    
    $stmt = $pdo->prepare("SELECT id, name FROM employees WHERE name LIKE ? COLLATE NOCASE LIMIT 1");
    $stmt->execute(['%' . $name . '%']);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

$employee = findEmployee($pdo, $employeeName, $employeeNumber);
$employeeId = $employee ? $employee['id'] : null;
$employeeName = $employee ? $employee['name'] : $employeeName;

// ============================================================
// تحديد نوع الطلب (بصيغة إنجليزية)
// ============================================================
if ($requestType === 'منحة' || $requestType === 'grant') {
    $requestType = 'grant';
} elseif ($requestType === 'سلفة' || $requestType === 'loan') {
    $requestType = 'loan';
} elseif ($requestType === 'اقتطاع' || $requestType === 'deduction') {
    $requestType = 'deduction';
} else {
    $requestType = 'grant'; // افتراضي
}

// ============================================================
// تحديد grant_id (مع افتراضي موجود)
// ============================================================
$grantId = null;
if ($requestType === 'grant') {
    if (!empty($grantType)) {
        $stmt = $pdo->prepare("SELECT id FROM grants WHERE name LIKE ? LIMIT 1");
        $stmt->execute(['%' . $grantType . '%']);
        $grant = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($grant) {
            $grantId = $grant['id'];
        }
    }
    // إذا لم يتم العثور، استخدم معرف موجود (مثلاً 21 = منحة تحاليل طبية)
    if (!$grantId) {
        $grantId = 9; // تأكد من وجود هذا المعرف في جدول grants
    }
}

// ============================================================
// تحديد source_id (مع افتراضي موجود)
// ============================================================
$sourceId = null;
if ($requestType === 'loan') {
    $sourceId = 2; // سلفيات (موجود في sources)
} elseif ($requestType === 'deduction') {
    $sourceId = 1; // سعدين للتجهير (موجود في sources)
}

error_log("✅ grant_id=$grantId, source_id=$sourceId");

// ============================================================
// التأكد من وجود الأعمدة المطلوبة
// ============================================================
try {
    $pdo->exec("ALTER TABLE requests ADD COLUMN employee_name TEXT");
    $pdo->exec("ALTER TABLE requests ADD COLUMN employee_number TEXT");
} catch (Exception $e) {
    // الأعمدة موجودة بالفعل
}

// ============================================================
// إدراج الطلب
// ============================================================
try {
    $stmt = $pdo->prepare("
        INSERT INTO requests (
            employee_id,
            employee_name,
            employee_number,
            request_type,
            grant_id,
            source_id,
            title,
            description,
            requested_amount,
            requested_months,
            requested_date,
            status,
            source,
            created_at
        ) VALUES (
            :employee_id,
            :employee_name,
            :employee_number,
            :request_type,
            :grant_id,
            :source_id,
            :title,
            :description,
            :requested_amount,
            :requested_months,
            :requested_date,
            'pending',
            'google_form',
            datetime('now')
        )
    ");

    $title = 'طلب من Google Forms: ' . ($grantType ?: $requestType);

    $stmt->execute([
        ':employee_id' => $employeeId,
        ':employee_name' => $employeeName,
        ':employee_number' => $employeeNumber,
        ':request_type' => $requestType,
        ':grant_id' => $grantId,
        ':source_id' => $sourceId,
        ':title' => $title,
        ':description' => $notes,
        ':requested_amount' => $amount,
        ':requested_months' => ($requestType === 'grant') ? 0 : 1,
        ':requested_date' => date('Y-m-d')
    ]);

    $requestId = $pdo->lastInsertId();

    // إضافة تعليق تلقائي
    $stmt = $pdo->prepare("
        INSERT INTO request_comments (request_id, user_id, comment)
        VALUES (?, ?, ?)
    ");
    $stmt->execute([
        $requestId,
        null,
        'تم استلام الطلب من Google Forms. ' . ($employeeId ? "الموظف: $employeeName" : "⚠️ موظف غير معروف")
    ]);

    auditLog($pdo, 'REQUEST_RECEIVED', "طلب جديد من Google Forms (ID: $requestId)");

    echo json_encode([
        'success' => true,
        'message' => 'تم استلام الطلب بنجاح',
        'request_id' => $requestId,
        'grant_id' => $grantId,
        'source_id' => $sourceId
    ]);

} catch (Exception $e) {
    error_log("❌ خطأ: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}