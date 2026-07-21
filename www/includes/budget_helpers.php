<?php
/**
 * budget_helpers.php - دوال مساعدة لوحدة الميزانية الاجتماعية
 * ============================================================
 */

if (!function_exists('getBudgetStats')) {
    /**
     * الحصول على إحصائيات الميزانية لسنة معينة
     */
    function getBudgetStats($pdo, $year) {
        $stmt = $pdo->prepare("SELECT initial_budget, remaining_budget FROM social_budget WHERE year = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$year]);
        $budget = $stmt->fetch();
        $initial = $budget ? (float)$budget['initial_budget'] : 0;
        $remaining = $budget ? (float)$budget['remaining_budget'] : 0;

        // جلب إجمالي المصروفات حسب النوع
        $stmt = $pdo->prepare("
            SELECT type, SUM(amount) as total, is_deduct
            FROM budget_transactions
            WHERE strftime('%Y', transaction_date) = ?
            GROUP BY type, is_deduct
        ");
        $stmt->execute([(string)$year]);
        $rows = $stmt->fetchAll();

        $loans = $grants = $installments = $refunds = $totalExpenses = $totalRefunds = 0;
        foreach ($rows as $r) {
            if ($r['is_deduct'] == 1) {
                $totalExpenses += $r['total'];
                if ($r['type'] == 'loan') $loans += $r['total'];
                elseif ($r['type'] == 'grant') $grants += $r['total'];
                elseif ($r['type'] == 'installment') $installments += $r['total'];
            } else {
                $totalRefunds += $r['total'];
                $refunds += $r['total'];
            }
        }

        return [
            'initial' => $initial,
            'remaining' => $remaining,
            'loans' => $loans,
            'grants' => $grants,
            'installments' => $installments,
            'refunds' => $refunds,
            'total_expenses' => $totalExpenses,
            'total_refunds' => $totalRefunds,
            'used_percentage' => $initial > 0 ? round(($totalExpenses / $initial) * 100, 1) : 0,
        ];
    }
}

if (!function_exists('getBudgetTransactions')) {
    /**
     * جلب معاملات الميزانية مع الفلاتر
     */
    function getBudgetTransactions($pdo, $filters = []) {
        $year = $filters['year'] ?? date('Y');
        $type = $filters['type'] ?? 'all';
        $limit = $filters['limit'] ?? 100;

        $sql = "
            SELECT bt.*, 
                   CASE 
                       WHEN bt.type = 'grant' THEN 'منحة'
                       WHEN bt.type = 'loan' THEN 'سلفة'
                       WHEN bt.type = 'installment' THEN 'قسط مردود'
                       ELSE 'أخرى'
                   END as type_label,
                   CASE WHEN bt.is_deduct = 1 THEN 'خصم' ELSE 'إضافة' END as direction,
                   CASE WHEN bt.is_deduct = 1 THEN bt.amount ELSE 0 END as debit,
                   CASE WHEN bt.is_deduct = 0 THEN bt.amount ELSE 0 END as credit
            FROM budget_transactions bt
            WHERE strftime('%Y', bt.transaction_date) = ?
        ";
        $params = [(string)$year];
        if ($type != 'all') {
            $sql .= " AND bt.type = ?";
            $params[] = $type;
        }
        $sql .= " ORDER BY bt.transaction_date DESC LIMIT ?";
        $params[] = $limit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}

if (!function_exists('getBudgetYears')) {
    function getBudgetYears($pdo) {
        $stmt = $pdo->query("SELECT DISTINCT year FROM social_budget ORDER BY year DESC");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}

if (!function_exists('getBudgetSummary')) {
    function getBudgetSummary($pdo) {
        $stmt = $pdo->query("
            SELECT id, year, initial_budget, remaining_budget,
                   (SELECT COALESCE(SUM(amount), 0) FROM budget_transactions WHERE strftime('%Y', transaction_date) = year AND is_deduct = 1) as total_expenses,
                   (SELECT COALESCE(SUM(amount), 0) FROM budget_transactions WHERE strftime('%Y', transaction_date) = year AND is_deduct = 0) as total_refunds
            FROM social_budget
            ORDER BY year DESC
        ");
        return $stmt->fetchAll();
    }
}

if (!function_exists('formatCurrency')) {
    function formatCurrency($amount) {
        return number_format($amount, 2) . ' دج';
    }
}

if (!function_exists('redirectBudget')) {
    function redirectBudget($path = 'dashboard.php', $params = []) {
        $query = !empty($params) ? '?' . http_build_query($params) : '';
        header("Location: $path$query");
        exit;
    }
}

if (!function_exists('setToast')) {
    // إضافة دالة setToast إذا لم تكن موجودة
    function setToast($message, $type = 'success', $duration = 3000) {
        $_SESSION['toast'] = ['message' => $message, 'type' => $type, 'duration' => $duration];
    }
}