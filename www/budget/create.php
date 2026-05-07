<?php
session_start();

// Security: Verify admin role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: ../login.php");
    exit;
}

require_once '../config/database.php';
include '../includes/header.php';

// Initialize variables
$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // 1. CSRF Token Validation
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        $error = "❌ طلب غير صالح!";
    } else {
        // 2. Validate and Sanitize Input
        $year = filter_var($_POST['year'] ?? null, FILTER_VALIDATE_INT);
        $initial = filter_var($_POST['initial_budget'] ?? null, FILTER_VALIDATE_FLOAT);
        
        // 3. Input Range Validation
        if (!$year || $year < 2000 || $year > 2100) {
            $error = "⚠️ السنة يجب أن تكون بين 2000 و 2100!";
        } elseif (!$initial || $initial <= 0) {
            $error = "⚠️ الميزانية يجب أن تكون موجبة!";
        } else {
            try {
                // 4. Check if year already exists
                $stmt = $pdo->prepare("SELECT id FROM social_budget WHERE year = ?");
                $stmt->execute([$year]);
                
                if ($stmt->fetch()) {
                    $error = "⚠️ السنة $year موجودة مسبقاً!";
                } else {
                    // 5. Insert new budget
                    $stmt = $pdo->prepare(
                        "INSERT INTO social_budget (year, initial_budget, remaining_budget) 
                         VALUES (?, ?, ?)"
                    );
                    $stmt->execute([$year, $initial, $initial]);
                    $success = true;
                    header("Location: index.php?success=1");
                    exit;
                }
            } catch (PDOException $e) {
                $error = "❌ خطأ في قاعدة البيانات!";
                error_log("Budget Creation Error: " . $e->getMessage());
            }
        }
    }
}

// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>

<style>
.form-container {
    max-width: 500px;
    margin: 30px auto;
    background: white;
    padding: 25px;
    border-radius: 20px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}

.error {
    color: #721c24;
    background-color: #f8d7da;
    border: 1px solid #f5c6cb;
    padding: 12px;
    margin-bottom: 15px;
    border-radius: 5px;
}

.success {
    color: #155724;
    background-color: #d4edda;
    border: 1px solid #c3e6cb;
    padding: 12px;
    margin-bottom: 15px;
    border-radius: 5px;
}

.form-group {
    margin-bottom: 15px;
}

.form-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: 500;
    color: #333;
}

.form-group input {
    width: 100%;
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: 5px;
    font-size: 14px;
}

.form-group input:focus {
    outline: none;
    border-color: #007bff;
    box-shadow: 0 0 5px rgba(0, 123, 255, 0.3);
}

.button-group {
    display: flex;
    gap: 10px;
    margin-top: 20px;
}

.btn-sm {
    flex: 1;
    padding: 10px;
    border: none;
    border-radius: 5px;
    background: #007bff;
    color: white;
    font-size: 14px;
    cursor: pointer;
    text-align: center;
    text-decoration: none;
    transition: background 0.3s ease;
}

.btn-sm:hover {
    background: #0056b3;
}

.btn-cancel {
    background: #6c757d;
}

.btn-cancel:hover {
    background: #5a6268;
}
</style>

<div class="form-container">
    <h2>➕ إضافة ميزانية جديدة</h2>
    
    <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    
    <form method="POST">
        <!-- CSRF Token (Hidden) -->
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        
        <!-- Year Input -->
        <div class="form-group">
            <label for="year">السنة</label>
            <input type="number" 
                   id="year"
                   name="year" 
                   min="2000" 
                   max="2100" 
                   value="<?= htmlspecialchars(date('Y') + 1) ?>" 
                   required>
        </div>
        
        <!-- Initial Budget Input -->
        <div class="form-group">
            <label for="initial_budget">الميزانية الأولية (دج)</label>
            <input type="number" 
                   id="initial_budget"
                   name="initial_budget" 
                   step="0.01" 
                   min="0.01"
                   placeholder="0.00"
                   required>
        </div>
        
        <!-- Action Buttons -->
        <div class="button-group">
            <button type="submit" class="btn-sm">💾 حفظ</button>
            <a href="index.php" class="btn-sm btn-cancel">إلغاء</a>
        </div>
    </form>
</div>

<?php include '../includes/footer.php'; ?>
