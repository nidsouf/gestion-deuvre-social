<?php
/**
 * employee_phone_helpers.php - دوال مساعدة لوحدة أرقام الهواتف
 */

/**
 * جلب قائمة أرقام الهواتف مع بيانات الموظفين
 */
function getEmployeePhoneNumbers($pdo, $filters = [], $limit = 50, $offset = 0) {
    $where = [];
    $params = [];

    if (!empty($filters['employee_id'])) {
        $where[] = "ep.employee_id = ?";
        $params[] = $filters['employee_id'];
    }
    if (!empty($filters['search'])) {
        $where[] = "(e.name LIKE ? OR ep.phone_number LIKE ?)";
        $search = '%' . $filters['search'] . '%';
        $params[] = $search;
        $params[] = $search;
    }
    if (isset($filters['is_active']) && $filters['is_active'] !== '') {
        $where[] = "ep.is_active = ?";
        $params[] = (int)$filters['is_active'];
    }

    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $sql = "
        SELECT 
            ep.*,
            e.name as employee_name,
            e.account_number,
            e.category
        FROM employee_phone_numbers ep
        JOIN employees e ON ep.employee_id = e.id
        $whereClause
        ORDER BY e.name ASC, ep.phone_number ASC
        LIMIT ? OFFSET ?
    ";
    $params[] = $limit;
    $params[] = $offset;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * عدد أرقام الهواتف (للباجيناشن)
 */
function countEmployeePhoneNumbers($pdo, $filters = []) {
    $where = [];
    $params = [];

    if (!empty($filters['employee_id'])) {
        $where[] = "ep.employee_id = ?";
        $params[] = $filters['employee_id'];
    }
    if (!empty($filters['search'])) {
        $where[] = "(e.name LIKE ? OR ep.phone_number LIKE ?)";
        $search = '%' . $filters['search'] . '%';
        $params[] = $search;
        $params[] = $search;
    }
    if (isset($filters['is_active']) && $filters['is_active'] !== '') {
        $where[] = "ep.is_active = ?";
        $params[] = (int)$filters['is_active'];
    }

    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $sql = "
        SELECT COUNT(*) 
        FROM employee_phone_numbers ep
        JOIN employees e ON ep.employee_id = e.id
        $whereClause
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

/**
 * جلب تفاصيل رقم هاتف محدد
 */
function getEmployeePhoneDetails($pdo, $id) {
    $stmt = $pdo->prepare("
        SELECT ep.*, e.name as employee_name, e.account_number, e.category
        FROM employee_phone_numbers ep
        JOIN employees e ON ep.employee_id = e.id
        WHERE ep.id = ?
    ");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * إضافة رقم هاتف جديد
 */
function addEmployeePhone($pdo, $employeeId, $phoneNumber, $monthlyAmount) {
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO employee_phone_numbers (employee_id, phone_number, monthly_amount, created_at, updated_at)
            VALUES (?, ?, ?, datetime('now'), datetime('now'))
        ");
        $stmt->execute([$employeeId, $phoneNumber, $monthlyAmount]);

        // تسجيل في سجل التدقيق
        auditLog($pdo, 'PHONE_ADDED', "إضافة رقم هاتف للموظف ID: $employeeId - الرقم: $phoneNumber");

        $pdo->commit();
        return $pdo->lastInsertId();
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("addEmployeePhone error: " . $e->getMessage());
        return false;
    }
}

/**
 * تحديث رقم هاتف
 */
function updateEmployeePhone($pdo, $id, $employeeId, $phoneNumber, $monthlyAmount, $isActive) {
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            UPDATE employee_phone_numbers 
            SET employee_id = ?, 
                phone_number = ?, 
                monthly_amount = ?, 
                is_active = ?,
                updated_at = datetime('now')
            WHERE id = ?
        ");
        $stmt->execute([$employeeId, $phoneNumber, $monthlyAmount, $isActive, $id]);

        auditLog($pdo, 'PHONE_UPDATED', "تحديث رقم هاتف ID: $id");

        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("updateEmployeePhone error: " . $e->getMessage());
        return false;
    }
}

/**
 * إلغاء تنشيط رقم هاتف (حذف ناعم)
 */
function deactivateEmployeePhone($pdo, $id) {
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            UPDATE employee_phone_numbers 
            SET is_active = 0, updated_at = datetime('now')
            WHERE id = ?
        ");
        $stmt->execute([$id]);

        auditLog($pdo, 'PHONE_DEACTIVATED', "إلغاء تنشيط رقم هاتف ID: $id");

        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("deactivateEmployeePhone error: " . $e->getMessage());
        return false;
    }
}

/**
 * حذف دائم لرقم هاتف
 */
function deleteEmployeePhone($pdo, $id) {
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("DELETE FROM employee_phone_numbers WHERE id = ?");
        $stmt->execute([$id]);

        auditLog($pdo, 'PHONE_DELETED', "حذف رقم هاتف ID: $id");

        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("deleteEmployeePhone error: " . $e->getMessage());
        return false;
    }
}

