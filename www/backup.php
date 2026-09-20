<?php
/**
 * backup.php - النسخ الاحتياطي (مع Bootstrap Modals + POST + CSRF)
 */
if (!function_exists('simpleLog')) {
    function simpleLog($msg) {
        $logDir = __DIR__ . '/logs/';
        if (!is_dir($logDir)) mkdir($logDir, 0777, true);
        $logFile = $logDir . 'backup_errors.log';
        $time = date('Y-m-d H:i:s');
        file_put_contents($logFile, "[$time] $msg" . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
require_once 'config/database.php';
require_once 'includes/security.php';
require_once 'includes/functions.php';

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

// ============================================================
// اكتشاف مسار قاعدة البيانات
// ============================================================
try {
    $dbInfo = $pdo->query("PRAGMA database_list")->fetch(PDO::FETCH_ASSOC);
    $dbPath = $dbInfo['file'] ?? '';
} catch (Exception $e) {
    die("تعذّر تحديد مسار قاعدة البيانات: " . $e->getMessage());
}

if (empty($dbPath) || !file_exists($dbPath)) {
    $candidates = [
        __DIR__ . '/database.sqlite',
        __DIR__ . '/data/deductions.db',
        __DIR__ . '/config/database.sqlite',
        __DIR__ . '/../database.sqlite',
    ];
    foreach ($candidates as $c) {
        if (file_exists($c)) {
            $dbPath = $c;
            break;
        }
    }
}

if (empty($dbPath) || !file_exists($dbPath)) {
    die("❌ لم يتم العثور على ملف قاعدة البيانات. المسار المكتشف: " . htmlspecialchars($dbPath ?: '—'));
}

// ============================================================
// إعدادات مجلد النسخ
// ============================================================
$backupDir = __DIR__ . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR;
if (!is_dir($backupDir)) mkdir($backupDir, 0777, true);

$msg = '';
$msgType = '';

// ============================================================
// معالجة POST (بدل GET)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRFToken();
    
    $action = $_POST['action'] ?? '';
    
    // ============================================================
    // 1. إنشاء نسخة
    // ============================================================
    if ($action === 'create') {
        simpleLog("طلب إنشاء نسخة. المصدر: $dbPath");
        
        if (!file_exists($dbPath)) {
            $msg = "❌ ملف قاعدة البيانات غير موجود: " . htmlspecialchars($dbPath);
            $msgType = "error";
        } elseif (!is_readable($dbPath)) {
            $msg = "❌ لا يمكن قراءة ملف قاعدة البيانات (صلاحيات)";
            $msgType = "error";
        } else {
            $date = date('Y-m-d_H-i-s');
            $filename = "backup_{$date}.db";
            $target = $backupDir . $filename;
            
            if (copy($dbPath, $target)) {
                $msg = "✅ تم إنشاء النسخة: $filename";
                $msgType = "success";
                simpleLog("تم الإنشاء: $filename (" . filesize($target) . " بايت)");
                
                // حذف النسخ القديمة (احتفظ بـ 30)
                $allBackups = glob($backupDir . 'backup_*.db');
                rsort($allBackups);
                if (count($allBackups) > 30) {
                    $toDelete = array_slice($allBackups, 30);
                    foreach ($toDelete as $old) {
                        @unlink($old);
                        simpleLog("حذف نسخة قديمة: " . basename($old));
                    }
                }
            } else {
                $msg = "❌ فشل نسخ الملف. تحقق من صلاحيات مجلد backups/";
                $msgType = "error";
                simpleLog("فشل copy() من $dbPath إلى $target");
            }
        }
    }
    
    // ============================================================
    // 2. استعادة نسخة
    // ============================================================
    elseif ($action === 'restore') {
        $filename = basename($_POST['filename'] ?? '');
        $source = $backupDir . $filename;
        simpleLog("طلب استعادة: $filename");
        
        if (!file_exists($source)) {
            $msg = "❌ ملف النسخة غير موجود";
            $msgType = "error";
        } elseif (!is_readable($source)) {
            $msg = "❌ لا يمكن قراءة ملف النسخة";
            $msgType = "error";
        } elseif (!is_writable($dbPath)) {
            $msg = "❌ ملف قاعدة البيانات الحالي غير قابل للكتابة";
            $msgType = "error";
        } else {
            // نسخة أمان قبل الاستعادة
            $safetyFile = $backupDir . 'before_restore_' . date('Y-m-d_H-i-s') . '.db';
            @copy($dbPath, $safetyFile);
            
            if (copy($source, $dbPath)) {
                $msg = "✅ تم استعادة النسخة: $filename";
                $msgType = "success";
                simpleLog("استعادة ناجحة: $filename (نسخة أمان: " . basename($safetyFile) . ")");
                echo "<meta http-equiv='refresh' content='2;url=backup.php'>";
            } else {
                $msg = "❌ فشلت الاستعادة";
                $msgType = "error";
                simpleLog("فشل copy() من $source إلى $dbPath");
            }
        }
    }
    
    // ============================================================
    // 3. حذف نسخة
    // ============================================================
    elseif ($action === 'delete') {
        $filename = basename($_POST['filename'] ?? '');
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
}

// جلب قائمة النسخ
$backups = glob($backupDir . '*.db');
rsort($backups);

$csrf_token = generateCSRFToken();
include 'includes/header.php';
?>

<style>
    body { background: #f0f2f5; font-family: 'Tajawal', sans-serif; }
    .backup-container { max-width: 1200px; margin: 30px auto; padding: 0 20px; }
    .card { background: white; border-radius: 28px; padding: 25px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); margin-bottom: 30px; }
    h2 { color: #1e3c72; margin-top: 0; }
    .alert { padding: 12px 20px; border-radius: 40px; margin-bottom: 20px; font-weight: bold; }
    .alert-success { background: #d4edda; color: #155724; border-right: 4px solid #28a745; }
    .alert-error { background: #f8d7da; color: #721c24; border-right: 4px solid #dc3545; }
    .alert-info { background: #d1ecf1; color: #0c5460; border-right: 4px solid #17a2b8; }
    
    .btn { display: inline-block; padding: 10px 20px; border-radius: 40px; text-decoration: none; font-weight: bold; margin: 5px; transition: 0.2s; border: none; cursor: pointer; font-size: 14px; }
    .btn:hover { transform: translateY(-2px); }
    .btn-primary { background: #28a745; color: white; }
    .btn-primary:hover { background: #218838; }
    .btn-warning { background: #ffc107; color: #333; }
    .btn-warning:hover { background: #e0a800; }
    .btn-danger { background: #dc3545; color: white; }
    .btn-danger:hover { background: #c82333; }
    .btn-info { background: #17a2b8; color: white; }
    .btn-info:hover { background: #138496; }
    
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 12px; text-align: center; border-bottom: 1px solid #ddd; }
    th { background: #1e3c72; color: white; }
    tr:hover td { background: #f9f9f9; }
    
    .info { background: #e3f2fd; padding: 15px; border-radius: 20px; margin-top: 20px; border-right: 4px solid #2a5298; font-size: 13px; }
    
    .path-info {
        background: #f8f9fa;
        padding: 10px 15px;
        border-radius: 10px;
        margin-bottom: 20px;
        font-family: monospace;
        font-size: 12px;
        color: #495057;
        border-right: 4px solid #17a2b8;
        word-break: break-all;
    }
    
    .action-cell { white-space: nowrap; }
    .action-cell .btn { padding: 6px 12px; font-size: 12px; margin: 2px; }
    
    /* تحسين مظهر Modal */
    .modal-content {
        border: none;
        border-radius: 20px;
        box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        direction: rtl;
    }
    .modal-header {
        border-radius: 20px 20px 0 0;
        padding: 20px 25px;
    }
    .modal-body { padding: 25px; }
    .modal-footer { padding: 15px 25px; border-top: 1px solid #e9ecef; }
    
    .modal-icon {
        font-size: 48px;
        margin-bottom: 15px;
        display: block;
    }
    
    .modal-warning .modal-header { background: #ffc107; color: #333; }
    .modal-danger .modal-header { background: #dc3545; color: white; }
    .modal-success .modal-header { background: #28a745; color: white; }
    
    .details-box {
        background: #f8f9fa;
        padding: 15px;
        border-radius: 10px;
        text-align: right;
        margin: 15px 0;
        font-size: 14px;
    }
    
    .details-box .row {
        display: flex;
        justify-content: space-between;
        padding: 5px 0;
        border-bottom: 1px dotted #ddd;
    }
    .details-box .row:last-child { border-bottom: none; }
    .details-box .label { font-weight: 700; color: #555; }
    .details-box .value { color: #1a1a2e; font-weight: 600; }
</style>

<div class="backup-container">
    <div class="card">
        <h2><i class="fas fa-database"></i> النسخ الاحتياطي</h2>

        <!-- عرض مسار قاعدة البيانات -->
        <div class="path-info">
            <strong>📁 مسار قاعدة البيانات:</strong><br>
            <?= htmlspecialchars($dbPath) ?>
            <br>
            <strong>📊 الحجم:</strong> <?= number_format(filesize($dbPath) / 1024, 2) ?> ك.ب
        </div>
        
        <?php if ($msg): ?>
            <div class="alert alert-<?= $msgType ?>"><?= $msg ?></div>
        <?php endif; ?>
        
        <div style="margin-bottom: 30px;">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createModal">
                ➕ إنشاء نسخة
            </button>
            <button type="button" class="btn btn-info" onclick="location.reload()">
                🔄 تحديث القائمة
            </button>
        </div>
        
        <h3>📦 النسخ المتاحة (<?= count($backups) ?>)</h3>
        
        <?php if (empty($backups)): ?>
            <p style="text-align:center; padding:20px; color:#6c757d;">
                📭 لا توجد نسخ احتياطية حتى الآن
            </p>
        <?php else: ?>
            <div style="overflow-x:auto;">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>اسم الملف</th>
                            <th>الحجم (ك.ب)</th>
                            <th>تاريخ الإنشاء</th>
                            <th>الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 1; foreach ($backups as $file):
                            $name = basename($file);
                            $size = round(filesize($file) / 1024, 2);
                            $date = date('Y-m-d H:i:s', filemtime($file));
                        ?>
                        <tr>
                            <td><?= $i++ ?></td>
                            <td style="font-family: monospace; font-size: 12px; text-align:right;">
                                <?= htmlspecialchars($name) ?>
                            </td>
                            <td><?= $size ?> ك.ب</td>
                            <td><?= $date ?></td>
                            <td class="action-cell">
                                <button type="button" class="btn btn-warning" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#restoreModal"
                                        data-filename="<?= htmlspecialchars($name) ?>"
                                        data-size="<?= $size ?>"
                                        data-date="<?= $date ?>">
                                    🔄 استعادة
                                </button>
                                <button type="button" class="btn btn-danger"
                                        data-bs-toggle="modal"
                                        data-bs-target="#deleteModal"
                                        data-filename="<?= htmlspecialchars($name) ?>"
                                        data-size="<?= $size ?>"
                                        data-date="<?= $date ?>">
                                    🗑️ حذف
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        
        <div class="info">
            <i class="fas fa-lightbulb"></i> <strong>ملاحظة:</strong>
            <ul style="margin: 8px 0; padding-right: 20px;">
                <li>يتم اكتشاف مسار قاعدة البيانات تلقائياً من الاتصال الحالي.</li>
                <li>يتم الاحتفاظ بـ 30 نسخة كحد أقصى، وتُحذف الأقدم تلقائياً.</li>
                <li>عند الاستعادة، يتم إنشاء نسخة أمان باسم <code>before_restore_*.db</code>.</li>
                <li>النسخ محفوظة في: <code>backups/</code></li>
            </ul>
        </div>
    </div>
</div>

<!-- ============================================================
     Modal: تأكيد إنشاء نسخة
============================================================ -->
<div class="modal fade" id="createModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header modal-success">
                <h5 class="modal-title">➕ تأكيد إنشاء نسخة</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <span class="modal-icon">💾</span>
                <h5>هل تريد إنشاء نسخة احتياطية جديدة؟</h5>
                <p style="color:#6c757d; font-size:14px;">
                    سيتم نسخ قاعدة البيانات الحالية إلى مجلد <code>backups/</code>.
                </p>
                <div class="details-box">
                    <div class="row">
                        <span class="label">📁 المصدر:</span>
                        <span class="value" style="font-family:monospace; font-size:11px;">
                            <?= htmlspecialchars(basename($dbPath)) ?>
                        </span>
                    </div>
                    <div class="row">
                        <span class="label">📊 الحجم:</span>
                        <span class="value"><?= number_format(filesize($dbPath) / 1024, 2) ?> ك.ب</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="action" value="create">
                    <button type="submit" class="btn btn-primary">✅ نعم، إنشاء النسخة</button>
                </form>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================
     Modal: تأكيد الاستعادة
============================================================ -->
<div class="modal fade" id="restoreModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header modal-warning">
                <h5 class="modal-title">⚠️ تأكيد الاستعادة</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <span class="modal-icon">🔄</span>
                <h5>هل أنت متأكد من استعادة هذه النسخة؟</h5>
                <div class="details-box">
                    <div class="row">
                        <span class="label">📄 اسم الملف:</span>
                        <span class="value" style="font-family:monospace; font-size:11px;" id="restoreFilename"></span>
                    </div>
                    <div class="row">
                        <span class="label">📊 الحجم:</span>
                        <span class="value" id="restoreSize"></span>
                    </div>
                    <div class="row">
                        <span class="label">📅 التاريخ:</span>
                        <span class="value" id="restoreDate"></span>
                    </div>
                </div>
                <div class="alert alert-warning" style="text-align:right; font-size:13px; margin:0;">
                    ⚠️ <strong>تحذير:</strong> سيتم استبدال قاعدة البيانات الحالية بالكامل.
                    <br>
                    ℹ️ سيتم إنشاء نسخة أمان تلقائياً قبل الاستعادة.
                </div>
            </div>
            <div class="modal-footer">
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="action" value="restore">
                    <input type="hidden" name="filename" id="restoreFilenameInput">
                    <button type="submit" class="btn btn-warning">🔄 نعم، استعادة</button>
                </form>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================
     Modal: تأكيد الحذف
============================================================ -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header modal-danger">
                <h5 class="modal-title">🗑️ تأكيد الحذف</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <span class="modal-icon">⚠️</span>
                <h5>هل أنت متأكد من حذف هذا الملف؟</h5>
                <div class="details-box">
                    <div class="row">
                        <span class="label">📄 اسم الملف:</span>
                        <span class="value" style="font-family:monospace; font-size:11px;" id="deleteFilename"></span>
                    </div>
                    <div class="row">
                        <span class="label">📊 الحجم:</span>
                        <span class="value" id="deleteSize"></span>
                    </div>
                    <div class="row">
                        <span class="label">📅 التاريخ:</span>
                        <span class="value" id="deleteDate"></span>
                    </div>
                </div>
                <p style="color:#dc3545; font-size:13px; margin:0;">
                    ⚠️ لا يمكن التراجع عن هذه العملية!
                </p>
            </div>
            <div class="modal-footer">
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="filename" id="deleteFilenameInput">
                    <button type="submit" class="btn btn-danger">🗑️ نعم، حذف</button>
                </form>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
            </div>
        </div>
    </div>
</div>

<script>
// ============================================================
// تعبئة بيانات Modal الاستعادة
// ============================================================
document.getElementById('restoreModal')?.addEventListener('show.bs.modal', function(event) {
    const button = event.relatedTarget;
    const filename = button.getAttribute('data-filename');
    const size = button.getAttribute('data-size');
    const date = button.getAttribute('data-date');
    
    document.getElementById('restoreFilename').textContent = filename;
    document.getElementById('restoreSize').textContent = size + ' ك.ب';
    document.getElementById('restoreDate').textContent = date;
    document.getElementById('restoreFilenameInput').value = filename;
});

// ============================================================
// تعبئة بيانات Modal الحذف
// ============================================================
document.getElementById('deleteModal')?.addEventListener('show.bs.modal', function(event) {
    const button = event.relatedTarget;
    const filename = button.getAttribute('data-filename');
    const size = button.getAttribute('data-size');
    const date = button.getAttribute('data-date');
    
    document.getElementById('deleteFilename').textContent = filename;
    document.getElementById('deleteSize').textContent = size + ' ك.ب';
    document.getElementById('deleteDate').textContent = date;
    document.getElementById('deleteFilenameInput').value = filename;
});
</script>

<?php include 'includes/footer.php'; ?>