<?php
/**
 * performance.php - تحسين أداء قاعدة البيانات
 * توفر: Caching, Query Optimization Helpers, Result Validation
 */

class QueryCache {
    private static $cache = [];
    private static $enabled = true;
    private static $ttl = 300; // 5 دقائق افتراضياً
    
    /**
     * الحصول على نتيجة مخزنة
     */
    public static function get($key) {
        if (!self::$enabled) return null;
        if (isset(self::$cache[$key]) && (time() - self::$cache[$key]['time']) < self::$ttl) {
            return self::$cache[$key]['data'];
        }
        return null;
    }
    
    /**
     * تخزين نتيجة
     */
    public static function set($key, $data) {
        if (!self::$enabled) return false;
        self::$cache[$key] = ['data' => $data, 'time' => time()];
        return true;
    }
    
    /**
     * تفعيل/تعطيل التخزين
     */
    public static function enable($status = true) {
        self::$enabled = $status;
    }
    
    /**
     * مسح جميع المخزون
     */
    public static function clear() {
        self::$cache = [];
    }
    
    /**
     * تعيين مدة التخزين (بالثواني)
     */
    public static function setTTL($seconds) {
        self::$ttl = $seconds;
    }
}

/**
 * تنفيذ استعلام مع تخزين النتيجة
 * @param PDO $pdo اتصال قاعدة البيانات
 * @param string $sql الاستعلام
 * @param array $params المعاملات
 * @param int $ttl مدة التخزين (ثواني)
 * @return array النتائج
 */
function cachedQuery($pdo, $sql, $params = [], $ttl = 300) {
    $cacheKey = md5($sql . serialize($params));
    $cached = QueryCache::get($cacheKey);
    if ($cached !== null) {
        return $cached;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $result = $stmt->fetchAll();
    
    QueryCache::set($cacheKey, $result);
    return $result;
}

/**
 * تنفيذ استعلام لصف واحد مع تخزين
 */
function cachedFetchOne($pdo, $sql, $params = [], $ttl = 300) {
    $cacheKey = md5('one_' . $sql . serialize($params));
    $cached = QueryCache::get($cacheKey);
    if ($cached !== null) {
        return $cached;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $result = $stmt->fetch();
    
    QueryCache::set($cacheKey, $result);
    return $result;
}

/**
 * التحقق من صحة نتيجة الاستعلام
 * @param mixed $result نتيجة الاستعلام
 * @param string $errorMessage رسالة خطأ مخصصة
 * @return mixed النتيجة أو false
 */
function validateResult($result, $errorMessage = '') {
    if ($result === false || $result === null) {
        if ($errorMessage) {
            error_log("Query validation failed: " . $errorMessage);
        }
        return false;
    }
    return $result;
}

/**
 * الحصول على قيمة مفردة مع تخزين
 */
function cachedFetchColumn($pdo, $sql, $params = [], $ttl = 300) {
    $cacheKey = md5('col_' . $sql . serialize($params));
    $cached = QueryCache::get($cacheKey);
    if ($cached !== null) {
        return $cached;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $result = $stmt->fetchColumn();
    
    QueryCache::set($cacheKey, $result);
    return $result;
}

/**
 * مسح المخزون المرتبط بجدول معين (عند التعديل)
 */
function invalidateTableCache($table) {
    // مسح جميع المخزون مؤقتاً (يمكن تحسينه لاحقاً)
    QueryCache::clear();
}

/**
 * تحميل كسول (Lazy Loading) للبيانات
 */
class LazyLoader {
    private $pdo;
    private $loaded = [];
    private $data = [];
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * تحميل بيانات الموظف عند الحاجة
     */
    public function loadEmployee($employeeId) {
        if (!isset($this->loaded['employee_' . $employeeId])) {
            $stmt = $this->pdo->prepare("SELECT * FROM employees WHERE id = ?");
            $stmt->execute([$employeeId]);
            $this->data['employee_' . $employeeId] = $stmt->fetch();
            $this->loaded['employee_' . $employeeId] = true;
        }
        return $this->data['employee_' . $employeeId];
    }
    
    /**
     * تحميل بيانات المصدر عند الحاجة
     */
    public function loadSource($sourceId) {
        if (!isset($this->loaded['source_' . $sourceId])) {
            $stmt = $this->pdo->prepare("SELECT * FROM sources WHERE id = ?");
            $stmt->execute([$sourceId]);
            $this->data['source_' . $sourceId] = $stmt->fetch();
            $this->loaded['source_' . $sourceId] = true;
        }
        return $this->data['source_' . $sourceId];
    }
    
    /**
     * تحميل بيانات المنحة عند الحاجة
     */
    public function loadGrant($grantId) {
        if (!isset($this->loaded['grant_' . $grantId])) {
            $stmt = $this->pdo->prepare("SELECT * FROM grants WHERE id = ?");
            $stmt->execute([$grantId]);
            $this->data['grant_' . $grantId] = $stmt->fetch();
            $this->loaded['grant_' . $grantId] = true;
        }
        return $this->data['grant_' . $grantId];
    }
}
?>