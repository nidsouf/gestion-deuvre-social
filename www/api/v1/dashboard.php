<?php
/**
 * api/v1/dashboard.php - إحصائيات لوحة التحكم
 * GET /api/v1/dashboard.php?year=2026
 */

require_once __DIR__ . '/middleware.php';
$auth = apiRequireAuth();

$year = (int)(apiGet('year', date('Y')));

try {
    // إحصائيات سريعة
    $stmt = $pdo->prepare("
        SELECT
            (SELECT COUNT(*) FROM employees) AS total_employees,
            (SELECT COUNT(*) FROM deductions) AS total_deductions,
            (SELECT COUNT(*) FROM deductions WHERE is_loan = 1 AND end_date >= date('now')) AS active_loans,
            (SELECT COALESCE(SUM(monthly_amount), 0) FROM deductions WHERE end_date >= date('now')) AS total_regular_monthly,
            (SELECT COALESCE(SUM(monthly_amount), 0) FROM employee_phone_numbers WHERE is_active = 1) AS total_phones
    ");
    $stmt->execute();
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // الميزانية
    $stmt = $pdo->prepare("SELECT initial_budget, remaining_budget FROM social_budget WHERE year = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$year]);
    $budget = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['initial_budget' => 0, 'remaining_budget' => 0];
    
    // الطلبات المعلقة
    $pendingRequests = 0;
    try {
        $pendingRequests = $pdo->query("SELECT COUNT(*) FROM requests WHERE status = 'pending'")->fetchColumn();
    } catch (Exception $e) {}
    
    // الإشعارات غير المقروءة
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE (user_id IS NULL OR user_id = ?) AND is_read = 0");
    $stmt->execute([$auth['user_id']]);
    $unreadNotifications = (int)$stmt->fetchColumn();
    
    apiResponse([
        'year' => $year,
        'stats' => [
            'employees' => (int)$stats['total_employees'],
            'deductions' => (int)$stats['total_deductions'],
            'active_loans' => (int)$stats['active_loans'],
            'monthly_deductions' => (float)$stats['total_regular_monthly'] + (float)$stats['total_phones'],
            'pending_requests' => (int)$pendingRequests,
            'unread_notifications' => $unreadNotifications,
        ],
        'budget' => [
            'initial' => (float)$budget['initial_budget'],
            'remaining' => (float)$budget['remaining_budget'],
            'spent' => (float)$budget['initial_budget'] - (float)$budget['remaining_budget'],
            'spent_percent' => $budget['initial_budget'] > 0 
                ? round((($budget['initial_budget'] - $budget['remaining_budget']) / $budget['initial_budget']) * 100, 1) 
                : 0,
        ],
    ]);
    
} catch (Exception $e) {
    apiError('فشل جلب البيانات: ' . $e->getMessage(), 500);
}