<?php
/**
 * deductions/print.php - طباعة وصل اقتطاع الموظف
 */
ob_start();
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/security.php';
require_once '../includes/functions.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    header('Location: list.php');
    exit;
}

// جلب بيانات الاقتطاع
$stmt = $pdo->prepare("
    SELECT d.*, e.name as employee_name, e.account_number, e.category as contract_type,
           e.hire_date, s.name as source_name
    FROM deductions d
    JOIN employees e ON d.employee_id = e.id
    LEFT JOIN sources s ON d.source_id = s.id
    WHERE d.id = ?
");
$stmt->execute([$id]);
$deduction = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$deduction) {
    header('Location: list.php?error=notfound');
    exit;
}

// ============================================================
// ✅ حل المشكلة 3: تحديد نوع العقد بشكل مرن
// ============================================================
$contractRaw = trim($deduction['contract_type'] ?? '');
$contractLower = strtolower($contractRaw);

// قائمة القيم التي تعني "دائم"
$permanentValues = ['permanent', 'cdi', 'دائم', 'داﺋﻢ', 'دائمـة', 'titular', 'titulaire'];

$isPermanent = false;
foreach ($permanentValues as $val) {
    if ($contractLower === strtolower($val) || mb_strpos($contractRaw, $val) !== false) {
        $isPermanent = true;
        break;
    }
}

$contractLabel = $isPermanent ? 'دائم' : 'متعاقد';

// ============================================================
// ✅ حل المشكلة 2: رقم تسلسلي
// ============================================================
$serialNumber = str_pad($id, 4, '0', STR_PAD_LEFT) . '/' . date('Y', strtotime($deduction['created_at'] ?? 'now'));

