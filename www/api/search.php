<?php
/**
 * api/search.php - البحث الموحّد (Ctrl+K)
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

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) {
    echo json_encode(['success' => true, 'results' => []]);
    exit;
}

$searchTerm = '%' . $q . '%';
$results = [];

try {
    // 1. الموظفون
    $stmt = $pdo->prepare("
        SELECT id, name, account_number 
        FROM employees 
        WHERE name LIKE ? OR account_number LIKE ?
        LIMIT 5
    ");
    $stmt->execute([$searchTerm, $searchTerm]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $results[] = [
            'type' => 'employee',
            'icon' => '👤',
            'title' => $row['name'],
            'subtitle' => 'رقم الحساب: ' . ($row['account_number'] ?? '—'),
            'url' => 'employees/view.php?id=' . $row['id']
        ];
    }
    
    // 2. الاقتطاعات
    $stmt = $pdo->prepare("
        SELECT d.id, e.name as employee_name, s.name as source_name, d.monthly_amount
        FROM deductions d
        LEFT JOIN employees e ON d.employee_id = e.id
        LEFT JOIN sources s ON d.source_id = s.id
        WHERE e.name LIKE ? OR s.name LIKE ?
        LIMIT 5
    ");
    $stmt->execute([$searchTerm, $searchTerm]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $results[] = [
            'type' => 'deduction',
            'icon' => '📋',
            'title' => 'اقتطاع - ' . ($row['employee_name'] ?? 'غير معروف'),
            'subtitle' => $row['source_name'] . ' • ' . number_format($row['monthly_amount'], 2) . ' دج',
            'url' => 'deductions/view.php?id=' . $row['id']
        ];
    }
    
    // 3. المنح
    $stmt = $pdo->prepare("
        SELECT eg.id, e.name as employee_name, g.name as grant_name, eg.amount
        FROM employee_grants eg
        LEFT JOIN employees e ON eg.employee_id = e.id
        LEFT JOIN grants g ON eg.grant_id = g.id
        WHERE e.name LIKE ? OR g.name LIKE ?
        LIMIT 5
    ");
    $stmt->execute([$searchTerm, $searchTerm]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $results[] = [
            'type' => 'grant',
            'icon' => '🎁',
            'title' => $row['grant_name'] . ' - ' . ($row['employee_name'] ?? ''),
            'subtitle' => number_format($row['amount'], 2) . ' دج',
            'url' => 'grants/employee_list.php?search=' . urlencode($q)
        ];
    }
    
    // 4. الشيكات
    $stmt = $pdo->prepare("
        SELECT sp.id, sp.cheque_number, s.name as source_name, sp.amount
        FROM source_payments sp
        LEFT JOIN sources s ON sp.source_id = s.id
        WHERE sp.cheque_number LIKE ? OR s.name LIKE ?
        LIMIT 5
    ");
    $stmt->execute([$searchTerm, $searchTerm]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $results[] = [
            'type' => 'cheque',
            'icon' => '💵',
            'title' => 'شيك رقم ' . $row['cheque_number'],
            'subtitle' => ($row['source_name'] ?? '') . ' • ' . number_format($row['amount'], 2) . ' دج',
            'url' => 'payments/edit.php?id=' . $row['id']
        ];
    }
    
    echo json_encode(['success' => true, 'results' => $results, 'query' => $q]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}