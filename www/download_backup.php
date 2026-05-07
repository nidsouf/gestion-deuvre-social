<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

// إلغاء أي مخرجات سابقة
ob_clean();

$backupDir = getBackupDir();
$dbname = 'deductions_db';
$username = 'root';
$password = '';
$host = 'localhost';

$date = date('Y-m-d_H-i-s');
$filename = $backupDir . $date . '_manual.sql';

$mysqldump = '"C:\xampp\mysql\bin\mysqldump.exe"';
$command = "$mysqldump --user={$username} --password={$password} --host={$host} --no-create-info=false --complete-insert --add-drop-table {$dbname} > \"{$filename}\" 2>&1";
exec($command, $output, $returnCode);

if ($returnCode === 0 && file_exists($filename) && filesize($filename) > 0) {
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="deductions_backup_' . $date . '.sql"');
    header('Content-Length: ' . filesize($filename));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    
    readfile($filename);
    unlink($filename);
    exit;
} else {
    die("❌ فشل إنشاء النسخة الاحتياطية");
}