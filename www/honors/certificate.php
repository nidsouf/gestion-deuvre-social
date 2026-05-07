<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    exit("غير مصرح");
}
require_once '../config/database.php';

$id = (int)$_GET['id'];
$stmt = $pdo->prepare("
    SELECT h.*, e.name as employee_name
    FROM labor_day_honorees h
    JOIN employees e ON h.employee_id = e.id
    WHERE h.id = ?
");
$stmt->execute([$id]);
$honoree = $stmt->fetch();
if (!$honoree) {
    exit("الموظف غير موجود");
}

// مصفوفة الأشهر العربية
$arabicMonths = [
    1 => 'جانفي', 2 => 'فيفري', 3 => 'مارس', 4 => 'أفريل',
    5 => 'ماي', 6 => 'جوان', 7 => 'جويلية', 8 => 'أوت',
    9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر'
];

$arabicDays = [
    'Monday' => 'الاثنين', 'Tuesday' => 'الثلاثاء', 'Wednesday' => 'الأربعاء',
    'Thursday' => 'الخميس', 'Friday' => 'الجمعة', 'Saturday' => 'السبت',
    'Sunday' => 'الأحد'
];

function formatDateArabic($date, $arabicDays, $arabicMonths) {
    $ts = strtotime($date);
    $dayEn = date('l', $ts);
    $dayAr = $arabicDays[$dayEn] ?? $dayEn;
    $dayNum = date('d', $ts);
    $monthNum = (int)date('m', $ts);
    $monthAr = $arabicMonths[$monthNum] ?? date('F', $ts);
    $year = date('Y', $ts);
    return "$dayAr $dayNum $monthAr $year";
}

// ========== عبارات الشكر والامتنان العشوائية ==========
$appreciationMessages = [
    "نثمن عالياً جهودك المخلصة وتفانيك في العمل، فأنت نموذج يُحتذى به.",
    "بكل فخر نكرم مسيرتك الحافلة بالعطاء والإخلاص، جزيل الشكر لك.",
    "تقديراً لالتزامك واجتهادك، نهديك هذه الشهادة كرمز على امتناننا.",
    "عطاؤك المستمر هو سر تميزنا، فلك منا كل التقدير والاحترام.",
    "بكل معاني الفخر نكرمك، فأنت عنوان النجاح والتفاني.",
    "شكراً لك لأنك جعلت التميز أسلوب حياة، فاستحققت هذا التكريم.",
    "نسبة لما بذلته من جهد وتضحية، نضع بين يديك هذا الإنجاز تقديراً لمجهودك.",
    "عملك الدؤوب وإخلاصك كانا مفتاح النجاح، فلك كل التحية والثناء.",
    "تفانيك في عملك جعل الفرق واضحاً، نحن ممتنون لك بكل صراحة.",
    "لأنك أثبت أن التفاني والعمل الجاد يصنعان الإنجاز، نكرمك بكل فخر."
];

// اختيار عبارة عشوائية
$randomMessage = $appreciationMessages[array_rand($appreciationMessages)];
?>

<!DOCTYPE html>
<html dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>شهادة تقدير - <?= htmlspecialchars($honoree['employee_name']) ?></title>
    <style>
        @page { size: A4 landscape; margin: 2cm; }
        body { font-family: 'Traditional Arabic', 'Segoe UI', serif; direction: rtl; text-align: center; background: #fff; padding: 20px; }
        .certificate { border: 5px double #2a5298; padding: 40px; margin: 20px; }
        h1 { color: #2a5298; font-size: 28pt; margin-bottom: 20px; }
        h2 { font-size: 24pt; margin: 20px 0; }
        .employee-name { font-size: 32pt; font-weight: bold; color: #e65100; margin: 30px 0; }
        .appreciation { font-size: 18pt; font-style: italic; color: #2a5298; margin: 20px 0; background: #f9f9f9; padding: 15px; border-radius: 20px; }
        .reason { font-size: 16pt; margin: 20px 0; color: #555; }
        .footer { margin-top: 40px; display: flex; justify-content: space-between; }
        .signature { border-top: 1px solid #000; width: 200px; text-align: center; }
        .date { font-size: 14pt; margin-top: 20px; }
    </style>
</head>
<body>
<div class="certificate">
    <h1>🎖️ شهادة تقدير 🎖️</h1>
    <h2>بمناسبة عيد العمال</h2>
    <div class="employee-name"><?= htmlspecialchars($honoree['employee_name']) ?></div>
    
    <!-- عرض العبارة العشوائية في مكان مميز -->
    <div class="appreciation">
        "<?= $randomMessage ?>"
    </div>
    
    <div class="reason"><?= htmlspecialchars($honoree['reason'] ?: "تقديراً لجهوده المتميزة وعطائه المستمر") ?></div>
    <div class="details">
        <p>السنة: <?= $honoree['year'] ?></p>
        <p>تاريخ التكريم: <?= formatDateArabic($honoree['honor_date'], $arabicDays, $arabicMonths) ?></p>
    </div>
    <div class="footer">
        <div class="signature">رئيس اللجنة<br>نيد شوقي</div>
        <div class="signature">المدير<br>(..........)</div>
    </div>
    <div class="date">كوينين-الوادي، في <?= formatDateArabic($honoree['honor_date'], $arabicDays, $arabicMonths) ?></div>
</div>
<script>
    window.onload = function() {
        window.print();
    };
</script>
</body>
</html>