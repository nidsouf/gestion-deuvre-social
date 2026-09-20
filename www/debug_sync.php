<?php
/**
 * debug_sync.php - تشخيص مشكلة المزامنة
 */
session_start();
require_once 'includes/auth_check.php';
require_once 'config/database.php';
require_once 'config/google_sheets.php';
require_once 'includes/functions.php';

if ($_SESSION['role'] !== 'admin') die('غير مصرح');

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>تشخيص المزامنة</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.rtl.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Cairo', sans-serif; background: #f0f2f5; padding: 20px; }
        .card { background: white; border-radius: 15px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        h3 { color: #2a5298; border-bottom: 3px solid #2a5298; padding-bottom: 10px; }
        pre { background: #f8f9fa; padding: 15px; border-radius: 10px; overflow-x: auto; font-size: 12px; }
        .ok { color: #28a745; font-weight: bold; }
        .err { color: #dc3545; font-weight: bold; }
        .warn { color: #ff9800; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { padding: 8px; border: 1px solid #ddd; text-align: right; }
        th { background: #2a5298; color: white; }
    </style>
</head>
<body>
    <h1>🔬 تشخيص المزامنة</h1>

    <!-- 1. الرابط الحالي -->
    <div class="card">
        <h3>1️⃣ رابط CSV المُستخدم</h3>
        <pre><?= htmlspecialchars(GOOGLE_SHEET_CSV_URL) ?></pre>
        <a href="<?= htmlspecialchars(GOOGLE_SHEET_CSV_URL) ?>" target="_blank" class="btn btn-primary">
            🔗 فتح الرابط في نافذة جديدة
        </a>
        <p class="mt-2"><small>⚠️ إذا لم تظهر البيانات الجديدة، فالمشكلة في Google Sheets (تحتاج إعادة نشر)</small></p>
    </div>

    <!-- 2. اختبار الاتصال -->
    <div class="card">
        <h3>2️⃣ اختبار جلب CSV</h3>
        <?php
        $cacheBuster = '&_=' . time();
        $testUrl = GOOGLE_SHEET_CSV_URL . $cacheBuster;
        
        $startTime = microtime(true);
        $context = stream_context_create([
            'http' => [
                'timeout' => 30,
                'user_agent' => 'PHP-Debug/1.0',
                'ignore_errors' => true,
            ]
        ]);
        
        $csv = @file_get_contents($testUrl, false, $context);
        $fetchTime = round(microtime(true) - $startTime, 2);
        
        if ($csv === false) {
            echo '<p class="err">❌ فشل الاتصال</p>';
            echo '<pre>HTTP Response Headers: ' . print_r($http_response_header ?? 'none', true) . '</pre>';
        } elseif (empty($csv)) {
            echo '<p class="err">❌ CSV فارغ</p>';
        } else {
            $lines = preg_split('/\r\n|\r|\n/', $csv);
            $lines = array_filter($lines, fn($l) => !empty(trim($l)));
            $rowCount = count($lines);
            
            echo '<p class="ok">✅ تم الجلب بنجاح</p>';
            echo '<p>⏱️ الوقت: ' . $fetchTime . ' ثانية</p>';
            echo '<p>📊 عدد السطور: <strong>' . $rowCount . '</strong></p>';
            echo '<p>📏 الحجم: ' . number_format(strlen($csv)) . ' بايت</p>';
            
            // عرض السطور (آخر 10)
            echo '<h4>📋 آخر 10 سطور:</h4>';
            echo '<table><thead><tr><th>#</th><th>السطر</th></tr></thead><tbody>';
            $linesArray = array_values($lines);
            $displayLines = array_slice($linesArray, -10);
            foreach ($displayLines as $i => $line) {
                $lineNum = $rowCount - 10 + $i + 1;
                echo '<tr><td>' . $lineNum . '</td><td><pre style="margin:0; white-space:pre-wrap; word-break:break-all;">' . htmlspecialchars($line) . '</pre></td></tr>';
            }
            echo '</tbody></table>';
            
            // تحليل الرأس
            $header = str_getcsv($linesArray[0]);
            echo '<h4>📋 أعمدة CSV (Header):</h4>';
            echo '<ul>';
            foreach ($header as $i => $col) {
                echo '<li>[' . $i . '] ' . htmlspecialchars($col) . '</li>';
            }
            echo '</ul>';
        }
        ?>
    </div>

    <!-- 3. مقارنة مع كود المزامنة -->
    <div class="card">
        <h3>3️⃣ الأعمدة المتوقعة في الكود</h3>
        <p>الكود يبحث عن هذه الأعمدة:</p>
        <table>
            <thead>
                <tr><th>الاسم في الكود</th><th>الأسماء البديلة</th></tr>
            </thead>
            <tbody>
                <tr><td>الاسم واللقب</td><td>الاسم</td></tr>
                <tr><td>رقم التأجير</td><td>رقم الحساب</td></tr>
                <tr><td>نوع الطلب</td><td>—</td></tr>
                <tr><td>نوع المنحة</td><td>—</td></tr>
                <tr><td>المبلغ المطلوب</td><td>المبلغ</td></tr>
                <tr><td>رقم الهاتف</td><td>—</td></tr>
                <tr><td>البريد الإلكتروني</td><td>—</td></tr>
                <tr><td>ملاحظات إضافية</td><td>ملاحظات</td></tr>
                <tr><td>الطابع الزمني</td><td>Timestamp</td></tr>
            </tbody>
        </table>
        <p class="warn">⚠️ إذا كانت أسماء الأعمدة في CSV مختلفة، عدّل الكود</p>
    </div>

    <!-- 4. آخر إدخالات sync_log -->
    <div class="card">
        <h3>4️⃣ آخر إدخالات sync_log</h3>
        <?php
        $stmt = $pdo->query("
            SELECT id, source, row_hash, employee_name, request_id, synced_at 
            FROM sync_log 
            ORDER BY synced_at DESC 
            LIMIT 15
        ");
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <table>
            <thead>
                <tr><th>#</th><th>الموظف</th><th>row_hash</th><th>request_id</th><th>التاريخ</th></tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><?= $log['id'] ?></td>
                        <td><?= htmlspecialchars($log['employee_name'] ?? '—') ?></td>
                        <td style="font-size:10px; font-family:monospace;"><?= htmlspecialchars(substr($log['row_hash'], 0, 16)) ?>...</td>
                        <td><?= $log['request_id'] ?? '—' ?></td>
                        <td><?= $log['synced_at'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- 5. آخر الطلبات -->
    <div class="card">
        <h3>5️⃣ آخر 10 طلبات في قاعدة البيانات</h3>
        <?php
        $stmt = $pdo->query("
            SELECT id, employee_name, employee_number, request_type, 
                   requested_amount, status, source, created_at
            FROM requests
            ORDER BY id DESC
            LIMIT 10
        ");
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <table>
            <thead>
                <tr><th>#</th><th>الاسم</th><th>النوع</th><th>المبلغ</th><th>الحالة</th><th>المصدر</th><th>التاريخ</th></tr>
            </thead>
            <tbody>
                <?php foreach ($requests as $r): ?>
                    <tr>
                        <td><?= $r['id'] ?></td>
                        <td><?= htmlspecialchars($r['employee_name'] ?? '—') ?></td>
                        <td><?= $r['request_type'] ?></td>
                        <td><?= number_format($r['requested_amount'], 2) ?></td>
                        <td><?= $r['status'] ?></td>
                        <td><?= $r['source'] ?></td>
                        <td><?= $r['created_at'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- 6. تنظيف -->
    <div class="card">
        <h3>6️⃣ إجراءات التنظيف</h3>
        <form method="POST">
            <button name="clean_sync_log" class="btn btn-warning">🗑️ حذف كل sync_log (لمزامنة كاملة)</button>
            <button name="clean_unknown" class="btn btn-danger">🗑️ حذف الطلبات "غير معروف" الملغاة</button>
        </form>
        
        <?php
        if (isset($_POST['clean_sync_log'])) {
            $count = $pdo->exec("DELETE FROM sync_log");
            echo "<p class='ok'>✅ تم حذف $count إدخال من sync_log. أعد المزامنة الآن.</p>";
        }
        if (isset($_POST['clean_unknown'])) {
            $count = $pdo->exec("DELETE FROM requests WHERE employee_name = 'غير معروف' AND status = 'cancelled'");
            echo "<p class='ok'>✅ تم حذف $count طلب ملغى.</p>";
        }
        ?>
    </div>

</body>
</html>