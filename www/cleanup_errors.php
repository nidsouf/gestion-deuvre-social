<?php
// fix_budget_simple.php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) die("تسجيل الدخول مطلوب");

$year = 2026;

// حساب الرصيد الصحيح
$stmt = $pdo->prepare("
    SELECT 
        initial_budget,
        COALESCE(SUM(CASE WHEN is_deduct = 1 THEN amount ELSE 0 END), 0) AS expenses,
        COALESCE(SUM(CASE WHEN is_deduct = 0 THEN amount ELSE 0 END), 0) AS refunds
    FROM social_budget, budget_transactions
    WHERE social_budget.year = ? AND strftime('%Y', budget_transactions.transaction_date) = ?
");
$stmt->execute([$year, $year]);
$data = $stmt->fetch();

$correctRemaining = $data['initial_budget'] - $data['expenses'] + $data['refunds'];

echo "الميزانية الصحيحة: " . number_format($correctRemaining, 2) . " دج<br>";

// تحديث
$update = $pdo->prepare("UPDATE social_budget SET remaining_budget = ? WHERE year = ?");
$update->execute([$correctRemaining, $year]);

echo "✅ تم تحديث remaining_budget إلى " . number_format($correctRemaining, 2) . " دج";