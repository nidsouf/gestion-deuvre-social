<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    exit('غير مصرح');
}
require_once '../config/database.php';

$employee_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$employee_id) {
    exit('معرف الموظف مطلوب');
}

// جلب بيانات الموظف وعدد الأوراق (يحسب من تاريخ التوظيف)
$stmt = $pdo->prepare("SELECT id, name, hire_date FROM employees WHERE id = ?");
$stmt->execute([$employee_id]);
$emp = $stmt->fetch();
if (!$emp) exit('موظف غير موجود');

function getTickets($hire_date) {
    if (empty($hire_date)) return 0;
    $today = new DateTime();
    $hire = new DateTime($hire_date);
    $diff = $hire->diff($today);
    $total_months = ($diff->y * 12) + $diff->m;
    $full = floor($total_months / 36);
    $rem = $total_months % 36;
    $extra = ($rem >= 18) ? 1 : 0;
    return $full + $extra;
}
$count = getTickets($emp['hire_date']);
if ($count == 0) exit('لا توجد أوراق لهذا الموظف');

// عنوان السحب (اختياري)
$draw_title = isset($_GET['draw_title']) ? htmlspecialchars($_GET['draw_title']) : 'سحب العمرة';

?>
<!DOCTYPE html>
<html dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>قصاصات - <?= htmlspecialchars($emp['name']) ?></title>
    <style>
        @page {
            size: A4;
            margin: 1cm;
        }
        body {
            font-family: 'Tahoma', 'Segoe UI', sans-serif;
            direction: rtl;
            background: white;
            margin: 0;
            padding: 0;
        }
        .page {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-around;
            gap: 20px;
        }
        .ticket {
            border: 1px solid #000;
            width: 180px;
            height: 250px;
            margin: 10px;
            padding: 10px;
            text-align: center;
            page-break-inside: avoid;
            background: #fff;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            display: inline-block;
            vertical-align: top;
        }
        .ticket-header {
            font-weight: bold;
            border-bottom: 1px dashed #ccc;
            padding-bottom: 5px;
            margin-bottom: 10px;
            font-size: 14px;
        }
        .ticket-body {
            font-size: 16px;
            margin: 20px 0;
        }
        .ticket-footer {
            font-size: 10px;
            color: #555;
            margin-top: 15px;
            border-top: 1px dashed #ccc;
            padding-top: 5px;
        }
        .ticket-number {
            font-size: 22px;
            font-weight: bold;
            margin: 10px 0;
        }
        @media print {
            body {
                margin: 0;
                padding: 0;
            }
            .no-print {
                display: none;
            }
            .ticket {
                break-inside: avoid;
                page-break-inside: avoid;
            }
        }
        .no-print {
            text-align: center;
            margin-bottom: 20px;
        }
        button {
            padding: 8px 16px;
            background: #2a5298;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }
    </style>
</head>
<body>
<div class="no-print">
    <h3>طباعة <?= $count ?> قصاصة للموظف: <?= htmlspecialchars($emp['name']) ?></h3>
    <button onclick="window.print();">🖨️ طباعة</button>
    <button onclick="window.close();">إغلاق</button>
</div>
<div class="page">
    <?php for ($i = 1; $i <= $count; $i++): ?>
        <div class="ticket">
            <div class="ticket-header"><?= htmlspecialchars($draw_title) ?></div>
            <div class="ticket-body">
                <div>اسم الموظف:</div>
                <strong><?= htmlspecialchars($emp['name']) ?></strong>
                <div class="ticket-number">رقم القصاصة: <?= $i ?></div>
                <div>رقم الموظف: <?= $emp['id'] ?></div>
            </div>
            <div class="ticket-footer">
                لجنة الخدمات الاجتماعية<br>
                تاريخ: <?= date('d/m/Y') ?>
            </div>
        </div>
    <?php endfor; ?>
</div>
</body>
</html>