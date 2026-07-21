<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/common_helpers.php';

// إنشاء الجدول إذا لم يكن موجوداً
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS umrah_draws (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        employee_id INTEGER NOT NULL,
        draw_date TEXT NOT NULL,
        tickets_count INTEGER NOT NULL,
        is_winner INTEGER DEFAULT 0,
        reserve_order INTEGER DEFAULT NULL,
        notes TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (employee_id) REFERENCES employees(id)
    )");
    $pdo->exec("ALTER TABLE umrah_draws ADD COLUMN reserve_order INTEGER DEFAULT NULL");
} catch (Exception $e) {}

$message = '';
$result = null;
$employees = $pdo->query("SELECT id, name, hire_date FROM employees WHERE hire_date IS NOT NULL AND hire_date != '' ORDER BY name")->fetchAll();

function calculateTickets($total_months) {
    $full_tickets = floor($total_months / 36);
    $remaining_months = $total_months % 36;
    $extra_ticket = ($remaining_months >= 18) ? 1 : 0;
    return ['full_tickets' => $full_tickets, 'remaining_months' => $remaining_months, 'extra_ticket' => $extra_ticket, 'total_tickets' => $full_tickets + $extra_ticket];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'calculate') {
        $employee_id = (int)$_POST['employee_id'];
        $stmt = $pdo->prepare("SELECT id, name, hire_date FROM employees WHERE id = ?");
        $stmt->execute([$employee_id]);
        $emp = $stmt->fetch();
        if ($emp && !empty($emp['hire_date'])) {
            $hire_date = $emp['hire_date'];
            $today = date('Y-m-d');
            $from = new DateTime($hire_date);
            $to = new DateTime($today);
            $diff = $from->diff($to);
            $total_months = ($diff->y * 12) + $diff->m;
            $calc = calculateTickets($total_months);
            $result = [
                'employee_id' => $employee_id,
                'employee_name' => $emp['name'],
                'hire_date' => $hire_date,
                'years' => $diff->y,
                'months' => $diff->m,
                'total_months' => $total_months,
                'full_tickets' => $calc['full_tickets'],
                'remaining_months' => $calc['remaining_months'],
                'extra_ticket' => $calc['extra_ticket'],
                'total_tickets' => $calc['total_tickets']
            ];
        } else {
            $message = "⚠️ الموظف غير موجود أو لا يوجد لديه تاريخ توظيف.";
        }
    }

    if ($_POST['action'] === 'store_draw') {
        $employee_id = (int)$_POST['employee_id'];
        $tickets_count = (int)$_POST['tickets_count'];
        $is_winner = isset($_POST['is_winner']) ? 1 : 0;
        $reserve_order = !empty($_POST['reserve_order']) ? (int)$_POST['reserve_order'] : null;
        $notes = trim($_POST['notes']);
        try {
            $stmt = $pdo->prepare("INSERT INTO umrah_draws (employee_id, draw_date, tickets_count, is_winner, reserve_order, notes) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$employee_id, date('Y-m-d'), $tickets_count, $is_winner, $reserve_order, $notes]);
            $message = "✅ تم تسجيل السحب للموظف بنجاح" . ($is_winner ? " 🎉 (فائز) 🎉" : "");
            if ($reserve_order) $message .= " (الاحتياطي رقم $reserve_order)";
        } catch (Exception $e) { $message = "❌ خطأ: " . $e->getMessage(); }
    }
}

$draws = $pdo->query("SELECT d.*, e.name as employee_name FROM umrah_draws d JOIN employees e ON d.employee_id = e.id ORDER BY d.draw_date DESC, d.id DESC LIMIT 20")->fetchAll();

include '../includes/header.php';
?>
<link rel="stylesheet" href="../assets/css/umrah.css">

