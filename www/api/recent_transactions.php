<?php
/**
 * api/recent_transactions.php - جلب آخر المعاملات (JSON)
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'غير مصرح']);
    exit;
}

$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

try {
    $stmt = $pdo->prepare("
        SELECT bt.*,
               CASE bt.type
                   WHEN 'grant'       THEN 'منحة'
                   WHEN 'loan'        THEN 'سلفة'
                   WHEN 'installment' THEN 'قسط مردود'
                   WHEN 'payment'     THEN 'شيك'
                   ELSE bt.type
               END AS type_ar,
               CASE bt.type
                   WHEN 'grant'       THEN 'badge-grant'
                   WHEN 'loan'        THEN 'badge-loan'
                   WHEN 'installment' THEN 'badge-installment'
                   WHEN 'payment'     THEN 'badge-payment'
                   ELSE 'badge-default'
               END AS type_class
        FROM budget_transactions bt
        WHERE strftime('%Y', bt.transaction_date) = :year
        ORDER BY bt.transaction_date DESC
        LIMIT 10
    ");
    $stmt->execute([':year' => (string)$year]);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'transactions' => $transactions,
        'fetched_at' => date('H:i:s')
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}