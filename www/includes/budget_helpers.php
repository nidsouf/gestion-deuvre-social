<?php
/**
 * budget_helpers.php - دوال مساعدة لوحدة الميزانية الاجتماعية
 * ============================================================
 */

if (!function_exists('getBudgetStats')) {
    /**
     * الحصول على إحصائيات الميزانية لسنة معينة
     */
    /**
 * الحصول على إحصائيات الميزانية لسنة معينة
 * @param PDO $pdo
 * @param int $year
 * @return array
 */
function getBudgetStats($pdo, $year) {
    // جلب الميزانية الأولية والمتبقية من social_budget
    $stmt = $pdo->prepare("SELECT initial_budget, remaining_budget FROM social_budget WHERE year = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$year]);
    $budget = $stmt->fetch();
    
    if (!$budget) {
        return [
            'initial' => 0,
            'remaining' => 0,
            'total_expenses' => 0,
            'total_refunds' => 0,
            'total_loans' => 0,
            'total_grants' => 0,
            'total_installments' => 0,
            'spent_percent' => 0
        ];
    }
    
    // حساب إجمالي الصرف والاسترجاعات من budget_transactions (جميع المعاملات)
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN is_deduct = 1 THEN amount ELSE 0 END), 0) AS total_expenses,
            COALESCE(SUM(CASE WHEN is_deduct = 0 THEN amount ELSE 0 END), 0) AS total_refunds,
            COALESCE(SUM(CASE WHEN type = 'loan' AND is_deduct = 1 THEN amount ELSE 0 END), 0) AS total_loans,
            COALESCE(SUM(CASE WHEN type = 'grant' AND is_deduct = 1 THEN amount ELSE 0 END), 0) AS total_grants,
            COALESCE(SUM(CASE WHEN type = 'installment' AND is_deduct = 0 THEN amount ELSE 0 END), 0) AS total_installments
        FROM budget_transactions
        WHERE strftime('%Y', transaction_date) = ?
    ");
    $stmt->execute([$year]);
    $stats = $stmt->fetch();
    
    // حساب النسبة المئوية المستخدمة
    $spentPercent = $budget['initial_budget'] > 0 
        ? round(($stats['total_expenses'] / $budget['initial_budget']) * 100) 
        : 0;
    
    return [
        'initial' => (float)$budget['initial_budget'],
        'remaining' => (float)$budget['remaining_budget'],
        'total_expenses' => (float)$stats['total_expenses'],
        'total_refunds' => (float)$stats['total_refunds'],
        'total_loans' => (float)$stats['total_loans'],
        'total_grants' => (float)$stats['total_grants'],
        'total_installments' => (float)$stats['total_installments'],
        'spent_percent' => $spentPercent
    ];
}
}

if (!function_exists('getBudgetTransactionsWithDetails')) {
    /**
     * جلب معاملات الميزانية مع الفلاتر
     */
    /**
 * جلب معاملات الميزانية مع تفاصيل إضافية (اسم الموظف، المرجع، تاريخ التسجيل)
 */
/**
 * جلب معاملات الميزانية مع تفاصيل إضافية (اسم الموظف، المرجع، تاريخ التسجيل)
 */
/**
 * جلب معاملات الميزانية مع تفاصيل إضافية (اسم الموظف، المرجع، تاريخ التسجيل)
 */
function getBudgetTransactionsWithDetails($pdo, $filters = []) {
    $year = $filters['year'] ?? date('Y');
    $type = $filters['type'] ?? 'all';
    $limit = $filters['limit'] ?? 100;

    $sql = "
        SELECT 
            bt.*,
            CASE 
                WHEN bt.type = 'grant' THEN 'منحة'
                WHEN bt.type = 'loan' THEN 'سلفة'
                WHEN bt.type = 'installment' THEN 'قسط مردود'
                ELSE 'أخرى'
            END as type_label,
            CASE WHEN bt.is_deduct = 1 THEN 'خصم' ELSE 'إضافة' END as direction,
            -- جلب اسم الموظف حسب نوع العملية
            CASE 
                WHEN bt.type = 'loan' THEN (
                    SELECT e.name FROM deductions d 
                    JOIN employees e ON d.employee_id = e.id 
                    WHERE d.id = bt.reference_id
                )
                WHEN bt.type = 'grant' THEN (
                    SELECT e.name FROM employee_grants eg 
                    JOIN employees e ON eg.employee_id = e.id 
                    WHERE eg.id = bt.reference_id
                )
                WHEN bt.type = 'installment' THEN (
                    -- التصحيح: reference_id يشير إلى deductions.id مباشرة
                    SELECT e.name FROM deductions d 
                    JOIN employees e ON d.employee_id = e.id 
                    WHERE d.id = bt.reference_id
                )
                ELSE NULL
            END as employee_name,
            bt.reference_id as ref_id,
            bt.transaction_date as recorded_at
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