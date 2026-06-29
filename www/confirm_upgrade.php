<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
include 'includes/header.php';
?>

<div style="direction: rtl; text-align: center; margin-top: 50px;">
    <h2>⚠️ ترقية قاعدة البيانات</h2>
    <p>سيتم إضافة أعمدة وفهارس ومشغلات جديدة لتحسين الأداء والأمان.</p>
    <p style="color: red;"><strong>يرجى التأكد من أخذ نسخة احتياطية من قاعدة البيانات قبل المتابعة.</strong></p>
    <div style="margin-top: 30px;">
        <a href="run_upgrade.php" class="btn btn-primary" style="background: #28a745;">نعم، قم بالترقية</a>
        <a href="settings.php" class="btn btn-secondary" style="background: #6c757d;">إلغاء</a>
    </div>
</div>

<?php include 'includes/footer.php'; ?>