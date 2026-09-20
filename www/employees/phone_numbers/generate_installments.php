<?php
/**
 * employees/phone_numbers/generate_installments.php
 * توليد الأقساط الشهرية لجميع اقتطاعات الهواتف للسنة المحددة
 * يتم تخطي الأقساط الموجودة مسبقاً لتجنب التكرار
 */

session_start();
require_once '../../includes/auth_check.php';
require_once '../../config/database.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';

if (!in_array($_SESSION['role'] ?? '', ['admin', 'manager', 'committee'])) {
    setToast('⚠️ غير مصرح لك بهذه الصفحة', 'warning');
    header('Location: index.php');
    exit;
}

$year = isset($_POST['year']) ? (int)$_POST['year'] : date('Y');

// معالجة POST (تأكيد التوليد)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate'])) {
    requireCSRFToken();

    // جلب جميع اقتطاعات الهواتف النشطة (source_id = 999)
    $stmt = $pdo->prepare("
        SELECT id, employee_id, source_id, monthly_amount
        FROM deductions
        WHERE source_id = 999 AND monthly_amount > 0
    ");
    $stmt->execute();
    $deductions = $stmt->fetchAll();

    if (empty($deductions)) {
        setToast('⚠️ لا توجد اقتطاعات هاتف نشطة لتوليد أقساطها', 'warning');
        header('Location: index.php');
        exit;
    }

    $inserted = 0;
    $skipped = 0;
    $months = range(1, 12); // جميع أشهر السنة

    foreach ($deductions as $d) {
        foreach ($months as $month) {
            // التحقق من وجود القسط مسبقاً
            $check = $pdo->prepare("
                SELECT COUNT(*) FROM monthly_installments
                WHERE deduction_id = ? AND year = ? AND month = ?
            ");
            $check->execute([$d['id'], $year, $month]);
            if ($check->fetchColumn() > 0) {
                $skipped++;
                continue;
            }

            // إدراج القسط الجديد (is_paid = 0)
            $insert = $pdo->prepare("
                INSERT INTO monthly_installments (
                    deduction_id, employee_id, source_id, year, month, amount, is_paid, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, 0, datetime('now'))
            ");
            $insert->execute([
                $d['id'],
                $d['employee_id'],
                $d['source_id'],
                $year,
                $month,
                $d['monthly_amount']
            ]);
            $inserted++;
        }
    }

    setToast("✅ تم توليد $inserted قسط هاتف جديد (تم تخطي $skipped قسط موجود مسبقاً) للسنة $year", 'success');
    header('Location: index.php');
    exit;
}

// عرض واجهة التأكيد (GET)
$pageTitle = 'توليد أقساط الهواتف';
require_once '../../includes/header.php';
?>
<link rel="stylesheet" href="/assets/css/header.css">
<link rel="stylesheet" href="../../assets/css/employee_phones.css">

<div class="container mt-4">
    <div class="card">
        <div class="card-header bg-info text-white">
            <h4 class="mb-0">📅 توليد أقساط الهواتف السنوية</h4>
        </div>
        <div class="card-body">
            <p>سيتم توليد الأقساط الشهرية (جميع أشهر السنة) لجميع اقتطاعات الهواتف النشطة.</p>
            <p><strong>⚠️ ملاحظة:</strong> سيتم تخطي الأقساط الموجودة مسبقاً لتجنب التكرار.</p>
            <form method="POST">
                <?= csrfField() ?>
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>📅 السنة</label>
                            <select name="year" class="form-control">
                                <?php for ($y = date('Y') - 1; $y <= date('Y') + 1; $y++): ?>
                                    <option value="<?= $y ?>" <?= $y == date('Y') ? 'selected' : '' ?>><?= $y ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <button type="submit" name="generate" class="btn btn-success">✅ توليد الأقساط الآن</button>
                <a href="index.php" class="btn btn-secondary">🔙 إلغاء</a>
            </form>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>