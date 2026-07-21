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


/**
 * تحويل رقم الشهر إلى اسم الشهر بالعربية
 * @param int|string $month رقم الشهر (1-12)
 * @return string اسم الشهر بالعربية أو القيمة الأصلية
 */
function getMonthNameArabic($month) {
    $month = (int)$month;
    if ($month < 1 || $month > 12) {
        return (string)$month;
    }
    $months = [
        1 => 'جانفي', 2 => 'فيفري', 3 => 'مارس', 4 => 'أفريل',
        5 => 'ماي', 6 => 'جوان', 7 => 'جويلية', 8 => 'أوت',
        9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر'
    ];
    return $months[$month] ?? '';
}
/**
 * تحويل رقم إلى كلمات باللغة العربية (يدعم الملايين)
 * @param float $number الرقم (يمكن أن يحتوي على كسور)
 * @return string النص بالعربية
 */
function numberToWords($number) {
    // فصل الجزء الصحيح والكسري
    $parts = explode('.', number_format($number, 2, '.', ''));
    $integer = (int)$parts[0];
    $decimal = isset($parts[1]) ? (int)$parts[1] : 0;

    if ($integer == 0 && $decimal == 0) {
        return 'صفر دينار جزائري';
    }

    // المصفوفات الأساسية
    $units = ['', 'واحد', 'اثنان', 'ثلاثة', 'أربعة', 'خمسة', 'ستة', 'سبعة', 'ثمانية', 'تسعة'];
    $teens = ['عشرة', 'أحد عشر', 'اثنا عشر', 'ثلاثة عشر', 'أربعة عشر', 'خمسة عشر', 'ستة عشر', 'سبعة عشر', 'ثمانية عشر', 'تسعة عشر'];
    $tens = ['', '', 'عشرون', 'ثلاثون', 'أربعون', 'خمسون', 'ستون', 'سبعون', 'ثمانون', 'تسعون'];
    $hundreds = ['', 'مائة', 'مائتان', 'ثلاثمائة', 'أربعمائة', 'خمسمائة', 'ستمائة', 'سبعمائة', 'ثمانمائة', 'تسعمائة'];

    /**
     * تحويل عدد أقل من 1000 إلى كلمات
     */
    function convertUnderThousand($num, $units, $teens, $tens, $hundreds) {
        if ($num == 0) return '';
        $h = (int)($num / 100);
        $r = $num % 100;
        $parts = [];

        if ($h > 0) {
            // التأكد من أن $h بين 1 و 9
            if ($h >= 1 && $h <= 9) {
                $parts[] = $hundreds[$h];
            } else {
                // إذا تجاوز 9، نعيد معالجته كرقم (حالة نادرة)
                $parts[] = $h . 'مائة';
            }
        }

        if ($r >= 10 && $r <= 19) {
            $parts[] = $teens[$r - 10];
        } elseif ($r > 0) {
            $t = (int)($r / 10);
            $u = $r % 10;
            if ($u > 0) {
                $parts[] = $units[$u] . ' و ' . $tens[$t];
            } else {
                $parts[] = $tens[$t];
            }
        }

        return implode(' و ', $parts);
    }

    /**
     * تحويل عدد كبير (أكثر من 1000) إلى كلمات
     */
    function convertLargeNumber($num, $units, $teens, $tens, $hundreds) {
        if ($num == 0) return '';

        $millions = (int)($num / 1000000);
        $remainder = $num % 1000000;
        $thousands = (int)($remainder / 1000);
        $hundredsPart = $remainder % 1000;

        $parts = [];

        // الملايين
        if ($millions > 0) {
            if ($millions == 1) {
                $parts[] = 'مليون';
            } elseif ($millions == 2) {
                $parts[] = 'مليونان';
            } elseif ($millions >= 3 && $millions <= 10) {
                $parts[] = convertUnderThousand($millions, $units, $teens, $tens, $hundreds) . ' ملايين';
            } else {
                $parts[] = convertUnderThousand($millions, $units, $teens, $tens, $hundreds) . ' مليوناً';
            }
        }

        // الآلاف
        if ($thousands > 0) {
            if ($thousands == 1) {
                $parts[] = 'ألف';
            } elseif ($thousands == 2) {
                $parts[] = 'ألفان';
            } elseif ($thousands >= 3 && $thousands <= 10) {
                $parts[] = convertUnderThousand($thousands, $units, $teens, $tens, $hundreds) . ' آلاف';
            } else {
                $parts[] = convertUnderThousand($thousands, $units, $teens, $tens, $hundreds) . ' ألفاً';
            }
        }

        // المئات (أقل من 1000)
        if ($hundredsPart > 0) {
            $parts[] = convertUnderThousand($hundredsPart, $units, $teens, $tens, $hundreds);
        }

        return implode(' و ', $parts);
    }

    // بناء النص
    $result = '';
    if ($integer > 0) {
        $result = convertLargeNumber($integer, $units, $teens, $tens, $hundreds);
        $result .= ' دينار';
    }

    // إضافة الجزء الكسري (سنتيمات)
    if ($decimal > 0) {
        if ($integer > 0) {
            $result .= ' و ';
        }
        $decimalWords = convertLargeNumber($decimal, $units, $teens, $tens, $hundreds);
        $result .= $decimalWords . ' سنتيم';
    }

    $result .= ' فقط لا غير';
    return $result;
}
/**
 * إنشاء أو تحديث أقساط شهرية لاقتطاع معين
 */
