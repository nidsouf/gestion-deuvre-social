<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

require_once '../config/database.php';
require_once '../includes/functions.php';

$id = $_GET['id'] ?? 0;

$stmt = $pdo->prepare("
    SELECT 
        d.*,
        e.name as employee_name,
        e.category,
        s.name as source_name
    FROM deductions d
    JOIN employees e ON d.employee_id = e.id
    JOIN sources s ON d.source_id = s.id
    WHERE d.id = ?
");
$stmt->execute([$id]);
$deduction = $stmt->fetch();

if (!$deduction) {
    die("الاقتطاع غير موجود");
}

$total_amount = $deduction['monthly_amount'] * $deduction['total_months'];
$remaining_months = 0;
$remaining_amount = 0;

if ($deduction['end_date'] >= date('Y-m-d')) {
    $start = new DateTime($deduction['start_date']);
    $end = new DateTime($deduction['end_date']);
    $now = new DateTime();
    $total_months = $start->diff($end)->m + 1;
    $elapsed_months = $start->diff($now)->m;
    $remaining_months = max(0, $total_months - $elapsed_months);
    $remaining_amount = $remaining_months * $deduction['monthly_amount'];
}
?>
<!DOCTYPE html>
<html dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>إيصال اقتطاع - <?= htmlspecialchars($deduction['employee_name']) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Tajawal', 'Segoe UI', Arial, sans-serif;
            background: #e9ecef;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }
        .receipt {
            max-width: 800px;
            width: 100%;
            background: white;
            border-radius: 20px;
            box-shadow: 0 15px 40px rgba(0,0,0,0.1);
            overflow: hidden;
            direction: rtl;
        }
        .receipt-header {
            background: linear-gradient(135deg, #1a3a2a, #2e7d32);
            color: white;
            padding: 25px;
            text-align: center;
        }
        .receipt-header h2 { font-size: 22px; margin-bottom: 5px; }
        .receipt-header p { font-size: 14px; opacity: 0.9; }
        .receipt-body { padding: 30px; }
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px dashed #ddd;
        }
        .info-label { font-weight: bold; color: #555; width: 40%; }
        .info-value { color: #333; width: 60%; text-align: left; }
        .amount-box {
            background: #e8f5e9;
            border-radius: 16px;
            padding: 20px;
            margin: 20px 0;
            text-align: center;
        }
        .amount-box .total { font-size: 28px; font-weight: bold; color: #2e7d32; }
        .status-badge {
            display: inline-block;
            padding: 5px 15px;
            border-radius: 30px;
            font-size: 13px;
            font-weight: bold;
        }
        .status-active { background: #d4edda; color: #155724; }
        .status-expired { background: #f8d7da; color: #721c24; }
        .status-expiring { background: #fff3cd; color: #856404; }
        .receipt-footer {
            background: #f8f9fa;
            padding: 20px;
            text-align: center;
            font-size: 12px;
            color: #888;
            border-top: 1px solid #eee;
        }
        .print-btn {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-top: 20px;
            padding: 0 30px 30px;
        }
        button {
            padding: 12px 25px;
            border: none;
            border-radius: 40px;
            font-weight: bold;
            cursor: pointer;
            font-size: 14px;
            transition: 0.3s;
        }
        .btn-print { background: #2a5298; color: white; }
        .btn-print:hover { background: #1e3c72; transform: scale(1.02); }
        .btn-back { background: #6c757d; color: white; }
        @media print {
            body { background: white; padding: 0; margin: 0; }
            .print-btn { display: none; }
            .receipt { box-shadow: none; border-radius: 0; }
            .status-badge { print-color-adjust: exact; }
        }
    </style>
</head>
<body>
<div class="receipt">
    <div class="receipt-header">
        <h2>مركز التكوين والتعليم المهنيين</h2>
        <p>الشهيد علي بوسحابة - بكوينين</p>
        <h3 style="margin-top: 10px;">لجنة الخدمات الاجتماعية</h3>
        <p>إيصال اقتطاع شهري</p>
    </div>
    <div class="receipt-body">
        <div class="info-row"><span class="info-label">📌 رقم الإيصال:</span><span class="info-value"><?= str_pad($deduction['id'], 6, '0', STR_PAD_LEFT) ?></span></div>
        <div class="info-row"><span class="info-label">👤 الموظف:</span><span class="info-value"><?= htmlspecialchars($deduction['employee_name']) ?></span></div>
        <div class="info-row"><span class="info-label">📁 التصنيف:</span><span class="info-value"><?= $deduction['category'] == 'Permanent' ? 'دائم' : 'متعاقد' ?></span></div>
        <div class="info-row"><span class="info-label">📂 المصدر:</span><span class="info-value"><?= htmlspecialchars($deduction['source_name']) ?></span></div>
        <div class="info-row"><span class="info-label">💰 المبلغ الشهري:</span><span class="info-value"><?= number_format($deduction['monthly_amount'], 2) ?> دج</span></div>
        <div class="info-row"><span class="info-label">📊 عدد الأشهر:</span><span class="info-value"><?= $deduction['total_months'] ?> شهر</span></div>
        <div class="info-row"><span class="info-label">📅 تاريخ البداية:</span><span class="info-value"><?= date('d/m/Y', strtotime($deduction['start_date'])) ?></span></div>
        <div class="info-row"><span class="info-label">📅 تاريخ النهاية:</span><span class="info-value"><?= date('d/m/Y', strtotime($deduction['end_date'])) ?></span></div>
        <div class="info-row"><span class="info-label">🔔 الحالة:</span><span class="info-value"><?php $today = date('Y-m-d'); if ($deduction['end_date'] < $today) { echo '<span class="status-badge status-expired">⚠️ منتهي</span>'; } elseif ($deduction['end_date'] < date('Y-m-d', strtotime('+30 days'))) { echo '<span class="status-badge status-expiring">⏰ ينتهي قريباً</span>'; } else { echo '<span class="status-badge status-active">✅ نشط</span>'; } ?></span></div>
        <div class="amount-box"><div>💵 إجمالي المبلغ المقتطع</div><div class="total"><?= number_format($total_amount, 2) ?> دج</div><?php if ($remaining_months > 0): ?><div style="margin-top: 10px; font-size: 13px;">المتبقي: <?= number_format($remaining_amount, 2) ?> دج (<?= $remaining_months ?> شهر)</div><?php endif; ?></div>
        <div style="text-align: center; margin-top: 20px; font-size: 12px; color: #777;">هذا الإيصال يثبت أن الموظف المذكور ملتزم بدفع المبلغ المحدد شهرياً<br>حتى تاريخ الانتهاء الموضح أعلاه.</div>
    </div>
    <div class="receipt-footer"><div>تاريخ الطباعة: <?= date('d/m/Y H:i') ?></div><div>نظام إدارة الاقتطاعات - لجنة الخدمات الاجتماعية</div></div>
</div>
<div class="print-btn"><button class="btn-print" onclick="window.print()">🖨️ طباعة الإيصال</button><button class="btn-back" onclick="window.location.href='list.php'">🔙 العودة إلى القائمة</button></div>
</body>
</html>