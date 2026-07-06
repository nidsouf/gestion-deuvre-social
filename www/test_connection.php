<?php
require_once 'config/database.php';
$stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='employees'");
if ($stmt->fetch()) {
    echo "✅ قاعدة البيانات الصحيحة متصلة. جدول employees موجود.";
} else {
    echo "❌ لا يزال الاتصال بقاعدة بيانات خاطئة أو فارغة.";
}
?>