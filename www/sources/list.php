<?php
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
include '../includes/header.php';

// ============================================================
// معالجة إضافة مصدر جديد
// ============================================================
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_source'])) {
    requireCSRFToken();
    $name = sanitizeInput($_POST['name']);
    if (empty($name)) {
        $message = "⚠️ اسم المصدر مطلوب";
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO sources (name) VALUES (?)");
            $stmt->execute([$name]);
            audit('SOURCE_ADDED', "Added source: $name");
            addNotification('مصدر جديد', "تم إضافة مصدر جديد: $name", null, 'success');
            $message = "✅ تم إضافة المصدر بنجاح";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'UNIQUE') !== false) {
                $message = "⚠️ هذا المصدر موجود مسبقاً";
            } else {
                $message = "❌ خطأ في قاعدة البيانات";
            }
        }
    }
}

// ============================================================
// حذف مصدر (مع التحقق من الارتباطات في كلا الجدولين)
// ============================================================
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    
    // التحقق من وجود اقترانات في deductions أو monthly_installments
    $stmt = $pdo->prepare("
        SELECT 
            (SELECT COUNT(*) FROM deductions WHERE source_id = ?) +
            (SELECT COUNT(*) FROM monthly_installments WHERE source_id = ?) as total
    ");
    $stmt->execute([$id, $id]);
    $total = $stmt->fetchColumn();
    
    if ($total > 0) {
        $message = "⚠️ لا يمكن حذف هذا المصدر لأنه مرتبط باقتطاعات (في deductions أو monthly_installments)";
    } else {
        $stmt = $pdo->prepare("DELETE FROM sources WHERE id = ?");
        $stmt->execute([$id]);
        audit('SOURCE_DELETED', "Deleted source ID: $id");
        addNotification('حذف مصدر', "تم حذف المصدر رقم $id", null, 'warning');
        $message = "✅ تم حذف المصدر بنجاح";
    }
}

// ============================================================
// جلب قائمة المصادر مع عدد الاقتطاعات من كلا الجدولين
// ============================================================
$sources = $pdo->query("SELECT * FROM sources ORDER BY name")->fetchAll();
$totalSources = count($sources);

// حساب الإحصائيات الكلية (للبطاقات)
$totalDeductions = $pdo->query("SELECT COUNT(*) FROM deductions")->fetchColumn();
$totalPhoneInstallments = $pdo->query("SELECT COUNT(*) FROM monthly_installments WHERE source_id = 999")->fetchColumn();
$totalAll = $totalDeductions + $totalPhoneInstallments;

$csrf_token = generateCSRFToken();
?>

