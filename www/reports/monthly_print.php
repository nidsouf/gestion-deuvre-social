<?php
/**
 * reports/monthly_print.php - نسخة الطباعة للتقرير الشهري
 * يتم تضمينه من monthly.php عند تفعيل الطباعة (print=1)
 * يدعم عرض الهواتف بدون أزرار تسديد
 */

if (!isset($year) || !isset($month) || !isset($month_name_ar)) {
    die("⚠️ لا يمكن الوصول到这个 الملف مباشرة.");
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تقرير شهري - <?= $month_name_ar . ' ' . $year ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
            background: white;
            padding: 20px;
        }
        .print-header {
            text-align: center;
            margin-bottom: 25px;
            border-bottom: 2px solid #2a5298;
            padding-bottom: 10px;
        }
        .print-header h2 {
            color: #2a5298;
            margin: 0;
        }
        .print-header h3 {
            margin: 5px 0;
        }
        .print-header p {
            color: #666;
        }
        .section-title {
            font-size: 18px;
            font-weight: bold;
            margin: 20px 0 10px;
            border-right: 4px solid #2a5298;
            padding-right: 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            font-size: 12pt;
        }
        th, td {
            border: 1px solid #999;
            padding: 6px 8px;
            text-align: center;
            vertical-align: middle;
        }
        th {
            background: #2a5298;
            color: white;
        }
        .total-row {
            background: #f0f0f0;
            font-weight: bold;
        }
        .badge-phone {
            background: #6f42c1;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10pt;
            display: inline-block;
        }
        .badge-djezzy {
            background: #6f42c1;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10pt;
            display: inline-block;
        }
        .djezzy-row {
            background: #f8f0ff;
        }
        .status-paid {
            background: #d4edda;
            color: #155724;
            padding: 2px 10px;
            border-radius: 12px;
            display: inline-block;
        }
        .status-active {
            background: #cce5ff;
            color: #004085;
            padding: 2px 10px;
            border-radius: 12px;
            display: inline-block;
        }
        .status-postponed {
            background: #fff3cd;
            color: #856404;
            padding: 2px 10px;
            border-radius: 12px;
            display: inline-block;
        }
        .grand-total-box {
            margin-top: 20px;
            padding: 12px;
            background: #ff9800;
            border-radius: 8px;
            text-align: center;
            font-weight: bold;
            font-size: 16pt;
        }
        .footer {
            text-align: center;
            margin-top: 30px;
            font-size: 10px;
            color: #666;
            border-top: 1px solid #ccc;
            padding-top: 10px;
        }

        @media print {
            body {
                margin: 0;
                padding: 0;
            }
            .no-print {
                display: none !important;
            }
        }
    </style>