/**
 * إنشاء أو تحديث أقساط شهرية لاقتطاع معين
 * مع توزيع الرصيد الدائن (credit_balance) على الأقساط المستقبلية
 */
/**
 * إنشاء أو تحديث أقساط شهرية لاقتطاع معين
 * مع توزيع الرصيد الدائن على أول قسط مستقبلي غير مدفوع
 * وتجنب تكرار الأقساط للأشهر المدفوعة
 */
/**
 * إنشاء أو تحديث أقساط شهرية مع توزيع credit_balance على أول قسط غير مدفوع
 */
function regenerateMonthlyInstallments($deductionId, $deleteExisting = false) {
    global $pdo;
    
    // جلب بيانات الاقتطاع
    $stmt = $pdo->prepare("SELECT * FROM deductions WHERE id = ?");
    $stmt->execute([$deductionId]);
    $deduction = $stmt->fetch();
    if (!$deduction) return false;
    
    // حذف الأقساط غير المدفوعة وغير المؤجلة (إن طُلب)
    if ($deleteExisting) {
        $delete = $pdo->prepare("
            DELETE FROM monthly_installments 
            WHERE deduction_id = ? AND is_paid = 0 AND is_postponed = 0
        ");
        $delete->execute([$deductionId]);
    }
    
    // جلب الأشهر التي لديها أقساط مدفوعة (لن نضيف أقساط جديدة لها)
    $paid_months = [];
    $stmtPaid = $pdo->prepare("
        SELECT year, month FROM monthly_installments 
        WHERE deduction_id = ? AND is_paid = 1
    ");
    $stmtPaid->execute([$deductionId]);
    while ($row = $stmtPaid->fetch()) {
        $paid_months[] = $row['year'] . '-' . $row['month'];
    }
    
    $totalMonths = $deduction['total_months'];
    if ($totalMonths <= 0) return false;
    
    $start = new DateTime($deduction['start_date']);
    $credit_balance = $deduction['credit_balance'];
    
    $current = clone $start;
    for ($i = 0; $i < $totalMonths; $i++) {
        $year = (int)$current->format('Y');
        $month = (int)$current->format('m');
        $key = $year . '-' . $month;
        
        // إذا كان هذا الشهر مدفوعاً بالفعل، نتجاوزه
        if (in_array($key, $paid_months)) {
            $current->modify('+1 month');
            continue;
        }
        
        $amount = $deduction['monthly_amount'];
        
        // توزيع الرصيد الدائن على أول قسط غير مدفوع
        if ($credit_balance > 0) {
            $amount += $credit_balance;
            $credit_balance = 0;
        }
        
        // التحقق من وجود قسط غير مدفوع لهذا الشهر
        $check = $pdo->prepare("
            SELECT id FROM monthly_installments 
            WHERE deduction_id = ? AND year = ? AND month = ? 
              AND is_paid = 0 AND is_postponed = 0
        ");
        $check->execute([$deductionId, $year, $month]);
        if (!$check->fetch()) {
            // إدراج قسط جديد
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
                $amount
            ]);
        } else {
            // تحديث المبلغ إن كان موجوداً
            $update = $pdo->prepare("
                UPDATE monthly_installments 
                SET amount = ? 
                WHERE deduction_id = ? AND year = ? AND month = ? 
                  AND is_paid = 0 AND is_postponed = 0
            ");
            $update->execute([$amount, $deductionId, $year, $month]);
        }
        
        $current->modify('+1 month');
    }
    
    // إذا بقي رصيد بعد كل الأقساط (نادراً)، نضيفه لآخر قسط
    if ($credit_balance > 0) {
        $last = $pdo->prepare("
            SELECT id FROM monthly_installments 
            WHERE deduction_id = ? AND is_paid = 0 AND is_postponed = 0
            ORDER BY year DESC, month DESC LIMIT 1
        ");
        $last->execute([$deductionId]);
        $last_inst = $last->fetch();
        if ($last_inst) {
            $update = $pdo->prepare("UPDATE monthly_installments SET amount = amount + ? WHERE id = ?");
            $update->execute([$credit_balance, $last_inst['id']]);
        }
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

// في includes/functions.php
if (!function_exists('safeFormatDate')) {
    function safeFormatDate($date) {
        if (empty($date) || $date === '0000-00-00' || $date === '1970-01-01') return '—';
        return date('d/m/Y', strtotime($date));
    }
}

// ============================================================
// دوال الميزانية
// ============================================================

/**
 * إعادة حساب الميزانية المتبقية من budget_transactions
 * @param PDO $pdo
 * @param int|null $year السنة المطلوبة (افتراضي: السنة الحالية)
 * @return bool
 */
function recalculateBudget($pdo, $year = null) {
    if ($year === null) $year = (int)date('Y');
    
    try {
        // حساب الإجمالي من budget_transactions
        $stmt = $pdo->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN is_deduct = 0 THEN amount ELSE 0 END), 0) AS total_add,
                COALESCE(SUM(CASE WHEN is_deduct = 1 THEN amount ELSE 0 END), 0) AS total_sub
            FROM budget_transactions
            WHERE strftime('%Y', transaction_date) = :year
        ");
        $stmt->execute([':year' => (string)$year]);
        $data = $stmt->fetch();
        $net = (float)$data['total_add'] - (float)$data['total_sub'];
        
        // جلب الميزانية الأولية
        $stmt = $pdo->prepare("
            SELECT id, initial_budget 
            FROM social_budget 
            WHERE year = :year 
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([':year' => $year]);
        $budget = $stmt->fetch();
        
        if (!$budget) {
            return false;
        }
        
        $remaining = (float)$budget['initial_budget'] + $net;
        
        // تحديث remaining_budget
        $update = $pdo->prepare("
            UPDATE social_budget 
            SET remaining_budget = :remaining, 
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $update->execute([
            ':remaining' => $remaining,
            ':id' => $budget['id']
        ]);
        
        // تسجيل العملية في سجل التدقيق
        if (function_exists('audit')) {
            audit('BUDGET_RECALCULATED', 
                "تم إعادة حساب ميزانية سنة $year. المتبقية الجديدة: " . number_format($remaining, 2) . " دج",
                'budget', 
                $budget['id'], 
                null, 
                ['remaining_budget' => $remaining]
            );
        }
        
        return true;
        
    } catch (Exception $e) {
        error_log("recalculateBudget error: " . $e->getMessage());
        return false;
    }
}

/**
 * تحديث الميزانية بعد أي عملية مالية (تسجيل تلقائي)
 * @param PDO $pdo
 * @param int $year السنة
 * @param float $amount المبلغ
 * @param int $is_deduct 1 = خصم, 0 = إضافة
 * @return bool
 */
function updateBudgetAfterTransaction($pdo, $year, $amount, $is_deduct) {
    if ($year === null) $year = (int)date('Y');
    
    try {
        $sign = $is_deduct ? -1 : 1;
        $change = $amount * $sign;
        
        $stmt = $pdo->prepare("
            UPDATE social_budget 
            SET remaining_budget = remaining_budget + :change,
                updated_at = CURRENT_TIMESTAMP
            WHERE year = :year
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([
            ':change' => $change,
            ':year' => $year
        ]);
        
        return $stmt->rowCount() > 0;
        
    } catch (Exception $e) {
        error_log("updateBudgetAfterTransaction error: " . $e->getMessage());
        return false;
    }
}

/**
 * الحصول على إحصائيات الميزانية لسنة معينة
 * @param PDO $pdo
 * @param int $year
 * @return array
 */
function getBudgetStats($pdo, $year = null) {
    if ($year === null) $year = (int)date('Y');
    
    // جلب الميزانية
    $stmt = $pdo->prepare("
        SELECT initial_budget, remaining_budget 
        FROM social_budget 
        WHERE year = :year 
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([':year' => $year]);
    $budget = $stmt->fetch();
    
    $initial = $budget ? (float)$budget['initial_budget'] : 0;
    $remaining = $budget ? (float)$budget['remaining_budget'] : 0;
    
    // جلب الصرف والاسترجاعات
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN is_deduct = 1 AND type IN ('loan', 'grant') THEN amount ELSE 0 END), 0) AS total_expenses,
            COALESCE(SUM(CASE WHEN is_deduct = 0 THEN amount ELSE 0 END), 0) AS total_refunds,
            COALESCE(SUM(CASE WHEN type = 'loan' AND is_deduct = 1 THEN amount ELSE 0 END), 0) AS total_loans,
            COALESCE(SUM(CASE WHEN type = 'grant' AND is_deduct = 1 THEN amount ELSE 0 END), 0) AS total_grants,
            COALESCE(SUM(CASE WHEN type = 'installment' AND is_deduct = 0 THEN amount ELSE 0 END), 0) AS total_installments
        FROM budget_transactions
        WHERE strftime('%Y', transaction_date) = :year
    ");
    $stmt->execute([':year' => (string)$year]);
    $stats = $stmt->fetch();
    
    $totalExpenses = (float)$stats['total_expenses'];
    $totalRefunds = (float)$stats['total_refunds'];
    $totalLoans = (float)$stats['total_loans'];
    $totalGrants = (float)$stats['total_grants'];
    $totalInstallments = (float)$stats['total_installments'];
    
    $spentPercent = $initial > 0 ? min(100, round((($initial - $remaining) / $initial) * 100)) : 0;
    
    return [
        'initial' => $initial,
        'remaining' => $remaining,
        'total_expenses' => $totalExpenses,
        'total_refunds' => $totalRefunds,
        'total_loans' => $totalLoans,
        'total_grants' => $totalGrants,
        'total_installments' => $totalInstallments,
        'spent_percent' => $spentPercent,
    ];
}

/**
 * جلب قائمة السنوات المتاحة في الميزانية
 */
function getBudgetYears($pdo) {
    $stmt = $pdo->query("SELECT DISTINCT year FROM social_budget ORDER BY year DESC");
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * جلب معاملات الميزانية مع الفلاتر
 */
function getBudgetTransactions($pdo, $filters = []) {
    $year = $filters['year'] ?? date('Y');
    $type = $filters['type'] ?? 'all';
    $limit = $filters['limit'] ?? 100;

    $sql = "
        SELECT bt.*, 
               CASE 
                   WHEN bt.type = 'grant' THEN 'منحة'
                   WHEN bt.type = 'loan' THEN 'سلفة'
                   WHEN bt.type = 'installment' THEN 'قسط مردود'
                   ELSE 'أخرى'
               END as type_label,
               CASE WHEN bt.is_deduct = 1 THEN 'خصم' ELSE 'إضافة' END as direction,
               CASE WHEN bt.is_deduct = 1 THEN bt.amount ELSE 0 END as debit,
               CASE WHEN bt.is_deduct = 0 THEN bt.amount ELSE 0 END as credit
        FROM budget_transactions bt
        WHERE strftime('%Y', bt.transaction_date) = ?
    ";
    $params = [(string)$year];
    if ($type != 'all') {
        $sql .= " AND bt.type = ?";
        $params[] = $type;
    }
    $sql .= " ORDER BY bt.transaction_date DESC LIMIT ?";
    $params[] = $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

?>