<div class="umrah-container">
    <div class="umrah-header"><h2>🕋 نظام أوراق العمرة</h2><p style="margin:5px 0 0; opacity:0.8;">📌 ورقة عن كل 3 سنوات + ورقة إضافية إذا كان الباقي 18 شهراً فأكثر.</p></div>

    <div class="form-card">
        <form method="POST">
            <input type="hidden" name="action" value="calculate">
            <div class="form-group">
                <label>👤 اختر الموظف</label>
                <select name="employee_id" required>
                    <option value="">-- اختر موظف --</option>
                    <?php foreach($employees as $emp): ?>
                        <option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['name']) ?> (<?= $emp['hire_date'] ?? 'غير محدد' ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn-submit">📋 حساب القصاصات</button>
        </form>
    </div>

    <?php if ($message): ?><div style="background:#d4edda; padding:15px; border-radius:12px; margin-bottom:20px;"><?= $message ?></div><?php endif; ?>

    <?php if ($result): ?>
    <div class="result-card">
        <h3>📊 نتيجة الحساب للموظف: <strong><?= htmlspecialchars($result['employee_name']) ?></strong></h3>
        <div class="ticket-count">🎫 <?= $result['total_tickets'] ?> قصاصة / ورقة</div>
        <div class="details">
            <table style="width:100%;"><tr><td style="width:40%;"><strong>تاريخ التوظيف:</strong></td><td><?= safeFormatDate($result['hire_date']) ?></td></tr>
            <tr><td><strong>المدة المنقضية:</strong></td><td><?= $result['years'] ?> سنوات و <?= $result['months'] ?> أشهر (إجمالي <?= $result['total_months'] ?> شهراً)</td></tr>
            <tr><td><strong>القصاصات الكاملة:</strong></td><td><?= $result['full_tickets'] ?> ورقة</td></tr>
            <tr><td><strong>المتبقي:</strong></td><td><?= $result['remaining_months'] ?> شهراً</td></tr>
            <tr><td><strong>قصاصة إضافية:</strong></td><td><?= $result['extra_ticket'] ? '<span class="badge badge-yes">نعم ✅</span>' : '<span class="badge badge-no">لا ❌</span>' ?></td></tr></table>
        </div>

        <form method="POST" style="margin-top:20px; border-top:1px solid #eee; padding-top:20px;">
            <input type="hidden" name="action" value="store_draw"><input type="hidden" name="employee_id" value="<?= $result['employee_id'] ?>"><input type="hidden" name="tickets_count" value="<?= $result['total_tickets'] ?>">
            <div class="form-group"><label>🏆 فائز في هذه القرعة؟</label>
                <div style="display:flex; gap:20px;"><label><input type="radio" name="is_winner" value="1"> نعم 🎉</label><label><input type="radio" name="is_winner" value="0" checked> لا</label></div>
            </div>
            <div class="form-group"><label>🔄 رقم الاحتياطي</label><input type="number" name="reserve_order" placeholder="مثال: 1 (للالاحتياطي الأول)" min="1"></div>
            <div class="form-group"><label>📝 ملاحظات</label><textarea name="notes" rows="2"></textarea></div>
            <button type="submit" class="btn-store">💾 تسجيل السحب / القرعة</button>
        </form>
    </div>
    <?php endif; ?>

    <div class="history-card">
        <h3>📜 سجل السحوبات السابقة</h3>
        <?php if (empty($draws)): ?><p>⚠️ لا توجد سحوبات مسجلة بعد.</p>
        <?php else: ?>
        <table class="draws-table">
            <thead><tr><th>#</th><th>الموظف</th><th>القصاصات</th><th>التاريخ</th><th>الفائز</th><th>الاحتياطي</th></tr></thead>
            <tbody><?php $i=1; foreach($draws as $draw): ?>
            <tr><td><?= $i++ ?></td><td><?= htmlspecialchars($draw['employee_name']) ?></td><td><?= $draw['tickets_count'] ?></td><td><?= safeFormatDate($draw['draw_date']) ?></td>
                <td><?= $draw['is_winner'] ? '<span class="badge badge-winner">🏆 فائز</span>' : '<span class="badge badge-no">غير فائز</span>' ?></td>
                <td><?= $draw['reserve_order'] ? '<span class="badge badge-reserve">الاحتياطي '.$draw['reserve_order'].'</span>' : '—' ?></td>
            </tr><?php endforeach; ?></tbody>
        </table><?php endif; ?>
    </div>
</div>

<?php include '../includes/footer.php'; ?>