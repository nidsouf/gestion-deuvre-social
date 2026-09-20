<?php
// ai_analyze_budget.php
session_start();
require_once 'includes/auth_check.php';
require_once 'config/database.php';
require_once 'includes/security.php';
require_once 'includes/AIService.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'طريقة غير مسموحة']);
    exit;
}

if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    http_response_code(403);
    echo json_encode(['error' => 'خطأ في التحقق الأمني']);
    exit;
}

$year = isset($_POST['year']) ? (int)$_POST['year'] : date('Y');
$forceRefresh = isset($_POST['force']) && $_POST['force'] === 'true';

try {
    $ai = new AIService($pdo);
    $result = $ai->analyzeBudget($year, $forceRefresh);
    
    echo json_encode([
        'success' => true,
        'year' => $year,
        'result' => $result,
        'cached' => isset($result['cached']) ? $result['cached'] : false
    ]);
    
} catch (Exception $e) {
    error_log("AI Analysis Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}