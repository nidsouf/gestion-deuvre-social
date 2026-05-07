<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../login.php"); exit; }
require_once '../config/database.php';

$draw_event_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$draw_event_id) { header("Location: draw_list.php"); exit; }

// جلب جميع المشاركين
$stmt = $pdo->prepare("
    SELECT ud.id as draw_id, ud.employee_id, ud.tickets_count, e.name as employee_name
    FROM umrah_draws ud
    JOIN employees e ON ud.employee_id = e.id
    WHERE ud.draw_event_id = ?
");
$stmt->execute([$draw_event_id]);
$allParticipants = $stmt->fetchAll();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $winner_id = isset($_POST['winner']) ? (int)$_POST['winner'] : 0;
    $reserve_orders = isset($_POST['reserve_order']) ? $_POST['reserve_order'] : [];

    if ($winner_id == 0) {
        $error = "⚠️ يرجى اختيار الفائز الرئيسي.";
    } else {
        // إعادة تعيين جميع المكافآت
        $pdo->prepare("UPDATE umrah_draws SET is_winner = 0, reserve_order = NULL WHERE draw_event_id = ?")->execute([$draw_event_id]);

        // تحديث الفائز
        $pdo->prepare("UPDATE umrah_draws SET is_winner = 1 WHERE draw_event_id = ? AND employee_id = ?")->execute([$draw_event_id, $winner_id]);

        // تحديث الاحتياطيين
        $order = 1;
        // نحتاج إلى ترتيب الاحتياطيين بحسب الرقم المدخل (ولكن الإدخال من النموذج يعطي ترتيباً حسب معرف الموظف)
        // الطريقة: نجمع الأزواج (employee_id => reserve_number) ثم نفرزها حسب reserve_number
        $mapping = [];
        foreach ($reserve_orders as $emp_id => $num) {
            $emp_id = (int)$emp_id;
            $num = (int)$num;
            if ($num > 0 && $emp_id != $winner_id) {
                $mapping[$emp_id] = $num;
            }
        }
        // فرز حسب رقم الاحتياطي
        asort($mapping);
        $current_order = 1;
        foreach ($mapping as $emp_id => $num) {
            $pdo->prepare("UPDATE umrah_draws SET reserve_order = ? WHERE draw_event_id = ? AND employee_id = ?")
                ->execute([$current_order, $draw_event_id, $emp_id]);
            $current_order++;
        }

        // تحديث حالة السحب إلى مكتمل
        $pdo->prepare("UPDATE umrah_draw_events SET status = 'completed', winner_id = ? WHERE id = ?")->execute([$winner_id, $draw_event_id]);

        $success = "✅ تم حفظ النتائج بنجاح! سيتم نقلك إلى صفحة النتائج.";
        header("refresh:2;url=view_draw.php?id=$draw_event_id");
        // لا نستخدم exit هنا ليعرض الرسالة قبل التوجيه
    }
}

include '../includes/header.php';
?>

<!DOCTYPE html>
<html dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>تحديد النتائج يدوياً</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; direction: rtl; background: #f0f2f5; margin: 20px; }
        .container { max-width: 800px; margin: auto; background: white; padding: 20px; border-radius: 16px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        select, input, button { width: 100%; padding: 8px; margin: 10px 0; border-radius: 8px; border: 1px solid #ccc; }
        button { background: #2a5298; color: white; cursor: pointer; }
        h2 { text-align: center; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: center; vertical-align: middle; }
        th { background: #2a5298; color: white; }
        .reserve-input { width: 80px; text-align: center; }
        .success { background: #d4edda; color: #155724; padding: 10px; border-radius: 8px; margin-bottom: 15px; }
        .error { background: #f8d7da; color: #721c24; padding: 10px; border-radius: 8px; margin-bottom: 15px; }
    </style>
</head>
<body>
<div class="container">
    <h2>🎲 تحديد نتيجة القرعة يدوياً</h2>
    <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <?php if (empty($allParticipants)): ?>
        <div class="error">⚠️ لا يوجد مشاركون في هذا السحب.</div>
        <a href="draw_list.php">العودة للقائمة</a>
    <?php else: ?>
        <form method="POST">
            <label><strong>🏆 اختر الفائز الرئيسي:</strong></label>
            <select name="winner" required>
                <option value="">-- اختر --</option>
                <?php foreach ($allParticipants as $p): ?>
                    <option value="<?= $p['employee_id'] ?>"><?= htmlspecialchars($p['employee_name']) ?> (<?= $p['tickets_count'] ?> ورقة)</option>
                <?php endforeach; ?>
            </select>

            <h3>ترتيب الاحتياطيين</h3>
            <p style="font-size:13px; color:#666;">قم بإدخال رقم الاحتياطي لكل موظف (1 للأول، 2 للثاني، إلخ). الفائز سيتم استبعاده تلقائياً.</p>
            <table>
                <thead><tr><th>الموظف</th><th>عدد الأوراق</th><th>رقم الاحتياطي</th></tr></thead>
                <tbody>
                    <?php foreach ($allParticipants as $p): ?>
                        <tr data-emp-id="<?= $p['employee_id'] ?>">
                            <td><?= htmlspecialchars($p['employee_name']) ?></td>
                            <td><?= $p['tickets_count'] ?></td>
                            <td>
                                <input type="number" name="reserve_order[<?= $p['employee_id'] ?>]" class="reserve-input" min="0" value="0" style="width:80px;">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <button type="submit" style="margin-top:20px;">💾 حفظ النتائج</button>
            <a href="draw_list.php" style="display:block; text-align:center; margin-top:10px;">إلغاء</a>
        </form>
    <?php endif; ?>
</div>
<script>
    // بسيط: تعطيل حقل الفائز المدخل
    document.querySelector('select[name="winner"]').addEventListener('change', function() {
        let winnerId = this.value;
        let inputs = document.querySelectorAll('.reserve-input');
        inputs.forEach(input => {
            let row = input.closest('tr');
            let empId = row.getAttribute('data-emp-id');
            if (empId == winnerId) {
                input.disabled = true;
                input.value = 0;
            } else {
                input.disabled = false;
            }
        });
    });
</script>
<?php include '../includes/footer.php'; ?>