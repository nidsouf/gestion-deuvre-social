<?php
// config/database.php - متوافق مع PHP Desktop

// محاولة العثور على ملف قاعدة البيانات في مسارات متعددة
$possiblePaths = [
    __DIR__ . '/../data/deductions.db',           // المسار الطبيعي
    __DIR__ . '/../../data/deductions.db',        // مستوى أعلى
    getcwd() . '/data/deductions.db',             // مسار العمل الحالي
    getcwd() . '/www/data/deductions.db',         // داخل www
    'E:/نظام تسيير لجنة الخدمات الاجتماعية/dist/win-unpacked/resources/data/deductions.db', // مسار مطلق قديم
    'C:/PHPDesktop/www/data/deductions.db'        // مسار PHP Desktop الافتراضي
];

$dbFile = null;
foreach ($possiblePaths as $path) {
    if (file_exists($path)) {
        $dbFile = $path;
        break;
    }
}

// إذا لم نعثر على الملف، حاول إنشاء مجلد data وملف قاعدة بيانات فارغ
if (!$dbFile) {
    $dataDir = __DIR__ . '/../data';
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0777, true);
    }
    $dbFile = $dataDir . '/deductions.db';
    // سيتم إنشاء الملف فارغاً لاحقاً
}

try {
    $pdo = new PDO("sqlite:$dbFile");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("PRAGMA foreign_keys = ON");
    
    // إذا كان الملف جديداً، قم بإنشاء الجداول الأساسية (اختياري)
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='employees'")->fetch();
    if (!$tables) {
        // هنا يمكنك وضع كود إنشاء الجداول إذا أردت
        // أو يمكنك نسخ قاعدة البيانات من مكان آخر
    }
} catch (PDOException $e) {
    die("فشل الاتصال بقاعدة البيانات: " . $e->getMessage() . "<br>المسار الذي تمت محاولته: " . $dbFile);
}

// إذا كنت تستخدم SQLite3 بدلاً من PDO، فاستخدم هذا الجزء بدلاً من أعلاه
// لكننا نستخدم PDO هنا لأنه أكثر توافقاً مع الكود الحالي
?>