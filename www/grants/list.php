<?php
session_start();
require_once '../includes/auth_check.php';
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
require_once '../config/database.php';
include '../includes/header.php';

// البحث
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// استعلام جلب المنح
$sql = "SELECT * FROM grants WHERE 1=1";
if ($search) {
    $sql .= " AND name LIKE :search";
}
$sql .= " ORDER BY id ASC";

$stmt = $pdo->prepare($sql);
if ($search) {
    $stmt->execute([':search' => "%$search%"]);
} else {
    $stmt->execute();
}
$grants = $stmt->fetchAll();

// إحصائيات
$totalGrants = count($grants);
$totalAmount = array_sum(array_column($grants, 'amount'));
?>

<style>
    /* ========== التصميم العصري ========== */
    body {
        font-family: 'Tajawal', 'Segoe UI', system-ui, sans-serif;
        background: #f4f7fc;
    }
    .stats-grid-modern {
        display: flex;
        gap: 1.5rem;
        flex-wrap: wrap;
        margin-bottom: 2rem;
    }
    .stat-card-modern {
        flex: 1;
        min-width: 160px;
        background: white;
        border-radius: 28px;
        padding: 1.2rem 1rem;
        text-align: center;
        box-shadow: 0 8px 20px rgba(0,0,0,0.05);
        transition: all 0.3s ease;
        border-bottom: 4px solid;
        cursor: default;
    }
    .stat-card-modern:hover {
        transform: translateY(-5px);
        box-shadow: 0 15px 30px rgba(0,0,0,0.1);
    }
    .stat-card-modern .stat-icon { font-size: 2rem; margin-bottom: 0.5rem; }
    .stat-card-modern .stat-label { font-size: 0.85rem; text-transform: uppercase; letter-spacing: 1px; font-weight: 600; color: #5a6874; }
    .stat-card-modern .stat-number { font-size: 2rem; font-weight: 800; margin-top: 0.25rem; }
    .stat-card-modern.grants { border-bottom-color: #9c27b0; }
    .stat-card-modern.grants .stat-number { color: #9c27b0; }
    .stat-card-modern.amount { border-bottom-color: #ff9800; }
    .stat-card-modern.amount .stat-number { color: #ff9800; }

    /* أزرار سريعة جديدة */
    .quick-actions {
        display: flex;
        gap: 15px;
        margin-bottom: 25px;
        flex-wrap: wrap;
    }
    .quick-btn {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 0.6rem 1.5rem;
        border-radius: 40px;
        text-decoration: none;
        font-weight: 600;
        transition: all 0.2s;
        font-size: 14px;
    }
    .quick-btn.assign {
        background: #28a745;
        color: white;
    }
    .quick-btn.assign:hover {
        background: #1e7e34;
        transform: translateY(-2px);
    }
    .quick-btn.list {
        background: #17a2b8;
        color: white;
    }
    .quick-btn.list:hover {
        background: #138496;
        transform: translateY(-2px);
    }

    /* شريط البحث */
    .search-modern {
        background: white;
        border-radius: 28px;
        padding: 1rem 1.5rem;
        margin-bottom: 2rem;
        box-shadow: 0 4px 12px rgba(0,0,0,0.03);
        border: 1px solid #eef2f6;
    }
    .search-form {
        display: flex;
        flex-wrap: wrap;
        gap: 1rem;
        align-items: center;
    }
    .search-input {
        flex: 1;
        padding: 0.7rem 1.2rem;
        border: 1px solid #dce3ec;
        border-radius: 40px;
        font-family: inherit;
        font-size: 0.9rem;
        transition: 0.2s;
    }
    .search-input:focus {
        outline: none;
        border-color: #2a5298;
        box-shadow: 0 0 0 3px rgba(42,82,152,0.1);
    }
    .btn-modern {
        padding: 0.6rem 1.5rem;
        border-radius: 40px;
        border: none;
        font-weight: 600;
        cursor: pointer;
        transition: 0.2s;
        font-family: inherit;
        text-decoration: none;
        display: inline-block;
        text-align: center;
    }
    .btn-primary { background: #2a5298; color: white; }
    .btn-primary:hover { background: #1e3c72; transform: scale(1.02); }
    .btn-reset { background: #f1f3f5; color: #5c6f87; }
    .btn-reset:hover { background: #e2e6ea; }
    .btn-add {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        background: linear-gradient(135deg, #28a745, #1e7e34);
        color: white;
        padding: 0.7rem 1.5rem;
        border-radius: 40px;
        text-decoration: none;
        font-weight: 600;
        margin-bottom: 1.5rem;
        transition: 0.2s;
    }
    .btn-add:hover { transform: translateY(-2px); box-shadow: 0 8px 16px rgba(0,0,0,0.1); }

    /* الجدول العصري */
    .table-wrapper {
        overflow-x: auto;
        border-radius: 24px;
        background: white;
        box-shadow: 0 10px 25px -5px rgba(0,0,0,0.05);
        margin-bottom: 1.5rem;
    }
    .data-table-modern {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.9rem;
        min-width: 600px;
    }
    .data-table-modern th {
        background: #f8fafd;
        color: #1e2f3e;
        font-weight: 700;
        padding: 1rem;
        border-bottom: 2px solid #e2e8f0;
        text-align: center;
    }
    .data-table-modern td {
        padding: 1rem;
        text-align: center;
        border-bottom: 1px solid #ecf3fa;
        vertical-align: middle;
    }
    .data-table-modern tbody tr:hover {
        background: #f9fbfe;
        transition: 0.1s;
    }
    .action-buttons {
        display: flex;
        gap: 0.5rem;
        justify-content: center;
        flex-wrap: wrap;
    }
    .btn-action {
        padding: 0.3rem 0.8rem;
        border-radius: 40px;
        text-decoration: none;
        font-size: 0.75rem;
        font-weight: 500;
        transition: 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 0.2rem;
    }
    .btn-edit { background: #ffedd5; color: #b45309; }
    .btn-edit:hover { background: #fed7aa; }
    .btn-delete { background: #fee2e2; color: #b91c1c; }
    .btn-delete:hover { background: #fecaca; }

    .quick-summary {
        background: #f1f5f9;
        border-radius: 20px;
        padding: 1rem 1.5rem;
        font-size: 0.85rem;
        color: #334155;
        display: flex;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 1rem;
    }
</style>

<div style="max-width: 1200px; margin: 0 auto;">
    <h2 style="font-size: 1.8rem; font-weight: 700; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.5rem;">
        🎁 قائمة المنح الاجتماعية
    </h2>

    <!-- بطاقات الإحصائيات -->
    <div class="stats-grid-modern">
        <div class="stat-card-modern grants">
            <div class="stat-icon">🎁</div>
            <div class="stat-label">إجمالي المنح</div>
            <div class="stat-number"><?= $totalGrants ?></div>
        </div>
        <div class="stat-card-modern amount">
            <div class="stat-icon">💰</div>
            <div class="stat-label">إجمالي القيم</div>
            <div class="stat-number"><?= number_format($totalAmount, 2) ?> دج</div>
        </div>
    </div>

    <!-- أزرار سريعة لمنح الموظفين -->
    <div class="quick-actions">
        <a href="assign.php" class="quick-btn assign">➕ منح موظف</a>
        <a href="employee_list.php" class="quick-btn list">📋 منح الموظفين</a>
    </div>

    <!-- شريط البحث -->
    <div class="search-modern">
        <form method="GET" class="search-form">
            <input type="text" name="search" class="search-input" placeholder="🔍 بحث باسم المنحة..." value="<?= htmlspecialchars($search) ?>">
            <button type="submit" class="btn-modern btn-primary">بحث</button>
            <?php if ($search): ?>
                <a href="list.php" class="btn-modern btn-reset">إلغاء</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- زر إضافة جديد -->
    <a href="add.php" class="btn-add">
        <span>➕</span> إضافة منحة جديدة
    </a>

    <!-- الجدول -->
    <div class="table-wrapper">
        <table class="data-table-modern">
            <thead>
                <tr>
                    <th>#</th>
                    <th>المنحة</th>
                    <th>القيمة (دج)</th>
                    <th>الإجراءات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($grants)): ?>
                    <tr>
                        <td colspan="4" style="text-align: center; padding: 2rem;">لا توجد منح مطابقة</span></small></td>
                    </tr>
                <?php else: ?>
                    <?php $i = 1; foreach($grants as $g): ?>
                        <tr>
                            <td><?= $i++ ?> </span></small></td>
                            <td><?= htmlspecialchars($g['name']) ?> </span></small></td>
                            <td><?= number_format($g['amount'], 2) ?> دج</span></small> </span></small></td>
                            <td class="action-buttons">
                                <a href="edit.php?id=<?= $g['id'] ?>" class="btn-action btn-edit">✏️ تعديل</a>
                                <a href="delete.php?id=<?= $g['id'] ?>" class="btn-action btn-delete" onclick="return confirm('حذف هذه المنحة؟')">🗑️ حذف</a>
                             </span></small></td>
                        </span></small>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- ملخص سريع -->
    <div class="quick-summary">
        <span>📊 عدد المنح: <?= $totalGrants ?></span>
        <span>💰 إجمالي القيم: <?= number_format($totalAmount, 2) ?> دج</span>
    </div>
</div>

<?php include '../includes/footer.php'; ?>