<?php
/**
 * config/database.php - إعدادات قاعدة البيانات
 * متوافق مع SQLite3 و PDO
 */

// تحديد المسار الصحيح لقاعدة البيانات
if (strpos(__DIR__, '\\resources\\www\\') !== false) {
    // داخل الحزمة (PHP Desktop)
    $dbFile = dirname(__DIR__, 2) . '/data/deductions.db';
} else {
    // بيئة التطوير العادية
    $dbFile = dirname(__DIR__, 2) . '/data/deductions.db';
}

// التأكد من وجود المجلد
$dataDir = dirname($dbFile);
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0777, true);
}

// إعدادات PDO
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO("sqlite:$dbFile", null, null, $options);
    $pdo->exec("PRAGMA foreign_keys = ON");
    $pdo->exec("PRAGMA journal_mode = WAL");
    $pdo->exec("PRAGMA cache_size = -2000");
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("فشل الاتصال بقاعدة البيانات: " . $e->getMessage());
}

/**
 * دالة لتنفيذ العمليات داخل Transaction
 * @param callable $callback دالة تحتوي على العمليات المطلوبة
 * @return mixed نتيجة الدالة
 * @throws Exception عند فشل أي عملية
 */
function db_transaction($callback) {
    global $pdo;
    try {
        $pdo->beginTransaction();
        $result = $callback($pdo);
        $pdo->commit();
        return $result;
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Transaction failed: " . $e->getMessage());
        throw $e;
    }
}

/**
 * دالة لإعداد قاعدة البيانات (إنشاء الجداول الأساسية)
 * @param PDO $pdo اتصال قاعدة البيانات
 */
function setupDatabase($pdo) {
    $tables = [
        // جدول المستخدمين
        "CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            role TEXT DEFAULT 'user',
            is_active INTEGER DEFAULT 1,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
        )",
        // جدول سجل التدقيق
        "CREATE TABLE IF NOT EXISTS audit_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            username TEXT,
            action TEXT NOT NULL,
            details TEXT,
            ip_address TEXT,
            user_agent TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )",
        // جدول الإشعارات
        "CREATE TABLE IF NOT EXISTS notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            title TEXT NOT NULL,
            message TEXT NOT NULL,
            type TEXT DEFAULT 'info',
            priority TEXT DEFAULT 'normal',
            is_read INTEGER DEFAULT 0,
            link TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )"
    ];
    
    foreach ($tables as $sql) {
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            error_log("Table creation failed: " . $e->getMessage());
        }
    }
}

// إعداد قاعدة البيانات (مرة واحدة)
setupDatabase($pdo);

// دالة مساعدة للاستعلامات السريعة
function db_query($sql, $params = []) {
    global $pdo;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

// دالة للحصول على صف واحد
function db_fetch_one($sql, $params = []) {
    return db_query($sql, $params)->fetch();
}

// دالة للحصول على جميع الصفوف
function db_fetch_all($sql, $params = []) {
    return db_query($sql, $params)->fetchAll();
}

// دالة لإدراج سجل والحصول على آخر ID
function db_insert($table, $data) {
    global $pdo;
    $columns = implode(',', array_keys($data));
    $placeholders = ':' . implode(',:', array_keys($data));
    $stmt = $pdo->prepare("INSERT INTO $table ($columns) VALUES ($placeholders)");
    $stmt->execute($data);
    return $pdo->lastInsertId();
}

// دالة لتحديث سجل
function db_update($table, $data, $where, $whereParams = []) {
    global $pdo;
    $set = [];
    foreach (array_keys($data) as $col) {
        $set[] = "$col = :$col";
    }
    $sql = "UPDATE $table SET " . implode(',', $set) . " WHERE $where";
    $params = array_merge($data, $whereParams);
    return db_query($sql, $params)->rowCount();
}

// دالة لحذف سجل
function db_delete($table, $where, $params = []) {
    return db_query("DELETE FROM $table WHERE $where", $params)->rowCount();
}
?>