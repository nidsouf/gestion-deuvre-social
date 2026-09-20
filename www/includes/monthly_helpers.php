<?php
// ============================================================
// monthly_helpers.php - دوال مساعدة للتقرير الشهري
// ============================================================

if (!function_exists('getTypeLabel')) {
    function getTypeLabel($item) {
        if ($item['source_name'] == 'هاتف' || $item['type'] == 'phone') {
            return '<span class="badge-phone">📱 هاتف</span>';
        }
        if ($item['source_name'] == 'Djezzy') {
            return '<span class="badge-djezzy">📱 جيزي</span>';
        }
        return $item['is_loan'] ? '💰 سلفة' : '📌 اقتطاع';
    }
}

if (!function_exists('getStatusLabel')) {
    /**
     * إرجاع تسمية الحالة مع الصنف المناسب
     */
    function getStatusLabel($item, $hasUnpaid = false) {
        if ($item['source_name'] == 'هاتف' || $item['source_name'] == 'Djezzy' || $item['type'] == 'phone') {
            return ['text' => '✅ نشط', 'class' => 'status-active'];
        }
        if ($item['is_paid']) {
            return ['text' => '✅ مدفوع', 'class' => 'status-paid'];
        }
        if ($item['is_postponed'] ?? 0) {
            return ['text' => '⏰ مؤجل', 'class' => 'status-postponed'];
        }
        return $hasUnpaid 
            ? ['text' => '✅ نشط', 'class' => 'status-active']
            : ['text' => '✅ مدفوع', 'class' => 'status-paid'];
    }
}

if (!function_exists('totalAmount')) {
    function totalAmount($items) {
        return array_sum(array_column($items, 'total_amount'));
    }
}

if (!function_exists('sortByName')) {
    function sortByName(&$items) {
        usort($items, fn($a, $b) => strcmp($a['employee_name'], $b['employee_name']));
    }
}

if (!function_exists('filterByCategory')) {
    function filterByCategory($items, $category, $exclude = false) {
        return array_values(array_filter($items, function($x) use ($category, $exclude) {
            return $exclude ? $x['category'] !== $category : $x['category'] === $category;
        }));
    }
}

if (!function_exists('formatAmount')) {
    function formatAmount($amount) {
        return number_format($amount, 2) . ' دج';
    }
}

if (!function_exists('redirectMonthly')) {
    function redirectMonthly($year, $month, $source = 0, $employee = 0, $showDjezzy = 1) {
        $params = http_build_query([
            'year' => $year,
            'month' => $month,
            'source_id' => $source,
            'employee_id' => $employee,
            'show_djezzy' => $showDjezzy
        ]);
        header("Location: monthly.php?$params");
        exit;
    }
}

if (!function_exists('calculateTotals')) {
    function calculateTotals($grouped_items) {
        $totalLoans = 0;
        $totalDeductions = 0;
        $totalPhones = 0;
        foreach ($grouped_items as $item) {
            if ($item['source_name'] == 'هاتف' || $item['type'] == 'phone') {
                $totalPhones += $item['total_amount'];
            } elseif (!empty($item['is_loan']) && $item['source_name'] != 'Djezzy') {
                $totalLoans += $item['total_amount'];
            } else {
                $totalDeductions += $item['total_amount'];
            }
        }
        return ['loans' => $totalLoans, 'deductions' => $totalDeductions, 'phones' => $totalPhones];
    }
}

// ============================================================
// دوال خاصة بالتقرير الشهري (التجميع والحساب)
// ============================================================

if (!function_exists('getEffectiveAmount')) {
    /**
     * حساب المبلغ الفعلي للقسط مع مراعاة الدفعات المقدمة
     */
    function getEffectiveAmount($item, $report_ym) {
        if ($item['type'] == 'djezzy' || $item['type'] == 'phone') {
            return $item['monthly_amount'];
        }
        if ($item['is_paid']) {
            return $item['monthly_amount'];
        }
        $monthly = $item['monthly_amount'];
        $pay_date = $item['first_early_payment_date'] ?? null;
        if (!empty($pay_date)) {
            $pay_ym = substr($pay_date, 0, 7);
            if ($pay_ym == $report_ym) {
                return $item['credit_balance'];
            }
            $next_ym = date('Y-m', strtotime($pay_date . ' +1 month'));
            if ($next_ym == $report_ym) {
                $remaining = $monthly - $item['credit_balance'];
                return $remaining < 0 ? 0 : $remaining;
            }
        }
        return $monthly;
    }
}

if (!function_exists('groupItems')) {
    /**
     * تجميع الاقتطاعات حسب (الموظف + المصدر) مع جمع المبالغ
     */
    function groupItems($items, $report_ym) {
        $grouped = [];
        foreach ($items as $it) {
            $key = $it['employee_id'] . '|' . $it['source_name'];
            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'employee_id' => $it['employee_id'],
                    'employee_name' => $it['employee_name'],
                    'category' => $it['category'],
                    'source_name' => $it['source_name'],
                    'total_amount' => 0,
                    'is_loan' => $it['is_loan'] ?? 0,
                    'is_paid' => $it['is_paid'] ?? 0,
                    'is_postponed' => $it['is_postponed'] ?? 0,
                    'type' => $it['type'],
                ];
            }
            $amount = ($it['type'] == 'djezzy' || $it['type'] == 'phone') 
                ? $it['monthly_amount'] 
                : getEffectiveAmount($it, $report_ym);
            $grouped[$key]['total_amount'] += $amount;
            if (!empty($it['is_postponed'])) {
                $grouped[$key]['is_postponed'] = 1;
            }
        }
        return array_values($grouped);
    }
}

// ============================================================
// دوال خاصة بالهواتف
// ============================================================

if (!function_exists('getActivePhonesByEmployee')) {
    /**
     * جلب أرقام الهواتف النشطة لموظف معين
     */
    function getActivePhonesByEmployee($pdo, $employeeId) {
        $stmt = $pdo->prepare("
            SELECT * FROM employee_phone_numbers
            WHERE employee_id = ? AND is_active = 1
        ");
        $stmt->execute([$employeeId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('getAllActivePhones')) {
    /**
     * جلب جميع أرقام الهواتف النشطة
     */
    function getAllActivePhones($pdo) {
        $stmt = $pdo->query("
            SELECT ep.*, e.name as employee_name
            FROM employee_phone_numbers ep
            JOIN employees e ON ep.employee_id = e.id
            WHERE ep.is_active = 1
            ORDER BY e.name
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('getEmployeePhoneTotal')) {
    /**
     * الحصول على إجمالي المبلغ الشهري لكل موظف (تجميع الأرقام النشطة)
     */
    function getEmployeePhoneTotal($pdo, $employeeId) {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(monthly_amount), 0) as total
            FROM employee_phone_numbers
            WHERE employee_id = ? AND is_active = 1
        ");
        $stmt->execute([$employeeId]);
        return (float)$stmt->fetchColumn();
    }
}

if (!function_exists('getEmployeesWithActivePhones')) {
    /**
     * الحصول على قائمة الموظفين الذين لديهم أرقام نشطة مع إجمالي المبالغ
     */
    function getEmployeesWithActivePhones($pdo) {
        $stmt = $pdo->query("
            SELECT 
                e.id,
                e.name,
                COALESCE(SUM(ep.monthly_amount), 0) as total_monthly
            FROM employees e
            JOIN employee_phone_numbers ep ON e.id = ep.employee_id
            WHERE ep.is_active = 1
            GROUP BY e.id
            HAVING total_monthly > 0
            ORDER BY e.name
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}