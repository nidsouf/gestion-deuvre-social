<?php
session_start();
if (!isset($_SESSION['user_id'])) exit();
require_once '../config/database.php';

// دالة حساب الأوراق (مرة واحدة فقط)
function calcTickets($hire_date) {
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

$draw_event_id = isset($_GET['draw_event_id']) ? (int)$_GET['draw_event_id'] : 0;
if ($draw_event_id) {
    // جلب المشاركين من حدث السحب
    $stmt = $pdo->prepare("
        SELECT e.id, e.name, e.hire_date, ud.tickets_count
        FROM umrah_draws ud
        JOIN employees e ON ud.employee_id = e.id
        WHERE ud.draw_event_id = ?
    ");
    $stmt->execute([$draw_event_id]);
    $employees = $stmt->fetchAll();
} else {
    // طباعة بناءً على المعرفات المرسلة
    $ids = isset($_GET['ids']) ? explode(',', $_GET['ids']) : [];
    if (empty($ids)) exit('لا يوجد مختارون');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id, name, hire_date FROM employees WHERE id IN ($in)");
    $stmt->execute($ids);
    $employees = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>طباعة جميع القصاصات</title>
    <style>
        @page { size: A4; margin: 1cm; }
        body { font-family: Tahoma, sans-serif; direction: rtl; background: white; margin: 0; padding: 20px; }
        .ticket { border: 1px solid #000; width: 180px; height: 250px; margin: 10px; padding: 10px; text-align: center; page-break-inside: avoid; display: inline-block; vertical-align: top; }
        .ticket-header { font-weight: bold; border-bottom: 1px dashed #ccc; margin-bottom: 10px; }
        .ticket-number { font-size: 22px; font-weight: bold; margin: 10px 0; }
        @media print { .no-print { display: none; } }
        .no-print { text-align: center; margin-bottom: 20px; }
        button { padding: 8px 16px; background: #2a5298; color: white; border: none; border-radius: 5px; cursor: pointer; }
    </style>
</head>
<body>
<div class="no-print">
    <h3>طباعة قصاصات جميع المترشحين</h3>
    <button onclick="window.print();">🖨️ طباعة</button>
    <button onclick="window.close();">إغلاق</button>
</div>
<div class="page">
    <?php foreach ($employees as $emp):
        $count = isset($emp['tickets_count']) ? $emp['tickets_count'] : calcTickets($emp['hire_date']);
        for ($i = 1; $i <= $count; $i++): ?>
            <div class="ticket">
                <div class="ticket-header">سحب العمرة</div>
                <div class="ticket-body">
                    <strong><?= htmlspecialchars($emp['name']) ?></strong><br>
                    رقم الموظف: <?= $emp['id'] ?><br>
                    القصاصة: <?= $i ?> / <?= $count ?>
                </div>
                <div class="ticket-footer">لجنة الخدمات الاجتماعية</div>
            </div>
        <?php endfor; ?>
    <?php endforeach; ?>
</div>
</body>
</html>