<?php
if (!function_exists('processPayment')) {
    function processPayment($pdo, $installmentId, $month, $year) {
        $stmt = $pdo->prepare("
            SELECT mi.*, d.is_loan, d.id as deduction_id
            FROM monthly_installments mi
            JOIN deductions d ON mi.deduction_id = d.id
            WHERE mi.id = ? AND mi.is_paid = 0
        ");
        $stmt->execute([$installmentId]);
        $inst = $stmt->fetch();
        
        if (!$inst) {
            throw new Exception('القسط غير موجود أو تم سداده مسبقاً');
        }
        
        $update = $pdo->prepare("UPDATE monthly_installments SET is_paid = 1, paid_date = datetime('now') WHERE id = ?");
        $update->execute([$installmentId]);
        
        if ($inst['is_loan']) {
            $amount = $inst['amount'];
            $stmtBudget = $pdo->prepare("
                UPDATE social_budget 
                SET remaining_budget = remaining_budget + ?
                WHERE id = (SELECT id FROM social_budget ORDER BY year DESC LIMIT 1)
            ");
            $stmtBudget->execute([$amount]);
            
            $stmtTrans = $pdo->prepare("
                INSERT INTO budget_transactions (reference_id, type, amount, description, is_deduct, transaction_date)
                VALUES (?, 'installment', ?, ?, 0, datetime('now'))
            ");
            $stmtTrans->execute([
                $inst['deduction_id'],
                $amount,
                "استرجاع سلفة (قسط شهر " . getMonthNameArabic($month) . " " . $year . ")"
            ]);
        }
    }
}