<?php
// ========== منع أي خطأ "Cannot redeclare" ==========
// نتحقق إن كانت الدالة غير معرفة مسبقاً قبل تعريفها
if (!function_exists('simpleLog')) {
    function simpleLog($msg) {
        $logDir = __DIR__ . '/logs/';
        if (!is_dir($logDir)) mkdir($logDir, 0777, true);
        $logFile = $logDir . 'backup_errors.log';
        $time = date('Y-m-d H:i:s');
        file_put_contents($logFile, "[$time] $msg" . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

// ========== بدء الجلسة والتحقق ==========
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
require_once 'config/database.php';

// التحقق من صلاحية المدير
try {
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user || $user['role'] !== 'admin') {
        die("غير مصرح بالوصول");
    }
} catch (Exception $e) {
    die("خطأ في قاعدة البيانات");
}

include 'includes/header.php';

// ========== إعدادات مجلد النسخ ==========
$backupDir = __DIR__ . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR;
if (!is_dir($backupDir)) mkdir($backupDir, 0777, true);

// متغير لتخزين رسالة للمستخدم
$msg = '';
$msgType = '';

// ========== معالجة الإجراءات عبر GET ==========

// 1. إنشاء نسخة
if (isset($_GET['create'])) {
    simpleLog("طلب إنشاء نسخة");
    $source = __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'deductions.db';
    if (!file_exists($source)) {
        $msg = "❌ ملف قاعدة البيانات غير موجود";
        $msgType = "error";
        simpleLog("خطأ: المصدر غير موجود");
    } else {
        $date = date('Y-m-d_H-i-s');
        $filename = "backup_{$date}.db";
        $target = $backupDir . $filename;
        if (copy($source, $target)) {
            $msg = "✅ تم إنشاء النسخة: $filename";
            $msgType = "success";
            simpleLog("تم الإنشاء: $filename");
        } else {
            $msg = "❌ فشل نسخ الملف";
            $msgType = "error";
            simpleLog("فشل copy()");
        }
    }
}

// 2. استعادة نسخة
if (isset($_GET['restore'])) {
    $filename = basename($_GET['restore']);
    $source = $backupDir . $filename;
    $target = __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'deductions.db';
    simpleLog("طلب استعادة: $filename");
    if (!file_exists($source)) {
        $msg = "❌ ملف النسخة غير موجود";
        $msgType = "error";
    } elseif (!is_readable($source)) {
        $msg = "❌ لا يمكن قراءة ملف النسخة";
        $msgType = "error";
    } elseif (!is_writable($target)) {
        $msg = "❌ ملف قاعدة البيانات الحالي غير قابل للكتابة";
        $msgType = "error";
    } else {
        if (copy($source, $target)) {
            $msg = "✅ تم استعادة النسخة: $filename";
            $msgType = "success";
            simpleLog("استعادة ناجحة: $filename");
            // إعادة تحميل الصفحة بعد ثانية لعرض التغيير
            echo "<meta http-equiv='refresh' content='2'>";
        } else {
            $msg = "❌ فشلت الاستعادة";
            $msgType = "error";
        }
    }
}

// 3. حذف نسخة
if (isset($_GET['delete'])) {
    $filename = basename($_GET['delete']);
    $file = $backupDir . $filename;
    simpleLog("طلب حذف: $filename");
    if (!file_exists($file)) {
        $msg = "❌ الملف غير موجود";
        $msgType = "error";
    } elseif (!is_writable($file)) {
        $msg = "❌ لا يمكن حذف الملف (صلاحيات)";
        $msgType = "error";
    } else {
        if (unlink($file)) {
            $msg = "✅ تم حذف الملف: $filename";
            $msgType = "success";
            simpleLog("حذف ناجح: $filename");
        } else {
            $msg = "❌ فشل الحذف";
            $msgType = "error";
        }
    }
}

// جلب قائمة النسخ الموجودة
$backups = glob($backupDir . '*.db');
rsort($backups);
?>

<style>
    /* أنماط بسيطة ونظيفة */
    body { background: #f0f2f5; font-family: 'Tajawal', sans-serif; }
    .backup-container { max-width: 1200px; margin: 30px auto; padding: 0 20px; }
    .card { background: white; border-radius: 28px; padding: 25px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); margin-bottom: 30px; }
    h2 { color: #1e3c72; margin-top: 0; }
    .alert { padding: 12px 20px; border-radius: 40px; margin-bottom: 20px; font-weight: bold; }
    .alert-success { background: #d4edda; color: #155724; border-right: 4px solid #28a745; }
    .alert-error { background: #f8d7da; color: #721c24; border-right: 4px solid #dc3545; }
    .btn { display: inline-block; padding: 10px 20px; border-radius: 40px; text-decoration: none; font-weight: bold; margin: 5px; transition: 0.2s; }
    .btn-primary { background: #28a745; color: white; }
    .btn-primary:hover { background: #218838; transform: translateY(-2px); }
    .btn-warning { background: #ffc107; color: #333; }
    .btn-danger { background: #dc3545; color: white; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 12px; text-align: center; border-bottom: 1px solid #ddd; }
    th { background: #1e3c72; color: white; border-radius: 15px 15px 0 0; }
    tr:hover td { background: #f9f9f9; }
    .actions a { margin: 0 5px; }
    .info { background: #e3f2fd; padding: 15px; border-radius: 20px; margin-top: 20px; border-right: 4px solid #2a5298; }
</style>

<div class="backup-container">
    <div class="card">
        <h2><i class="fas fa-database"></i> النسخ الاحتياطي</h2>
        
        <?php if ($msg): ?>
            <div class="alert alert-<?= $msgType ?>"><?= $msg ?></div>
        <?php endif; ?>
        
        <div style="margin-bottom: 30px;">
            <a href="?create=1" class="btn btn-primary" onclick="return confirm('إنشاء نسخة احتياطية جديدة؟')">
                <i class="fas fa-plus"></i> إنشاء نسخة
            </a>
        </div>
        
        <h3>النسخ المتاحة</h3>
        <?php if (empty($backups)): ?>
            <p>📭 لا توجد نسخ احتياطية حتى الآن</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr><th>اسم الملف</th><th>الحجم (ك.ب)</th><th>تاريخ الإنشاء</th><th>الإجراءات</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($backups as $file):
                        $name = basename($file);
                        $size = round(filesize($file) / 1024, 2);
                        $date = date('Y-m-d H:i:s', filemtime($file));
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($name) ?></td>
                        <td><?= $size ?> ك.ب</td>
                        <td><?= $date ?></td>
                        <td class="actions">
                            <a href="?restore=<?= urlencode($name) ?>" class="btn btn-warning" onclick="return confirm('⚠️ استعادة هذه النسخة ستحل محل قاعدة البيانات الحالية. هل أنت متأكد؟')">
                                <i class="fas fa-undo-alt"></i> استعادة
                            </a>
                            <a href="?delete=<?= urlencode($name) ?>" class="btn btn-danger" onclick="return confirm('🗑️ هل أنت متأكد من حذف هذا الملف نهائياً؟')">
                                <i class="fas fa-trash-alt"></i> حذف
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        
        <div class="info">
            <i class="fas fa-lightbulb"></i> <strong>ملاحظة:</strong> تأكد من أن مجلد <code>backups/</code> وملف <code>data/deductions.db</code> قابلان للكتابة (صلاحيات). إذا واجهت مشكلة في الحذف أو الاستعادة، راجع صلاحيات الملفات.
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>