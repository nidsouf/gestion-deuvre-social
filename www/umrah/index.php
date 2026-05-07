<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
require_once '../config/database.php';
require_once '../includes/functions.php';

// التأكد من وجود جدول umrah_draws وإضافة عمود reserve_order إذا لم يكن موجوداً
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
    // محاولة إضافة عمود reserve_order (يتجاهل الخطأ إذا كان موجوداً)
    $pdo->exec("ALTER TABLE umrah_draws ADD COLUMN reserve_order INTEGER DEFAULT NULL");
} catch (Exception $e) {
    // العمود موجود أو خطأ آخر نتجاهله
}

$message = '';
$result = null;
$employee_id = 0;
$employee_name = '';
$tickets = 0;
$years = 0;
$months = 0;
$calc = null;

function getDateDiffInYearsMonths($from, $to) {
    $fromDate = new DateTime($from);
    $toDate = new DateTime($to);
    $diff = $fromDate->diff($toDate);
    return [
        'years' => $diff->y,
        'months' => $diff->m,
        'total_months' => ($diff->y * 12) + $diff->m
    ];
}

function calculateTicketsFromTotalMonths($total_months) {
    $full_tickets = floor($total_months / 36);
    $remaining_months = $total_months % 36;
    $extra_ticket = ($remaining_months >= 18) ? 1 : 0;
    return [
        'full_tickets' => $full_tickets,
        'remaining_months' => $remaining_months,
        'extra_ticket' => $extra_ticket,
        'total_tickets' => $full_tickets + $extra_ticket
    ];
}

