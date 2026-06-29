<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';

$exportDir = __DIR__ . '/../exports/';
if (!file_exists($exportDir)) {
    mkdir($exportDir, 0777, true);
}

$message = '';
$messageType = '';

// ========== معالجة التصدير ==========
if (isset($_POST['export_type'])) {
    $exportType = $_POST['export_type'];
    $year = isset($_POST['year']) ? (int)$_POST['year'] : date('Y');
    $month = isset($_POST['month']) ? (int)$_POST['month'] : date('m');
    $default_price = 25;
    
    $filename = '';
    $headers = [];
    $data = [];
    $grand_total = 0;
    
    try {
        if ($exportType == 'beneficiaries') {
            // ===== قائمة المستفيدين =====
            $stmt = $pdo->query("
                SELECT code, last_name, first_name, type, price_per_meal 
                FROM meal_beneficiaries 
                WHERE is_active = 1 
                ORDER BY last_name, first_name
            ");
            $data = $stmt->fetchAll();
            $filename = "liste_beneficiaires_" . date('Ymd_His') . ".csv";
            $headers = ['رقم التسجيل', 'اللقب', 'الاسم', 'النوع', 'سعر الوجبة (دج)'];
            
        } elseif ($exportType == 'employees_beneficiaries') {
            // ===== تصدير الموظفين كصيغة المستفيدين =====
            $stmt = $pdo->query("
                SELECT 
                    e.id as code,
                    e.name,
                    e.category
                FROM employees e
                ORDER BY e.name
            ");
            $data = $stmt->fetchAll();
            $filename = "liste_employes_beneficiaires_" . date('Ymd_His') . ".csv";
            $headers = ['رقم التسجيل', 'اللقب', 'الاسم', 'النوع', 'سعر الوجبة (دج)'];
            
        } elseif ($exportType == 'employees_detailed') {
            // ===== تصدير الموظفين تفصيلياً =====
            $stmt = $pdo->query("
                SELECT 
                    e.id as code,
                    e.name,
                    e.category,
                    e.department,
                    e.hire_date
                FROM employees e
                ORDER BY e.name
            ");
            $data = $stmt->fetchAll();
            $filename = "liste_employes_detaille_" . date('Ymd_His') . ".csv";
            $headers = ['رقم التسجيل', 'الاسم الكامل', 'الفئة', 'القسم', 'تاريخ التوظيف'];
            
        } elseif ($exportType == 'monthly_report') {
            // ===== تقرير وجبات شهري =====
            $stmt = $pdo->prepare("
                SELECT 
                    e.code,
                    e.last_name,
                    e.first_name,
                    e.type,
                    COALESCE(mr.meal_count, 0) as total_meals,
                    COALESCE(mr.meal_count, 0) as present_count,
                    0 as absent_count,
                    e.price_per_meal,
                    COALESCE(mr.total_amount, 0) as total_amount
                FROM meal_beneficiaries e
                LEFT JOIN meal_records mr ON e.id = mr.beneficiary_id AND mr.year = ? AND mr.month = ?
                WHERE e.is_active = 1
                ORDER BY e.last_name, e.first_name
            ");
            $stmt->execute([$year, $month]);
            $data = $stmt->fetchAll();
            $filename = "rapport_repas_{$year}-" . str_pad($month,2,'0',STR_PAD_LEFT) . "_" . date('Ymd_His') . ".csv";
            $headers = ['رقم التسجيل', 'اللقب', 'الاسم', 'النوع', 'عدد الوجبات', 'حاضر', 'غائب', 'سعر الوجبة (دج)', 'المبلغ المستحق (دج)'];
            $grand_total = 0;
            
        } else {
            throw new Exception("نوع التصدير غير معروف");
        }
        
        // ===== إنشاء ملف CSV (بفاصل ;) =====
        $filePath = $exportDir . $filename;
        $output = fopen($filePath, 'w');
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM لدعم UTF-8
        fputcsv($output, $headers, ';');  // استخدام ; كفاصل
        
        if ($exportType == 'monthly_report') {
            foreach ($data as $row) {
                $total = $row['total_amount'];
                $grand_total += $total;
                fputcsv($output, [
                    $row['code'],
                    $row['last_name'],
                    $row['first_name'],
                    $row['type'] == 'trainee' ? 'متربص' : 'موظف',
                    $row['total_meals'],
                    $row['present_count'],
                    $row['absent_count'],
                    number_format($row['price_per_meal'], 2),
                    number_format($total, 2)
                ], ';');
            }
            fputcsv($output, [], ';');
            fputcsv($output, ['الإجمالي العام', '', '', '', '', '', '', '', number_format($grand_total, 2)], ';');
            
        } elseif ($exportType == 'beneficiaries') {
            foreach ($data as $row) {
                fputcsv($output, [
                    $row['code'],
                    $row['last_name'],
                    $row['first_name'],
                    $row['type'] == 'trainee' ? 'متربص' : 'موظف',
                    number_format($row['price_per_meal'], 2)
                ], ';');
            }
            
        } elseif ($exportType == 'employees_beneficiaries') {
            foreach ($data as $row) {
                // تقسيم الاسم إلى لقب واسم أول
                $nameParts = explode(' ', trim($row['name']));
                if (count($nameParts) >= 2) {
                    $last_name = array_pop($nameParts);
                    $first_name = implode(' ', $nameParts);
                } else {
                    $last_name = $row['name'];
                    $first_name = '';
                }
                $type = ($row['category'] == 'trainee') ? 'متربص' : 'موظف';
                
                fputcsv($output, [
                    $row['code'],
                    $last_name,
                    $first_name,
                    $type,
                    number_format($default_price, 2)
                ], ';');
            }
            
        } elseif ($exportType == 'employees_detailed') {
            foreach ($data as $row) {
                fputcsv($output, [
                    $row['code'],
                    $row['name'],
                    $row['category'] == 'Permanent' ? 'دائم' : 'متعاقد',
                    $row['department'] ?? '',
                    $row['hire_date'] ? date('d/m/Y', strtotime($row['hire_date'])) : ''
                ], ';');
            }
        }
        fclose($output);
        
        // تسجيل في سجل التدقيق
        if (function_exists('audit')) {
            audit('EXPORT_' . strtoupper($exportType), "تم تصدير ملف $filename بنجاح");
        }
        
        $message = "✅ تم إنشاء الملف بنجاح: $filename";
        $messageType = "success";
        
    } catch (Exception $e) {
        $message = "❌ خطأ: " . $e->getMessage();
        $messageType = "error";
    }
}

// ========== معالجة حذف ملف ==========
if (isset($_GET['delete'])) {
    $filename = basename($_GET['delete']);
    $filePath = $exportDir . $filename;
    if (file_exists($filePath) && unlink($filePath)) {
        if (function_exists('audit')) {
            audit('EXPORT_DELETE', "تم حذف ملف $filename");
        }
        $message = "✅ تم حذف الملف: $filename";
        $messageType = "success";
    } else {
        $message = "❌ فشل حذف الملف";
        $messageType = "error";
    }
}

// جلب قائمة الملفات المصدرة
$files = glob($exportDir . '*.csv');
rsort($files);

$csrf_token = generateCSRFToken();
include '../includes/header.php';
?>

<style>
    .export-container { max-width: 1200px; margin: 0 auto; }
    .export-card { background: white; border-radius: 20px; padding: 25px; margin-bottom: 25px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
    .export-card h3 { margin-bottom: 15px; border-bottom: 2px solid #2a5298; padding-bottom: 10px; }
    .btn-export { background: #28a745; color: white; border: none; padding: 10px 20px; border-radius: 30px; cursor: pointer; }
    .btn-export:hover { background: #218838; }
    .btn-download { background: #17a2b8; color: white; padding: 4px 12px; border-radius: 20px; text-decoration: none; font-size: 12px; display: inline-block; }
    .btn-delete { background: #dc3545; color: white; padding: 4px 12px; border-radius: 20px; text-decoration: none; font-size: 12px; display: inline-block; }
    .files-table { width: 100%; border-collapse: collapse; background: white; border-radius: 15px; overflow: hidden; }
    .files-table th, .files-table td { border: 1px solid #ddd; padding: 10px; text-align: center; }
    .files-table th { background: #2a5298; color: white; }
    .form-group { margin-bottom: 15px; }
    .form-group label { display: block; font-weight: bold; margin-bottom: 5px; }
    .form-group select { padding: 8px 15px; border-radius: 20px; border: 1px solid #ccc; min-width: 200px; }
</style>

<div class="export-container">
    <h2>📤 إدارة التصدير</h2>
    
    <?php if ($message): ?>
        <div style="background: <?= $messageType == 'success' ? '#d4edda' : '#f8d7da' ?>; padding: 15px; border-radius: 10px; margin-bottom: 20px;">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>
    
    <!-- نماذج التصدير -->
    <div class="export-card">
        <h3>📥 تصدير البيانات</h3>
        <form method="POST" style="display: flex; gap: 20px; flex-wrap: wrap; align-items: flex-end;">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            
            <div class="form-group">
                <label>نوع التصدير</label>
                <select name="export_type" required>
                    <option value="beneficiaries">📋 قائمة المستفيدين (الوجبات)</option>
                    <option value="employees_beneficiaries">👤 قائمة الموظفين (كصيغة المستفيدين)</option>
                    <option value="employees_detailed">👤 قائمة الموظفين (تفصيلية)</option>
                    <option value="monthly_report">📊 تقرير وجبات شهري</option>
                </select>
            </div>
            
            <div class="form-group" id="monthly_fields" style="display: none;">
                <label>الشهر والسنة</label>
                <div style="display: flex; gap: 10px;">
                    <select name="month">
                        <?php for($m=1;$m<=12;$m++): ?>
                            <option value="<?= $m ?>" <?= $m == date('m') ? 'selected' : '' ?>><?= getMonthNameArabic($m) ?></option>
                        <?php endfor; ?>
                    </select>
                    <select name="year">
                        <?php for($y=2020;$y<=date('Y')+1;$y++): ?>
                            <option value="<?= $y ?>" <?= $y == date('Y') ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>
            
            <button type="submit" class="btn-export">📤 إنشاء ملف CSV</button>
        </form>
    </div>
    
    <!-- قائمة الملفات المصدرة -->
    <div class="export-card">
        <h3>📂 الملفات المصدرة</h3>
        <?php if (empty($files)): ?>
            <p style="color:#666;">لا توجد ملفات مصدرة حتى الآن.</p>
        <?php else: ?>
            <table class="files-table">
                <thead>
                    <tr><th>#</th><th>اسم الملف</th><th>الحجم</th><th>تاريخ الإنشاء</th><th>الإجراءات</th></tr>
                </thead>
                <tbody>
                    <?php $i=1; foreach ($files as $file): 
                        $name = basename($file);
                        $size = round(filesize($file) / 1024, 2);
                        $date = date('d/m/Y H:i:s', filemtime($file));
                    ?>
                    <tr>
                        <td><?= $i++ ?></td>
                        <td><?= htmlspecialchars($name) ?></td>
                        <td><?= $size ?> ك.ب</td>
                        <td><?= $date ?></td>
                        <td>
                            <a href="../exports/<?= urlencode($name) ?>" class="btn-download" download>📥 تحميل</a>
                            <a href="?delete=<?= urlencode($name) ?>" class="btn-delete" onclick="return confirm('🗑️ هل أنت متأكد من حذف هذا الملف؟')">🗑️ حذف</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<script>
    document.querySelector('select[name="export_type"]').addEventListener('change', function() {
        const monthlyFields = document.getElementById('monthly_fields');
        if (this.value == 'monthly_report') {
            monthlyFields.style.display = 'block';
        } else {
            monthlyFields.style.display = 'none';
        }
    });
</script>

<?php include '../includes/footer.php'; ?>