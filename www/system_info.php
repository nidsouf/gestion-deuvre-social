<?php
/**
 * system_info.php - عرض معلومات النظام (PHP, SQLite, PHP Desktop)
 */
ob_start();
session_start();
require_once 'includes/auth_check.php';
require_once 'config/database.php';
require_once 'includes/security.php';
require_once 'includes/functions.php';

// التحقق من الصلاحيات (مدير فقط)
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    $_SESSION['toast'] = ['message' => '⚠️ غير مسموح لك بالوصول إلى هذه الصفحة', 'type' => 'warning', 'duration' => 3000];
    header("Location: index.php");
    exit;
}

$csrf_token = generateCSRFToken();

// جلب معلومات قاعدة البيانات
$dbSize = 0;
$tableStats = [];
$tableCount = 0;
$totalRecords = 0;

try {
    // حجم قاعدة البيانات
    $dbFile = __DIR__ . '/data/deductions.db';
    if (file_exists($dbFile)) {
        $dbSize = round(filesize($dbFile) / 1024 / 1024, 2); // MB
    }
    
    // عدد الجداول
    $stmt = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'");
    $tableCount = $stmt->fetchColumn();
    
    // إحصائيات كل جدول (أهم 10 جداول)
    $tables = ['employees', 'deductions', 'sources', 'grants', 'employee_grants', 'social_budget', 'budget_transactions', 'notifications', 'audit_log', 'users'];
    foreach ($tables as $table) {
        $checkStmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='$table'");
        if ($checkStmt->fetch()) {
            $countStmt = $pdo->query("SELECT COUNT(*) FROM $table");
            $count = $countStmt->fetchColumn();
            $totalRecords += $count;
            $tableStats[] = ['name' => $table, 'records' => $count];
        }
    }
} catch (PDOException $e) {
    // تجاهل الأخطاء
}

include 'includes/header.php';
?>

<style>
.system-container { max-width: 1200px; margin: 0 auto; }
.section-card { background: white; border-radius: 20px; padding: 20px; margin-bottom: 25px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
.section-title { color: #2a5298; border-bottom: 3px solid #2a5298; padding-bottom: 10px; margin-bottom: 20px; display: inline-block; }
.info-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #eee; flex-wrap: wrap; }
.info-label { font-weight: bold; color: #555; width: 250px; }
.info-value { color: #333; flex: 1; }
.status-ok { color: #28a745; font-weight: bold; }
.status-warning { color: #fd7e14; font-weight: bold; }
.status-error { color: #dc3545; font-weight: bold; }
table { width: 100%; border-collapse: collapse; }
th, td { padding: 10px; text-align: center; border-bottom: 1px solid #ddd; }
th { background: #2a5298; color: white; }
.btn-optimize { background: #28a745; color: white; padding: 10px 20px; border-radius: 30px; text-decoration: none; display: inline-block; margin-top: 10px; }
.btn-optimize:hover { background: #218838; }
.badge { display: inline-block; padding: 3px 8px; border-radius: 15px; font-size: 11px; }
.badge-good { background: #d4edda; color: #155724; }
.badge-warning { background: #fff3cd; color: #856404; }
</style>

<div class="system-container">
    <h2 style="margin-bottom: 20px;">🖥️ معلومات النظام</h2>
    
    <!-- معلومات PHP -->
    <div class="section-card">
        <h3 class="section-title">🐘 PHP</h3>
        <div class="info-row"><span class="info-label">الإصدار:</span><span class="info-value"><?= phpversion() ?></span></div>
        <div class="info-row"><span class="info-label">خادم الويب:</span><span class="info-value"><?= $_SERVER['SERVER_SOFTWARE'] ?? 'PHP Desktop (Embedded Server)' ?></span></div>
        <div class="info-row"><span class="info-label">نظام التشغيل:</span><span class="info-value"><?= PHP_OS ?></span></div>
        <div class="info-row"><span class="info-label">أقصى وقت للتنفيذ:</span><span class="info-value"><?= ini_get('max_execution_time') ?> ثانية</span></div>
        <div class="info-row"><span class="info-label">أقصى حجم للرفع:</span><span class="info-value"><?= ini_get('upload_max_filesize') ?></span></div>
        <div class="info-row"><span class="info-label">الذاكرة المستخدمة:</span><span class="info-value"><?= round(memory_get_usage() / 1024 / 1024, 2) ?> MB</span></div>
        <div class="info-row"><span class="info-label">الذاكرة القصوى:</span><span class="info-value"><?= ini_get('memory_limit') ?></span></div>
    </div>
    
    <!-- معلومات SQLite -->
    <div class="section-card">
        <h3 class="section-title">🗄️ SQLite</h3>
        <div class="info-row"><span class="info-label">الإصدار:</span><span class="info-value"><?= $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) ?></span></div>
        <div class="info-row"><span class="info-label">حجم قاعدة البيانات:</span><span class="info-value"><?= $dbSize ?> MB</span></div>
        <div class="info-row"><span class="info-label">عدد الجداول:</span><span class="info-value"><?= $tableCount ?></span></div>
        <div class="info-row"><span class="info-label">إجمالي السجلات:</span><span class="info-value"><?= number_format($totalRecords) ?></span></div>
        <div class="info-row"><span class="info-label">مسار قاعدة البيانات:</span><span class="info-value"><small><?= __DIR__ . '/data/deductions.db' ?></small></span></div>
    </div>
    
    <!-- إحصائيات الجداول -->
    <div class="section-card">
        <h3 class="section-title">📊 إحصائيات الجداول</h3>
        <div style="overflow-x: auto;">
            <table>
                <thead><tr><th>#</th><th>اسم الجدول</th><th>عدد السجلات</th><th>الحالة</th></tr></thead>
                <tbody>
                    <?php $i = 1; foreach ($tableStats as $stat): ?>
                    <tr>
                        <td><?= $i++ ?></td>
                        <td><?= $stat['name'] ?></td>
                        <td><?= number_format($stat['records']) ?></td>
                        <td>
                            <?php if ($stat['records'] > 10000): ?>
                                <span class="badge badge-warning">⚠️ كبير</span>
                            <?php else: ?>
                                <span class="badge badge-good">✅ جيد</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- معلومات الجلسة -->
    <div class="section-card">
        <h3 class="section-title">👤 معلومات الجلسة</h3>
        <div class="info-row"><span class="info-label">اسم المستخدم:</span><span class="info-value"><?= escape($_SESSION['username'] ?? 'غير مسجل') ?></span></div>
        <div class="info-row"><span class="info-label">الدور:</span><span class="info-value"><?= escape($_SESSION['role'] ?? 'غير محدد') ?></span></div>
        <div class="info-row"><span class="info-label">IP العنوان:</span><span class="info-value"><?= $_SERVER['REMOTE_ADDR'] ?? 'غير معروف' ?></span></div>
        <div class="info-row"><span class="info-label">وقت تسجيل الدخول:</span><span class="info-value"><?= date('Y-m-d H:i:s', $_SESSION['login_time'] ?? time()) ?></span></div>
    </div>
    
    <!-- روابط سريعة -->
    <div style="text-align: center; margin-top: 20px;">
        <a href="database_optimize.php" class="btn-optimize">🔧 تحسين قاعدة البيانات</a>
        <a href="backup.php" class="btn-optimize" style="background: #17a2b8;">💾 نسخ احتياطي</a>
    </div>
</div>

<?php
ob_end_flush();
include 'includes/footer.php';
?>