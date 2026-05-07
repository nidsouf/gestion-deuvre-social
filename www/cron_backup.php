<?php
// هذا الملف يُشغل مرة يوميًا تلقائيًا (عبر مهمة مجدولة)
require_once 'config/database.php';
require_once 'includes/functions.php';

// ========== 1. نسخ احتياطي تلقائي ==========
$backupDir = __DIR__ . '/backups/auto/';
if (!file_exists($backupDir)) {
    mkdir($backupDir, 0777, true);
}

$filename = $backupDir . date('Y-m-d_H-i-s') . '_auto.sql';
$command = "mysqldump -u root deductions_db > \"$filename\"";
exec($command);

if (file_exists($filename)) {
    // تسجيل النسخة في جدول backup_log (إذا كان موجودًا)
    $stmt = $pdo->prepare("INSERT INTO backup_log (filename, size) VALUES (?, ?)");
    $stmt->execute([basename($filename), filesize($filename)]);
}

// حذف النسخ الأقدم من 30 يومًا
foreach (glob($backupDir . "*.sql") as $file) {
    if (filemtime($file) < strtotime('-30 days')) {
        unlink($file);
    }
}

// ========== 2. إشعارات السلفات التي ستنتهي خلال 7 أيام ==========
$soonLoans = $pdo->query("
    SELECT d.id, e.name as employee_name, d.end_date 
    FROM deductions d 
    JOIN employees e ON d.employee_id = e.id 
    WHERE d.is_loan = 1 
    AND d.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
")->fetchAll();

foreach ($soonLoans as $loan) {
    // نتحقق إذا كان الإشعار لم يضف من قبل لهذه السلفة خلال 24 ساعة
    $check = $pdo->prepare("SELECT id FROM notifications WHERE reference_id = ? AND type = 'loan_expiring' AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)");
    $check->execute([$loan['id']]);
    if (!$check->fetch()) {
        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, message, type, link, reference_id) VALUES (?, ?, 'loan_expiring', ?, ?)");
        $stmt->execute([
            1, // user_id (يمكن تغييره ليشمل جميع المديرين لاحقًا)
            "⚠️ سلفة الموظف {$loan['employee_name']} تنتهي بتاريخ " . date('d/m/Y', strtotime($loan['end_date'])),
            "deductions/list.php",
            $loan['id']
        ]);
    }
}

echo "✅ تم تشغيل cron job بنجاح في " . date('Y-m-d H:i:s');
?>