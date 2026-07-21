<?php
require_once 'config/database.php';

$years = [2026, 2027];

foreach ($years as $year) {
    // 1. جلب الميزانية الأولية
    $stmt = $pdo->prepare("SELECT initial_budget FROM social_budget WHERE year = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$year]);
    $initial = (float)$stmt->fetchColumn();
    if ($initial <= 0) continue;

    // 2. حساب الصرف والاسترجاعات من budget_transactions
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN is_deduct = 1 AND type IN ('loan', 'grant', 'installment') THEN amount ELSE 0 END), 0) AS expenses,
            COALESCE(SUM(CASE WHEN is_deduct = 0 THEN amount ELSE 0 END), 0) AS refunds
        FROM budget_transactions
        WHERE strftime('%Y', transaction_date) = :year
    ");
    $stmt->execute([':year' => (string)$year]);
    $data = $stmt->fetch();
    $expenses = (float)$data['expenses'];
    $refunds = (float)$data['refunds'];

    // 3. حساب المتبقية الصحيحة
    $correctRemaining = $initial - $expenses + $refunds;

    // 4. تحديث social_budget
    $update = $pdo->prepare("
        UPDATE social_budget
        SET remaining_budget = ?
        WHERE year = ? AND id = (
            SELECT id FROM social_budget WHERE year = ? ORDER BY id DESC LIMIT 1
        )
    ");
    $update->execute([$correctRemaining, $year, $year]);

    echo "✅ سنة $year: الأولية = " . number_format($initial, 2) .
         "، الصرف = " . number_format($expenses, 2) .
         "، الاسترجاعات = " . number_format($refunds, 2) .
         " → المتبقية الصحيحة = " . number_format($correctRemaining, 2) . " دج<br>";
}