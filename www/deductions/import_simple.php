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
                    // تخطي الصفوف الفارغة تماماً
                    if (empty($row[0]) && empty($row[1]) && empty($row[2]) && empty($row[3]) && empty($row[4])) {
                        continue;
                    }
                    
                    $employee_name = trim($row[0] ?? '');
                    $source_id = (int)($row[1] ?? 0);
                    
                    // تنظيف المبلغ الكلي (إزالة الفواصل)
                    $total_amount_raw = trim($row[2] ?? '0');
                    $total_amount_raw = str_replace(',', '', $total_amount_raw);
                    $total_amount = (float)$total_amount_raw;
                    
                    // تنظيف عدد الأقساط
                    $total_months_raw = trim($row[3] ?? '0');
                    $total_months_raw = str_replace(',', '', $total_months_raw);
                    $total_months = (int)$total_months_raw;
                    
                    $start_date = trim($row[4] ?? '');
                    
                    // التحقق من صحة البيانات
                    if (empty($employee_name)) {
                        $errors[] = "الصف " . ($index + 2) . ": اسم الموظف فارغ";
                        continue;
                    }
                    if ($source_id <= 0) {
                        $errors[] = "الصف " . ($index + 2) . ": رقم المصدر غير صحيح ($source_id) - استخدم 1 لسعدين، 2 لسولاف";
                        continue;
                    }
                    if ($total_amount <= 0) {
                        $errors[] = "الصف " . ($index + 2) . ": المبلغ الكلي غير صحيح";
                        continue;
                    }
                    if ($total_months <= 0) {
                        $errors[] = "الصف " . ($index + 2) . ": عدد الأقساط غير صحيح";
                        continue;
                    }
                    if (empty($start_date)) {
                        $errors[] = "الصف " . ($index + 2) . ": تاريخ البداية فارغ";
                        continue;
                    }
                    
                    // البحث عن الموظف
                    $stmt = $pdo->prepare("SELECT id FROM employees WHERE name = ?");
                    $stmt->execute([$employee_name]);
                    $employee = $stmt->fetch();
                    
                    if (!$employee) {
                        $errors[] = "الصف " . ($index + 2) . ": الموظف '$employee_name' غير موجود";
                        continue;
                    }
                    
                    // البحث عن المصدر بالرقم
                    $stmt = $pdo->prepare("SELECT id FROM sources WHERE id = ?");
                    $stmt->execute([$source_id]);
                    $source = $stmt->fetch();
                    
                    if (!$source) {
                        $errors[] = "الصف " . ($index + 2) . ": المصدر برقم $source_id غير موجود (1=سعدين, 2=سولاف)";
                        continue;
                    }
                    
                    // حساب المبلغ الشهري وتاريخ النهاية
                    $monthly_amount = $total_amount / $total_months;
                    $end_date = date('Y-m-d', strtotime($start_date . ' + ' . ($total_months - 1) . ' months'));
                    
                    // إدخال الاقتطاع
                    $stmt = $pdo->prepare("INSERT INTO deductions (employee_id, source_id, monthly_amount, total_months, start_date, end_date) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$employee['id'], $source['id'], $monthly_amount, $total_months, $start_date, $end_date]);
                    $successCount++;
                }
                
                $message = '<div class="alert alert-success">✅ تم استيراد ' . $successCount . ' اقتطاع بنجاح!</div>';
                if (!empty($errors)) {
                    $message .= '<div class="alert alert-warning">⚠️ ' . count($errors) . ' أخطاء:<br>' . implode('<br>', array_slice($errors, 0, 15)) . '</div>';
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
    <h2 style="margin-bottom: 20px;">📂 استيراد الاقتطاعات من Excel</h2>

    <?= $message ?>

    <div class="info-box">
        <h3>📌 هيكل ملف Excel المطلوب</h3>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr style="background:#2a5298; color:white;">
                        <th>A</th><th>B</th><th>C</th><th>D</th><th>E</th>
                    </tr>
                    <tr style="background:#ddd;">
                        <th>اسم الموظف</th><th>رقم المصدر</th><th>المبلغ الكلي</th><th>عدد الأقساط</th><th>تاريخ البداية</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td>خيار سامية</td><td>1</td><td>94380</td><td>10</td><td>2021-07-01</td></tr>
                    <tr><td>الأشراف حياة</td><td>1</td><td>28600</td><td>10</td><td>2021-07-01</td></tr>
                </tbody>
            </table>
        </div>
        <p><strong>ملاحظات:</strong></p>
        <ul>
            <li>✔️ العمود A: <strong>اسم الموظف</strong> (إجباري، يجب أن يكون موجوداً في قاعدة البيانات)</li>
            <li>✔️ العمود B: <strong>رقم المصدر</strong> (1=سعدين للتجهيز, 2=سولاف)</li>
            <li>✔️ العمود C: <strong>المبلغ الكلي</strong> (رقم)</li>
            <li>✔️ العمود D: <strong>عدد الأقساط (شهور)</strong></li>
            <li>✔️ العمود E: <strong>تاريخ البداية</strong> (صيغة YYYY-MM-DD)</li>
        </ul>
    </div>

    <div class="info-box">
        <h3>📂 رفع ملف Excel</h3>
        <form method="POST" enctype="multipart/form-data">
            <div class="form-group">
                <label>اختر ملف Excel (.xlsx, .xls):</label>
                <input type="file" name="excel_file" accept=".xlsx, .xls" required>
            </div>
            <button type="submit" class="btn btn-primary">📤 استيراد الاقتطاعات</button>
            <a href="list.php" class="btn btn-secondary">🔙 إلغاء</a>
        </form>
    </div>
</div>

<?php include '../includes/footer.php'; ?>