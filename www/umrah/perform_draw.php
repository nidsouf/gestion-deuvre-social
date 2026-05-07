<?php
ob_start(); // يمنع مشكلة الرؤوس (headers)
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
require_once '../config/database.php';

// ========== معالجة القرعة (قبل أي مخرجات أو تضمين header.php) ==========
$draw_event_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$draw_event_id) {
    header("Location: draw_list.php");
    exit;
}

// جلب المشاركين
$candidates = $pdo->prepare("
    SELECT d.id as draw_id, d.employee_id, d.tickets_count, e.name as employee_name
    FROM umrah_draws d
    JOIN employees e ON d.employee_id = e.id
    WHERE d.draw_event_id = ? AND d.is_winner = 0 AND d.reserve_order IS NULL
");
$candidates->execute([$draw_event_id]);
$candidates = $candidates->fetchAll();

if (empty($candidates)) {
    // لا يوجد مشاركون أو تمت القرعة مسبقاً
    header("Location: draw_list.php");
    exit;
}

// دالة سحب عشوائي مرجح
function weightedRandom($candidates) {
    $total = array_sum(array_column($candidates, 'tickets_count'));
    $rand = mt_rand(1, $total);
    $cumulative = 0;
    foreach ($candidates as $candidate) {
        $cumulative += $candidate['tickets_count'];
        if ($rand <= $cumulative) {
            return $candidate;
        }
    }
    return $candidates[0];
}

// اختيار الفائز الرئيسي
$winner = weightedRandom($candidates);
$winner_id = $winner['employee_id'];
$winner_draw_id = $winner['draw_id'];

// إزالة الفائز من قائمة المرشحين للاحتياطيين
$remaining = array_filter($candidates, function($c) use ($winner_id) {
    return $c['employee_id'] != $winner_id;
});
$remaining = array_values($remaining);

// تعيين الاحتياطيين بشكل عشوائي (إعادة ترتيب)
shuffle($remaining);
$reserve_order = 1;
foreach ($remaining as $res) {
    $pdo->prepare("UPDATE umrah_draws SET reserve_order = ? WHERE id = ?")
        ->execute([$reserve_order, $res['draw_id']]);
    $reserve_order++;
}

// تحديث الفائز
$pdo->prepare("UPDATE umrah_draws SET is_winner = 1 WHERE id = ?")->execute([$winner_draw_id]);
$pdo->prepare("UPDATE umrah_draw_events SET status = 'completed', winner_id = ? WHERE id = ?")
    ->execute([$winner_id, $draw_event_id]);

// إعادة التوجيه إلى صفحة عرض النتائج
header("Location: view_draw.php?id=$draw_event_id");
exit;
?>