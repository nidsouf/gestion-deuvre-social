<?php
require_once 'config/database.php';

$alterStatements = [
    "ALTER TABLE users ADD COLUMN is_active INTEGER DEFAULT 1",
    "ALTER TABLE users ADD COLUMN password_changed_at TEXT",
    "ALTER TABLE users ADD COLUMN updated_at TEXT",
    "ALTER TABLE employees ADD COLUMN updated_at TEXT",
    "ALTER TABLE deductions ADD COLUMN updated_at TEXT",
    "ALTER TABLE deductions ADD COLUMN grant_date TEXT",
    "ALTER TABLE employee_grants ADD COLUMN updated_at TEXT",
    "ALTER TABLE employee_phone_numbers ADD COLUMN updated_at TEXT",
    "ALTER TABLE meeting_minutes ADD COLUMN updated_at TEXT",
    "ALTER TABLE meal_records ADD COLUMN updated_at TEXT",
    "ALTER TABLE meal_trimesters ADD COLUMN updated_at TEXT",
    "ALTER TABLE labor_day_honorees ADD COLUMN updated_at TEXT",
    "ALTER TABLE settings ADD COLUMN updated_at TEXT",
];

foreach ($alterStatements as $sql) {
    try {
        $pdo->exec($sql);
        echo "✓ " . substr($sql, 0, 50) . " ... تم<br>";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'duplicate column name') !== false) {
            echo "⚠️ العمود موجود بالفعل: " . substr($sql, 0, 50) . "<br>";
        } else {
            echo "❌ خطأ: " . $e->getMessage() . "<br>";
        }
    }
}

echo "<br>✅ اكتملت الترقية. يمكنك الآن استخدام النظام بشكل طبيعي.";
?>