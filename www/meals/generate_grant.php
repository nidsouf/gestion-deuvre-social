<?php
session_start();

// ========== التحقق من تسجيل الدخول ==========
if (!isset($_SESSION['user_id'])) {
    die("⚠️ يرجى تسجيل الدخول أولاً.");
}

// ========== تحذير إذا لم يكن المستخدم مديراً ==========
$isAdmin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
if (!$isAdmin) {
    echo "<div style='background:#fff3cd; padding:15px; border-radius:10px; color:#856404; direction:rtl; font-family:Tahoma; max-width:800px; margin:30px auto;'>
            ⚠️ <strong>تنبيه:</strong> أنت لست مديراً. قد لا تملك الصلاحية الكاملة لتوليد المنح. 
            <br>إذا كنت مديراً، تأكد من تسجيل الخروج والدخول مرة أخرى.
          </div>";
}

require_once '../config/database.php';
require_once '../includes/functions.php';

// ========== تفعيل عرض الأخطاء ==========
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ========== التحقق من وجود منحة لنفس الموظف ونفس الشهر ==========
function grantExistsForMonth($employee_id, $grant_id, $year, $month) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM employee_grants 
        WHERE employee_id = ? AND grant_id = ? 
        AND strftime('%Y', grant_date) = ? AND strftime('%m', grant_date) = ?
    ");
    $stmt->execute([$employee_id, $grant_id, $year, sprintf("%02d", $month)]);
    return $stmt->fetchColumn() > 0;
}

// ========== جلب معاملات الشهر والسنة ==========
$month = isset($_GET['month']) ? (int)$_GET['month'] : date('m');
$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');

echo "<div style='direction:rtl; font-family:Tahoma; max-width:800px; margin:30px auto; background:white; padding:25px; border-radius:15px; box-shadow:0 2px 10px rgba(0,0,0,0.1);'>";
echo "<h2 style='color:#2a5298;'>🍽️ توليد منح وجبات المطعم</h2>";
echo "<p><strong>الشهر:</strong> " . getMonthNameArabic($month) . " <strong>السنة:</strong> $year</p>";
echo "<hr>";