/**
 * الحصول على إحصائيات أرقام الهواتف
 */
function getEmployeePhoneStats($pdo) {
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive,
            SUM(monthly_amount) as total_monthly
        FROM employee_phone_numbers
    ");
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * الحصول على أرقام نشطة لموظف معين
 */
function getActivePhonesByEmployee($pdo, $employeeId) {
    $stmt = $pdo->prepare("
        SELECT * FROM employee_phone_numbers
        WHERE employee_id = ? AND is_active = 1
        ORDER BY phone_number
    ");
    $stmt->execute([$employeeId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * الحصول على إجمالي المبلغ الشهري لكل موظف (تجميع الأرقام النشطة)
 */
function getEmployeePhoneTotal($pdo, $employeeId) {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(monthly_amount), 0) as total
        FROM employee_phone_numbers
        WHERE employee_id = ? AND is_active = 1
    ");
    $stmt->execute([$employeeId]);
    return (float)$stmt->fetchColumn();
}

/**
 * الحصول على قائمة الموظفين الذين لديهم أرقام نشطة مع إجمالي المبالغ
 */
function getEmployeesWithActivePhones($pdo) {
    $stmt = $pdo->query("
        SELECT 
            e.id,
            e.name,
            COALESCE(SUM(ep.monthly_amount), 0) as total_monthly
        FROM employees e
        JOIN employee_phone_numbers ep ON e.id = ep.employee_id
        WHERE ep.is_active = 1
        GROUP BY e.id
        HAVING total_monthly > 0
        ORDER BY e.name
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * مزامنة اقتطاع الهاتف للموظف
 * - يحذف الاقتطاع القديم (إن وجد) وينشئ واحداً جديداً بمجموع الأرقام النشطة
 * - يُستخدم عند إضافة/تعديل/تفعيل/إلغاء تفعيل أي رقم
 */
function syncEmployeePhoneDeduction($pdo, $employeeId) {
    // 1. حساب إجمالي مبالغ الأرقام النشطة لهذا الموظف
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(monthly_amount), 0) as total
        FROM employee_phone_numbers
        WHERE employee_id = ? AND is_active = 1
    ");
    $stmt->execute([$employeeId]);
    $total = (float)$stmt->fetchColumn();

    // 2. البحث عن اقتطاع الهاتف الحالي (source_id = 999)
    $stmt = $pdo->prepare("
        SELECT id, monthly_amount FROM deductions
        WHERE employee_id = ? AND source_id = 999
    ");
    $stmt->execute([$employeeId]);
    $existing = $stmt->fetch();

    if ($total > 0) {
        if ($existing) {
            // تحديث المبلغ فقط
            $stmt = $pdo->prepare("
                UPDATE deductions 
                SET monthly_amount = ?, updated_at = datetime('now')
                WHERE id = ?
            ");
            $stmt->execute([$total, $existing['id']]);
            return $existing['id'];
        } else {
            // إنشاء اقتطاع جديد (end_date = 2099-12-31 لتجنب NULL)
            $stmt = $pdo->prepare("
                INSERT INTO deductions (
                    employee_id, source_id, monthly_amount, total_months,
                    start_date, end_date, is_loan, created_at
                ) VALUES (?, 999, ?, 0, date('now'), '2099-12-31', 0, datetime('now'))
            ");
            $stmt->execute([$employeeId, $total]);
            return $pdo->lastInsertId();
        }
    } else {
        // لا يوجد أرقام نشطة -> حذف الاقتطاع إن وجد
        if ($existing) {
            // حذف الأقساط المستقبلية غير المدفوعة لهذا الاقتطاع
            $stmt = $pdo->prepare("
                DELETE FROM monthly_installments 
                WHERE deduction_id = ? AND is_paid = 0
            ");
            $stmt->execute([$existing['id']]);
            
            // حذف الاقتطاع نفسه
            $stmt = $pdo->prepare("DELETE FROM deductions WHERE id = ?");
            $stmt->execute([$existing['id']]);
        }
        return null;
    }
}