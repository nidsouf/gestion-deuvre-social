<?php
session_start();
require_once '../includes/auth_check.php';
require_once '../config/database.php';
require_once '../includes/functions.php';
include '../includes/header.php';

// معالجة إضافة مصدر جديد
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

// حذف مصدر
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    // التحقق من وجود اقترانات
    $check = $pdo->prepare("SELECT COUNT(*) FROM deductions WHERE source_id = ?");
    $check->execute([$id]);
    if ($check->fetchColumn() > 0) {
        $message = "⚠️ لا يمكن حذف هذا المصدر لأنه مرتبط باقتطاعات";
    } else {
        $stmt = $pdo->prepare("DELETE FROM sources WHERE id = ?");
        $stmt->execute([$id]);
        audit('SOURCE_DELETED', "Deleted source ID: $id");
        addNotification('حذف مصدر', "تم حذف المصدر رقم $id", null, 'warning');
        $message = "✅ تم حذف المصدر بنجاح";
    }
}

// جلب قائمة المصادر
$sources = $pdo->query("SELECT * FROM sources ORDER BY name")->fetchAll();
$totalSources = count($sources);

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
</style>

<div class="sources-container">
    <h2>📁 مصادر البيانات</h2>
    
    <div class="stats-grid">
        <div class="stat-card" style="border-bottom-color: #2a5298;"><div>📊 إجمالي المصادر</div><div class="number"><?= $totalSources ?></div></div>
        <div class="stat-card" style="border-bottom-color: #28a745;"><div>✅ نشطة</div><div class="number"><?= $totalSources ?></div></div>
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
                    $countDed = $pdo->prepare("SELECT COUNT(*) FROM deductions WHERE source_id = ?");
                    $countDed->execute([$src['id']]);
                    $dedCount = $countDed->fetchColumn();
                ?>
                <tr class="source-row">
                    <td><?= $i++ ?></td>
                    <td><?= htmlspecialchars($src['name']) ?></td>
                    <td><?= $dedCount ?> اقتطاع</span></small></td>
                    <td>
                        <?php if ($dedCount > 0): ?>
                            <span class="disabled-delete">🔒 لا يمكن الحذف (مرتبط)</span>
                        <?php else: ?>
                            <a href="?delete=<?= $src['id'] ?>" class="btn-delete" onclick="return confirm('هل أنت متأكد من حذف هذا المصدر؟')">🗑️ حذف</a>
                        <?php endif; ?>
                     </span></small></td>
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