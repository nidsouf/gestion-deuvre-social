<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

require_once '../config/database.php';

// ========== معالجة POST قبل أي ناتج ==========
$message = '';
$successCount = 0;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['excel_file'])) {
    // التحقق من وجود المكتبة
    if (!file_exists('../vendor/simple/SimpleXLSX.php')) {
        $message = '<div class="alert alert-error">❌ مكتبة SimpleXLSX غير موجودة. يرجى تثبيتها عبر composer أو تحميلها يدوياً.</div>';
    } else {
        require_once '../vendor/simple/SimpleXLSX.php';
        use Shuchkin\SimpleXLSX;
        
        $file = $_FILES['excel_file'];
        
        if ($file['error'] == 0) {
            if ($xlsx = SimpleXLSX::parse($file['tmp_name'])) {
                $rows = $xlsx->rows();
                array_shift($rows); // تخطي رأس الجدول
                
                foreach ($rows as $index => $row) {
                    $employee_name = trim($row[0] ?? '');
                    $category_raw = trim($row[1] ?? '');
                    
                    if (empty($employee_name)) continue;
                    
                    // تحويل النص إلى القيمة الصحيحة للقاعدة
                    $category = 'Contract';
                    if ($category_raw == 'دائم' || $category_raw == 'Permanent' || $category_raw == 'permanent') {
                        $category = 'Permanent';
                    } elseif ($category_raw == 'متعاقد' || $category_raw == 'Contract' || $category_raw == 'contract') {
                        $category = 'Contract';
                    } elseif (!empty($category_raw)) {
                        $errors[] = "الصف " . ($index + 2) . ": الطبيعة غير معروفة '$category_raw' للموظف '$employee_name' - تم تعيينها كمتعاقد";
                    }
                    
                    // التحقق من وجود الموظف
                    $stmt = $pdo->prepare("SELECT id FROM employees WHERE name = ?");
                    $stmt->execute([$employee_name]);
                    $existing = $stmt->fetch();
                    
                    if (!$existing) {
                        $stmt = $pdo->prepare("INSERT INTO employees (name, category) VALUES (?, ?)");
                        $stmt->execute([$employee_name, $category]);
                        $successCount++;
                    } else {
                        $stmt = $pdo->prepare("UPDATE employees SET category = ? WHERE name = ?");
                        $stmt->execute([$category, $employee_name]);
                        $errors[] = "الصف " . ($index + 2) . ": الموظف '$employee_name' موجود مسبقاً - تم تحديث تصنيفه إلى " . ($category == 'Permanent' ? 'دائم' : 'متعاقد');
                    }
                }
                
                $message = '<div class="alert alert-success">✅ تم إضافة ' . $successCount . ' موظف جديد بنجاح!</div>';
                if (!empty($errors)) {
                    $message .= '<div class="alert alert-warning">⚠️ ' . count($errors) . ' ملاحظة:<br>' . implode('<br>', array_slice($errors, 0, 15)) . '</div>';
                }
            } else {
                $message = '<div class="alert alert-error">❌ خطأ في قراءة الملف: ' . SimpleXLSX::parseError() . '</div>';
            }
        } else {
            $message = '<div class="alert alert-error">❌ خطأ في رفع الملف</div>';
        }
    }
}

// ========== بعد المعالجة، نبدأ عرض الصفحة ==========
include '../includes/header.php';

$employees = $pdo->query("SELECT name, category FROM employees ORDER BY name")->fetchAll();
?>

<style>
    .import-container {
        max-width: 1000px;
        margin: 0 auto;
    }
    .info-box {
        background: #f0f2f5;
        padding: 20px;
        border-radius: 20px;
        margin-bottom: 25px;
    }
    .info-box h3 {
        color: #1b5e20;
        margin-bottom: 15px;
    }
    .alert {
        padding: 12px;
        border-radius: 12px;
        margin-bottom: 20px;
    }
    .alert-success { background: #d4edda; color: #155724; }
    .alert-error { background: #f8d7da; color: #721c24; }
    .alert-warning { background: #fff3cd; color: #856404; }
    .form-group {
        margin-bottom: 20px;
    }
    .form-group label {
        display: block;
        font-weight: bold;
        margin-bottom: 8px;
    }
    .form-group input {
        width: 100%;
        padding: 10px;
        border: 1px solid #ddd;
        border-radius: 12px;
    }
    .btn {
        padding: 10px 20px;
        margin: 5px;
        border: none;
        border-radius: 12px;
        cursor: pointer;
        text-decoration: none;
        display: inline-block;
        font-weight: bold;
    }
    .btn-primary {
        background: linear-gradient(135deg, #2a5298, #1e3c72);
        color: white;
    }
    .btn-secondary {
        background: #6c757d;
        color: white;
    }
    .table-wrapper {
        overflow-x: auto;
        border-radius: 16px;
        background: white;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    }
    table {
        width: 100%;
        border-collapse: collapse;
    }
    th, td {
        padding: 10px;
        text-align: center;
        border-bottom: 1px solid #ddd;
    }
    th {
        background: #2a5298;
        color: white;
    }
    tr:hover {
        background: #f5f5f5;
    }
</style>

<div class="import-container">
    <h2 style="margin-bottom: 20px;">👥 استيراد الموظفين من Excel</h2>

    <?= $message ?>

    <div class="info-box">
        <h3>📌 هيكل ملف Excel المطلوب</h3>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr><th>العمود A</th><th>العمود B</th></tr>
                    <tr style="background:#ddd;"><th>اسم الموظف</th><th>الطبيعة</th></tr>
                </thead>
                <tbody>
                    <tr><td>خيار سامية</td><td>متعاقد</td></tr>
                    <tr><td>الأشراف حياة</td><td>دائم</td></tr>
                    <tr><td>بن حامدي معمر</td><td>متعاقد</td></tr>
                    <tr><td>حموية صلاح الدين</td><td>دائم</td></tr>
                </tbody>
            </table>
        </div>
        <p><strong>ملاحظات:</strong></p>
        <ul>
            <li>✔️ العمود A: <strong>اسم الموظف</strong> (إجباري)</li>
            <li>✔️ العمود B: <strong>الطبيعة</strong> (اختياري - القيم المسموحة: <strong>دائم</strong> أو <strong>متعاقد</strong>)</li>
            <li>✔️ إذا كان العمود B فارغاً، سيتم تعيين الطبيعة تلقائياً إلى <strong>متعاقد</strong></li>
        </ul>
    </div>

    <div class="info-box">
        <h3>📁 الموظفون الموجودون حالياً</h3>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr><th>اسم الموظف</th><th>الطبيعة</th></tr>
                </thead>
                <tbody>
                    <?php if (empty($employees)): ?>
                        <tr><td colspan="2" style="text-align:center;">لا يوجد موظفون بعد</td></tr>
                    <?php else: ?>
                        <?php foreach($employees as $emp): ?>
                            <tr>
                                <td><?= htmlspecialchars($emp['name']) ?></td>
                                <td><?= $emp['category'] == 'Permanent' ? 'دائم' : 'متعاقد' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="info-box">
        <h3>📂 رفع ملف Excel</h3>
        <form method="POST" enctype="multipart/form-data">
            <div class="form-group">
                <label>اختر ملف Excel (.xlsx, .xls):</label>
                <input type="file" name="excel_file" accept=".xlsx, .xls" required>
            </div>
            <button type="submit" class="btn btn-primary">📤 استيراد الموظفين</button>
            <a href="list.php" class="btn btn-secondary">🔙 إلغاء</a>
        </form>
    </div>
</div>

<?php include '../includes/footer.php'; ?>