</head>
<body>
    <div class="print-header">
        <h2>مركز التكوين والتعليم المهنيين</h2>
        <h3>الشهيد علي بوسحابة - بكوينين</h3>
        <h4>لجنة الخدمات الاجتماعية</h4>
        <p>التقرير الشهري للاقتطاعات - <?= $month_name_ar . ' ' . $year ?></p>
        <?php if ($show_paid): ?>
            <p style="color:#17a2b8;">(يشمل الأقساط المدفوعة)</p>
        <?php endif; ?>
    </div>

    <!-- الدائمون -->
    <div class="section-title">👔 الموظفون الدائمون</div>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>الموظف</th>
                <th>المصدر</th>
                <th>المبلغ (دج)</th>
                <th>النوع</th>
                <th>الحالة</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($permG)): ?>
                <tr><td colspan="6" style="text-align:center;">لا توجد بيانات</td></tr>
            <?php else: 
                $i = 1;
                foreach ($permG as $it):
                    $amount = $it['total_amount'];
                    $isPhone = ($it['source_name'] == 'هاتف' || $it['type'] == 'phone');
                    $typeLabel = $isPhone 
                        ? '<span class="badge-phone">📱 هاتف</span>' 
                        : (($it['source_name'] == 'Djezzy') 
                            ? '<span class="badge-djezzy">📱 جيزي</span>' 
                            : ($it['is_loan'] ? '💰 سلفة' : '📌 اقتطاع'));
                    
                    if ($it['is_paid']) {
                        $statusText = '✅ مدفوع';
                        $statusClass = 'status-paid';
                    } elseif ($it['is_postponed'] ?? 0) {
                        $statusText = '⏰ مؤجل';
                        $statusClass = 'status-postponed';
                    } else {
                        $statusText = '✅ نشط';
                        $statusClass = 'status-active';
                    }
                    
                    $rowClass = ($isPhone || $it['source_name'] == 'Djezzy') ? 'djezzy-row' : ($it['is_paid'] ? 'paid-row' : '');
            ?>
                <tr class="<?= $rowClass ?>">
                    <td><?= $i++ ?></td>
                    <td><?= htmlspecialchars($it['employee_name']) ?></td>
                    <td><?= htmlspecialchars($it['source_name']) ?></td>
                    <td><?= number_format($amount, 2) ?> دج</td>
                    <td><?= $typeLabel ?></td>
                    <td><span class="<?= $statusClass ?>"><?= $statusText ?></span></td>
                </tr>
            <?php endforeach; ?>
            <tr class="total-row">
                <td colspan="3"><strong>الإجمالي</strong></td>
                <td colspan="3"><strong><?= number_format($totalPermanent, 2) ?> دج</strong></td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- المتعاقدون -->
    <div style="page-break-before:always;"></div>
    <div class="section-title">👕 الموظفون المتعاقدون</div>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>الموظف</th>
                <th>المصدر</th>
                <th>المبلغ (دج)</th>
                <th>النوع</th>
                <th>الحالة</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($contG)): ?>
                <tr><td colspan="6" style="text-align:center;">لا توجد بيانات</td></tr>
            <?php else: 
                $i = 1;
                foreach ($contG as $it):
                    $amount = $it['total_amount'];
                    $isPhone = ($it['source_name'] == 'هاتف' || $it['type'] == 'phone');
                    $typeLabel = $isPhone 
                        ? '<span class="badge-phone">📱 هاتف</span>' 
                        : (($it['source_name'] == 'Djezzy') 
                            ? '<span class="badge-djezzy">📱 جيزي</span>' 
                            : ($it['is_loan'] ? '💰 سلفة' : '📌 اقتطاع'));
                    
                    if ($it['is_paid']) {
                        $statusText = '✅ مدفوع';
                        $statusClass = 'status-paid';
                    } elseif ($it['is_postponed'] ?? 0) {
                        $statusText = '⏰ مؤجل';
                        $statusClass = 'status-postponed';
                    } else {
                        $statusText = '✅ نشط';
                        $statusClass = 'status-active';
                    }
                    
                    $rowClass = ($isPhone || $it['source_name'] == 'Djezzy') ? 'djezzy-row' : ($it['is_paid'] ? 'paid-row' : '');
            ?>
                <tr class="<?= $rowClass ?>">
                    <td><?= $i++ ?></td>
                    <td><?= htmlspecialchars($it['employee_name']) ?></td>
                    <td><?= htmlspecialchars($it['source_name']) ?></td>
                    <td><?= number_format($amount, 2) ?> دج</td>
                    <td><?= $typeLabel ?></td>
                    <td><span class="<?= $statusClass ?>"><?= $statusText ?></span></td>
                </tr>
            <?php endforeach; ?>
            <tr class="total-row">
                <td colspan="3"><strong>الإجمالي</strong></td>
                <td colspan="3"><strong><?= number_format($totalContract, 2) ?> دج</strong></td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="grand-total-box">
        💰 الإجمالي العام للشهر: <?= number_format($grandTotal, 2) ?> دج
    </div>

    <div class="footer">
        تم إنشاء التقرير بواسطة نظام إدارة الاقتطاعات بتاريخ <?= date('Y-m-d H:i:s') ?>
        <br>
        <?= date('l d F Y', strtotime('now')) ?>
    </div>

    <script>
        window.onload = function() {
            window.print();
        };
    </script>
</body>
</html>