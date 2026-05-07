<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
require_once '../config/database.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    header("Location: index.php");
    exit;
}

// جلب اسم المكرم
$stmt = $pdo->prepare("SELECT e.name FROM labor_day_honorees h JOIN employees e ON h.employee_id = e.id WHERE h.id = ?");
$stmt->execute([$id]);
$name = $stmt->fetchColumn();
if (!$name) {
    header("Location: index.php");
    exit;
}

include '../includes/header.php';
?>

<div style="direction: rtl; text-align: center; margin-top: 50px;">
    <h3>⚠️ هل أنت متأكد من حذف هذا التكريم؟</h3>
    <p><strong>الموظف:</strong> <?= htmlspecialchars($name) ?></p>
    <a href="delete.php?id=<?= $id ?>" class="btn btn-danger" style="background:#dc3545; color:white; padding:8px 16px; text-decoration:none; border-radius:8px;">نعم، احذف</a>
    <a href="index.php" class="btn btn-secondary" style="background:#6c757d; color:white; padding:8px 16px; text-decoration:none; border-radius:8px;">إلغاء</a>
</div>

<?php include '../includes/footer.php'; ?>