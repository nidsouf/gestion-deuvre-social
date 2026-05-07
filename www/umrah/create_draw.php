<?php
ob_start();
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
require_once '../config/database.php';

// ========== معالجة POST ==========
$message = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_draw'])) {
    $title = trim($_POST['title']);
    $selected_ids = isset($_POST['selected']) ? $_POST['selected'] : [];

    if (empty($selected_ids)) {
        $message = "⚠️ يجب اختيار موظف واحد على الأقل للمشاركة في القرعة.";
    } else {
        try {
            $pdo->beginTransaction();

            // إنشاء حدث السحب
            $stmt = $pdo->prepare("INSERT INTO umrah_draw_events (draw_date, title, status) VALUES (datetime('now'), ?, 'pending')");
            $stmt->execute([$title]);
            $draw_event_id = $pdo->lastInsertId();

            // جلب عدد الأوراق لكل موظف
            $ticketsMap = [];
            foreach ($selected_ids as $emp_id) {
                $emp = $pdo->prepare("SELECT hire_date FROM employees WHERE id = ?");
                $emp->execute([$emp_id]);
                $hire = $emp->fetchColumn();
                if ($hire) {
                    $today = new DateTime();
                    $hireDate = new DateTime($hire);
                    $diff = $hireDate->diff($today);
                    $total_months = ($diff->y * 12) + $diff->m;
                    $full = floor($total_months / 36);
                    $rem = $total_months % 36;
                    $extra = ($rem >= 18) ? 1 : 0;
                    $tickets = $full + $extra;
                    if ($tickets > 0) {
                        $ticketsMap[$emp_id] = $tickets;
                    }
                }
            }

            // إدراج المشاركين
            $insert = $pdo->prepare("INSERT INTO umrah_draws (draw_event_id, employee_id, draw_date, tickets_count, is_winner, reserve_order) VALUES (?, ?, date('now'), ?, 0, NULL)");
            foreach ($ticketsMap as $eid => $tick) {
                $insert->execute([$draw_event_id, $eid, $tick]);
            }

            $pdo->commit();
            header("Location: draw_list.php");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "❌ خطأ: " . $e->getMessage();
        }
    }
}

// ========== جلب الموظفين للعرض ==========
$employees = [];
$emps = $pdo->query("SELECT id, name, hire_date FROM employees ORDER BY name")->fetchAll();
foreach ($emps as $e) {
    $tickets = 0;
    if (!empty($e['hire_date'])) {
        $today = new DateTime();
        $hire = new DateTime($e['hire_date']);
        $diff = $hire->diff($today);
        $total_months = ($diff->y * 12) + $diff->m;
        $full = floor($total_months / 36);
        $rem = $total_months % 36;
        $extra = ($rem >= 18) ? 1 : 0;
        $tickets = $full + $extra;
    }
    $employees[] = [
        'id' => $e['id'],
        'name' => $e['name'],
        'hire_date' => $e['hire_date'],
        'tickets' => $tickets
    ];
}
include '../includes/header.php';
?>

<style>
    .container { max-width: 1000px; margin: auto; padding: 20px; }
    .employee-list { background: white; padding: 20px; border-radius: 20px; margin-bottom: 20px; max-height: 600px; overflow-y: auto; }
    .employee-item { display: flex; align-items: center; gap: 10px; padding: 8px; border-bottom: 1px solid #eee; flex-wrap: wrap; }
    .employee-check { width: 30px; }
    .employee-info { flex: 4; }
    .btn-print { background: #17a2b8; color: white; padding: 4px 12px; border-radius: 20px; text-decoration: none; font-size: 12px; margin-right: 10px; }
    .btn-submit { background: #28a745; color: white; padding: 10px 20px; border: none; border-radius: 30px; cursor: pointer; width: 100%; }
    .form-group { margin-bottom: 15px; }
    .form-control { width: 100%; padding: 8px; border-radius: 8px; border: 1px solid #ccc; }
    .print-all { margin: 20px 0; text-align: left; }
    .btn-print-all { background: #ff9800; color: white; padding: 8px 16px; border-radius: 30px; text-decoration: none; }
</style>

<div class="container">
    <h2>➕ إنشاء سحب جديد</h2>
    <?php if ($message): ?>
        <div style="background:#ffe6e5; padding:10px; border-radius:8px;"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <form method="POST" id="drawForm">
        <div class="form-group">
            <label>🏷️ عنوان السحب (اختياري)</label>
            <input type="text" name="title" class="form-control" placeholder="مثال: سحب العمرة لشهر رمضان 2026">
        </div>
        <h3>اختر المترشحين (سيتم حساب عدد الأوراق تلقائياً)</h3>
        <div class="employee-list">
            <?php foreach ($employees as $emp): ?>
                <div class="employee-item">
                    <div class="employee-check">
                        <input type="checkbox" name="selected[]" value="<?= $emp['id'] ?>" class="emp-checkbox" id="emp_<?= $emp['id'] ?>">
                    </div>
                    <div class="employee-info">
                        <label for="emp_<?= $emp['id'] ?>">
                            <strong><?= htmlspecialchars($emp['name']) ?></strong>
                            (رقم الموظف: <?= $emp['id'] ?>) – 
                            <?= $emp['tickets'] ?> ورقة
                            <?php if (!empty($emp['hire_date'])): ?>
                                (تاريخ التوظيف: <?= date('d/m/Y', strtotime($emp['hire_date'])) ?>)
                            <?php else: ?>
                                (⚠️ لا يوجد تاريخ توظيف)
                            <?php endif; ?>
                        </label>
                    </div>
                    <div>
                        <a href="print_tickets.php?id=<?= $emp['id'] ?>&draw_title=<?= urlencode('سحب العمرة') ?>" target="_blank" class="btn-print">🖨️ قصاصات</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="print-all">
            <a href="#" id="printAllBtn" class="btn-print-all">🖨️ طباعة قصاصات المختارين</a>
        </div>
        <button type="submit" name="create_draw" class="btn-submit">💾 بدء السحب وحفظ المشاركين</button>
    </form>
</div>

<script>
    document.getElementById('printAllBtn').addEventListener('click', function(e) {
        e.preventDefault();
        let selected = [];
        document.querySelectorAll('.emp-checkbox:checked').forEach(cb => {
            selected.push(cb.value);
        });
        if (selected.length === 0) {
            alert('الرجاء اختيار موظفين أولاً');
            return;
        }
        let url = 'print_all_tickets.php?ids=' + selected.join(',');
        window.open(url, '_blank');
    });
</script>

<?php include '../includes/footer.php'; ?>