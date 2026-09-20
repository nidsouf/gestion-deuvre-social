<?php
/**
 * api/v1/deductions.php - الاقتطاعات
 * 
 * GET  /api/v1/deductions.php                    → القائمة
 * GET  /api/v1/deductions.php?id=5               → واحد
 * GET  /api/v1/deductions.php?employee_id=10     → اقتطاعات موظف
 * GET  /api/v1/deductions.php?id=5&installments=1 → مع الأقساط
 */

require_once __DIR__ . '/middleware.php';
$auth = apiRequireAuth();

$method = $_SERVER['REQUEST_METHOD'];
$id = (int)apiGet('id', 0);

try {
    if ($method !== 'GET') {
        apiError('هذا الـ endpoint للقراءة فقط حالياً', 405);
    }
    
    if ($id > 0) {
        $stmt = $pdo->prepare("
            SELECT d.*, 
                   e.name as employee_name, e.account_number,
                   s.name as source_name
            FROM deductions d
            LEFT JOIN employees e ON d.employee_id = e.id
            LEFT JOIN sources s ON d.source_id = s.id
            WHERE d.id = ?
        ");
        $stmt->execute([$id]);
        $deduction = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$deduction) apiError('الاقتطاع غير موجود', 404);
        
        // الأقساط إن طُلبت
        if (apiGet('installments')) {
            $stmt = $pdo->prepare("
                SELECT id, year, month, amount, is_paid, is_postponed
                FROM monthly_installments
                WHERE deduction_id = ?
                ORDER BY year, month
            ");
            $stmt->execute([$id]);
            $deduction['installments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // إحصائيات
            $deduction['stats'] = [
                'total' => count($deduction['installments']),
                'paid' => count(array_filter($deduction['installments'], fn($i) => $i['is_paid'])),
                'remaining' => count(array_filter($deduction['installments'], fn($i) => !$i['is_paid'])),
            ];
        }
        
        apiResponse($deduction);
    }
    
    // قائمة
    $employeeId = (int)apiGet('employee_id', 0);
    $isActive = apiGet('active', '');
    $page = max(1, (int)apiGet('page', 1));
    $perPage = min(100, max(1, (int)apiGet('per_page', 20)));
    $offset = ($page - 1) * $perPage;
    
    $where = ['1=1'];
    $params = [];
    
    if ($employeeId > 0) {
        $where[] = "d.employee_id = ?";
        $params[] = $employeeId;
    }
    
    if ($isActive === '1') {
        $where[] = "d.end_date >= date('now')";
    }
    
    $whereClause = implode(' AND ', $where);
    
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM deductions d WHERE $whereClause");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
    
    $stmt = $pdo->prepare("
        SELECT d.id, d.employee_id, d.source_id, d.monthly_amount, 
               d.total_months, d.start_date, d.end_date, d.is_loan,
               e.name as employee_name, s.name as source_name,
               (SELECT COUNT(*) FROM monthly_installments WHERE deduction_id = d.id AND is_paid = 1) as paid_count,
               (SELECT COUNT(*) FROM monthly_installments WHERE deduction_id = d.id AND is_paid = 0) as remaining_count
        FROM deductions d
        LEFT JOIN employees e ON d.employee_id = e.id
        LEFT JOIN sources s ON d.source_id = s.id
        WHERE $whereClause
        ORDER BY d.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $params[] = $perPage;
    $params[] = $offset;
    $stmt->execute($params);
    
    apiResponse([
        'items' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'pagination' => apiPaginate($total, $page, $perPage),
    ]);
    
} catch (Exception $e) {
    apiError('خطأ: ' . $e->getMessage(), 500);
}