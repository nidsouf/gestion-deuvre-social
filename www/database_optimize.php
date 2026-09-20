<?php
/**
 * database_optimize.php - تحسين قاعدة البيانات مع نظام الأرشفة الكامل
 * النسخة النهائية المُصلَّحة - آمنة وموثوقة
 */
ob_start();
session_start();
require_once 'includes/auth_check.php';
require_once 'config/database.php';
require_once 'includes/security.php';
require_once 'includes/functions.php';

// ============================================================
// التحقق من الصلاحيات
// ============================================================
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    $_SESSION['toast'] = ['message' => '⚠️ غير مسموح لك بالوصول إلى هذه الصفحة', 'type' => 'warning', 'duration' => 3000];
    header("Location: index.php");
    exit;
}

// ============================================================
// اكتشاف مسار قاعدة البيانات
// ============================================================
try {
    $dbInfo = $pdo->query("PRAGMA database_list")->fetch(PDO::FETCH_ASSOC);
    $dbFile = $dbInfo['file'] ?? '';
} catch (Exception $e) {
    $dbFile = '';
}

// احتياطي
if (empty($dbFile) || !file_exists($dbFile)) {
    $candidates = [
        __DIR__ . '/database.sqlite',
        __DIR__ . '/data/deductions.db',
        __DIR__ . '/config/database.sqlite',
        __DIR__ . '/../database.sqlite',
    ];
    foreach ($candidates as $c) {
        if (file_exists($c)) {
            $dbFile = $c;
            break;
        }
    }
}

if (empty($dbFile) || !file_exists($dbFile)) {
    die('❌ لم يتم العثور على ملف قاعدة البيانات.');
}

$backupDir = __DIR__ . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR;
if (!is_dir($backupDir)) mkdir($backupDir, 0777, true);