// معالجة حساب القصاصات
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'calculate') {
        $employee_id = (int)$_POST['employee_id'];
        $stmt = $pdo->prepare("SELECT id, name, hire_date FROM employees WHERE id = ?");
        $stmt->execute([$employee_id]);
        $emp = $stmt->fetch();
        
        if ($emp && !empty($emp['hire_date'])) {
            $employee_name = $emp['name'];
            $hire_date = $emp['hire_date'];
            $today = date('Y-m-d');
            $diff = getDateDiffInYearsMonths($hire_date, $today);
            $years = $diff['years'];
            $months = $diff['months'];
            $total_months = $diff['total_months'];
            $calc = calculateTicketsFromTotalMonths($total_months);
            $tickets = $calc['total_tickets'];
            
            $result = [
                'employee_id' => $employee_id,
                'employee_name' => $employee_name,
                'hire_date' => $hire_date,
                'years' => $years,
                'months' => $months,
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
    
    // معالجة حفظ نتيجة السحب (مع الاحتياطي)
    if ($_POST['action'] === 'store_draw') {
        $employee_id = (int)$_POST['employee_id'];
        $tickets_count = (int)$_POST['tickets_count'];
        $is_winner = isset($_POST['is_winner']) ? 1 : 0;
        $reserve_order = !empty($_POST['reserve_order']) ? (int)$_POST['reserve_order'] : null;
        $notes = trim($_POST['notes']);
        $draw_date = date('Y-m-d');
        
        try {
            $stmt = $pdo->prepare("INSERT INTO umrah_draws (employee_id, draw_date, tickets_count, is_winner, reserve_order, notes) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$employee_id, $draw_date, $tickets_count, $is_winner, $reserve_order, $notes]);
            $message = "✅ تم تسجيل السحب للموظف بنجاح" . ($is_winner ? " 🎉 (فائز) 🎉" : "");
            if ($reserve_order) {
                $message .= " (الاحتياطي رقم $reserve_order)";
            }
        } catch (Exception $e) {
            $message = "❌ خطأ أثناء التسجيل: " . $e->getMessage();
        }
    }
}

// جلب سجل السحوبات السابقة (آخر 20)
$draws = $pdo->query("
    SELECT d.*, e.name as employee_name 
    FROM umrah_draws d
    JOIN employees e ON d.employee_id = e.id
    ORDER BY d.draw_date DESC, d.id DESC
    LIMIT 20
")->fetchAll();

// جلب قائمة الموظفين للاختيار
$employees = $pdo->query("SELECT id, name, hire_date FROM employees WHERE hire_date IS NOT NULL AND hire_date != '' ORDER BY name")->fetchAll();

include '../includes/header.php';
?>

<style>
    .umrah-container {
        direction: rtl;
        max-width: 1000px;
        margin: 0 auto;
        padding: 20px;
    }
    .form-card, .result-card, .history-card {
        background: white;
        border-radius: 20px;
        padding: 25px;
        margin-bottom: 25px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    }
    .form-group {
        margin-bottom: 20px;
    }
    .form-group label {
        display: block;
        font-weight: bold;
        margin-bottom: 8px;
    }
    .form-group select, .form-group input, .form-group textarea {
        width: 100%;
        padding: 10px;
        border-radius: 12px;
        border: 1px solid #ccc;
    }
    button {
        background: #2a5298;
        color: white;
        padding: 12px 20px;
        border: none;
        border-radius: 30px;
        cursor: pointer;
        font-weight: bold;
        width: 100%;
    }
    .btn-store {
        background: #28a745;
        margin-top: 20px;
    }
    .ticket-count {
        font-size: 48px;
        font-weight: bold;
        color: #2a5298;
        text-align: center;
        margin: 20px 0;
    }
    .details {
        background: #f0f2f5;
        padding: 15px;
        border-radius: 12px;
        margin-top: 15px;
    }
    .info-text {
        color: #666;
        font-size: 14px;
        margin-top: 10px;
    }
    table.details-table {
        width: 100%;
        border-collapse: collapse;
    }
    table.details-table td {
        padding: 6px;
        border-bottom: 1px solid #ddd;
    }
    .badge {
        display: inline-block;
        padding: 4px 8px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: bold;
    }
    .badge-yes { background: #28a745; color: white; }
    .badge-no { background: #dc3545; color: white; }
    .winner-badge { background: #ffc107; color: #000; }
    .reserve-badge { background: #17a2b8; color: white; }
</style>

<div class="umrah-container">
    <h2>🕋 نظام أوراق العمرة (قصاصات السحب)</h2>
    <p class="info-text">📌 يحتسب الموظف ورقة (قصاصة) عن كل 3 سنوات خدمة كاملة، بالإضافة إلى ورقة إذا كان الباقي من الخدمة 18 شهراً فأكثر (1.5 سنة).</p>

    <div class="form-card">
        <form method="POST">
            <input type="hidden" name="action" value="calculate">
            <div class="form-group">
                <label>👤 اختر الموظف</label>
                <select name="employee_id" required>
                    <option value="">-- اختر موظف --</option>
                    <?php foreach($employees as $emp): ?>
                        <option value="<?= $emp['id'] ?>">
                            <?= htmlspecialchars($emp['name']) ?> (تاريخ التوظيف: <?= $emp['hire_date'] ?? 'غير محدد' ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit">📋 حساب القصاصات</button>
        </form>
    </div>

    <?php if($message): ?>
        <div style="background:#d4edda; padding:15px; border-radius:12px; margin-bottom:20px;">
            <?= $message ?>
        </div>
    <?php endif; ?>

    <?php if($result): ?>
    <div class="result-card">
        <h3>📊 نتيجة الحساب للموظف: <strong><?= htmlspecialchars($result['employee_name']) ?></strong></h3>
        <div class="ticket-count">
            🎫 <?= $result['total_tickets'] ?> قصاصة / ورقة
        </div>
        <div class="details">
            <table class="details-table">
                <tr><td style="width:40%"><strong>تاريخ التوظيف:</strong></td><td><?= date('d/m/Y', strtotime($result['hire_date'])) ?></td></tr>
                <tr><td><strong>المدة المنقضية:</strong></td><td><?= $result['years'] ?> سنوات و <?= $result['months'] ?> أشهر (إجمالي <?= $result['total_months'] ?> شهراً)</span></small></td></tr>
                <tr><td><strong>القصاصات الكاملة (كل 36 شهراً):</strong></td><td><?= $result['full_tickets'] ?> ورقة</span></small></td></tr>
                <tr><td><strong>المتبقي (أقل من 36 شهراً):</strong></td><td><?= $result['remaining_months'] ?> شهراً</span></small></td></tr>
                <tr><td><strong>قصاصة إضافية (≥ 18 شهراً):</strong></td><td>
                    <?php if($result['extra_ticket']): ?>
                        <span class="badge badge-yes">نعم ✅</span>
                    <?php else: ?>
                        <span class="badge badge-no">لا ❌</span>
                    <?php endif; ?>
                 </span></small></td></tr>
            </table>
        </div>
        
        <!-- نموذج تسجيل السحب مع الاحتياطي -->
        <form method="POST" style="margin-top:20px; border-top:1px solid #eee; padding-top:20px;">
            <input type="hidden" name="action" value="store_draw">
            <input type="hidden" name="employee_id" value="<?= $result['employee_id'] ?>">
            <input type="hidden" name="tickets_count" value="<?= $result['total_tickets'] ?>">
            
            <div class="form-group">
                <label>🏆 فائز في هذه القرعة؟</label>
                <div style="display: flex; gap: 20px;">
                    <label><input type="radio" name="is_winner" value="1"> نعم (فائز) 🎉</label>
                    <label><input type="radio" name="is_winner" value="0" checked> لا</label>
                </div>
            </div>
            
            <div class="form-group">
                <label>🔄 رقم الاحتياطي (إذا كان من الاحتياطيين)</label>
                <input type="number" name="reserve_order" placeholder="مثال: 1 (للاحتياطي الأول), 2 (للثاني), ..." min="1" step="1">
                <small>اتركه فارغاً إذا لم يكن ضمن الاحتياطيين.</small>
            </div>
            
            <div class="form-group">
                <label>📝 ملاحظات (اختياري)</label>
                <textarea name="notes" rows="2" placeholder="مثال: القرعة الأولى للأعضاء، تاريخ السحب..."></textarea>
            </div>
            
            <button type="submit" class="btn-store">💾 تسجيل السحب / القرعة</button>
        </form>
    </div>
    <?php endif; ?>

    <!-- سجل السحوبات السابقة -->
    <div class="history-card">
        <h3>📜 سجل السحوبات السابقة</h3>
        <?php if(count($draws) == 0): ?>
            <p>⚠️ لا توجد سحوبات مسجلة بعد.</p>
        <?php else: ?>
            <table style="width:100%; border-collapse: collapse;">
                <thead>
                    <tr style="background:#2a5298; color:white;">
                        <th style="padding:8px;">#</th>
                        <th style="padding:8px;">الموظف</th>
                        <th style="padding:8px;">عدد القصاصات</th>
                        <th style="padding:8px;">تاريخ السحب</th>
                        <th style="padding:8px;">الفائز</th>
                        <th style="padding:8px;">الاحتياطي</th>
                        <th style="padding:8px;">ملاحظات</th>
                    </span></small></tr>
                </thead>
                <tbody>
                    <?php $i=1; foreach($draws as $draw): ?>
                    <tr style="border-bottom:1px solid #eee;">
                        <td style="padding:8px;"><?= $i++ ?> </span></small></td>
                        <td style="padding:8px;"><?= htmlspecialchars($draw['employee_name']) ?> </span></small></td>
                        <td style="padding:8px; text-align:center;"><?= $draw['tickets_count'] ?> </span></small></td>
                        <td style="padding:8px;"><?= date('d/m/Y', strtotime($draw['draw_date'])) ?> </span></small></td>
                        <td style="padding:8px; text-align:center;">
                            <?php if($draw['is_winner']): ?>
                                <span class="badge winner-badge">🏆 فائز</span>
                            <?php else: ?>
                                <span class="badge badge-no">غير فائز</span>
                            <?php endif; ?>
                         </span></small></td>
                        <td style="padding:8px; text-align:center;">
                            <?php if($draw['reserve_order']): ?>
                                <span class="badge reserve-badge">الاحتياطي <?= $draw['reserve_order'] ?></span>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                         </span></small></td>
                        <td style="padding:8px;"><?= htmlspecialchars($draw['notes'] ?? '-') ?> </span></small></td>
                    </span></small></tr>
                    <?php endforeach; ?>
                </tbody>
             </span></small></table>
        <?php endif; ?>
    </div>
</div>

<?php include '../includes/footer.php'; ?>