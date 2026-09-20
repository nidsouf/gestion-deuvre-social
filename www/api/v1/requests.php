<?php
/**
 * api/v1/requests.php - طلبات الموظفين
 * 
 * GET  /api/v1/requests.php              → القائمة
 * GET  /api/v1/requests.php?id=5         → طلب واحد
 * POST /api/v1/requests.php              → تقديم طلب جديد
 */

require_once __DIR__ . '/middleware.php';
$auth = apiRequireAuth();

$method = $_SERVER['REQUEST_METHOD'];
$id = (int)apiGet('id', 0);

try {
    if ($method === 'GET') {
        if ($id > 0) {
            $stmt = $pdo->prepare("
                SELECT r.*, e.name as employee_name, g.name as grant_name, s.name as source_name
                FROM requests r
                LEFT JOIN employees e ON r.employee_id = e.id
                LEFT JOIN grants g ON r.grant_id = g.id
                LEFT JOIN sources s ON r.source_id = s.id
                WHERE r.id = ?
            ");
            $stmt->execute([$id]);
            $request = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$request) apiError('الطلب غير موجود', 404);
            
            // التعليقات
            $stmt = $pdo->prepare("
                SELECT c.id, c.comment, c.created_at, u.username
                FROM request_comments c
                LEFT JOIN users u ON c.user_id = u.id
                WHERE c.request_id = ?
                ORDER BY c.created_at ASC
            ");
            $stmt->execute([$id]);
            $request['comments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            apiResponse($request);
        }
        
        // قائمة
        $status = apiGet('status', '');
        $onlyMine = (bool)apiGet('mine', false);
        $page = max(1, (int)apiGet('page', 1));
        $perPage = min(100, max(1, (int)apiGet('per_page', 20)));
        $offset = ($page - 1) * $perPage;
        
        $where = ['1=1'];
        $params = [];
        
        if ($status && $status !== 'all') {
            $where[] = "r.status = ?";
            $params[] = $status;
        }
        
        if ($onlyMine && $auth['employee_id']) {
            $where[] = "r.employee_id = ?";
            $params[] = $auth['employee_id'];
        } else if (!in_array($auth['role'], ['admin', 'manager', 'committee']) && $auth['employee_id']) {
            // الموظف العادي يرى طلباته فقط
            $where[] = "r.employee_id = ?";
            $params[] = $auth['employee_id'];
        }
        
        $whereClause = implode(' AND ', $where);
        
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM requests r WHERE $whereClause");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();
        
        $stmt = $pdo->prepare("
            SELECT r.id, r.request_type, r.title, r.requested_amount, 
                   r.status, r.source, r.created_at, r.executed,
                   e.name as employee_name
            FROM requests r
            LEFT JOIN employees e ON r.employee_id = e.id
            WHERE $whereClause
            ORDER BY r.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $params[] = $perPage;
        $params[] = $offset;
        $stmt->execute($params);
        
        apiResponse([
            'items' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'pagination' => apiPaginate($total, $page, $perPage),
        ]);
    }
    
    elseif ($method === 'POST') {
        $type = apiGet('request_type', '');
        $title = trim(apiGet('title', ''));
        $description = trim(apiGet('description', ''));
        $amount = (float)apiGet('amount', 0);
        $months = (int)apiGet('months', 1);
        $grantId = (int)apiGet('grant_id', 0);
        
        if (!in_array($type, ['loan', 'grant', 'deduction'])) {
            apiError('نوع الطلب غير صالح', 400);
        }
        if (empty($title)) apiError('عنوان الطلب مطلوب', 400);
        if ($amount <= 0) apiError('المبلغ يجب أن يكون موجباً', 400);
        
        $employeeId = $auth['employee_id'] ?? (int)apiGet('employee_id', 0);
        if (!$employeeId) apiError('معرف الموظف مطلوب', 400);
        
        $sourceId = null;
        if ($type === 'loan') $sourceId = 2;
        elseif ($type === 'deduction') $sourceId = 1;
        
        $stmt = $pdo->prepare("
            INSERT INTO requests (
                employee_id, request_type, grant_id, source_id,
                title, description, requested_amount, requested_months,
                requested_date, status, source, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, date('now'), 'pending', 'mobile', datetime('now'))
        ");
        $stmt->execute([
            $employeeId, $type, $grantId ?: null, $sourceId,
            $title, $description, $amount, $months
        ]);
        $newId = $pdo->lastInsertId();
        
        apiLog('REQUEST_CREATED_MOBILE', "id=$newId type=$type amount=$amount", $auth['user_id']);
        
        apiResponse(['id' => $newId], 201, 'تم تقديم الطلب بنجاح');
    }
    
    else {
        apiError('طريقة غير مدعومة', 405);
    }
    
} catch (Exception $e) {
    apiError('خطأ: ' . $e->getMessage(), 500);
}