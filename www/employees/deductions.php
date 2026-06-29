<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
require_once '../config/database.php';
require_once '../includes/functions.php';

// ========== استقبال المعاملات ==========
$employee_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$status = isset($_GET['status']) ? $_GET['status'] : 'all'; // all, active, expired, expiring

if (!$employee_id) {
    header("Location: list.php");
    exit;
}

// جلب بيانات الموظف
$stmtEmp = $pdo->prepare("SELECT name FROM employees WHERE id = ?");
$stmtEmp->execute([$employee_id]);
$employee = $stmtEmp->fetch();
if (!$employee) {
    echo "<div style='padding:20px;'>⚠️ موظف غير موجود</div>";
    include '../includes/footer.php';
    exit;
}

// ========== بناء استعلام الاقتطاعات مع الفلتر ==========
$sql = "SELECT d.*, s.name as source_name 
        FROM deductions d
        JOIN sources s ON d.source_id = s.id
        WHERE d.employee_id = :employee_id";
$params = [':employee_id' => $employee_id];

// إضافة شرط الحالة
$today = date('Y-m-d');
if ($status == 'active') {
    $sql .= " AND d.end_date >= :today";
    $params[':today'] = $today;
} elseif ($status == 'expired') {
    $sql .= " AND d.end_date < :today";
    $params[':today'] = $today;
} elseif ($status == 'expiring') {
    $sql .= " AND d.end_date >= :today AND julianday(d.end_date) - julianday(:today) <= 30";
    $params[':today'] = $today;
}
// 'all' لا يضيف شرطاً

$sql .= " ORDER BY d.start_date DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$deductions = $stmt->fetchAll();

// ========== حساب الإحصائيات للفلاتر ==========
// إجمالي الاقتطاعات
$total_all = count($deductions);
// نشطة
$stmtActive = $pdo->prepare("SELECT COUNT(*) FROM deductions WHERE employee_id = ? AND end_date >= ?");
$stmtActive->execute([$employee_id, date('Y-m-d')]);
$total_active = $stmtActive->fetchColumn();
// منتهية
$stmtExpired = $pdo->prepare("SELECT COUNT(*) FROM deductions WHERE employee_id = ? AND end_date < ?");
$stmtExpired->execute([$employee_id, date('Y-m-d')]);
$total_expired = $stmtExpired->fetchColumn();
// تنتهي قريباً
$stmtExpiring = $pdo->prepare("SELECT COUNT(*) FROM deductions WHERE employee_id = ? AND end_date >= ? AND julianday(end_date) - julianday(?) <= 30");
$stmtExpiring->execute([$employee_id, date('Y-m-d'), date('Y-m-d')]);
$total_expiring = $stmtExpiring->fetchColumn();

include '../includes/header.php';
?>

<style>
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        flex-wrap: wrap;
        gap: 10px;
    }
    .btn-back {
        background: #6c757d;
        color: white;
        padding: 8px 16px;
        border-radius: 25px;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        font-size: 14px;
    }
    .btn-back:hover {
        background: #5a6268;
    }
    .filter-buttons {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }
    .btn-early-payment {
    background: #fd7e14;
    color: white;
    padding: 4px 10px;
    border-radius: 15px;
    text-decoration: none;
    font-size: 12px;
    display: inline-block;
    margin: 0 2px;
}
.btn-early-payment:hover {
    background: #e36209;
}
    .filter-btn {
        padding: 6px 15px;
        border-radius: 25px;
        text-decoration: none;
        background: #f0f2f5;
        color: #333;
        transition: 0.2s;
        font-size: 13px;
    }
    .filter-btn.active {
        background: #2a5298;
        color: white;
    }
    .filter-btn:hover:not(.active) {
        background: #ddd;
    }
    .data-table {
        width: 100%;
        border-collapse: collapse;
        background: white;
        margin-top: 20px;
    }
    .data-table th, .data-table td {
        border: 1px solid #ddd;
        padding: 10px;
        text-align: center;
    }
    .data-table th {
        background: #2a5298;
        color: white;
    }
    .badge-status {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: bold;
    }
    .badge-active { background: #28a74520; color: #1e7e34; border: 1px solid #28a74540; }
    .badge-expired { background: #dc354520; color: #a71d2a; border: 1px solid #dc354540; }
    .badge-expiring { background: #ffc10720; color: #b26a00; border: 1px solid #ffc10740; }
    .no-data {
        text-align: center;
        padding: 30px;
        color: #666;
    }
</style>

<div class="container" style="max-width: 1200px; margin: 0 auto; padding: 0 15px;">
    <!-- رأس الصفحة مع زر العودة في أقصى اليسار والفلاتر -->
    <div class="page-header">
        <a href="list.php" class="btn-back">
            <i class="fas fa-arrow-right"></i> العودة إلى قائمة الموظفين
        </a>
        <div class="filter-buttons">
            <a href="?id=<?= $employee_id ?>&status=all" class="filter-btn <?= $status == 'all' ? 'active' : '' ?>">
                📋 الكل (<?= $total_all ?>)
            </a>
            <a href="?id=<?= $employee_id ?>&status=active" class="filter-btn <?= $status == 'active' ? 'active' : '' ?>">
                ✅ نشط (<?= $total_active ?>)
            </a>
            <a href="?id=<?= $employee_id ?>&status=expiring" class="filter-btn <?= $status == 'expiring' ? 'active' : '' ?>">
                ⚠️ ينتهي قريباً (<?= $total_expiring ?>)
            </a>
            <a href="?id=<?= $employee_id ?>&status=expired" class="filter-btn <?= $status == 'expired' ? 'active' : '' ?>">
                🔴 منتهي (<?= $total_expired ?>)
            </a>
            <a href="early_payment.php?id=<?= $d['id'] ?>" class="btn-early-payment">💰 تسديد مقدم</a>
        </div>
    </div>

    <h2>
        <i class="fas fa-user"></i> اقتطاعات الموظف: <?= htmlspecialchars($employee['name']) ?>
    </h2>

    <?php if (empty($deductions)): ?>
        <div class="no-data">
            <i class="fas fa-info-circle"></i> لا توجد اقتطاعات مسجلة لهذا الموظف.
        </div>
    <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>المصدر</th>
                    <th>المبلغ الشهري (دج)</th>
                    <th>عدد الأشهر</th>
                    <th>تاريخ البداية</th>
                    <th>تاريخ النهاية</th>
                    <th>الحالة</th>
                </tr>
            </thead>
            <tbody>
                <?php $i = 1; foreach ($deductions as $d): 
                    $end_date = $d['end_date'];
                    $today = date('Y-m-d');
                    $status_class = 'badge-active';
                    $status_text = 'نشط';
                    if ($end_date < $today) {
                        $status_class = 'badge-expired';
                        $status_text = 'منتهي';
                    } elseif ((strtotime($end_date) - strtotime($today)) / (60*60*24) <= 30) {
                        $status_class = 'badge-expiring';
                        $status_text = 'ينتهي قريباً';
                    }
                ?>
                <tr>
                    <td><?= $i++ ?></td>
                    <td><?= htmlspecialchars($d['source_name']) ?></td>
                    <td><?= number_format($d['monthly_amount'], 2) ?> دج</span></small></td>
                    <td><?= $d['total_months'] ?> شهر</span></small></td>
                    <td><?= date('d/m/Y', strtotime($d['start_date'])) ?></td>
                    <td><?= date('d/m/Y', strtotime($d['end_date'])) ?></td>
                    <td><span class="badge-status <?= $status_class ?>"><?= $status_text ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php include '../includes/footer.php'; ?>