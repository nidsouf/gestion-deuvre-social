<?php
/**
 * requests/sync_from_google.php - مزامنة يدوية من Google Sheets
 * النسخة الكاملة المُحدَّثة - تدعم جميع أسماء الأعمدة
 */
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';
require_once '../includes/requests_helpers.php';
require_once '../config/google_sheets.php';

// التحقق من الصلاحية
if (!in_array($_SESSION['role'] ?? '', ['admin', 'manager', 'committee'])) {
    setToast('⚠️ غير مصرح لك بهذه الصفحة', 'warning');
    header('Location: index.php');
    exit;
}

// ============================================================
// إنشاء جدول sync_log إذا لم يكن موجوداً
// ============================================================
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sync_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            source TEXT NOT NULL,
            row_hash TEXT UNIQUE,
            employee_name TEXT,
            request_id INTEGER,
            synced_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
} catch (Exception $e) {
    error_log("sync_log table error: " . $e->getMessage());
}

// ============================================================
// ✅ دالة قراءة مرنة للقيم من الصف
// تدعم: الاسم الدقيق، الاسم التقريبي، الفهرس الاحتياطي
// ============================================================
if (!function_exists('getColValue')) {
    function getColValue($row, $header, $names, $fallbackIndex = -1) {
        // 1. البحث بالاسم الدقيق
        if (is_array($names)) {
            foreach ($names as $name) {
                foreach ($header as $i => $colName) {
                    if (trim($colName) === trim($name)) {
                        return trim($row[$i] ?? '');
                    }
                }
            }
        }
        
        // 2. البحث المرن (يحتوي على)
        if (is_array($names)) {
            foreach ($names as $name) {
                foreach ($header as $i => $colName) {
                    if (!empty($name) && stripos($colName, $name) !== false) {
                        return trim($row[$i] ?? '');
                    }
                }
            }
        }
        
        // 3. الفهرس الاحتياطي
        if ($fallbackIndex >= 0 && isset($row[$fallbackIndex])) {
            return trim($row[$fallbackIndex]);
        }
        
        return '';
    }
}

// ============================================================
// ✅ دالة تنظيف المبالغ (تدعم "50000 دج" و "50,000.00" وغيرها)
// ============================================================
if (!function_exists('cleanAmount')) {
    function cleanAmount($amountRaw) {
        if (empty($amountRaw)) return 0;
        
        // إزالة كل ما ليس رقماً أو فاصلة أو نقطة
        $cleaned = preg_replace('/[^0-9.,]/', '', $amountRaw);
        $cleaned = str_replace(' ', '', $cleaned);
        
        // إذا كان هناك فاصل آلاف (مثل: 50,000) احذفه
        if (preg_match('/^\d{1,3}(,\d{3})+(\.\d+)?$/', $cleaned)) {
            $cleaned = str_replace(',', '', $cleaned);
        }
        
        // استبدال الفاصلة بنقطة (في حالة الأرقام العشرية)
        $cleaned = str_replace(',', '.', $cleaned);
        
        return (float)$cleaned;
    }
}

$isPost = ($_SERVER['REQUEST_METHOD'] === 'POST');

