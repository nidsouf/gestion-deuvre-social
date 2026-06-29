<?php
/**
 * budget/create.php - إضافة ميزانية جديدة (محسّن ومتوافق مع النظام)
 */
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';
include '../includes/header.php';

// التحقق من الصلاحيات (يمكن إضافة دور admin لاحقاً)
// if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
//     header("Location: /index.php");
//     exit;
// }

$error = '';
$success = false;

// معالجة POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // التحقق من CSRF باستخدام الدالة الموحدة
    requireCSRFToken();
    
    // التحقق من Rate Limiting (5 محاولات لكل IP في الساعة)
    if (isRateLimited('budget_create', 5, 3600)) {
        $error = '⚠️ لقد تجاوزت عدد المحاولات المسموحة. الرجاء المحاولة لاحقاً.';
    } else {
        // تنظيف المدخلات
        $year = sanitizeInput($_POST['year'] ?? '');
        $initial = filter_var($_POST['initial_budget'] ?? 0, FILTER_VALIDATE_FLOAT);
        
        // التحقق من صحة البيانات
        if (!validateNumber($year) || $year < 2000 || $year > 2100) {
            $error = '⚠️ السنة يجب أن تكون بين 2000 و 2100';
        } elseif (!$initial || $initial <= 0) {
            $error = '⚠️ الميزانية يجب أن تكون أكبر من صفر';
        } else {
            try {
                // التحقق من عدم وجود السنة مسبقاً
                $stmt = $pdo->prepare("SELECT id FROM social_budget WHERE year = ?");
                $stmt->execute([$year]);
                
                if ($stmt->fetch()) {
                    $error = "⚠️ السنة {$year} موجودة مسبقاً";
                } else {
                    // إضافة الميزانية داخل Transaction (لضمان سلامة البيانات)
                    $result = db_transaction(function($pdo) use ($year, $initial) {
                        $stmt = $pdo->prepare("INSERT INTO social_budget (year, initial_budget, remaining_budget) VALUES (?, ?, ?)");
                        return $stmt->execute([$year, $initial, $initial]);
                    });
                    
                    if ($result) {
                        // تسجيل العملية في سجل التدقيق
                        audit('BUDGET_CREATED', "تم إضافة ميزانية جديدة للسنة $year بقيمة $initial دج");
                        
                        // إضافة إشعار للمدير
                        addNotification('ميزانية جديدة', "تم إضافة ميزانية السنة $year بقيمة " . number_format($initial, 2) . " دج", null, 'success');
                        
                        $_SESSION['toast'] = [
                            'message' => '✅ تم إضافة الميزانية بنجاح',
                            'type' => 'success',
                            'duration' => 3000
                        ];
                        header("Location: index.php");
                        exit;
                    } else {
                        $error = '❌ فشل إضافة الميزانية. الرجاء المحاولة مرة أخرى.';
                    }
                }
            } catch (PDOException $e) {
                error_log("Budget creation error: " . $e->getMessage());
                $error = '❌ حدث خطأ في قاعدة البيانات. تم تسجيل المشكلة وسيتم التعامل معها.';
            }
        }
    }
}

// توليد رمز CSRF جديد
$csrf_token = generateCSRFToken();
?>

<style>
/* استخدام أنماط النظام الموحدة، مع تخصيصات إضافية */
.form-container {
    max-width: 500px;
    margin: 30px auto;
    background: white;
    padding: 25px;
    border-radius: 20px;
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}

.form-container h2 {
    text-align: center;
    margin-bottom: 20px;
    color: #2a5298;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    font-weight: bold;
    margin-bottom: 8px;
    color: #333;
}

.form-group input {
    width: 100%;
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: 12px;
    font-size: 14px;
    transition: 0.3s;
}

.form-group input:focus {
    outline: none;
    border-color: #2a5298;
    box-shadow: 0 0 0 3px rgba(42,82,152,0.1);
}

.button-group {
    display: flex;
    gap: 15px;
    margin-top: 25px;
}

.btn-save {
    flex: 1;
    background: #2a5298;
    color: white;
    padding: 10px;
    border: none;
    border-radius: 30px;
    font-weight: bold;
    cursor: pointer;
    transition: 0.3s;
}

.btn-save:hover {
    background: #1e3c72;
    transform: translateY(-2px);
}

.btn-cancel {
    flex: 1;
    background: #6c757d;
    color: white;
    padding: 10px;
    border: none;
    border-radius: 30px;
    font-weight: bold;
    text-decoration: none;
    text-align: center;
    transition: 0.3s;
}

.btn-cancel:hover {
    background: #5a6268;
    transform: translateY(-2px);
}

.error-message {
    background: #f8d7da;
    color: #721c24;
    padding: 12px;
    border-radius: 10px;
    margin-bottom: 20px;
    text-align: center;
}
</style>

<div class="form-container">
    <h2>➕ إضافة ميزانية جديدة</h2>
    
    <?php if ($error): ?>
        <div class="error-message"><?= escape($error) ?></div>
    <?php endif; ?>
    
    <form method="POST">
        <!-- CSRF Token -->
        <input type="hidden" name="csrf_token" value="<?= escape($csrf_token) ?>">
        
        <div class="form-group">
            <label for="year">📅 السنة</label>
            <input type="number" 
                   id="year"
                   name="year" 
                   min="2000" 
                   max="2100" 
                   value="<?= escape(date('Y') + 1) ?>" 
                   required>
        </div>
        
        <div class="form-group">
            <label for="initial_budget">💰 الميزانية الأولية (دج)</label>
            <input type="number" 
                   id="initial_budget"
                   name="initial_budget" 
                   step="0.01" 
                   min="0.01"
                   placeholder="0.00"
                   required>
        </div>
        
        <div class="button-group">
            <button type="submit" class="btn-save">💾 حفظ</button>
            <a href="index.php" class="btn-cancel">🔙 إلغاء</a>
        </div>
    </form>
</div>

<?php include '../includes/footer.php'; ?>