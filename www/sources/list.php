<?php
session_start();
require_once '../includes/auth_check.php';
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

require_once '../config/database.php';
require_once '../includes/functions.php'; // ✅ إضافة هذا السطر لتجنب خطأ الدالة

// ========== معالجة POST (إضافة مصدر) ==========
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['new_source'])) {
    $newSource = trim($_POST['new_source']);
    if (!empty($newSource)) {
        $stmt = $pdo->prepare("INSERT INTO sources (name) VALUES (?)");
        $stmt->execute([$newSource]);
        if (function_exists('createBackup')) {
            createBackup('add_source');
        }
    }
    header("Location: list.php");
    exit;
}

// ========== معالجة GET (حذف مصدر) ==========
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM sources WHERE id = ?");
    $stmt->execute([$id]);
    if (function_exists('createBackup')) {
        createBackup('delete_source');
    }
    header("Location: list.php");
    exit;
}

// ========== البحث ==========
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

$sql = "SELECT * FROM sources WHERE 1=1";
if ($search) {
    $sql .= " AND name LIKE :search";
}
$sql .= " ORDER BY name ASC";

$stmt = $pdo->prepare($sql);
if ($search) {
    $stmt->execute([':search' => "%$search%"]);
} else {
    $stmt->execute();
}
$sources = $stmt->fetchAll();

// ========== بعد الانتهاء من كل المعالجة، نبدأ عرض الصفحة ==========
include '../includes/header.php';
?>

<style>
    .search-bar {
        background: #f8f9fa;
        padding: 15px;
        border-radius: 12px;
        margin-bottom: 20px;
        display: flex;
        gap: 10px;
        align-items: center;
        flex-wrap: wrap;
    }
    .search-bar input {
        flex: 1;
        padding: 10px;
        border: 1px solid #ddd;
        border-radius: 8px;
    }
    .btn-reset {
        background: #6c757d;
        color: white;
        padding: 10px 20px;
        border-radius: 8px;
        text-decoration: none;
    }
</style>

<div class="section">
    <div class="section-header">
        <h3 class="section-title">📁 مصادر البيانات</h3>
    </div>

    <!-- شريط البحث -->
    <div class="search-bar">
        <form method="GET" style="display: flex; gap: 10px; width: 100%;">
            <input type="text" name="search" placeholder="🔍 بحث باسم المصدر..." value="<?= htmlspecialchars($search) ?>">
            <button type="submit" class="btn-sm">بحث</button>
            <?php if ($search): ?>
                <a href="list.php" class="btn-reset">إلغاء</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- نموذج إضافة مصدر -->
    <form method="POST" style="margin-bottom: 20px; display: flex; gap: 10px;">
        <input type="text" name="new_source" placeholder="اسم المصدر الجديد (مثال: djezzy)" required style="flex: 1; padding: 10px; border-radius: 12px; border: 1px solid #ccc;">
        <button type="submit" class="btn-sm" style="background: #2a5298;">➕ إضافة مصدر</button>
    </form>

    <!-- جدول المصادر -->
    <table class="data-table">
        <thead>
            <tr>
                <th>#</th>
                <th>الاسم</th>
                <th>الإجراءات</th>
            </tr>
        </thead>
        <tbody>
            <?php $i = 1; foreach($sources as $src): ?>
            <tr>
                <td><?= $i++ ?></td>
                <td><?= htmlspecialchars($src['name']) ?> </span></small></td>
                <td>
                    <?php 
                    // منع حذف المصادر الأساسية (يمكنك تعديل القائمة حسب حاجتك)
                    $protected = ['سعدين للتجهير', 'سلفيات', 'djezzy', 'saadin', 'soulef'];
                    if (!in_array($src['name'], $protected)): ?>
                        <a href="?delete=<?= $src['id'] ?>" class="btn-sm" style="background: #dc3545;" onclick="return confirm('حذف هذا المصدر؟')">🗑️ حذف</a>
                    <?php else: ?>
                        <span style="color: gray;">لا يمكن الحذف</span>
                    <?php endif; ?>
                 </span></small></td>
             </span></small></tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php include '../includes/footer.php'; ?>