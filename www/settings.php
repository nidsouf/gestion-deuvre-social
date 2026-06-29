<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
// التحقق من الصلاحية: إذا لم يكن الدور موجوداً في الجلسة، نستعلمه من قاعدة البيانات
if (!isset($_SESSION['role'])) {
    require_once 'config/database.php';
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $_SESSION['role'] = $user['role'] ?? 'user';
}
if ($_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}
// باقي الكود...

include 'includes/header.php';

// ========== إنشاء جدول settings إذا لم يكن موجوداً ==========
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            setting_key TEXT NOT NULL UNIQUE,
            setting_value TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    // إضافة بعض الإعدادات الافتراضية إذا كان الجدول فارغاً
    $stmt = $pdo->query("SELECT COUNT(*) FROM settings");
    $count = $stmt->fetchColumn();
    if ($count == 0) {
        $defaults = [
            'company_name' => 'مركز التكوين والتعليم المهنيين',
            'currency' => 'دج',
            'date_format' => 'd/m/Y',
            'low_budget_alert' => '100000'
        ];
        $insert = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)");
        foreach ($defaults as $key => $value) {
            $insert->execute([$key, $value]);
        }
    }
} catch (PDOException $e) {
    // تجاهل الخطأ إذا كان الجدول موجوداً مسبقاً
}

// ========== حفظ الإعدادات ==========
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    foreach ($_POST as $key => $value) {
        if ($key == 'submit') continue;
        // SQLite: INSERT OR REPLACE (بديل ON DUPLICATE KEY UPDATE)
        $stmt = $pdo->prepare("INSERT OR REPLACE INTO settings (setting_key, setting_value) VALUES (?, ?)");
        $stmt->execute([$key, $value]);
    }
    $message = '<div class="alert alert-success">✅ تم حفظ الإعدادات بنجاح</div>';
}

// ========== جلب الإعدادات الحالية ==========
$settings = [];
$stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
while ($row = $stmt->fetch()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
?>

<style>
    .settings-form {
        max-width: 600px;
        margin: 0 auto;
    }
    .form-group {
        margin-bottom: 20px;
    }
    .form-group label {
        display: block;
        font-weight: bold;
        margin-bottom: 8px;
    }
    .form-group input, .form-group select {
        width: 100%;
        padding: 10px;
        border: 1px solid #ddd;
        border-radius: 12px;
    }
    .alert {
        background: #d4edda;
        color: #155724;
        padding: 12px;
        border-radius: 12px;
        margin-bottom: 20px;
    }
</style>

<div class="section">
    <div class="section-header">
        <h3 class="section-title">⚙️ إعدادات النظام</h3>
    </div>

    <?= $message ?? '' ?>

    <form method="POST" class="settings-form">
        <div class="form-group">
            <label>🏢 اسم الشركة / المؤسسة</label>
            <input type="text" name="company_name" value="<?= htmlspecialchars($settings['company_name'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label>💰 العملة</label>
            <input type="text" name="currency" value="<?= htmlspecialchars($settings['currency'] ?? 'دج') ?>">
        </div>
        <div class="form-group">
            <label>📅 تنسيق التاريخ</label>
            <select name="date_format">
                <option value="d/m/Y" <?= ($settings['date_format'] ?? '') == 'd/m/Y' ? 'selected' : '' ?>>يوم/شهر/سنة</option>
                <option value="Y-m-d" <?= ($settings['date_format'] ?? '') == 'Y-m-d' ? 'selected' : '' ?>>سنة-شهر-يوم</option>
                <option value="m/d/Y" <?= ($settings['date_format'] ?? '') == 'm/d/Y' ? 'selected' : '' ?>>شهر/يوم/سنة</option>
            </select>
        </div>
        <div class="form-group">
            <label>⚠️ حد التنبيه لانخفاض الميزانية (دج)</label>
            <input type="number" name="low_budget_alert" value="<?= htmlspecialchars($settings['low_budget_alert'] ?? '100000') ?>">
        </div>
        <a href="confirm_upgrade.php" class="btn btn-warning">🔄 ترقية قاعدة البيانات</a>
        <button type="submit" class="btn-sm">💾 حفظ الإعدادات</button>
    </form>
</div>

<?php include 'includes/footer.php'; ?>