<style>
    .sources-container { direction: rtl; max-width: 1200px; margin: 0 auto; padding: 20px; }
    .stats-grid { display: flex; gap: 20px; margin-bottom: 30px; flex-wrap: wrap; }
    .stat-card { background: white; border-radius: 20px; padding: 20px; text-align: center; flex: 1; min-width: 180px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); border-bottom: 3px solid; }
    .stat-card .number { font-size: 28px; font-weight: 700; margin-top: 10px; }
    .form-card { background: #f8f9fa; border-radius: 20px; padding: 25px; margin-bottom: 30px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
    .form-group { margin-bottom: 15px; }
    .form-group label { display: block; font-weight: bold; margin-bottom: 8px; }
    .form-group input { width: 100%; padding: 10px; border-radius: 12px; border: 1px solid #ccc; }
    .btn-add { background: #28a745; color: white; border: none; padding: 10px 20px; border-radius: 30px; cursor: pointer; font-weight: bold; }
    .data-table { width: 100%; border-collapse: collapse; background: white; border-radius: 16px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
    .data-table th, .data-table td { border: 1px solid #ddd; padding: 12px; text-align: center; }
    .data-table th { background: #2a5298; color: white; }
    .btn-delete { background: #dc3545; color: white; padding: 4px 12px; border-radius: 20px; text-decoration: none; font-size: 12px; display: inline-block; }
    .disabled-delete { background: #6c757d; color: #ddd; padding: 4px 12px; border-radius: 20px; font-size: 12px; display: inline-block; cursor: not-allowed; }
    .search-box { margin-bottom: 20px; }
    .search-box input { width: 300px; padding: 8px 15px; border-radius: 30px; border: 1px solid #ccc; }
    .badge-phone { background: #6f42c1; color: white; padding: 2px 8px; border-radius: 12px; font-size: 11px; display: inline-block; margin-right: 5px; }
</style>

<div class="sources-container">
    <h2>📁 مصادر البيانات</h2>
    
    <div class="stats-grid">
        <div class="stat-card" style="border-bottom-color: #2a5298;">
            <div>📊 إجمالي المصادر</div>
            <div class="number"><?= $totalSources ?></div>
        </div>
        <div class="stat-card" style="border-bottom-color: #28a745;">
            <div>📋 إجمالي الاقتطاعات (جميع المصادر)</div>
            <div class="number"><?= number_format($totalAll) ?></div>
        </div>
        <div class="stat-card" style="border-bottom-color: #6f42c1;">
            <div>📱 أقساط الهواتف</div>
            <div class="number"><?= number_format($totalPhoneInstallments) ?></div>
        </div>
    </div>

    <?php if ($message): ?>
        <div style="background:<?= strpos($message, '✅') !== false ? '#d4edda' : '#f8d7da' ?>; color:<?= strpos($message, '✅') !== false ? '#155724' : '#721c24' ?>; padding:12px; border-radius:12px; margin-bottom:20px;"><?= $message ?></div>
    <?php endif; ?>

    <div class="form-card">
        <h3>➕ إضافة مصدر جديد</h3>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <div class="form-group">
                <label>🏷️ اسم المصدر</label>
                <input type="text" name="name" placeholder="مثال: دجيزي, سعدين للتجهير, سلفيات" required>
            </div>
            <button type="submit" name="add_source" class="btn-add">💾 إضافة المصدر</button>
        </form>
    </div>

    <div class="search-box">
        <input type="text" id="searchSource" placeholder="🔍 بحث باسم المصدر..." onkeyup="filterTable()">
    </div>

    <div style="overflow-x: auto;">
        <table class="data-table" id="sourcesTable">
            <thead>
                <tr><th>#</th><th>الاسم</th><th>عدد الاقتطاعات المرتبطة</th><th>الإجراءات</th></tr>
            </thead>
            <tbody>
                <?php $i=1; foreach ($sources as $src):
                    // حساب الاقتطاعات من كلا الجدولين
                    $stmt = $pdo->prepare("
                        SELECT 
                            (SELECT COUNT(*) FROM deductions WHERE source_id = ?) +
                            (SELECT COUNT(*) FROM monthly_installments WHERE source_id = ?) as total_count
                    ");
                    $stmt->execute([$src['id'], $src['id']]);
                    $totalCount = $stmt->fetchColumn();
                    
                    $isPhone = ($src['id'] == 999);
                ?>
                <tr class="source-row">
                    <td><?= $i++ ?></td>
                    <td>
                        <?= htmlspecialchars($src['name']) ?>
                        <?php if ($isPhone): ?>
                            <span class="badge-phone">📱 هاتف</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= $totalCount ?> اقتطاع
                        <?php if ($isPhone && $totalCount == 0): ?>
                            <small style="color:#6f42c1;">(تأكد من توليد الأقساط)</small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($totalCount > 0): ?>
                            <span class="disabled-delete">🔒 لا يمكن الحذف (مرتبط)</span>
                        <?php else: ?>
                            <a href="?delete=<?= $src['id'] ?>" class="btn-delete" onclick="return confirm('هل أنت متأكد من حذف هذا المصدر؟')">🗑️ حذف</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function filterTable() {
    let input = document.getElementById('searchSource');
    let filter = input.value.toLowerCase();
    let rows = document.querySelectorAll('.source-row');
    rows.forEach(row => {
        let name = row.cells[1].innerText.toLowerCase();
        if (name.includes(filter)) row.style.display = '';
        else row.style.display = 'none';
    });
}
</script>

<?php include '../includes/footer.php'; ?>