<?php
/**
 * deductions/helpers.php
 * دوال مساعدة لوحدة الاقتطاعات - متوافقة مع هيكل الجدول الفعلي
 */

/**
 * حساب إحصائيات الاقتطاعات (السلف والقروض)
 */
function getDeductionStats(PDO $pdo, $filters = []) {
    $params = [];
    $where = [];

    if (!empty($filters['type'])) {
        if ($filters['type'] === 'loan') {
            $where[] = "d.is_loan = 1";
        } elseif ($filters['type'] === 'salary_advance') {
            $where[] = "d.is_loan = 0";
        }
    }

    if (!empty($filters['status']) && $filters['status'] !== 'all') {
        if ($filters['status'] === 'active') {
            $where[] = "d.end_date >= date('now')";
        } elseif ($filters['status'] === 'completed') {
            $where[] = "d.end_date < date('now')";
        }
    }

    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $query = "
        SELECT 
            COUNT(*) as total_deductions,
            SUM(d.monthly_amount * d.total_months) as total_amount,
            SUM(d.credit_balance) as total_remaining,
            SUM(d.monthly_amount) as total_monthly,
            COUNT(CASE WHEN d.is_loan = 1 THEN 1 END) as loans_count,
            COUNT(CASE WHEN d.is_loan = 0 THEN 1 END) as advances_count,
            SUM(CASE WHEN d.is_loan = 1 THEN d.monthly_amount * d.total_months END) as loans_total,
            SUM(CASE WHEN d.is_loan = 0 THEN d.monthly_amount * d.total_months END) as advances_total
        FROM deductions d
        $whereClause
    ";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * الحصول على قائمة الاقتطاعات مع بيانات الموظفين
 */
/**
 * الحصول على قائمة الاقتطاعات مع بيانات الموظفين والأقساط المحسوبة من monthly_installments
 */
/**
 * الحصول على قائمة الاقتطاعات مع بيانات الموظفين والأقساط المحسوبة من monthly_installments
 */
function getDeductionsList(PDO $pdo, $filters = [], $limit = 50, $offset = 0) {
    $params = [];
    $where = [];

    if (!empty($filters['type'])) {
        if ($filters['type'] === 'loan') {
            $where[] = "d.is_loan = 1";
        } elseif ($filters['type'] === 'salary_advance') {
            $where[] = "d.is_loan = 0";
        }
    }

    if (!empty($filters['employee_id'])) {
        $where[] = "d.employee_id = ?";
        $params[] = $filters['employee_id'];
    }

    if (!empty($filters['status']) && $filters['status'] !== 'all') {
        if ($filters['status'] === 'active') {
            $where[] = "d.end_date >= date('now')";
        } elseif ($filters['status'] === 'completed') {
            $where[] = "d.end_date < date('now')";
        }
    }

    if (!empty($filters['search'])) {
        $where[] = "(e.name LIKE ? OR e.account_number LIKE ?)";
        $search = '%' . $filters['search'] . '%';
        $params[] = $search;
        $params[] = $search;
    }

    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    // داخل دالة getDeductionsList، في استعلام SELECT، أضف source_id و source_name
$query = "
    SELECT 
        d.*,
        s.name as source_name,
        e.name as full_name,
        e.account_number,
        e.category as contract_type,
        CASE 
            WHEN d.end_date < date('now') THEN 'منتهي'
            WHEN d.end_date < date('now', '+30 days') THEN 'ينتهي قريباً'
            ELSE 'نشط'
        END as status,
        COALESCE((
            SELECT COUNT(*) 
            FROM monthly_installments mi 
            WHERE mi.deduction_id = d.id AND mi.is_paid = 1
        ), 0) as paid_count,
        COALESCE((
            SELECT COUNT(*) 
            FROM monthly_installments mi 
            WHERE mi.deduction_id = d.id AND mi.is_paid = 0 AND mi.is_postponed = 0
        ), 0) as unpaid_count,
        COALESCE((
            SELECT COUNT(*) 
            FROM monthly_installments mi 
            WHERE mi.deduction_id = d.id
        ), 0) as total_installments
    FROM deductions d
    JOIN employees e ON d.employee_id = e.id
    JOIN sources s ON d.source_id = s.id
    $whereClause
    ORDER BY d.created_at DESC
    LIMIT ? OFFSET ?
";

    $params[] = $limit;
    $params[] = $offset;

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * الحصول على إجمالي عدد الاقتطاعات (للباجيناشن)
 */
function countDeductions(PDO $pdo, $filters = []) {
    $params = [];
    $where = [];

    if (!empty($filters['type'])) {
        if ($filters['type'] === 'loan') {
            $where[] = "d.is_loan = 1";
        } elseif ($filters['type'] === 'salary_advance') {
            $where[] = "d.is_loan = 0";
        }
    }

    if (!empty($filters['employee_id'])) {
        $where[] = "d.employee_id = ?";
        $params[] = $filters['employee_id'];
    }

    if (!empty($filters['status']) && $filters['status'] !== 'all') {
        if ($filters['status'] === 'active') {
            $where[] = "d.end_date >= date('now')";
        } elseif ($filters['status'] === 'completed') {
            $where[] = "d.end_date < date('now')";
        }
    }

    if (!empty($filters['search'])) {
        $where[] = "(e.name LIKE ? OR e.account_number LIKE ?)";
        $search = '%' . $filters['search'] . '%';
        $params[] = $search;
        $params[] = $search;
    }

    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $query = "
        SELECT COUNT(*) as total
        FROM deductions d
        JOIN employees e ON d.employee_id = e.id
        $whereClause
    ";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

/**
 * الحصول على تفاصيل الاقتطاع مع الأقساط
 */
function getDeductionDetails(PDO $pdo, $deductionId) {
    $stmt = $pdo->prepare("
        SELECT d.*, 
               e.name as full_name, 
               e.account_number,
               e.category as contract_type
        FROM deductions d
        JOIN employees e ON d.employee_id = e.id
        WHERE d.id = ?
    ");
    $stmt->execute([$deductionId]);
    $deduction = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$deduction) {
        return null;
    }

    // محاولة جلب الأقساط من جدول monthly_installments (إن وجد)
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM monthly_installments
            WHERE deduction_id = ?
            ORDER BY month_year ASC
        ");
        $stmt->execute([$deductionId]);
        $deduction['installments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // إنشاء أقساط افتراضية بناءً على البيانات المتاحة
        $installments = [];
        $total = $deduction['total_months'];
        $paid = $deduction['paid_months'];
        $monthly = $deduction['monthly_amount'];
        $start = strtotime($deduction['start_date']);
        
        for ($i = 0; $i < $total; $i++) {
            $monthDate = date('Y-m-01', strtotime("+$i months", $start));
            $isPaid = $i < $paid;
            $installments[] = [
                'id' => null,
                'month_year' => $monthDate,
                'amount' => $monthly,
                'is_paid' => $isPaid ? 1 : 0,
                'is_postponed' => 0,
                'paid_at' => $isPaid ? date('Y-m-d H:i:s') : null,
            ];
        }
        $deduction['installments'] = $installments;
    }

    return $deduction;
}

/**
 * تحديث الميزانية عند حذف اقتطاع (استرجاع المبلغ)
 */
function refundDeductionBudget(PDO $pdo, $deduction) {
    $remaining = $deduction['credit_balance'] ?? 0;
    if ($remaining <= 0) {
        return true;
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO budget_transactions (
                type, reference_id, amount, is_deduct, description, created_at
            ) VALUES (
                'refund', ?, ?, 0, ?, datetime('now')
            )
        ");
        $description = "استرجاع مبلغ الاقتطاع رقم {$deduction['id']} (حذف)";
        $stmt->execute([$deduction['id'], $remaining, $description]);

        $stmt = $pdo->prepare("
            UPDATE social_budget 
            SET remaining_budget = remaining_budget + ?,
                updated_at = datetime('now')
            WHERE id = 1
        ");
        $stmt->execute([$remaining]);

        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        return false;
    }
}