<?php
// ai_get_report.php
session_start();
require_once 'includes/auth_check.php';
require_once 'config/database.php';
require_once 'includes/AIService.php';

header('Content-Type: application/json');

$reportId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$reportId) {
    echo json_encode(['error' => 'رقم التقرير مطلوب']);
    exit;
}

try {
    $ai = new AIService($pdo);
    $report = $ai->getReport($reportId);
    
    if (!$report) {
        echo json_encode(['error' => 'التقرير غير موجود']);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'id' => $report['id'],
        'type' => $report['type'],
        'year' => $report['year'],
        'summary' => $report['summary'],
        'analysis' => $report['analysis'],
        'recommendations' => $report['recommendations'],
        'alerts' => $report['alerts'],
        'model' => $report['model'],
        'created_at' => $report['created_at']
    ]);
    
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}