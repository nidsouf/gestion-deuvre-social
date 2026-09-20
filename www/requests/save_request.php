<?php
/**
 * requests/save_request.php - حفظ طلب جديد
 */
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';
require_once '../includes/requests_helpers.php';

// ============================================================
// التحقق من طريقة الطلب
// ============================================================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

// ============================================================
// التحقق من CSRF
// ============================================================
if (function_exists('requireCSRFToken')) {
    requireCSRFToken();
}

// ============================================================
// جلب البيانات من POST
// ============================================================
$employeeId  = isset($_POST['employee_id'])     ? (int)$_POST['employee_id']            : 0;
$type        = isset($_POST['request_type'])    ? trim($_POST['request_type'])           : '';
$grantId     = isset($_POST['grant_id']) && (int)$_POST['grant_id'] > 0 
                ? (int)$_POST['grant_id'] 
                : null;   // ✅ التصحيح هنا
$title       = isset($_POST['title'])           ? trim($_POST['title'])                  : '';
$description = isset($_POST['description'])     ? trim($_POST['description'])            : '';
$amount      = isset($_POST['requested_amount'])? (float)$_POST['requested_amount']      : 0;
$months      = isset($_POST['requested_months'])? (int)$_POST['requested_months']        : 1;
$requestDate = isset($_POST['request_date'])    ? $_POST['request_date']                 : date('Y-m-d');

// ============================================================
// التحقق من الصلاحية
// ============================================================
$userRole       = $_SESSION['role'] ?? 'employee';
$userEmployeeId = $_SESSION['employee_id'] ?? 0;

if (!in_array($userRole, ['admin', 'manager', 'committee']) && ($employeeId != $userEmployeeId)) {
    if (function_exists('setToast')) {
        setToast('⚠️ غير مسموح لك بتقديم طلب لموظف آخر', 'warning');
    } else {
        $_SESSION['toast'] = ['message' => '⚠️ غير مسموح لك بتقديم طلب لموظف آخر', 'type' => 'warning', 'duration' => 3000];
    }
    header('Location: add.php');
    exit;
}

// ============================================================
// التحقق من صحة البيانات
// ============================================================
$errors = [];
if (!$employeeId) $errors[] = 'الموظف مطلوب';
if (!in_array($type, ['loan', 'grant', 'deduction'])) $errors[] = 'نوع الطلب غير صحيح';
if ($type === 'grant' && !$grantId) $errors[] = 'نوع المنحة مطلوب';
if (empty($title)) $errors[] = 'عنوان الطلب مطلوب';
if ($amount <= 0) $errors[] = 'المبلغ يجب أن يكون موجباً';
if (($type === 'loan' || $type === 'deduction') && $months <= 0) $errors[] = 'عدد الأشهر مطلوب';

if (!empty($errors)) {
    $errorMsg = '⚠️ ' . implode(' - ', $errors);
    if (function_exists('setToast')) {
        setToast($errorMsg, 'warning');
    } else {
        $_SESSION['toast'] = ['message' => $errorMsg, 'type' => 'warning', 'duration' => 3000];
    }
    header('Location: add.php');
    exit;
}

// ============================================================
// تعيين المصدر الافتراضي
// ============================================================
$sourceId = null;
if ($type === 'loan') $sourceId = 2;
elseif ($type === 'deduction') $sourceId = 1;
// للمنحة: sourceId يبقى null (لا يوجد مصدر دفع محدد)

// ============================================================
// إدراج الطلب في قاعدة البيانات
// ============================================================
try {
    $stmt = $pdo->prepare("
        INSERT INTO requests (
            employee_id, request_type, grant_id, source_id,
            title, description, requested_amount, requested_months,
            requested_date, status, source, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'manual', datetime('now'))
    ");
    $stmt->execute([
        $employeeId, $type, $grantId, $sourceId,
        $title, $description, $amount, $months, $requestDate
    ]);

    $requestId = $pdo->lastInsertId();

    if (function_exists('auditLog')) {
        auditLog($pdo, 'REQUEST_CREATED', "طلب جديد: $title (رقم $requestId)");
    }

    if (function_exists('setToast')) {
        setToast('✅ تم تقديم الطلب بنجاح', 'success');
    } else {
        $_SESSION['toast'] = ['message' => '✅ تم تقديم الطلب بنجاح', 'type' => 'success', 'duration' => 3000];
    }

    header('Location: index.php');
    exit;

} catch (Exception $e) {
    $errorMsg = '❌ حدث خطأ: ' . $e->getMessage();
    if (function_exists('setToast')) {
        setToast($errorMsg, 'error');
    } else {
        $_SESSION['toast'] = ['message' => $errorMsg, 'type' => 'error', 'duration' => 3000];
    }
    header('Location: add.php');
    exit;
}