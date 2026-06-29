<?php
/**
 * database_optimize.php - تحسين قاعدة البيانات وإزالة التكرارات
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

$message = '';
$error = '';
$optimizationResults = [];

// معالجة تحسين قاعدة البيانات
if (isset($_POST['action']) && $_POST['action'] === 'optimize') {
    requireCSRFToken();
    if (isRateLimited('db_optimize', 2, 3600)) {
        $error = '⚠️ تجاوزت عدد المحاولات. حاول لاحقاً.';
    } else {
        try {
            // 1. VACUUM - إعادة بناء قاعدة البيانات
            $pdo->exec("VACUUM");
            $optimizationResults[] = "✅ تم تنفيذ VACUUM (إعادة بناء قاعدة البيانات)";
            
            // 2. ANALYZE - تحديث إحصائيات الاستعلامات
            $pdo->exec("ANALYZE");
            $optimizationResults[] = "✅ تم تنفيذ ANALYZE (تحديث إحصائيات الاستعلامات)";
            
            // 3. حذف الاقتطاعات المنتهية (اختياري)
            if (isset($_POST['delete_old'])) {
                $stmt = $pdo->prepare("DELETE FROM deductions WHERE end_date < date('now', '-1 year')");
                $stmt->execute();
                $deleted = $stmt->rowCount();
                if ($deleted > 0) {
                    $optimizationResults[] = "✅ تم حذف $deleted اقتطاع منتهٍ منذ أكثر من سنة";
                }
            }
            
            // 4. حذف الإشعارات القديمة
            if (isset($_POST['delete_old_notifications'])) {
                $stmt = $pdo->prepare("DELETE FROM notifications WHERE created_at < date('now', '-3 months')");
                $stmt->execute();
                $deleted = $stmt->rowCount();
                if ($deleted > 0) {
                    $optimizationResults[] = "✅ تم حذف $deleted إشعار قديم (أقدم من 3 أشهر)";
                }
            }
            
            // 5. إزالة التكرارات في audit_log
            if (isset($_POST['deduplicate_audit'])) {
                $pdo->exec("DELETE FROM audit_log WHERE id NOT IN (SELECT MIN(id) FROM audit_log GROUP BY action, details, created_at)");
                $optimizationResults[] = "✅ تمت إزالة التكرارات من سجل التدقيق";
            }
            
            $message = "تم تحسين قاعدة البيانات بنجاح!";
            audit('DATABASE_OPTIMIZED', "تم تحسين قاعدة البيانات بواسطة {$_SESSION['username']}");
            addNotification('تحسين قاعدة البيانات', 'تم إجراء صيانة وتحسين لقاعدة البيانات', null, 'success');
            
        } catch (PDOException $e) {
            error_log("Database optimize error: " . $e->getMessage());
            $error = "❌ خطأ أثناء التحسين: " . $e->getMessage();
        }
    }
}

// جلب إحصائيات قبل التحسين
$dbSize = 0;
$dbFile = __DIR__ . '/data/deductions.db';
if (file_exists($dbFile)) {
    $dbSize = round(filesize($dbFile) / 1024 / 1024, 2);
}

$stmt = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'");
$tableCount = $stmt->fetchColumn();

$oldDeductions = 0;
$stmt = $pdo->prepare("SELECT COUNT(*) FROM deductions WHERE end_date < date('now', '-1 year')");
$stmt->execute();
$oldDeductions = $stmt->fetchColumn();

$oldNotifications = 0;
$stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE created_at < date('now', '-3 months')");
$stmt->execute();
$oldNotifications = $stmt->fetchColumn();

$csrf_token = generateCSRFToken();
include 'includes/header.php';
?>

<style>
.optimize-container { max-width: 900px; margin: 0 auto; }
.section-card { background: white; border-radius: 20px; padding: 20px; margin-bottom: 25px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
.section-title { color: #2a5298; border-bottom: 3px solid #2a5298; padding-bottom: 10px; margin-bottom: 20px; display: inline-block; }
.stats-grid { display: flex; gap: 20px; margin-bottom: 25px; flex-wrap: wrap; }
.stat-card { background: linear-gradient(135deg, #2a5298, #1e3c72); color: white; padding: 15px; border-radius: 15px; flex: 1; text-align: center; }
.stat-card .value { font-size: 28px; font-weight: bold; }
.checkbox-group { margin: 15px 0; }
.checkbox-group label { display: block; margin: 8px 0; cursor: pointer; }
.checkbox-group input { margin-left: 10px; }
.btn-optimize { background: #28a745; color: white; padding: 12px 30px; border: none; border-radius: 30px; font-size: 16px; cursor: pointer; }
.btn-optimize:hover { background: #218838; }
.btn-back { background: #6c757d; color: white; padding: 12px 30px; border: none; border-radius: 30px; text-decoration: none; display: inline-block; margin-right: 10px; }
.success-message { background: #d4edda; color: #155724; padding: 15px; border-radius: 10px; margin-bottom: 20px; }
.error-message { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 10px; margin-bottom: 20px; }
.result-list { background: #e8f5e9; padding: 15px; border-radius: 10px; margin-top: 20px; }
</style>

<div class="optimize-container">
    <h2 style="margin-bottom: 20px;">🔧 تحسين قاعدة البيانات</h2>
    
    <?php if ($message): ?>
        <div class="success-message">✅ <?= escape($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="error-message">❌ <?= escape($error) ?></div>
    <?php endif; ?>
    
    <!-- الإحصائيات الحالية -->
    <div class="stats-grid">
        <div class="stat-card"><div>💾 حجم قاعدة البيانات</div><div class="value"><?= $dbSize ?> MB</div></div>
        <div class="stat-card"><div>📊 عدد الجداول</div><div class="value"><?= $tableCount ?></div></div>
        <div class="stat-card"><div>🗑️ اقتطاعات منتهية</div><div class="value"><?= number_format($oldDeductions) ?></div></div>
        <div class="stat-card"><div>📢 إشعارات قديمة</div><div class="value"><?= number_format($oldNotifications) ?></div></div>
    </div>
    
    <!-- نتائج التحسين السابقة -->
    <?php if (!empty($optimizationResults)): ?>
    <div class="result-list">
        <strong>📋 نتائج التحسين:</strong>
        <ul style="margin-top: 10px;">
            <?php foreach ($optimizationResults as $result): ?>
                <li><?= escape($result) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>
    
    <!-- نموذج التحسين -->
    <div class="section-card">
        <h3 class="section-title">⚙️ إعدادات التحسين</h3>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= escape($csrf_token) ?>">
            <input type="hidden" name="action" value="optimize">
            
            <div class="checkbox-group">
                <label>
                    <input type="checkbox" name="delete_old" <?= $oldDeductions > 0 ? 'checked' : '' ?>>
                    🗑️ حذف الاقتطاعات المنتهية منذ أكثر من سنة (<?= number_format($oldDeductions) ?> سجل)
                </label>
                <label>
                    <input type="checkbox" name="delete_old_notifications" <?= $oldNotifications > 0 ? 'checked' : '' ?>>
                    📢 حذف الإشعارات الأقدم من 3 أشهر (<?= number_format($oldNotifications) ?> إشعار)
                </label>
                <label>
                    <input type="checkbox" name="deduplicate_audit">
                    🔄 إزالة التكرارات من سجل التدقيق (audit_log)
                </label>
            </div>
            
            <div style="margin-top: 20px;">
                <a href="system_info.php" class="btn-back">🔙 العودة</a>
                <button type="submit" class="btn-optimize" onclick="return confirm('⚠️ هل أنت متأكد؟ سيتم تحسين قاعدة البيانات. هذه العملية قد تستغرق بضع ثوانٍ.')">
                    🚀 بدء التحسين
                </button>
            </div>
        </form>
    </div>
    
    <!-- نصيحة -->
    <div class="section-card" style="background: #e3f2fd;">
        <h3 class="section-title" style="color:#1e3c72;">💡 معلومات مهمة</h3>
        <ul style="margin-right: 20px;">
            <li><strong>VACUUM</strong> - يعيد بناء قاعدة البيانات ويقلل حجمها</li>
            <li><strong>ANALYZE</strong> - يحسن سرعة الاستعلامات</li>
            <li><strong>ينصح بعمل نسخة احتياطية</strong> قبل التحسين من صفحة <a href="backup.php">النسخ الاحتياطي</a></li>
            <li>التحسين قد يستغرق بعض الوقت حسب حجم قاعدة البيانات</li>
        </ul>
    </div>
</div>

<?php
ob_end_flush();
include 'includes/footer.php';
?>