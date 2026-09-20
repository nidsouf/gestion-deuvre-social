<?php
/**
 * employees/phone_numbers/delete.php - حذف رقم هاتف نهائياً
 */

session_start();
require_once '../../includes/auth_check.php';
require_once '../../config/database.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';
require_once '../../includes/employee_phone_helpers.php';

if (!in_array($_SESSION['role'] ?? '', ['admin', 'manager', 'committee'])) {
    setToast('⚠️ غير مصرح لك بهذه الصفحة', 'warning');
    header('Location: index.php');
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    setToast('⚠️ رقم الهاتف غير صالح', 'warning');
    header('Location: index.php');
    exit;
}

try {
    $pdo->beginTransaction();
    
    // جلب employee_id قبل الحذف
    $stmtEmp = $pdo->prepare("SELECT employee_id FROM employee_phone_numbers WHERE id = ?");
    $stmtEmp->execute([$id]);
    $empId = $stmtEmp->fetchColumn();
    
    // حذف الرقم
    $stmt = $pdo->prepare("DELETE FROM employee_phone_numbers WHERE id = ?");
    $stmt->execute([$id]);
    
    // مزامنة اقتطاع الهاتف للموظف (سيتم حذف الاقتطاع إن لم يتبقَ أرقام)
    if ($empId) {
        syncEmployeePhoneDeduction($pdo, $empId);
    }
    
    auditLog($pdo, 'PHONE_DELETED', "حذف رقم هاتف ID: $id");
    $pdo->commit();
    setToast('✅ تم حذف الرقم وتحديث الاقتطاع بنجاح', 'success');
} catch (Exception $e) {
    $pdo->rollBack();
    setToast('❌ حدث خطأ أثناء الحذف: ' . $e->getMessage(), 'error');
}

header('Location: index.php');
exit;