<?php
require_once __DIR__ . '/../config/backup_config.php';

// ========== دوال مساعدة للتواريخ في SQLite ==========

function getCurrentDateSQLite() {
    return date('Y-m-d');
}

function getCurrentYearSQLite() {
    return date('Y');
}

// ========== الدوال الأساسية ==========

function formatNumber($number) {
    return number_format($number, 2, '.', ',');
}

function getEmployeeCategory($employeeId, $pdo) {
    $stmt = $pdo->prepare("SELECT category FROM employees WHERE id = ?");
    $stmt->execute([$employeeId]);
    $result = $stmt->fetch();
    return $result ? $result['category'] : 'Contract';
}

function getAllSources($pdo) {
    $stmt = $pdo->query("SELECT * FROM sources ORDER BY name");
    return $stmt->fetchAll();
}

function getMonthlyInstallment($startDate, $selectedDate, $totalMonths) {
    $start = new DateTime($startDate);
    $selected = new DateTime($selectedDate);
    $diff = $selected->diff($start);
    $months = $diff->y * 12 + $diff->m;
    $current = $months + 1;
    
    if ($current < 1) $current = 1;
    if ($current > $totalMonths) $current = $totalMonths;
    
    return $current . ' / ' . $totalMonths;
}

// ========== دوال النسخ الاحتياطي (لـ SQLite) ==========

function getBackupDir() {
    $backupDir = BACKUP_PATH;
    if (!file_exists($backupDir)) {
        mkdir($backupDir, 0777, true);
    }
    return $backupDir;
}

function createBackup($reason = 'manual') {
    $backupDir = getBackupDir();
    $dbFile = __DIR__ . '/../data/deductions.db';
    
    if (!file_exists($dbFile)) {
        return false;
    }
    
    $date = date('Y-m-d_H-i-s');
    $filename = $backupDir . $date . '_' . $reason . '.db';
    
    $success = copy($dbFile, $filename);
    
    if ($success) {
        cleanOldBackups($backupDir, BACKUP_KEEP_COUNT);
        return true;
    }
    
    return false;
}

function restoreBackup($filePath, $pdo) {
    if (!file_exists($filePath)) {
        return false;
    }
    
    try {
        $pdo = null;
        $dbFile = __DIR__ . '/../data/deductions.db';
        $success = copy($filePath, $dbFile);
        return $success;
    } catch (Exception $e) {
        return false;
    }
}

function cleanOldBackups($backupDir, $keep = BACKUP_KEEP_COUNT) {
    $files = glob($backupDir . '*.db');
    if (count($files) > $keep) {
        usort($files, function($a, $b) {
            return filemtime($a) - filemtime($b);
        });
        $filesToDelete = array_slice($files, 0, count($files) - $keep);
        foreach ($filesToDelete as $file) {
            unlink($file);
        }
    }
}

function getBackupList() {
    $backupDir = getBackupDir();
    if (!file_exists($backupDir)) {
        return [];
    }
    $files = glob($backupDir . '*.db');
    rsort($files);
    $backups = [];
    foreach ($files as $file) {
        $backups[] = [
            'name' => basename($file),
            'size' => round(filesize($file) / 1024, 2),
            'date' => date('Y-m-d H:i:s', filemtime($file))
        ];
    }
    return $backups;
}

// ========== دوال الميزانية ==========

function getCurrentBudget() {
    global $pdo;
    $stmt = $pdo->query("SELECT * FROM social_budget ORDER BY year DESC LIMIT 1");
    return $stmt->fetch();
}

function updateBudget($amount, $type = 'deduct') {
    global $pdo;
    
    $budget = $pdo->query("SELECT id, remaining_budget FROM social_budget ORDER BY year DESC LIMIT 1")->fetch();
    if (!$budget) return false;
    
    if ($type == 'deduct') {
        $newRemaining = $budget['remaining_budget'] - $amount;
    } else {
        $newRemaining = $budget['remaining_budget'] + $amount;
    }
    
    $update = $pdo->prepare("UPDATE social_budget SET remaining_budget = ? WHERE id = ?");
    return $update->execute([$newRemaining, $budget['id']]);
}

function autoCreateNextYearBudget() {
    global $pdo;
    
    $currentYear = date('Y');
    $nextYear = $currentYear + 1;
    
    $check = $pdo->prepare("SELECT id FROM social_budget WHERE year = ?");
    $check->execute([$nextYear]);
    if ($check->fetch()) return;
    
    $lastBudget = $pdo->query("SELECT * FROM social_budget ORDER BY year DESC LIMIT 1")->fetch();
    if (!$lastBudget) return;
    
    $carryOver = $lastBudget['remaining_budget'];
    
    $stmt = $pdo->prepare("INSERT INTO social_budget (year, initial_budget, remaining_budget) VALUES (?, ?, ?)");
    $stmt->execute([$nextYear, $carryOver, $carryOver]);
}

// ========== دوال الإشعارات ==========

function addNotification($userId, $message, $type = 'info', $link = null) {
    global $pdo;
    $stmt = $pdo->prepare("INSERT INTO notifications (user_id, message, type, link) VALUES (?, ?, ?, ?)");
    $stmt->execute([$userId, $message, $type, $link]);
}

// ========== دالة مساعدة للاستعلامات ==========

function executeDateQuery($pdo, $sql, $params = []) {
    $sql = str_replace('CURDATE()', "date('now')", $sql);
    $sql = str_replace('NOW()', "CURRENT_TIMESTAMP", $sql);
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}
?>