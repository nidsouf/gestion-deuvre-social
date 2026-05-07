<?php
require_once 'config/database.php';

// 1. البحث عن أسماء مكررة (نفس الاسم تماماً)
$duplicates = $pdo->query("
    SELECT name, COUNT(*) as count 
    FROM employees 
    GROUP BY name 
    HAVING COUNT(*) > 1
")->fetchAll(PDO::FETCH_ASSOC);

if ($duplicates) {
    echo "⚠️ أسماء مكررة:\n";
    foreach ($duplicates as $d) {
        echo " - {$d['name']} مكرر {$d['count']} مرات\n";
    }
} else {
    echo "✅ لا توجد أسماء مكررة تماماً.\n";
}

// 2. البحث عن أسماء متشابهة (اختياري - قد يعطي نتائج خاطئة)
$similar = $pdo->query("
    SELECT name FROM employees 
    WHERE name LIKE '%حكيم%' OR name LIKE '%زبيدي%'
")->fetchAll(PDO::FETCH_COLUMN);
echo "\n🔍 أسماء تحتوي على 'حكيم' أو 'زبيدي' للمراجعة:\n";
print_r($similar);
?>