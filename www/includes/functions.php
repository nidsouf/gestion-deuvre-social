<?php
/**
 * functions.php - دوال مساعدة عامة للنظام (نسخة مصححة نهائياً)
 */

require_once __DIR__ . '/../config/database.php';

// ============================================================
// دوال XSS Protection (موجودة هنا فقط، وليست في security.php)
// ============================================================

if (!function_exists('escape')) {
    function escape($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('sanitizeInput')) {
    function sanitizeInput($input) {
        return trim(htmlspecialchars($input, ENT_QUOTES, 'UTF-8'));
    }
}

if (!function_exists('formatNumber')) {
    function formatNumber($number) {
        return number_format($number, 2, '.', ',');
    }
}

if (!function_exists('validateDate')) {
    function validateDate($date) {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }
}

// ============================================================
// دوال الموظفين والمصادر
// ============================================================

function getSourceName($source_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT name FROM sources WHERE id = ?");
    $stmt->execute([$source_id]);
    return $stmt->fetchColumn() ?: 'مصدر غير معروف';
}

function getAllSources($pdo) {
    $stmt = $pdo->query("SELECT * FROM sources ORDER BY name");
    return $stmt->fetchAll();
}

function getEmployeeCategory($employee_id, $pdo) {
    $stmt = $pdo->prepare("SELECT category FROM employees WHERE id = ?");
    $stmt->execute([$employee_id]);
    $result = $stmt->fetch();
    return $result ? $result['category'] : 'Contract';
}

function getMonthlyInstallment($startDate, $selectedDate, $totalMonths) {
    $start = new DateTime($startDate);
    $selected = new DateTime($selectedDate);
    $diff = $selected->diff($start);
    $months = $diff->y * 12 + $diff->m;
    $current = $months + 1;
    if ($current < 1) $current = 1;
    if ($current > $totalMonths) $current = $totalMonths;
    return $current . ' / ' . $totalMonths;
}

// ============================================================
// دوال الميزانية
// ============================================================

function updateBudget($amount, $operation) {
    global $pdo;
    try {
        $stmt = $pdo->query("SELECT id, remaining_budget FROM social_budget ORDER BY year DESC LIMIT 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;
        
        $newAmount = ($operation === 'deduct') ? $row['remaining_budget'] - $amount : $row['remaining_budget'] + $amount;
        $update = $pdo->prepare("UPDATE social_budget SET remaining_budget = ?, last_updated = CURRENT_TIMESTAMP WHERE id = ?");
        return $update->execute([$newAmount, $row['id']]);
    } catch (Exception $e) {
        error_log("Budget update failed: " . $e->getMessage());
        return false;
    }
}

// ============================================================
// دوال النسخ الاحتياطي
// ============================================================

function createBackup($backupDir = null) {
    $dbFile = __DIR__ . '/../database.sqlite';
    if (!file_exists($dbFile)) return null;
    if (!$backupDir) $backupDir = __DIR__ . '/../backups';
    if (!is_dir($backupDir)) mkdir($backupDir, 0777, true);
    
    $backupFile = $backupDir . '/backup_' . date('Y-m-d_H-i-s') . '.db';
    if (copy($dbFile, $backupFile)) {
        foreach (glob($backupDir . '/backup_*.db') as $file) {
            if (filemtime($file) < strtotime('-30 days')) unlink($file);
        }
        return $backupFile;
    }
    return null;
}

// ============================================================
// دوال التدقيق والإشعارات
// ============================================================



function addNotification($title, $message, $user_id = null, $type = 'info', $priority = 'normal', $link = null) {
    global $pdo;
    $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, priority, link) VALUES (?, ?, ?, ?, ?, ?)");
    return $stmt->execute([$user_id, $title, $message, $type, $priority, $link]);
}

function getUserNotifications($user_id = null, $limit = 20) {
    global $pdo;
    if ($user_id === null) $user_id = $_SESSION['user_id'] ?? 0;
    $limit = (int)$limit;
    if ($limit < 1) $limit = 20;
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id IS NULL OR user_id = ? ORDER BY created_at DESC LIMIT $limit");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll();
}
/**
 * كتابة رسالة في سجل الأخطاء
 * @param string $message النص المسجل
 * @param string $level مستوى الخطأ (info, warning, error)
 */
function writeLog($message, $level = 'info') {
    $logDir = __DIR__ . '/../logs/';
    if (!file_exists($logDir)) {
        mkdir($logDir, 0777, true);
    }
    $logFile = $logDir . 'error.log';
    $timestamp = date('Y-m-d H:i:s');
    $levelUpper = strtoupper($level);
    $logMessage = "[$timestamp] [$levelUpper] $message" . PHP_EOL;
    file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
}
// ============================================================
// دوال تحويل الأرقام إلى حروف (اختيارية)
// ============================================================


function getMonthNameArabic($month) {
    $months = [
        1 => 'جانفي', 2 => 'فيفري', 3 => 'مارس', 4 => 'أفريل',
        5 => 'ماي', 6 => 'جوان', 7 => 'جويلية', 8 => 'أوت',
        9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر'
    ];
    return $months[(int)$month] ?? '';
}
function numberToWords($number) {
    $parts = explode('.', number_format($number, 2, '.', ''));
    $dinars = (int)$parts[0];
    $centimes = isset($parts[1]) ? (int)$parts[1] : 0;
    if ($dinars == 0 && $centimes == 0) return 'صفر دينار جزائري';
    $units = ['', 'واحد', 'اثنان', 'ثلاثة', 'أربعة', 'خمسة', 'ستة', 'سبعة', 'ثمانية', 'تسعة'];
    $teens = ['عشرة', 'أحد عشر', 'اثنا عشر', 'ثلاثة عشر', 'أربعة عشر', 'خمسة عشر', 'ستة عشر', 'سبعة عشر', 'ثمانية عشر', 'تسعة عشر'];
    $tens = ['', '', 'عشرون', 'ثلاثون', 'أربعون', 'خمسون', 'ستون', 'سبعون', 'ثمانون', 'تسعون'];
    $hundreds = ['', 'مائة', 'مائتان', 'ثلاثمائة', 'أربعمائة', 'خمسمائة', 'ستمائة', 'سبعمائة', 'ثمانمائة', 'تسعمائة'];
    
    function convertUnderThousand($num, $units, $teens, $tens, $hundreds) {
        if ($num == 0) return '';
        $h = (int)($num / 100);
        $r = $num % 100;
        $parts = [];
        if ($h > 0) $parts[] = $hundreds[$h];
        if ($r >= 10 && $r <= 19) $parts[] = $teens[$r - 10];
        elseif ($r > 0) {
            $t = (int)($r / 10);
            $u = $r % 10;
            if ($u > 0) $parts[] = $units[$u] . ' و ' . $tens[$t];
            else $parts[] = $tens[$t];
        }
        return implode(' و ', $parts);
    }
    
    $result = '';
    if ($dinars > 0) {
        $thousands = (int)($dinars / 1000);
        $remainder = $dinars % 1000;
        if ($thousands > 0) {
            if ($thousands == 1) $result .= 'ألف';
            elseif ($thousands == 2) $result .= 'ألفان';
            else {
                $thousandsWord = convertUnderThousand($thousands, $units, $teens, $tens, $hundreds);
                if ($thousands >= 3 && $thousands <= 10) $result .= $thousandsWord . ' آلاف';
                else $result .= $thousandsWord . ' ألفًا';
            }
            if ($remainder > 0) $result .= ' و ';
        }
        if ($remainder > 0) $result .= convertUnderThousand($remainder, $units, $teens, $tens, $hundreds);
        $result .= ' دينار';
    }
    if ($centimes > 0) {
        if ($dinars > 0) $result .= ' و ';
        $result .= convertUnderThousand($centimes, $units, $teens, $tens, $hundreds) . ' سنتيم';
    }
    $result .= ' فقط لا غير';
    return $result;
}
/**
 * إنشاء أو تحديث أقساط شهرية لاقتطاع معين
 */
function regenerateMonthlyInstallments($deductionId, $deleteExisting = false) {
    global $pdo;

    // جلب بيانات الاقتطاع
    $stmt = $pdo->prepare("SELECT * FROM deductions WHERE id = ?");
    $stmt->execute([$deductionId]);
    $deduction = $stmt->fetch();
    if (!$deduction) return false;

    // حساب عدد الأقساط بناءً على total_months
    $start = new DateTime($deduction['start_date']);
    $end = new DateTime($deduction['end_date']);
    $interval = $start->diff($end);
    $totalMonths = ($interval->y * 12) + $interval->m + 1;
    if ($deduction['total_months'] > 0) {
        $totalMonths = $deduction['total_months'];
    }

    // لا نحذف أي شيء، فقط نضيف الأقساط المفقودة
    $current = clone $start;
    for ($i = 0; $i < $totalMonths; $i++) {
        $year = (int)$current->format('Y');
        $month = (int)$current->format('m');

        // التحقق من وجود قسط لهذا الشهر (بغض النظر عن حالته)
        $check = $pdo->prepare("SELECT id FROM monthly_installments WHERE deduction_id = ? AND year = ? AND month = ?");
        $check->execute([$deductionId, $year, $month]);
        if (!$check->fetch()) {
            // إدراج القسط
            $insert = $pdo->prepare("
                INSERT INTO monthly_installments (deduction_id, employee_id, source_id, year, month, amount, is_paid, is_postponed, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 0, 0, datetime('now'))
            ");
            $insert->execute([
                $deductionId,
                $deduction['employee_id'],
                $deduction['source_id'],
                $year,
                $month,
                $deduction['monthly_amount']
            ]);
        }

        $current->modify('+1 month');
    }

    return true;
}

/**
 * تسجيل عملية في سجل التدقيق
 */
function audit($action, $details, $entity_type = null, $entity_id = null, $old_values = null, $new_values = null) {
    global $pdo;
    $user_id = $_SESSION['user_id'] ?? null;
    $username = $_SESSION['username'] ?? 'guest';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    $stmt = $pdo->prepare("
        INSERT INTO audit_logs (user_id, username, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))
    ");
    
    $old_json = $old_values ? json_encode($old_values) : null;
    $new_json = $new_values ? json_encode($new_values) : null;
    
    $stmt->execute([$user_id, $username, $action, $entity_type, $entity_id, $old_json, $new_json, $ip, $user_agent]);
}

/**
 * جلب قيمة قاعدة من system_rules
 */
function getRule($rule_code, $default = null) {
    global $pdo;
    static $rules = [];
    
    if (empty($rules)) {
        $stmt = $pdo->query("SELECT rule_code, rule_value, rule_type FROM system_rules WHERE is_active = 1");
        while ($row = $stmt->fetch()) {
            $value = $row['rule_value'];
            if ($row['rule_type'] == 'number') {
                $value = (float)$value;
            } elseif ($row['rule_type'] == 'boolean') {
                $value = (bool)$value;
            } elseif ($row['rule_type'] == 'json') {
                $value = json_decode($value, true);
            }
            $rules[$row['rule_code']] = $value;
        }
    }
    
    return isset($rules[$rule_code]) ? $rules[$rule_code] : $default;
}

/**
 * تحديث قيمة قاعدة
 */
function updateRule($rule_code, $value) {
    global $pdo;
    $type = 'string';
    if (is_numeric($value)) {
        $type = 'number';
    } elseif (is_bool($value)) {
        $type = 'boolean';
        $value = $value ? '1' : '0';
    } elseif (is_array($value)) {
        $type = 'json';
        $value = json_encode($value);
    }
    
    $stmt = $pdo->prepare("UPDATE system_rules SET rule_value = ?, rule_type = ?, updated_at = datetime('now') WHERE rule_code = ?");
    return $stmt->execute([$value, $type, $rule_code]);
}
?>