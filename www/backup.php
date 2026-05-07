<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: login.php");
    exit;
}
require_once 'config/database.php';
include 'includes/header.php';

$backupDir = __DIR__ . '/backups/';
if (!file_exists($backupDir)) mkdir($backupDir, 0777, true);

$message = '';
$backupFile = null;

// إنشاء نسخة احتياطية
if (isset($_POST['create_backup'])) {
    $dbFile = __DIR__ . '/data/deductions.db';
    if (!file_exists($dbFile)) {
        $message = "<div class='alert-error'>❌ ملف قاعدة البيانات غير موجود</div>";
    } else {
        $externalPath = trim($_POST['external_path'] ?? '');
        $targetDir = $externalPath ?: $backupDir;
        
        // التحقق من صحة المسار الخارجي
        if ($externalPath && !is_dir($externalPath)) {
            if (!mkdir($externalPath, 0777, true)) {
                $message = "<div class='alert-error'>❌ لا يمكن إنشاء المجلد: $externalPath</div>";
                $targetDir = $backupDir;
            }
        }
        
        $date = date('Y-m-d_H-i-s');
        $filename = "backup_{$date}.db";
        $targetFile = rtrim($targetDir, '/\\') . DIRECTORY_SEPARATOR . $filename;
        
        if (copy($dbFile, $targetFile)) {
            $message = "<div class='alert-success'>✅ تم إنشاء النسخة الاحتياطية: " . basename($targetFile) . "<br>📍 المسار: " . $targetFile . "</div>";
        } else {
            $message = "<div class='alert-error'>❌ فشل في إنشاء النسخة الاحتياطية</div>";
        }
    }
}

// استعادة نسخة
if (isset($_GET['restore'])) {
    $file = $backupDir . basename($_GET['restore']);
    if (file_exists($file)) {
        $dbFile = __DIR__ . '/data/deductions.db';
        if (copy($file, $dbFile)) {
            $message = "<div class='alert-success'>✅ تم استعادة النسخة الاحتياطية بنجاح</div>";
        } else {
            $message = "<div class='alert-error'>❌ فشل في الاستعادة</div>";
        }
    }
}

// حذف نسخة
if (isset($_GET['delete_backup'])) {
    $file = $backupDir . basename($_GET['delete_backup']);
    if (file_exists($file) && unlink($file)) {
        $message = "<div class='alert-success'>✅ تم حذف الملف</div>";
    }
}

// قائمة النسخ المحلية
$backups = glob($backupDir . '*.db');
rsort($backups);
?>

<style>
    .alert-success { background: #d4edda; color: #155724; padding: 12px; border-radius: 12px; margin-bottom: 20px; }
    .alert-error { background: #f8d7da; color: #721c24; padding: 12px; border-radius: 12px; margin-bottom: 20px; }
    .form-group { margin-bottom: 15px; }
    .form-group label { display: block; font-weight: bold; margin-bottom: 5px; }
    .form-group input[type="text"] { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 12px; direction: ltr; text-align: left; }
    .form-group small { font-size: 12px; color: #666; display: block; margin-top: 5px; }
    .btn-folder { background: #6c757d; color: white; border: none; padding: 8px 15px; border-radius: 12px; cursor: pointer; margin-top: 5px; }
</style>

<h2>💾 النسخ الاحتياطي لقاعدة البيانات</h2>

<?= $message ?>

<form method="POST" style="background: #f8f9fa; padding: 20px; border-radius: 20px; margin-bottom: 30px;">
    <div class="form-group">
        <label>📁 مجلد الحفظ المخصص (اختياري)</label>
        <input type="text" name="external_path" id="external_path" placeholder="مثال: D:\MyBackups  أو  C:\Users\اسمي\Desktop\نسخ احتياطية" value="<?= htmlspecialchars($_POST['external_path'] ?? '') ?>">
        <small>اتركه فارغاً للحفظ في المجلد الافتراضي (backups). يمكنك استخدام مسار على قرص آخر أو USB.</small>
        <button type="button" class="btn-folder" onclick="selectFolder()">📂 اختيار مجلد</button>
    </div>
    <button type="submit" name="create_backup" class="btn-sm" style="background: #28a745;">📀 إنشاء نسخة احتياطية</button>
</form>

<script>
// محاولة استخدام واجهة اختيار المجلد الحديثة (Chrome/Edge)
async function selectFolder() {
    if ('showDirectoryPicker' in window) {
        try {
            const dirHandle = await window.showDirectoryPicker();
            const path = dirHandle.name; // لاحظ: لا يعطي المسار الكامل لأسباب أمنية، لكنه يعطي اسم المجلد فقط.
            // بدلاً من ذلك، نعرض المسار النسبي أو نطلب من المستخدم لصق المسار يدوياً.
            alert("تم اختيار المجلد: " + path + "\nالرجاء نسخ المسار الكامل يدوياً إلى الحقل أعلاه (مثال: D:\\MyBackups)");
        } catch(e) { console.log(e); }
    } else {
        alert("متصفحك لا يدعم اختيار المجلدات تلقائياً. الرجاء كتابة المسار يدوياً.");
    }
}
</script>

<h3>📁 النسخ المتاحة (المجلد الافتراضي)</h3>
<table class="data-table">
    <thead>
        <tr><th>اسم الملف</th><th>الحجم</th><th>التاريخ</th><th>الإجراءات</th></tr>
    </thead>
    <tbody>
        <?php if (empty($backups)): ?>
            <tr><td colspan="4" style="text-align: center;">لا توجد نسخ احتياطية</td></tr>
        <?php else: ?>
            <?php foreach($backups as $b): $name = basename($b); $size = round(filesize($b)/1024,2); $date = date('Y-m-d H:i:s', filemtime($b)); ?>
                <tr>
                    <td><?= $name ?></td>
                    <td><?= $size ?> ك.ب</td>
                    <td><?= $date ?></td>
                    <td>
                        <a href="?restore=<?= urlencode($name) ?>" onclick="return confirm('استعادة هذه النسخة؟')" style="color:green;">🔄 استعادة</a> |
                        <a href="?delete_backup=<?= urlencode($name) ?>" onclick="return confirm('حذف؟')" style="color:red;">🗑️ حذف</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>

<div style="margin-top: 20px; background: #e3f2fd; padding: 15px; border-radius: 12px;">
    <p><strong>💡 نصيحة:</strong> يمكنك حفظ النسخ الاحتياطية على قرص خارجي (USB) بكتابة المسار مثل <code>E:\Backups</code> في الحقل أعلاه. تأكد من أن المجلد قابل للكتابة.</p>
</div>

<?php include 'includes/footer.php'; ?>