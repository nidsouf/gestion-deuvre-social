<?php
/**
 * api/v1/employees.php - الموظفون
 * 
 * GET    /api/v1/employees.php            → القائمة
 * GET    /api/v1/employees.php?id=5       → موظف واحد
 * 
 * POST   /api/v1/employees.php            → إضافة (admin/manager)
 * PUT    /api/v1/employees.php?id=5       → تعديل (admin/manager)
 * DELETE /api/v1/employees.php?id=5       → حذف (admin)
 */

require_once __DIR__ . '/middleware.php';
$auth = apiRequireAuth();

$method = $_SERVER['REQUEST_METHOD'];
$id = (int)(apiGet('id', 0));

try {
    // ============================================================
    // GET: قائمة أو موظف واحد
    // ============================================================
    if ($method === 'GET') {
        if ($id > 0) {
            $stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
            $stmt->execute([$id]);
            $employee = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$employee) apiError('الموظف غير موجود', 404);
            
            // إحصائيات الموظف
            $stmt = $pdo->prepare("
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN is_paid = 1 THEN 1 ELSE 0 END) as paid,
                    SUM(CASE WHEN is_paid = 0 AND is_postponed = 0 THEN 1 ELSE 0 END) as pending
                FROM monthly_installments
                WHERE employee_id = ?
            ");
            $stmt->execute([$id]);
            $employee['stats'] = $stmt->fetch(PDO::FETCH_ASSOC);
            
            apiResponse($employee);
        }
        
        // قائمة مع بحث وترقيم
        $search = trim(apiGet('search', ''));
        $page = max(1, (int)apiGet('page', 1));
        $perPage = min(100, max(1, (int)apiGet('per_page', 20)));
        $offset = ($page - 1) * $perPage;
        
        $where = ['1=1'];
        $params = [];
        
        if ($search) {
            $where[] = "(name LIKE ? OR account_number LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        
        $whereClause = implode(' AND ', $where);
        
        // العد
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE $whereClause");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();
        
        // البيانات
        $stmt = $pdo->prepare("
            SELECT id, name, account_number, category, hire_date, meal_allowance
            FROM employees 
            WHERE $whereClause
            ORDER BY name
            LIMIT ? OFFSET ?
        ");
        $params[] = $perPage;
        $params[] = $offset;
        $stmt->execute($params);
        $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        apiResponse([
            'items' => $employees,
            'pagination' => apiPaginate($total, $page, $perPage),
        ]);
    }
    
    // ============================================================
    // POST: إضافة موظف
    // ============================================================
    elseif ($method === 'POST') {
        apiRequireRole($auth, ['admin', 'manager']);
        
        $name = trim(apiGet('name', ''));
        $accountNumber = trim(apiGet('account_number', ''));
        $category = apiGet('category', 'Contract');
        $hireDate = apiGet('hire_date', date('Y-m-d'));
        $mealAllowance = (float)apiGet('meal_allowance', 0);
        
        if (empty($name)) apiError('اسم الموظف مطلوب', 400);
        
        $stmt = $pdo->prepare("
            INSERT INTO employees (name, account_number, category, hire_date, meal_allowance)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$name, $accountNumber, $category, $hireDate, $mealAllowance]);
        $newId = $pdo->lastInsertId();
        
        apiLog('EMPLOYEE_CREATED', "id=$newId name=$name", $auth['user_id']);
        apiResponse(['id' => $newId], 201, 'تم إضافة الموظف بنجاح');
    }
    
    // ============================================================
    // PUT: تعديل موظف
    // ============================================================
    elseif ($method === 'PUT') {
        apiRequireRole($auth, ['admin', 'manager']);
        if (!$id) apiError('معرف الموظف مطلوب', 400);
        
        $fields = [];
        $params = [];
        
        foreach (['name', 'account_number', 'category', 'hire_date', 'meal_allowance'] as $f) {
            $val = apiGet($f);
            if ($val !== null) {
                $fields[] = "$f = ?";
                $params[] = $val;
            }
        }
        
        if (empty($fields)) apiError('لا توجد حقول للتعديل', 400);
        
        $params[] = $id;
        $stmt = $pdo->prepare("UPDATE employees SET " . implode(', ', $fields) . " WHERE id = ?");
        $stmt->execute($params);
        
        apiLog('EMPLOYEE_UPDATED', "id=$id", $auth['user_id']);
        apiResponse(null, 200, 'تم تحديث الموظف');
    }
    
    // ============================================================
    // DELETE: حذف موظف
    // ============================================================
    elseif ($method === 'DELETE') {
        apiRequireRole($auth, ['admin']);
        if (!$id) apiError('معرف الموظف مطلوب', 400);
        
        // التحقق من عدم وجود اقتطاعات
        $check = $pdo->prepare("SELECT COUNT(*) FROM deductions WHERE employee_id = ?");
        $check->execute([$id]);
        if ($check->fetchColumn() > 0) {
            apiError('لا يمكن حذف الموظف لوجود اقتطاعات مرتبطة', 409, 'HAS_DEDUCTIONS');
        }
        
        $stmt = $pdo->prepare("DELETE FROM employees WHERE id = ?");
        $stmt->execute([$id]);
        
        apiLog('EMPLOYEE_DELETED', "id=$id", $auth['user_id']);
        apiResponse(null, 200, 'تم حذف الموظف');
    }
    
    else {
        apiError('طريقة غير مدعومة', 405);
    }
    
} catch (Exception $e) {
    apiLog('EMPLOYEES_ERROR', $e->getMessage());
    apiError('حدث خطأ: ' . $e->getMessage(), 500);
}