// ========== التحقق من وجود بيانات وجبات ==========
$stmt = $pdo->prepare("
    SELECT COUNT(*) as count 
    FROM meal_records 
    WHERE year = ? AND month = ? AND meal_count > 0
");
$stmt->execute([$year, $month]);
$hasRecords = $stmt->fetchColumn();

if ($hasRecords == 0) {
    echo "<div style='background:#f8d7da; padding:15px; border-radius:10px; color:#721c24;'>
            ❌ لا توجد بيانات وجبات للشهر المحدد. قم باستيراد تقرير CSV أولاً.
          </div>";
    echo "<a href='import_monthly.php' style='display:inline-block; margin-top:15px; background:#28a745; color:white; padding:10px 20px; border-radius:30px; text-decoration:none;'>📥 استيراد تقرير</a>";
    echo "</div>";
    exit;
}

// ========== جلب بيانات الوجبات للموظفين ==========
$stmt = $pdo->prepare("
    SELECT 
        employee_id,
        SUM(meal_count) as total_meals,
        SUM(total_amount) as total_amount
    FROM meal_records
    WHERE year = ? AND month = ?
    GROUP BY employee_id
    HAVING total_meals > 0
");
$stmt->execute([$year, $month]);
$employees = $stmt->fetchAll();

if (empty($employees)) {
    echo "<div style='background:#fff3cd; padding:15px; border-radius:10px; color:#856404;'>
            ⚠️ لا يوجد موظفون لديهم وجبات مسجلة في هذا الشهر.
          </div>";
    echo "</div>";
    exit;
}

// ========== التحقق من وجود جدول meal_installments ==========
try {
    $pdo->query("SELECT id FROM meal_installments LIMIT 1");
} catch (PDOException $e) {
    echo "<div style='background:#f8d7da; padding:15px; border-radius:10px; color:#721c24;'>
            ❌ جدول meal_installments غير موجود. سيتم إنشاؤه...
          </div>";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS meal_installments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            employee_id INTEGER NOT NULL,
            year INTEGER NOT NULL,
            month INTEGER NOT NULL,
            total_meals INTEGER DEFAULT 0,
            total_amount REAL DEFAULT 0,
            grant_amount REAL DEFAULT 0,
            is_processed INTEGER DEFAULT 0,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )
    ");
    echo "<div style='background:#d4edda; padding:10px; border-radius:10px; color:#155724;'>✅ تم إنشاء جدول meal_installments.</div>";
}

// ========== التحقق من وجود نوع المنحة (بدون description) ==========
$stmtGrantType = $pdo->query("SELECT id FROM grants WHERE name = 'منحة وجبات المطعم'");
$grantType = $stmtGrantType->fetch();

if (!$grantType) {
    // إدراج بدون description لأن العمود غير موجود
    $pdo->exec("INSERT INTO grants (name, amount) VALUES ('منحة وجبات المطعم', 0)");
    $grantTypeId = $pdo->lastInsertId();
    echo "<div style='background:#d4edda; padding:10px; border-radius:10px; color:#155724;'>✅ تم إنشاء نوع المنحة 'منحة وجبات المطعم'.</div>";
} else {
    $grantTypeId = $grantType['id'];
}

// التحقق من وجود منح لهذا الشهر مسبقاً
$stmtCheck = $pdo->prepare("
    SELECT COUNT(*) FROM meal_installments 
    WHERE year = ? AND month = ? AND is_processed = 1
");
$stmtCheck->execute([$year, $month]);
$existingGrants = $stmtCheck->fetchColumn();

if ($existingGrants > 0) {
    echo "<div style='background:#fff3cd; padding:15px; border-radius:10px; color:#856404;'>
            ⚠️ توجد منح مسجلة بالفعل لهذا الشهر ({$month}/{$year}). 
            <br>لإعادة التوليد، قم بحذف المنح الحالية أولاً.
          </div>";
    echo "<a href='report.php?month=$month&year=$year' style='background:#2a5298; color:white; padding:10px 20px; border-radius:30px; text-decoration:none;'>🔙 العودة إلى التقرير</a>";
    echo "</div>";
    exit;
}

// ========== توليد المنح ==========
$generated = 0;
$total_grants = 0;
$errors = [];

foreach ($employees as $emp) {
    $grant_amount = $emp['total_amount'] / 2;
    
    try {
        // 1. التحقق من وجود سجل في meal_installments
        $stmtCheck = $pdo->prepare("SELECT id FROM meal_installments WHERE employee_id = ? AND year = ? AND month = ?");
        $stmtCheck->execute([$emp['employee_id'], $year, $month]);
        $exists = $stmtCheck->fetch();
        
        if ($exists) {
            $update = $pdo->prepare("
                UPDATE meal_installments 
                SET total_meals = ?, total_amount = ?, grant_amount = ?, is_processed = 1 
                WHERE id = ?
            ");
            $update->execute([$emp['total_meals'], $emp['total_amount'], $grant_amount, $exists['id']]);
        } else {
            $insert = $pdo->prepare("
                INSERT INTO meal_installments (employee_id, year, month, total_meals, total_amount, grant_amount, is_processed) 
                VALUES (?, ?, ?, ?, ?, ?, 1)
            ");
            $insert->execute([$emp['employee_id'], $year, $month, $emp['total_meals'], $emp['total_amount'], $grant_amount]);
        }
        
        // التحقق من عدم تكرار المنحة لنفس الموظف في نفس الشهر
if (grantExistsForMonth($emp['employee_id'], $grantTypeId, $year, $month)) {
    $errors[] = "الموظف ID {$emp['employee_id']} لديه بالفعل منحة وجبات لهذا الشهر";
    continue;
}

        // 2. إدراج المنحة في employee_grants
        $stmtGrant = $pdo->prepare("
            INSERT INTO employee_grants (employee_id, grant_id, amount, grant_date, notes)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmtGrant->execute([
            $emp['employee_id'],
            $grantTypeId,
            $grant_amount,
            date('Y-m-d'),
            "منحة وجبات - {$month}/{$year}"
        ]);
        
        $generated++;
        $total_grants += $grant_amount;
        
    } catch (Exception $e) {
        $errors[] = "الموظف ID {$emp['employee_id']}: " . $e->getMessage();
    }
}

// ========== تحديث الميزانية ==========
if ($total_grants > 0) {
    try {
        $stmtBudget = $pdo->prepare("SELECT id, remaining_budget FROM social_budget WHERE year = ? ORDER BY id DESC LIMIT 1");
        $stmtBudget->execute([$year]);
        $budget = $stmtBudget->fetch();
        
        if ($budget) {
            $newRemaining = $budget['remaining_budget'] - $total_grants;
            $updateBudget = $pdo->prepare("UPDATE social_budget SET remaining_budget = ? WHERE id = ?");
            $updateBudget->execute([$newRemaining, $budget['id']]);
            echo "<div style='background:#d4edda; padding:10px; border-radius:10px; color:#155724;'>✅ تم خصم " . number_format($total_grants, 2) . " دج من الميزانية.</div>";
        } else {
            echo "<div style='background:#fff3cd; padding:10px; border-radius:10px; color:#856404;'>⚠️ لا توجد ميزانية مسجلة للسنة $year. لم يتم تحديث الميزانية.</div>";
        }
    } catch (Exception $e) {
        $errors[] = "تحديث الميزانية: " . $e->getMessage();
    }
}

// ========== تسجيل في سجل التدقيق ==========
if (function_exists('audit')) {
    audit('MEAL_GRANT_GENERATED', "توليد منح وجبات لشهر {$month}/{$year} لعدد {$generated} موظف بقيمة {$total_grants} دج");
}

// ========== إضافة إشعار ==========
if (function_exists('addNotification')) {
    addNotification(
        '🍽️ توليد منح وجبات المطعم',
        "تم توليد منح وجبات المطعم لشهر {$month}/{$year} لعدد {$generated} موظف بقيمة " . number_format($total_grants, 2) . " دج",
        null,
        'success'
    );
}

// ========== عرض النتيجة ==========
if ($generated > 0) {
    echo "<div style='background:#d4edda; padding:15px; border-radius:10px; color:#155724; margin-top:20px;'>
            ✅ تم توليد المنح بنجاح! <br>
            عدد الموظفين: <strong>{$generated}</strong><br>
            إجمالي المنح: <strong>" . number_format($total_grants, 2) . " دج</strong>
          </div>";
} else {
    echo "<div style='background:#f8d7da; padding:15px; border-radius:10px; color:#721c24; margin-top:20px;'>
            ❌ لم يتم توليد أي منح. تأكد من وجود بيانات وجبات للشهر المحدد.
          </div>";
}

if (!empty($errors)) {
    echo "<div style='background:#f8d7da; padding:15px; border-radius:10px; color:#721c24; margin-top:10px;'>
            <strong>⚠️ الأخطاء:</strong><br>" . implode('<br>', $errors) . "
          </div>";
}

echo "<div style='margin-top:20px;'>";
echo "<a href='report.php?month=$month&year=$year' style='background:#2a5298; color:white; padding:10px 20px; border-radius:30px; text-decoration:none;'>🔙 العودة إلى التقرير</a>";
echo " <a href='dashboard.php' style='background:#17a2b8; color:white; padding:10px 20px; border-radius:30px; text-decoration:none;'>📊 لوحة التحكم</a>";
echo "</div>";
echo "</div>";
?>