// ============================================================
// معالجة طلب المزامنة (POST)
// ============================================================
if ($isPost) {
    requireCSRFToken();
    
    $imported = 0;
    $skipped = 0;
    $errors = [];
    $details = [];
    
    try {
        // ============================================================
        // 1. جلب CSV من Google Sheets (مع Cache Buster)
        // ============================================================
        $cacheBuster = '&_=' . time();
        $csvUrl = GOOGLE_SHEET_CSV_URL . $cacheBuster;
        
        $context = stream_context_create([
            'http' => [
                'timeout' => GOOGLE_FETCH_TIMEOUT,
                'user_agent' => 'PHP-Desktop-Sync/2.0',
                'ignore_errors' => true,
                'header' => "Cache-Control: no-cache\r\nPragma: no-cache\r\n"
            ]
        ]);
        
        $csv = @file_get_contents($csvUrl, false, $context);
        
        if ($csv === false || empty($csv)) {
            throw new Exception('تعذّر الاتصال بـ Google Sheets. تأكد من الرابط ومن اتصال الإنترنت.');
        }
        
        // التحقق من أنه CSV وليس HTML
        if (stripos($csv, '<html') !== false || stripos($csv, '<!DOCTYPE') !== false) {
            throw new Exception('الرابط يعيد صفحة HTML وليس CSV. تأكد من نشر الجدول بصيغة CSV.');
        }
        
        // ============================================================
        // 2. تحويل CSV إلى مصفوفة
        // ============================================================
        $lines = preg_split('/\r\n|\r|\n/', $csv);
        $lines = array_filter($lines, fn($l) => !empty(trim($l)));
        $lines = array_values($lines);
        
        if (count($lines) < 2) {
            throw new Exception('الجدول فارغ أو يحتوي على سطر واحد فقط.');
        }
        
        // السطر الأول: الرأس
        $header = str_getcsv(array_shift($lines));
        $header = array_map('trim', $header);
        
        // ============================================================
        // 3. معالجة كل صف
        // ============================================================
        foreach ($lines as $lineNum => $line) {
            $row = str_getcsv($line);
            
            // تعديل عدد الأعمدة إذا كانت أقل من الرأس
            if (count($row) < count($header)) {
                $row = array_pad($row, count($header), '');
            }
            
            // ============================================================
            // ✅ قراءة البيانات مع دعم الأسماء الجديدة والفهارس
            // ============================================================
            $employeeName = getColValue($row, $header, 
                                ['اللقب و الاسم', 'الاسم واللقب', 'الاسم', 'employee_name'], 
                                2);
            
            $employeeNumber = getColValue($row, $header, 
                                ['رقم التأجير', 'رقم الحساب', 'employee_number'], 
                                -1);
            
            $position = getColValue($row, $header, 
                            ['الرتبة او الوظيفة', 'الرتبة', 'الوظيفة', 'position'], 
                            3);
            
            $requestType = getColValue($row, $header, 
                                ['نوع الطلب', 'request_type'], 
                                4);
            
            $grantType = getColValue($row, $header, 
                            ['نوع المنحة', 'grant_type'], 
                            5);
            
            $amountRaw = getColValue($row, $header, 
                            ['المبلغ المطلوب', 'المبلغ', 'amount'], 
                            6);
            
            $phone = getColValue($row, $header, 
                        ['رقم الهاتف', 'الهاتف', 'phone'], 
                        7);
            
            $email = getColValue($row, $header, 
                        ['البريد الإلكتروني', 'email'], 
                        8);
            
            $notes = getColValue($row, $header, 
                        ['ملاحظات إضافية', 'ملاحظات', 'notes'], 
                        9);
            
            $timestamp = getColValue($row, $header, 
                            ['Horodateur', 'الطابع الزمني', 'Timestamp'], 
                            0);
            
            // تنظيف المبلغ
            $amount = cleanAmount($amountRaw);
            
            // تخطي إذا كان الاسم فارغاً
            if (empty($employeeName)) {
                error_log("⚠️ سطر بدون اسم: " . $line);
                continue;
            }
            
            // ============================================================
            // نوع الطلب
            // ============================================================
            $typeMap = [
                'منحة' => 'grant', 'سلفة' => 'loan', 'اقتطاع' => 'deduction',
                'grant' => 'grant', 'loan' => 'loan', 'deduction' => 'deduction'
            ];
            $requestTypeEn = $typeMap[$requestType] ?? 'grant';
            
            // ============================================================
            // grant_id
            // ============================================================
            $grantId = null;
            if ($requestTypeEn === 'grant' && !empty($grantType)) {
                $stmt = $pdo->prepare("SELECT id FROM grants WHERE name LIKE ? LIMIT 1");
                $stmt->execute(['%' . $grantType . '%']);
                $grantId = $stmt->fetchColumn() ?: null;
            }
            
            // ============================================================
            // ✅ row_hash محسّن (بدون timestamp)
            // ============================================================
            $submittedYear = date('Y', strtotime($timestamp)) ?: date('Y');
            $rowHash = md5(
                strtolower(trim($employeeName)) . '|' .
                strtolower(trim($requestType)) . '|' .
                strtolower(trim($grantType)) . '|' .
                $amount . '|' .
                $submittedYear
            );
            
            // ============================================================
            // ✅ فحص مزدوج: sync_log + requests
            // ============================================================
            
            // أ. فحص sync_log
            $stmt = $pdo->prepare("SELECT id FROM sync_log WHERE row_hash = ? LIMIT 1");
            $stmt->execute([$rowHash]);
            if ($stmt->fetchColumn()) {
                $skipped++;
                continue;
            }
            
            // ب. فحص requests (خلال آخر 60 يوماً) - فحص متسامح
            $stmt = $pdo->prepare("
                SELECT id FROM requests
                WHERE TRIM(LOWER(employee_name)) = TRIM(LOWER(?))
                  AND request_type = ?
                  AND ABS(COALESCE(requested_amount, 0) - ?) < 0.01
                  AND source = 'google_form'
                  AND datetime(created_at) > datetime('now', '-60 days')
                LIMIT 1
            ");
            $stmt->execute([$employeeName, $requestTypeEn, $amount]);
            if ($stmt->fetchColumn()) {
                // أضف إلى sync_log حتى لا يُعاد فحصه
                try {
                    $stmt = $pdo->prepare("INSERT OR IGNORE INTO sync_log (source, row_hash, employee_name) VALUES ('google_sheets', ?, ?)");
                    $stmt->execute([$rowHash, $employeeName]);
                } catch (Exception $e) {}
                $skipped++;
                continue;
            }
            
            // ============================================================
            // البحث عن الموظف
            // ============================================================
            $employeeId = null;
            
            if (!empty($employeeNumber)) {
                $stmt = $pdo->prepare("SELECT id FROM employees WHERE account_number = ? LIMIT 1");
                $stmt->execute([$employeeNumber]);
                $employeeId = $stmt->fetchColumn() ?: null;
            }
            
            if (!$employeeId) {
                // بحث ذكي: إزالة المسافات الزائدة
                $cleanName = trim(preg_replace('/\s+/', ' ', $employeeName));
                
                // أ. مطابقة دقيقة
                $stmt = $pdo->prepare("SELECT id FROM employees WHERE TRIM(name) = ? COLLATE NOCASE LIMIT 1");
                $stmt->execute([$cleanName]);
                $employeeId = $stmt->fetchColumn() ?: null;
                
                // ب. مطابقة جزئية
                if (!$employeeId) {
                    $stmt = $pdo->prepare("SELECT id FROM employees WHERE name LIKE ? COLLATE NOCASE LIMIT 1");
                    $stmt->execute(['%' . $cleanName . '%']);
                    $employeeId = $stmt->fetchColumn() ?: null;
                }
                
                // ج. مطابقة عكسية (البحث عن الأسماء التي تحتوي على الاسم المرسل)
                if (!$employeeId) {
                    $nameParts = explode(' ', $cleanName);
                    if (count($nameParts) >= 2) {
                        $firstWord = $nameParts[0];
                        $stmt = $pdo->prepare("SELECT id FROM employees WHERE name LIKE ? COLLATE NOCASE LIMIT 1");
                        $stmt->execute(['%' . $firstWord . '%']);
                        $employeeId = $stmt->fetchColumn() ?: null;
                    }
                }
            }
            
            $isUnknownEmployee = ($employeeId === null);
            
            // ============================================================
            // source_id
            // ============================================================
            $sourceId = null;
            if ($requestTypeEn === 'loan') $sourceId = 2;
            elseif ($requestTypeEn === 'deduction') $sourceId = 1;
            
            // ============================================================
            // تحويل التاريخ
            // ============================================================
            $requestedDate = date('Y-m-d');
            if (!empty($timestamp)) {
                // دعم تنسيقات: DD/MM/YYYY HH:MM:SS و YYYY-MM-DD HH:MM:SS
                $ts = strtotime(str_replace('/', '-', $timestamp));
                if ($ts !== false) {
                    $requestedDate = date('Y-m-d', $ts);
                } else {
                    $ts = strtotime($timestamp);
                    if ($ts !== false) {
                        $requestedDate = date('Y-m-d', $ts);
                    }
                }
            }
            
            // ============================================================
            // إدراج الطلب
            // ============================================================
            try {
                $pdo->beginTransaction();
                
                $stmt = $pdo->prepare("
                    INSERT INTO requests (
                        employee_id, employee_name, employee_number,
                        request_type, grant_id, source_id,
                        title, description,
                        requested_amount, requested_months,
                        requested_date, status, source, created_at
                    ) VALUES (
                        :employee_id, :employee_name, :employee_number,
                        :request_type, :grant_id, :source_id,
                        :title, :description,
                        :requested_amount, :requested_months,
                        :requested_date, 'pending', 'google_form', datetime('now')
                    )
                ");
                
                $title = 'طلب من Google Forms: ' . ($grantType ?: $requestType);
                $months = ($requestTypeEn === 'grant') ? 0 : 1;
                
                // ✅ بناء الوصف مع الوظيفة والهاتف والبريد
                $fullDescription = $notes;
                if ($position) $fullDescription = "💼 الوظيفة: $position\n" . $fullDescription;
                if ($phone) $fullDescription .= "\n📞 الهاتف: $phone";
                if ($email) $fullDescription .= "\n✉️ البريد: $email";
                if ($isUnknownEmployee) $fullDescription .= "\n⚠️ موظف غير مسجل في قاعدة البيانات";
                
                $stmt->execute([
                    ':employee_id' => $employeeId,
                    ':employee_name' => $employeeName,
                    ':employee_number' => $employeeNumber,
                    ':request_type' => $requestTypeEn,
                    ':grant_id' => $grantId,
                    ':source_id' => $sourceId,
                    ':title' => $title,
                    ':description' => $fullDescription,
                    ':requested_amount' => $amount,
                    ':requested_months' => $months,
                    ':requested_date' => $requestedDate
                ]);
                
                $requestId = $pdo->lastInsertId();
                
                // تسجيل في sync_log
                $stmt = $pdo->prepare("
                    INSERT OR IGNORE INTO sync_log (source, row_hash, employee_name, request_id)
                    VALUES ('google_sheets', ?, ?, ?)
                ");
                $stmt->execute([$rowHash, $employeeName, $requestId]);
                
                // تعليق تلقائي
                $stmt = $pdo->prepare("
                    INSERT INTO request_comments (request_id, user_id, comment)
                    VALUES (?, NULL, ?)
                ");
                $comment = 'تم استيراد الطلب من Google Sheets.';
                if ($position) $comment .= "\n💼 الوظيفة: $position";
                if ($isUnknownEmployee) {
                    $comment .= ' ⚠️ الموظف غير مسجل في قاعدة البيانات.';
                }
                $stmt->execute([$requestId, $comment]);
                
                $pdo->commit();
                
                $imported++;
                $details[] = [
                    'name' => $employeeName,
                    'type' => $requestType,
                    'amount' => $amount,
                    'status' => $isUnknownEmployee ? 'unknown' : 'imported',
                    'request_id' => $requestId,
                    'unknown' => $isUnknownEmployee
                ];
                
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = "❌ $employeeName: " . $e->getMessage();
            }
        }
        
        $message = "✅ تم استيراد $imported طلب جديد";
        if ($skipped > 0) $message .= " | تم تخطي $skipped (مُزامن مسبقاً)";
        if (!empty($errors)) $message .= " | أخطاء: " . count($errors);
        
        setToast($message, empty($errors) ? 'success' : 'warning');
        
    } catch (Exception $e) {
        setToast('❌ ' . $e->getMessage(), 'error');
    }
    
    $_SESSION['sync_results'] = [
        'imported' => $imported,
        'skipped' => $skipped,
        'errors' => $errors,
        'details' => $details
    ];
    
    header('Location: sync_from_google.php');
    exit;
}

// ============================================================
// عرض الصفحة
// ============================================================
$results = $_SESSION['sync_results'] ?? null;
unset($_SESSION['sync_results']);

$totalSynced = $pdo->query("SELECT COUNT(*) FROM sync_log WHERE source = 'google_sheets'")->fetchColumn();
$lastSync = $pdo->query("SELECT MAX(synced_at) FROM sync_log WHERE source = 'google_sheets'")->fetchColumn();

$csrf_token = generateCSRFToken();
$pageTitle = 'مزامنة من Google Sheets';
include '../includes/header.php';
?>

<link rel="stylesheet" href="../assets/css/requests.css">

<style>
    .sync-container { max-width: 900px; margin: 30px auto; padding: 0 15px; }
    .sync-header {
        background: linear-gradient(135deg, #4285f4, #34a853);
        color: white; padding: 30px; border-radius: 20px; text-align: center;
        margin-bottom: 30px; box-shadow: 0 6px 25px rgba(66, 133, 244, 0.3);
    }
    .sync-header h1 { margin: 0 0 10px 0; font-size: 28px; }
    .sync-header p { margin: 0; opacity: 0.95; font-size: 15px; }
    .sync-card {
        background: white; border-radius: 16px; padding: 30px;
        box-shadow: 0 3px 15px rgba(0,0,0,0.08); margin-bottom: 20px;
    }
    .sync-info {
        background: #f0f7ff; border-right: 5px solid #4285f4;
        padding: 15px 20px; border-radius: 10px; margin-bottom: 20px;
        font-size: 14px; line-height: 1.8;
    }
    .sync-info code {
        background: #fff; padding: 2px 8px; border-radius: 4px;
        font-size: 12px; color: #1a73e8; word-break: break-all;
    }
    .stats-row {
        display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 12px; margin-bottom: 20px;
    }
    .mini-stat {
        background: #f8f9fa; border-radius: 12px; padding: 15px;
        text-align: center; border: 1px solid #e9ecef;
    }
    .mini-stat .mini-label { font-size: 12px; color: #6c757d; margin-bottom: 5px; }
    .mini-stat .mini-value { font-size: 20px; font-weight: 700; color: #1a73e8; }
    .btn-sync {
        background: linear-gradient(135deg, #4285f4, #34a853);
        color: white; border: none; padding: 16px 40px;
        font-size: 18px; font-weight: 700; border-radius: 50px;
        cursor: pointer; transition: all 0.3s; display: block;
        width: 100%; max-width: 400px; margin: 0 auto;
        box-shadow: 0 4px 15px rgba(66, 133, 244, 0.4);
    }
    .btn-sync:hover { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(66, 133, 244, 0.5); }
    .btn-sync:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
    .results-box { background: #f8f9fa; border-radius: 12px; padding: 20px; margin-top: 20px; }
    .stat-item {
        display: flex; justify-content: space-between; padding: 10px 15px;
        border-bottom: 1px solid #e9ecef; font-size: 15px;
    }
    .stat-item:last-child { border-bottom: none; }
    .stat-item .label { font-weight: 600; color: #495057; }
    .stat-item .value { font-weight: 700; }
    .stat-item.success .value { color: #28a745; }
    .stat-item.warning .value { color: #ffc107; }
    .stat-item.danger .value { color: #dc3545; }
    .error-list { background: #f8d7da; border-radius: 8px; padding: 15px; margin-top: 15px; }
    .error-list li { margin-bottom: 5px; font-size: 13px; color: #721c24; }
    .details-table { width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 13px; }
    .details-table th { background: #e9ecef; padding: 10px; text-align: right; border: 1px solid #dee2e6; }
    .details-table td { padding: 8px 10px; border: 1px solid #dee2e6; }
    .loading-spinner { display: none; text-align: center; padding: 20px; }
    .loading-spinner.active { display: block; }
    .spinner {
        border: 4px solid #f3f3f3; border-top: 4px solid #4285f4;
        border-radius: 50%; width: 40px; height: 40px;
        animation: spin 1s linear infinite; margin: 0 auto 15px;
    }
    @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    .badge-unknown {
        background: #ff9800; color: white; padding: 2px 8px;
        border-radius: 10px; font-size: 11px; margin-right: 5px;
    }
</style>

<div class="sync-container">

    <div class="sync-header">
        <h1>🔄 مزامنة طلبات Google Forms</h1>
        <p>استيراد الطلبات الجديدة من Google Sheets إلى التطبيق</p>
    </div>

    <div class="sync-card">
        <div class="stats-row">
            <div class="mini-stat">
                <div class="mini-label">📊 إجمالي الطلبات المُزامنة</div>
                <div class="mini-value"><?= number_format($totalSynced) ?></div>
            </div>
            <div class="mini-stat">
                <div class="mini-label">🕒 آخر مزامنة</div>
                <div class="mini-value" style="font-size: 14px;">
                    <?= $lastSync ? date('d/m/Y H:i', strtotime($lastSync)) : '—' ?>
                </div>
            </div>
        </div>

        <div class="sync-info">
            <strong>ℹ️ كيف يعمل هذا؟</strong><br>
            1. عندما يرسل موظف استمارة Google، تُحفظ البيانات تلقائياً في Google Sheet.<br>
            2. اضغط الزر أدناه لاستيراد الطلبات الجديدة إلى التطبيق.<br>
            3. النظام يتعرف تلقائياً على الطلبات المُزامنة سابقاً ويتخطاها.
        </div>

        <div class="sync-info" style="border-right-color: #ff9800; background: #fff8e1;">
            <strong>⚠️ ملاحظة:</strong> الموظفون غير المسجلين في قاعدة البيانات سيتم استيراد طلباتهم مع علامة تحذير.
        </div>

        <form method="POST" id="syncForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <button type="submit" class="btn-sync" id="syncButton">
                🔄 ابدأ المزامنة الآن
            </button>
        </form>

        <div class="loading-spinner" id="loadingSpinner">
            <div class="spinner"></div>
            <p>جاري المزامنة... يرجى الانتظار</p>
        </div>
    </div>

    <?php if ($results): ?>
        <div class="sync-card">
            <h3 style="margin-top: 0; color: #1a73e8;">📊 نتائج المزامنة</h3>

            <div class="results-box">
                <div class="stat-item success">
                    <span class="label">✅ طلبات مُستوردة</span>
                    <span class="value"><?= $results['imported'] ?? 0 ?></span>
                </div>
                <div class="stat-item warning">
                    <span class="label">⏭️ طلبات مُتخطاة (مزامنة مسبقاً)</span>
                    <span class="value"><?= $results['skipped'] ?? 0 ?></span>
                </div>
                <div class="stat-item danger">
                    <span class="label">❌ أخطاء</span>
                    <span class="value"><?= count($results['errors'] ?? []) ?></span>
                </div>
            </div>

            <?php if (!empty($results['details'])): ?>
                <h4 style="margin-top: 20px;">📋 تفاصيل الطلبات المُستوردة:</h4>
                <table class="details-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>الموظف</th>
                            <th>النوع</th>
                            <th>المبلغ</th>
                            <th>رقم الطلب</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results['details'] as $i => $d): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td>
                                    <?= htmlspecialchars($d['name']) ?>
                                    <?php if (!empty($d['unknown'])): ?>
                                        <span class="badge-unknown">⚠️ غير مسجل</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($d['type']) ?></td>
                                <td><?= number_format($d['amount'], 2) ?> دج</td>
                                <td>
                                    <a href="view.php?id=<?= $d['request_id'] ?>" target="_blank">
                                        #<?= $d['request_id'] ?> 👁️
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if (!empty($results['errors'])): ?>
                <div class="error-list">
                    <strong>⚠️ الأخطاء:</strong>
                    <ul>
                        <?php foreach ($results['errors'] as $err): ?>
                            <li><?= htmlspecialchars($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div style="text-align: center; margin-top: 20px;">
        <a href="index.php" class="btn btn-secondary" style="padding: 10px 25px;">
            ⬅️ العودة إلى الطلبات
        </a>
        <a href="google_requests.php" class="btn btn-primary" style="padding: 10px 25px;">
            📋 عرض طلبات Google Forms
        </a>
    </div>
</div>

<script>
document.getElementById('syncForm').addEventListener('submit', function() {
    document.getElementById('syncButton').disabled = true;
    document.getElementById('syncButton').innerHTML = '⏳ جاري المزامنة...';
    document.getElementById('loadingSpinner').classList.add('active');
});
</script>

<?php include '../includes/footer.php'; ?>