// ============================================================
// إنشاء جداول الأرشفة تلقائياً إن لم تكن موجودة
// ============================================================
function ensureArchiveTables($pdo) {
    $sql = "
    CREATE TABLE IF NOT EXISTS deductions_archive (
        archive_id INTEGER PRIMARY KEY AUTOINCREMENT,
        original_id INTEGER NOT NULL,
        employee_id INTEGER,
        source_id INTEGER,
        monthly_amount REAL,
        total_months INTEGER,
        start_date TEXT,
        end_date TEXT,
        is_loan INTEGER,
        created_at TEXT,
        grant_date TEXT,
        updated_at TEXT,
        included_in_minute_id INTEGER,
        paid_months INTEGER,
        remaining_months INTEGER,
        credit_balance REAL,
        notes TEXT,
        archived_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        archived_by TEXT,
        archive_reason TEXT
    );

    CREATE TABLE IF NOT EXISTS monthly_installments_archive (
        archive_id INTEGER PRIMARY KEY AUTOINCREMENT,
        original_id INTEGER,
        deduction_original_id INTEGER,
        employee_id INTEGER,
        source_id INTEGER,
        year INTEGER,
        month INTEGER,
        amount REAL,
        is_paid INTEGER,
        paid_date TEXT,
        created_at TEXT,
        is_postponed INTEGER,
        postponed_from_month TEXT,
        archived_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE INDEX IF NOT EXISTS idx_ded_arch_employee ON deductions_archive(employee_id);
    CREATE INDEX IF NOT EXISTS idx_ded_arch_end_date ON deductions_archive(end_date);
    CREATE INDEX IF NOT EXISTS idx_ded_arch_original ON deductions_archive(original_id);
    CREATE INDEX IF NOT EXISTS idx_inst_arch_deduction ON monthly_installments_archive(deduction_original_id);
    ";
    
    foreach (explode(';', $sql) as $query) {
        $q = trim($query);
        if (!empty($q)) {
            try { $pdo->exec($q); } catch (Exception $e) {}
        }
    }
}

ensureArchiveTables($pdo);

$message = '';
$error = '';
$optimizationResults = [];
$backupCreated = '';

// ============================================================
// معالجة التحسين
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'optimize') {
    requireCSRFToken();
    
    // Rate Limiting (اختياري)
    if (function_exists('isRateLimited') && isRateLimited('db_optimize', 3, 3600)) {
        $error = '⚠️ تجاوزت عدد المحاولات المسموح بها. حاول بعد ساعة.';
    } else {
        try {
            // ============================================================
            // 1. نسخة احتياطية إلزامية قبل التحسين
            // ============================================================
            $backupFilename = 'pre_optimize_' . date('Y-m-d_H-i-s') . '.db';
            $backupPath = $backupDir . $backupFilename;
            
            if (!@copy($dbFile, $backupPath)) {
                throw new Exception('❌ فشل إنشاء النسخة الاحتياطية الإلزامية. تم إلغاء التحسين.');
            }
            
            $backupCreated = $backupFilename;
            $optimizationResults[] = "💾 تم إنشاء نسخة احتياطية: <strong>" . htmlspecialchars($backupFilename) . "</strong>";
            
            // ============================================================
            // 2. معالجة العمليات الاختيارية (قبل VACUUM)
            // ============================================================
            
            // 2.1 أرشفة الاقتطاعات المنتهية
            if (isset($_POST['archive_old'])) {
                $pdo->beginTransaction();
                
                $stmt = $pdo->prepare("
                    SELECT * FROM deductions 
                    WHERE end_date < date('now', '-1 year')
                ");
                $stmt->execute();
                $oldDeductionsList = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (!empty($oldDeductionsList)) {
                    $username = $_SESSION['username'] ?? 'admin';
                    
                    $archiveStmt = $pdo->prepare("
                        INSERT INTO deductions_archive (
                            original_id, employee_id, source_id, monthly_amount, total_months,
                            start_date, end_date, is_loan, created_at, grant_date, updated_at,
                            included_in_minute_id, paid_months, remaining_months, credit_balance,
                            notes, archived_by, archive_reason
                        ) VALUES (
                            :original_id, :employee_id, :source_id, :monthly_amount, :total_months,
                            :start_date, :end_date, :is_loan, :created_at, :grant_date, :updated_at,
                            :included_in_minute_id, :paid_months, :remaining_months, :credit_balance,
                            :notes, :archived_by, :reason
                        )
                    ");
                    
                    $instStmt = $pdo->prepare("
                        INSERT INTO monthly_installments_archive (
                            original_id, deduction_original_id, employee_id, source_id,
                            year, month, amount, is_paid, paid_date, created_at,
                            is_postponed, postponed_from_month
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    $archivedCount = 0;
                    $installmentsCount = 0;
                    
                    foreach ($oldDeductionsList as $d) {
                        // أرشفة الاقتطاع
                        $archiveStmt->execute([
                            ':original_id' => $d['id'],
                            ':employee_id' => $d['employee_id'] ?? null,
                            ':source_id' => $d['source_id'] ?? null,
                            ':monthly_amount' => $d['monthly_amount'] ?? 0,
                            ':total_months' => $d['total_months'] ?? 0,
                            ':start_date' => $d['start_date'] ?? null,
                            ':end_date' => $d['end_date'] ?? null,
                            ':is_loan' => $d['is_loan'] ?? 0,
                            ':created_at' => $d['created_at'] ?? null,
                            ':grant_date' => $d['grant_date'] ?? null,
                            ':updated_at' => $d['updated_at'] ?? null,
                            ':included_in_minute_id' => $d['included_in_minute_id'] ?? null,
                            ':paid_months' => $d['paid_months'] ?? 0,
                            ':remaining_months' => $d['remaining_months'] ?? 0,
                            ':credit_balance' => $d['credit_balance'] ?? 0,
                            ':notes' => $d['notes'] ?? null,
                            ':archived_by' => $username,
                            ':reason' => 'اقتطاع منتهٍ منذ أكثر من سنة'
                        ]);
                        $archivedCount++;
                        
                        // أرشفة الأقساط المرتبطة
                        $instQuery = $pdo->prepare("SELECT * FROM monthly_installments WHERE deduction_id = ?");
                        $instQuery->execute([$d['id']]);
                        $insts = $instQuery->fetchAll(PDO::FETCH_ASSOC);
                        
                        foreach ($insts as $inst) {
                            $instStmt->execute([
                                $inst['id'] ?? null,
                                $d['id'],
                                $inst['employee_id'] ?? null,
                                $inst['source_id'] ?? null,
                                $inst['year'] ?? null,
                                $inst['month'] ?? null,
                                $inst['amount'] ?? 0,
                                $inst['is_paid'] ?? 0,
                                $inst['paid_date'] ?? null,
                                $inst['created_at'] ?? null,
                                $inst['is_postponed'] ?? 0,
                                $inst['postponed_from_month'] ?? null
                            ]);
                            $installmentsCount++;
                        }
                        
                        // حذف الأقساط الأصلية
                        $pdo->prepare("DELETE FROM monthly_installments WHERE deduction_id = ?")->execute([$d['id']]);
                    }
                    
                    // حذف الاقتطاعات الأصلية
                    $ids = array_column($oldDeductionsList, 'id');
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $pdo->prepare("DELETE FROM deductions WHERE id IN ($placeholders)")->execute($ids);
                    
                    $pdo->commit();
                    
                    $optimizationResults[] = "📦 تمت أرشفة <strong>" . number_format($archivedCount) . "</strong> اقتطاع و <strong>" . number_format($installmentsCount) . "</strong> قسط مرتبط";
                    $optimizationResults[] = "💡 يمكنك عرض الأرشيف من <a href='archive_view.php' style='color:#0066cc; text-decoration:underline; font-weight:bold;'>صفحة الأرشيف</a>";
                } else {
                    $pdo->commit();
                    $optimizationResults[] = "ℹ️ لا توجد اقتطاعات قديمة للأرشفة";
                }
            }
            
            // 2.2 حذف الإشعارات القديمة
            if (isset($_POST['delete_old_notifications'])) {
                $stmt = $pdo->prepare("
                    DELETE FROM notifications 
                    WHERE created_at < datetime('now', '-3 months')
                ");
                $stmt->execute();
                $deleted = $stmt->rowCount();
                
                if ($deleted > 0) {
                    $optimizationResults[] = "📢 تم حذف <strong>" . number_format($deleted) . "</strong> إشعار قديم (أقدم من 3 أشهر)";
                } else {
                    $optimizationResults[] = "ℹ️ لا توجد إشعارات قديمة للحذف";
                }
            }
            
            // ⚠️ ملاحظة: لا نحذف أي سجلات من audit_logs (سجل التدقيق)
            // لأنها وثيقة أمنية يجب الاحتفاظ بها
            
            // ============================================================
            // 3. ANALYZE (تحديث إحصائيات الاستعلامات)
            // ============================================================
            $pdo->exec("ANALYZE");
            $optimizationResults[] = "✅ تم تحديث إحصائيات الاستعلامات (ANALYZE)";
            
            // ============================================================
            // 4. VACUUM (إعادة بناء قاعدة البيانات)
            // ============================================================
            clearstatcache(true, $dbFile);
            $sizeBefore = filesize($dbFile);
            
            // تعطيل Foreign Keys مؤقتاً
            $pdo->exec("PRAGMA foreign_keys = OFF");
            
            try {
                $pdo->exec("VACUUM");
            } catch (PDOException $e) {
                // محاولة بديلة
                $pdo->exec("PRAGMA optimize");
                throw new Exception("VACUUM فشل: " . $e->getMessage());
            }
            
            $pdo->exec("PRAGMA foreign_keys = ON");
            
            clearstatcache(true, $dbFile);
            $sizeAfter = filesize($dbFile);
            $saved = $sizeBefore - $sizeAfter;
            
            $sizeBeforeMB = round($sizeBefore / 1024 / 1024, 2);
            $sizeAfterMB = round($sizeAfter / 1024 / 1024, 2);
            $savedMB = round($saved / 1024 / 1024, 2);
            
            $optimizationResults[] = "🔄 تم تنفيذ VACUUM: من <strong>{$sizeBeforeMB} MB</strong> إلى <strong>{$sizeAfterMB} MB</strong> (توفير: <strong>{$savedMB} MB</strong>)";
            
            // ============================================================
            // 5. تسجيل في سجل التدقيق
            // ============================================================
            if (function_exists('audit')) {
                $username = $_SESSION['username'] ?? 'unknown';
                audit('DATABASE_OPTIMIZED', "تحسين قاعدة البيانات بواسطة $username");
            }
            
            // 6. إشعار
            if (function_exists('addNotification')) {
                addNotification(
                    'تحسين قاعدة البيانات',
                    'تم إجراء صيانة لقاعدة البيانات بنجاح',
                    null,
                    'success'
                );
            }
            
            $message = "✅ تم تحسين قاعدة البيانات بنجاح!";
            
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("Database optimize error: " . $e->getMessage());
            $error = "❌ خطأ في قاعدة البيانات: " . $e->getMessage();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("Database optimize error: " . $e->getMessage());
            $error = $e->getMessage();
        }
    }
}

// ============================================================
// جلب الإحصائيات
// ============================================================
clearstatcache(true, $dbFile);
$dbSizeMB = file_exists($dbFile) ? round(filesize($dbFile) / 1024 / 1024, 2) : 0;

$tableCount = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'")->fetchColumn();

// الاقتطاعات القديمة
$stmt = $pdo->query("SELECT COUNT(*) FROM deductions WHERE end_date < date('now', '-1 year')");
$oldDeductions = $stmt->fetchColumn();

// الأقساط المرتبطة بالاقتطاعات القديمة
$stmt = $pdo->query("
    SELECT COUNT(*) FROM monthly_installments 
    WHERE deduction_id IN (
        SELECT id FROM deductions WHERE end_date < date('now', '-1 year')
    )
");
$oldInstallments = $stmt->fetchColumn();

// الإشعارات القديمة
$stmt = $pdo->query("SELECT COUNT(*) FROM notifications WHERE created_at < datetime('now', '-3 months')");
$oldNotifications = $stmt->fetchColumn();

// عدد النسخ الاحتياطية
$backupCount = count(glob($backupDir . '*.db'));

// عدد السجلات في الأرشيف
$archivedCount = 0;
try {
    $archivedCount = $pdo->query("SELECT COUNT(*) FROM deductions_archive")->fetchColumn();
} catch (Exception $e) {}

$csrf_token = generateCSRFToken();
include 'includes/header.php';
?>

<style>
.optimize-container { max-width: 1000px; margin: 0 auto; padding: 0 15px; }
.section-card { background: white; border-radius: 20px; padding: 25px; margin-bottom: 25px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
.section-title { color: #2a5298; border-bottom: 3px solid #2a5298; padding-bottom: 10px; margin-bottom: 20px; display: inline-block; font-size: 20px; }
.stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 25px; }
.stat-card { background: linear-gradient(135deg, #2a5298, #1e3c72); color: white; padding: 18px; border-radius: 15px; text-align: center; }
.stat-card.warning { background: linear-gradient(135deg, #f39c12, #e67e22); }
.stat-card.danger { background: linear-gradient(135deg, #dc3545, #c82333); }
.stat-card.success { background: linear-gradient(135deg, #28a745, #20c997); }
.stat-card.info { background: linear-gradient(135deg, #17a2b8, #0c5460); }
.stat-card .value { font-size: 26px; font-weight: bold; margin-top: 5px; }
.stat-card .label { font-size: 13px; opacity: 0.9; }
.checkbox-group { margin: 15px 0; }
.checkbox-group label { display: flex; align-items: center; padding: 12px 15px; margin: 8px 0; cursor: pointer; background: #f8f9fa; border-radius: 10px; transition: all 0.2s; border: 2px solid transparent; }
.checkbox-group label:hover { background: #e9ecef; }
.checkbox-group label.checked { background: #e8f5e9; border-color: #28a745; }
.checkbox-group label.checked.archive { background: #d1ecf1; border-color: #17a2b8; }
.checkbox-group input { margin-left: 12px; width: 18px; height: 18px; cursor: pointer; flex-shrink: 0; }
.checkbox-group .option-desc { font-size: 12px; color: #6c757d; margin-top: 4px; }
.checkbox-group > label > div { flex: 1; }
.btn-optimize { background: linear-gradient(135deg, #28a745, #20c997); color: white; padding: 14px 35px; border: none; border-radius: 30px; font-size: 16px; font-weight: bold; cursor: pointer; transition: all 0.2s; }
.btn-optimize:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4); }
.btn-optimize:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
.btn-back { background: #6c757d; color: white; padding: 14px 30px; border: none; border-radius: 30px; text-decoration: none; display: inline-block; margin-right: 10px; font-weight: bold; transition: all 0.2s; }
.btn-back:hover { background: #5a6268; color: white; }
.btn-archive { background: linear-gradient(135deg, #17a2b8, #0c5460); color: white; padding: 14px 30px; border: none; border-radius: 30px; text-decoration: none; display: inline-block; margin-right: 10px; font-weight: bold; transition: all 0.2s; }
.btn-archive:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(23, 162, 184, 0.4); color: white; }
.success-message { background: #d4edda; color: #155724; padding: 15px 20px; border-radius: 12px; margin-bottom: 20px; border-right: 5px solid #28a745; font-weight: 600; }
.error-message { background: #f8d7da; color: #721c24; padding: 15px 20px; border-radius: 12px; margin-bottom: 20px; border-right: 5px solid #dc3545; font-weight: 600; }
.result-list { background: #e8f5e9; padding: 20px; border-radius: 12px; margin-top: 20px; border-right: 5px solid #28a745; }
.result-list ul { margin: 10px 0 0 0; padding-right: 20px; list-style: none; }
.result-list li { padding: 8px 0; font-size: 14px; border-bottom: 1px dotted #c3e6cb; }
.result-list li:last-child { border-bottom: none; }
.info-box { background: #e3f2fd; padding: 15px 20px; border-radius: 12px; margin-bottom: 20px; border-right: 5px solid #2196f3; font-size: 14px; line-height: 1.8; }
.warning-box { background: #fff3cd; padding: 15px 20px; border-radius: 12px; margin-bottom: 20px; border-right: 5px solid #ffc107; font-size: 14px; }
.archive-info { background: #d1ecf1; padding: 15px 20px; border-radius: 12px; margin-bottom: 20px; border-right: 5px solid #17a2b8; font-size: 14px; line-height: 1.8; }
.backup-info { background: #d1ecf1; padding: 12px 18px; border-radius: 10px; margin-top: 15px; border-right: 4px solid #17a2b8; font-size: 13px; }
.backup-info strong { color: #0c5460; }
.actions-row { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 20px; align-items: center; }

/* Modal */
.modal-content { direction: rtl; border-radius: 20px; border: none; box-shadow: 0 10px 40px rgba(0,0,0,0.2); }
.modal-header { border-radius: 20px 20px 0 0; padding: 20px 25px; }
.modal-body { padding: 25px; }
.modal-footer { padding: 15px 25px; gap: 10px; }
.modal-icon { font-size: 48px; display: block; margin-bottom: 15px; text-align: center; }
.modal-preview { background: #f8f9fa; padding: 15px; border-radius: 12px; text-align: right; font-size: 14px; margin: 15px 0; }
.modal-preview ul { margin: 10px 0 0 0; padding-right: 20px; list-style: none; }
.modal-preview li { padding: 6px 0; border-bottom: 1px dotted #ddd; }
.modal-preview li:last-child { border-bottom: none; }
</style>

<div class="optimize-container">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:15px;">
        <h2 style="margin:0;">🔧 تحسين قاعدة البيانات</h2>
        <div>
            <a href="archive_view.php" class="btn-archive">📦 عرض الأرشيف (<?= number_format($archivedCount) ?>)</a>
            <a href="system_info.php" class="btn-back">🔙 العودة</a>
        </div>
    </div>
    
    <?php if ($message): ?>
        <div class="success-message">✅ <?= escape($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="error-message">❌ <?= escape($error) ?></div>
    <?php endif; ?>
    
    <?php if ($backupCreated): ?>
        <div class="backup-info">
            <strong>💾 نسخة احتياطية تلقائية:</strong>
            <code><?= escape($backupCreated) ?></code>
            — يمكنك استعادتها من <a href="backup.php">صفحة النسخ الاحتياطي</a>.
        </div>
    <?php endif; ?>
    
    <!-- الإحصائيات -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="label">💾 حجم قاعدة البيانات</div>
            <div class="value"><?= $dbSizeMB ?> <small style="font-size:14px;">MB</small></div>
        </div>
        <div class="stat-card success">
            <div class="label">📊 عدد الجداول</div>
            <div class="value"><?= $tableCount ?></div>
        </div>
        <div class="stat-card warning">
            <div class="label">🗑️ اقتطاعات منتهية</div>
            <div class="value"><?= number_format($oldDeductions) ?></div>
        </div>
        <div class="stat-card warning">
            <div class="label">📢 إشعارات قديمة</div>
            <div class="value"><?= number_format($oldNotifications) ?></div>
        </div>
        <div class="stat-card info">
            <div class="label">📦 أرشيف</div>
            <div class="value"><?= number_format($archivedCount) ?></div>
        </div>
        <div class="stat-card">
            <div class="label">💾 نسخ احتياطية</div>
            <div class="value"><?= number_format($backupCount) ?></div>
        </div>
    </div>
    
    <!-- نتائج التحسين -->
    <?php if (!empty($optimizationResults)): ?>
    <div class="result-list">
        <strong>📋 نتائج التحسين:</strong>
        <ul>
            <?php foreach ($optimizationResults as $result): ?>
                <li><?= $result ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>
    
    <!-- نموذج التحسين -->
    <div class="section-card">
        <h3 class="section-title">⚙️ إعدادات التحسين</h3>
        
        <div class="info-box">
            <strong>💡 ماذا يفعل هذا؟</strong><br>
            • <strong>VACUUM:</strong> يعيد بناء قاعدة البيانات ويقلل حجمها (لا يفقد البيانات).<br>
            • <strong>ANALYZE:</strong> يحسّن سرعة الاستعلامات بتحديث الإحصائيات.<br>
            • <strong>نسخة احتياطية إلزامية:</strong> تُنشأ تلقائياً قبل أي عملية.
        </div>
        
        <div class="archive-info">
            📦 <strong>نظام الأرشفة:</strong>
            بدلاً من حذف الاقتطاعات المنتهية نهائياً، يقوم النظام بنقلها إلى <strong>الأرشيف</strong>.
            يمكنك استعادتها في أي وقت من <a href="archive_view.php" style="color:#0c5460; font-weight:bold; text-decoration:underline;">صفحة الأرشيف</a>.
        </div>
        
        <div class="warning-box">
            ⚠️ <strong>تنبيه:</strong> راجع الخيارات بعناية. الأرشفة آمنة (قابلة للاستعادة)، أما حذف الإشعارات فنهائي.
        </div>
        
        <form method="POST" id="optimizeForm">
            <input type="hidden" name="csrf_token" value="<?= escape($csrf_token) ?>">
            <input type="hidden" name="action" value="optimize">
            
            <div class="checkbox-group">
                <!-- ✅ الأرشفة (بدل الحذف) -->
                <label id="labelArchiveOld" class="archive-option">
                    <input type="checkbox" name="archive_old" id="archiveOld" value="1">
                    <div>
                        📦 <strong>أرشفة الاقتطاعات المنتهية منذ أكثر من سنة</strong>
                        <div class="option-desc">
                            (<?= number_format($oldDeductions) ?> اقتطاع، و <?= number_format($oldInstallments) ?> قسط مرتبط)
                            — <em style="color:#17a2b8;">✓ قابلة للاستعادة لاحقاً</em>
                        </div>
                    </div>
                </label>
                
                <!-- حذف الإشعارات القديمة -->
                <label id="labelDeleteNotifs">
                    <input type="checkbox" name="delete_old_notifications" id="deleteNotifs" value="1">
                    <div>
                        📢 <strong>حذف الإشعارات الأقدم من 3 أشهر</strong>
                        <div class="option-desc">
                            (<?= number_format($oldNotifications) ?> إشعار) — <em style="color:#dc3545;">نهائي</em>
                        </div>
                    </div>
                </label>
            </div>
            
            <div class="info-box" style="background:#e8f5e9; border-color:#28a745;">
                <strong>🛡️ ملاحظات أمنية:</strong><br>
                • <strong>سجل التدقيق (audit_logs)</strong> لا يُحذف أبداً — يبقى للأمان.<br>
                • <strong>النسخة الاحتياطية الإلزامية</strong> تُنشأ تلقائياً قبل أي عملية.<br>
                • <strong>الأرشيف</strong> يبقى قابلاً للاستعادة حتى تقرر حذفه نهائياً.
            </div>
            
            <div class="actions-row">
                <button type="button" class="btn-optimize" id="openOptimizeModal">
                    🚀 بدء التحسين
                </button>
            </div>
        </form>
    </div>
    
    <!-- معلومات مهمة -->
    <div class="section-card" style="background: #f8f9fa;">
        <h3 class="section-title" style="color:#1e3c72;">💡 نصائح</h3>
        <ul style="margin-right: 20px; line-height: 2;">
            <li>يُنصح بتشغيل التحسين <strong>شهرياً</strong> للحفاظ على الأداء.</li>
            <li>قبل التحسين، يمكنك إنشاء نسخة احتياطية يدوية من <a href="backup.php">هنا</a>.</li>
            <li>قد يستغرق VACUUM بعض الوقت حسب حجم قاعدة البيانات.</li>
            <li>لا تُغلق المتصفح أثناء تنفيذ العملية.</li>
            <li>يمكنك استعادة أي اقتطاع مؤرشف من <a href="archive_view.php">صفحة الأرشيف</a>.</li>
        </ul>
    </div>
</div>

<!-- ============================================================
     Modal: تأكيد التحسين
============================================================ -->
<div class="modal fade" id="optimizeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background: linear-gradient(135deg, #28a745, #20c997); color:white;">
                <h5 class="modal-title">⚠️ تأكيد التحسين</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="modal-icon">🔧</div>
                <h5 style="text-align:center; margin-bottom: 15px;">هل أنت متأكد من تحسين قاعدة البيانات؟</h5>
                
                <div class="modal-preview" id="modalPreviewBox">
                    <!-- سيتم تعبئته بـ JavaScript -->
                </div>
                
                <div style="background:#d4edda; padding:12px; border-radius:10px; text-align:right; font-size:13px; color:#155724;">
                    💾 <strong>سيتم إنشاء نسخة احتياطية تلقائياً</strong> قبل التنفيذ.
                </div>
            </div>
            <div class="modal-footer" style="justify-content:center;">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius:30px; padding:10px 25px;">
                    إلغاء
                </button>
                <button type="button" class="btn btn-success" id="confirmOptimizeBtn" style="border-radius:30px; padding:10px 25px; font-weight:bold;">
                    ✅ نعم، ابدأ التحسين
                </button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // ============================================================
    // تفعيل/تعطيل نمط التحديد
    // ============================================================
    const archiveOld = document.getElementById('archiveOld');
    const deleteNotifs = document.getElementById('deleteNotifs');
    const labelArchiveOld = document.getElementById('labelArchiveOld');
    const labelDeleteNotifs = document.getElementById('labelDeleteNotifs');
    
    function updateStyle(cb, label) {
        if (cb.checked) {
            label.classList.add('checked');
            if (cb === archiveOld) {
                label.classList.add('archive');
            }
        } else {
            label.classList.remove('checked');
            label.classList.remove('archive');
        }
    }
    
    if (archiveOld) archiveOld.addEventListener('change', () => updateStyle(archiveOld, labelArchiveOld));
    if (deleteNotifs) deleteNotifs.addEventListener('change', () => updateStyle(deleteNotifs, labelDeleteNotifs));
    
    // ============================================================
    // فتح Modal مع معاينة
    // ============================================================
    document.getElementById('openOptimizeModal').addEventListener('click', function() {
        let html = '<strong>📋 ملخص العملية:</strong><ul>';
        html += '<li>✅ <strong>VACUUM</strong> — إعادة بناء قاعدة البيانات</li>';
        html += '<li>✅ <strong>ANALYZE</strong> — تحديث إحصائيات الاستعلامات</li>';
        html += '<li>💾 <strong>إنشاء نسخة احتياطية إلزامية</strong></li>';
        
        if (archiveOld && archiveOld.checked) {
            html += '<li style="color:#17a2b8;">📦 <strong>أرشفة <?= number_format($oldDeductions) ?> اقتطاع</strong> و <strong><?= number_format($oldInstallments) ?> قسط</strong> (قابلة للاستعادة)</li>';
        }
        
        if (deleteNotifs && deleteNotifs.checked) {
            html += '<li style="color:#dc3545;">📢 <strong>حذف <?= number_format($oldNotifications) ?> إشعار قديم</strong> (نهائي)</li>';
        }
        
        if ((!archiveOld || !archiveOld.checked) && (!deleteNotifs || !deleteNotifs.checked)) {
            html += '<li style="color:#28a745;">✨ <strong>تحسين فقط</strong> — لا حذف ولا أرشفة</li>';
        }
        
        html += '</ul>';
        document.getElementById('modalPreviewBox').innerHTML = html;
        
        const modal = new bootstrap.Modal(document.getElementById('optimizeModal'));
        modal.show();
    });
    
    // ============================================================
    // تأكيد التنفيذ
    // ============================================================
    document.getElementById('confirmOptimizeBtn').addEventListener('click', function() {
        this.disabled = true;
        this.innerHTML = '⏳ جاري التنفيذ...';
        document.getElementById('optimizeForm').submit();
    });
});
</script>

<?php
ob_end_flush();
include 'includes/footer.php';
?>