// جلب الأقساط (لحساب الإجماليات فقط)
$stmt = $pdo->prepare("
    SELECT * FROM monthly_installments
    WHERE deduction_id = ?
    ORDER BY year ASC, month ASC
");
$stmt->execute([$id]);
$installments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// حساب المبالغ
$totalAmount = 0;
$paidAmount = 0;
$remainingAmount = 0;
$paidCount = 0;

foreach ($installments as $inst) {
    $amount = (float)$inst['amount'];
    $totalAmount += $amount;
    if ($inst['is_paid'] == 1) {
        $paidAmount += $amount;
        $paidCount++;
    } else {
        $remainingAmount += $amount;
    }
}

// المبلغ بالحروف
$totalWords = numberToWords($totalAmount);
$remainingWords = numberToWords($remainingAmount);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>وصل اقتطاع رقم <?= $serialNumber ?> - <?= htmlspecialchars($deduction['employee_name']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        html, body {
            font-family: 'Cairo', 'Traditional Arabic', 'Segoe UI', Tahoma, sans-serif;
            background: #f0f2f5;
            color: #1a1a2e;
            font-size: 12px;
            line-height: 1.45;
        }
        
        body {
            padding: 15px;
        }
        
        /* ============================================================
           ✅ حل المشكلة 1: استخدام max-width بدل width ثابت
        ============================================================ */
        .print-container {
            width: 100%;
            max-width: 794px;          /* عرض A4 عند 96dpi */
            margin: 0 auto;
            background: white;
            padding: 15mm 12mm;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            border-radius: 6px;
            overflow: hidden;           /* منع تجاوز المحتوى */
        }
        
        /* ============== الرأس ============== */
        .header {
            text-align: center;
            border-bottom: 1.5px solid #1E5A4A;
            padding-bottom: 6px;
            margin-bottom: 10px;
        }
        
        .header .country { font-size: 12px; font-weight: 700; }
        .header .ministry { font-size: 11px; font-weight: 600; margin-top: 1px; }
        .header .center { font-size: 10.5px; color: #555; margin-top: 1px; }
        
        .header .committee {
            font-size: 12px;
            font-weight: 800;
            color: #1E5A4A;
            margin-top: 4px;
            padding: 3px 0;
            border-top: 1px dashed #ccc;
            border-bottom: 1px dashed #ccc;
        }
        
        /* ============== شريط الرقم التسلسلي ============== */
        .serial-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 8px 0;
            padding: 5px 12px;
            background: linear-gradient(135deg, #e3f2fd, #bbdefb);
            border: 1px solid #2196f3;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
            color: #1565c0;
        }
        
        .serial-bar .label { font-size: 10.5px; color: #1976d2; }
        .serial-bar .value { font-size: 12px; color: #0d47a1; letter-spacing: 0.5px; }
        
        .doc-title {
            text-align: center;
            font-size: 15px;
            font-weight: 800;
            color: #1E5A4A;
            margin: 10px 0;
            padding: 6px;
            background: linear-gradient(135deg, #f0f7f4, #e0efe8);
            border-radius: 6px;
            border: 1px solid #1E5A4A;
        }
        
        /* ============== معلومات الموظف ============== */
        .employee-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 4px 18px;
            padding: 8px 12px;
            background: #f8f9fa;
            border-radius: 6px;
            border-right: 3px solid #1E5A4A;
            margin-bottom: 10px;
        }
        
        .employee-info .info-item {
            display: flex;
            justify-content: space-between;
            padding: 2px 0;
            border-bottom: 1px dotted #ddd;
            font-size: 11px;
        }
        
        .employee-info .info-item:last-child { border-bottom: none; }
        
        .employee-info .label {
            font-weight: 700;
            color: #4a5568;
            font-size: 10.5px;
        }
        
        .employee-info .value {
            font-weight: 600;
            color: #1a1a2e;
            font-size: 11px;
        }
        
        /* ============== جدول التفاصيل ============== */
        .details-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }
        
        .details-table th {
            background: linear-gradient(135deg, #1E5A4A, #2E7D64);
            color: white;
            padding: 5px 8px;
            font-weight: 700;
            font-size: 11px;
            text-align: right;
            border: 1px solid #1E5A4A;
        }
        
        .details-table td {
            padding: 4px 8px;
            border: 1px solid #d0d7de;
            font-size: 11px;
        }
        
        .details-table tr:nth-child(even) td { background: #fafbfc; }
        
        .details-table .amount-cell { font-weight: 700; color: #1E5A4A; }
        .details-table .amount-cell.danger { color: #c0392b; }
        
        /* ============== صناديق الملخص ============== */
        .summary-boxes {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 8px;
            margin: 10px 0;
        }
        
        .summary-box {
            padding: 6px 8px;
            border-radius: 6px;
            text-align: center;
            border: 1px solid #ddd;
        }
        
        .summary-box.total { background: #e3f2fd; border-color: #2196f3; }
        .summary-box.paid { background: #e8f5e9; border-color: #4caf50; }
        .summary-box.remaining { background: #fff3e0; border-color: #ff9800; }
        
        .summary-box .box-label {
            font-size: 9.5px;
            font-weight: 700;
            color: #555;
            margin-bottom: 2px;
        }
        
        .summary-box .box-value { font-size: 13px; font-weight: 800; }
        .summary-box.total .box-value { color: #1565c0; }
        .summary-box.paid .box-value { color: #2e7d32; }
        .summary-box.remaining .box-value { color: #e65100; }
        
        /* ============== صندوق المبلغ بالحروف ============== */
        .words-box {
            background: #fff8e1;
            border: 1px dashed #ffb300;
            border-radius: 6px;
            padding: 8px 12px;
            margin: 10px 0;
            font-size: 10.5px;
            color: #5d4037;
            line-height: 1.6;
        }
        
        .words-box strong { color: #e65100; font-size: 11px; }
        
        .section-title {
            font-size: 12px;
            font-weight: 800;
            color: #1E5A4A;
            margin: 10px 0 6px 0;
            padding-right: 8px;
            border-right: 3px solid #1E5A4A;
        }
        
        /* ============== التوقيع ============== */
        .signatures {
            display: flex;
            justify-content: flex-start;
            direction: ltr;
            margin-top: 25px;
            padding-top: 10px;
            border-top: 1px dashed #ccc;
        }
        
        .signature-item {
            text-align: left;
            padding-left: 20px;
        }
        
        .signature-item .sig-label {
            font-weight: 700;
            color: #1a1a2e;
            font-size: 12px;
            margin-bottom: 30px;
        }
        
        .signature-item .sig-line {
            border-bottom: 1.5px solid #333;
            width: 150px;
            margin: 0;
        }
        
        /* ============== تذييل ============== */
        .footer-note {
            text-align: center;
            font-size: 9px;
            color: #888;
            margin-top: 15px;
            padding-top: 6px;
            border-top: 1px solid #eee;
        }
        
        /* ============== أزرار الطباعة ============== */
        .print-actions {
            text-align: center;
            margin: 15px auto;
            padding: 10px;
            background: white;
            border-radius: 10px;
            max-width: 794px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }
        
        .print-actions .btn {
            padding: 8px 20px;
            border-radius: 25px;
            font-weight: 700;
            font-size: 13px;
            border: none;
            cursor: pointer;
            margin: 0 5px;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-print {
            background: linear-gradient(135deg, #1E5A4A, #2E7D64);
            color: white;
        }
        
        .btn-print:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(30, 90, 74, 0.3);
            color: white;
        }
        
        .btn-back { background: #6c757d; color: white; }
        .btn-back:hover { background: #5a6268; color: white; }
        
        /* ============================================================
           إعدادات الطباعة
        ============================================================ */
        @media print {
            @page {
                size: A4;
                margin: 10mm 8mm;
            }
            
            html, body {
                background: white !important;
                padding: 0 !important;
                margin: 0 !important;
                font-size: 10.5px;
                width: auto !important;
                height: auto !important;
            }
            
            .print-container {
                width: 100% !important;
                max-width: 100% !important;
                min-height: auto !important;
                box-shadow: none !important;
                border-radius: 0 !important;
                padding: 0 !important;
                margin: 0 !important;
                overflow: visible !important;
            }
            
            .print-actions { display: none !important; }
            
            .header { border-bottom: 1.5px solid #000; }
            
            .doc-title {
                background: #f0f0f0 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .details-table th {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
                background: #e0e0e0 !important;
                color: #000 !important;
                border: 1px solid #000;
            }
            
            .employee-info,
            .summary-box,
            .words-box,
            .serial-bar {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            /* منع قطع العناصر الهامة */
            .summary-boxes,
            .words-box,
            .signatures {
                page-break-inside: avoid;
            }
            
            .details-table tr {
                page-break-inside: avoid;
            }
        }
    </style>
</head>
<body>

<!-- أزرار التحكم (لا تظهر عند الطباعة) -->
<div class="print-actions">
    <button onclick="window.print()" class="btn btn-print">🖨️ طباعة الوصل</button>
    <a href="view.php?id=<?= $id ?>" class="btn btn-back">⬅️ العودة للتفاصيل</a>
</div>

<!-- محتوى الوصل -->
<div class="print-container">
    
    <!-- ============== الرأس ============== -->
    <div class="header">
        <div class="country">الجمهورية الجزائرية الديمقراطية الشعبية</div>
        <div class="ministry">وزارة التكوين والتعليم المهنيين</div>
        <div class="center">مركز التكوين المهني والتمهين - كوينين</div>
        <div class="committee">لجنة الخدمات الاجتماعية</div>
    </div>
    
    <!-- ============== الرقم التسلسلي ============== -->
    <div class="serial-bar">
        <span class="label">📄 رقم الوصل:</span>
        <span class="value"><?= htmlspecialchars($serialNumber) ?></span>
        <span class="label">📅 تاريخ الإصدار:</span>
        <span class="value"><?= date('d/m/Y') ?></span>
    </div>
    
    <div class="doc-title">📋 وصل اقتطاع من الراتب</div>
    
    <!-- ============== معلومات الموظف ============== -->
    <div class="employee-info">
        <div class="info-item">
            <span class="label">👤 الاسم واللقب:</span>
            <span class="value"><?= htmlspecialchars($deduction['employee_name']) ?></span>
        </div>
        <div class="info-item">
            <span class="label">🔢 رقم الحساب:</span>
            <span class="value"><?= htmlspecialchars($deduction['account_number'] ?? '—') ?></span>
        </div>
        <div class="info-item">
            <span class="label">📄 نوع العقد:</span>
            <span class="value"><?= htmlspecialchars($contractLabel) ?></span>
        </div>
        <div class="info-item">
            <span class="label">📅 تاريخ التعيين:</span>
            <span class="value"><?= safeFormatDate($deduction['hire_date']) ?></span>
        </div>
        <div class="info-item">
            <span class="label">🏦 المصدر:</span>
            <span class="value"><?= htmlspecialchars($deduction['source_name'] ?? '—') ?></span>
        </div>
        <div class="info-item">
            <span class="label">📌 نوع الاقتطاع:</span>
            <span class="value"><?= $deduction['is_loan'] ? 'سلفة' : 'اقتطاع شهري' ?></span>
        </div>
    </div>
    
    <!-- ============== صناديق الملخص ============== -->
    <div class="summary-boxes">
        <div class="summary-box total">
            <div class="box-label">💵 المبلغ الإجمالي</div>
            <div class="box-value"><?= number_format($totalAmount, 2) ?> دج</div>
        </div>
        <div class="summary-box paid">
            <div class="box-label">✅ المسدد</div>
            <div class="box-value"><?= number_format($paidAmount, 2) ?> دج</div>
        </div>
        <div class="summary-box remaining">
            <div class="box-label">⏳ المتبقي</div>
            <div class="box-value"><?= number_format($remainingAmount, 2) ?> دج</div>
        </div>
    </div>
    
    <!-- ============== تفاصيل الاقتطاع ============== -->
    <div class="section-title">📊 تفاصيل الاقتطاع</div>
    <table class="details-table">
        <tr>
            <th style="width: 45%;">البيان</th>
            <th style="width: 55%;">القيمة</th>
        </tr>
        <tr>
            <td>المبلغ الإجمالي للاقتطاع</td>
            <td class="amount-cell"><?= number_format($totalAmount, 2) ?> دج</td>
        </tr>
        <tr>
            <td>القسط الشهري</td>
            <td class="amount-cell"><?= number_format($deduction['monthly_amount'], 2) ?> دج</td>
        </tr>
        <tr>
            <td>عدد الأقساط الكلية</td>
            <td><?= count($installments) ?> قسط</td>
        </tr>
        <tr>
            <td>عدد الأقساط المسددة</td>
            <td style="color: #27ae60; font-weight: 700;"><?= $paidCount ?> قسط</td>
        </tr>
        <tr>
            <td>عدد الأقساط المتبقية</td>
            <td style="color: #c0392b; font-weight: 700;"><?= count($installments) - $paidCount ?> قسط</td>
        </tr>
        <tr>
            <td>تاريخ البداية</td>
            <td><?= safeFormatDate($deduction['start_date']) ?></td>
        </tr>
        <tr>
            <td>تاريخ النهاية</td>
            <td><?= safeFormatDate($deduction['end_date']) ?></td>
        </tr>
        <tr>
            <td>تاريخ الصرف</td>
            <td><?= safeFormatDate($deduction['grant_date']) ?></td>
        </tr>
        <tr>
            <td><strong>المبلغ المتبقي للسداد</strong></td>
            <td class="amount-cell danger"><strong><?= number_format($remainingAmount, 2) ?> دج</strong></td>
        </tr>
    </table>
    
    <!-- ============== المبلغ بالحروف ============== -->
    <div class="words-box">
        <strong>💬 المبلغ الإجمالي بالحروف:</strong>
        <?= htmlspecialchars($totalWords) ?>
        <br>
        <strong>💬 المبلغ المتبقي بالحروف:</strong>
        <?= htmlspecialchars($remainingWords) ?>
    </div>
    
    <!-- ============== التوقيع ============== -->
    <div class="signatures">
        <div class="signature-item">
            <div class="sig-label">رئيس اللجنة</div>
            <div class="sig-line"></div>
            <div style="margin-top: 5px; font-size: 11px; color: #555;">(نيد شوقي)</div>
        </div>
    </div>
    
    <!-- ============== تذييل ============== -->
    <div class="footer-note">
        تم إصدار هذا الوصل بتاريخ <?= date('d/m/Y') ?> على الساعة <?= date('H:i') ?>
        <br>
        نظام إدارة الاقتطاعات والمنح الاجتماعية - لجنة الخدمات الاجتماعية
    </div>
</div>

</body>
</html>
<?php
ob_end_flush();
?>