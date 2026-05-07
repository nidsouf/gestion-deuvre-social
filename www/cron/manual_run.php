<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
require_once '../config/database.php';
?>
<!DOCTYPE html>
<html dir="rtl">
<head><meta charset="UTF-8"><title>تشغيل التحديث التلقائي</title></head>
<body>
<h2>🚀 تشغيل تحديث الاقتطاعات الشهرية</h2>
<?php
if (isset($_POST['run'])) {
    ob_start();
    include 'update_deductions.php';
    $output = ob_get_clean();
    echo "<pre>$output</pre>";
    echo "<hr><a href='manual_run.php'>رجوع</a>";
} else {
    echo '<form method="POST"><button type="submit" name="run">▶️ تشغيل التحديث الآن</button></form>';
}
?>
</body>
</html>