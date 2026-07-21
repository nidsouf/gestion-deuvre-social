<?php
if (!function_exists('getTypeLabel')) {
    function getTypeLabel($item) {
        if ($item['source_name'] == 'Djezzy') {
            return '<span class="badge-djezzy">📱 جيزي</span>';
        }
        return $item['is_loan'] ? '💰 سلفة' : '📌 اقتطاع';
    }
}

if (!function_exists('getStatusLabel')) {
    function getStatusLabel($item, $hasUnpaid = false) {
        if ($item['source_name'] == 'Djezzy') {
            return ['text' => '✅ نشط', 'class' => 'status-active'];
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
        foreach ($grouped_items as $item) {
            if (!empty($item['is_loan']) && $item['source_name'] != 'Djezzy') {
                $totalLoans += $item['total_amount'];
            } else {
                $totalDeductions += $item['total_amount'];
            }
        }
        return ['loans' => $totalLoans, 'deductions' => $totalDeductions];
    }
}
// ============================================================
// دوال خاصة بالتقرير الشهري (التجميع والحساب)
// ============================================================

if (!function_exists('getEffectiveAmount')) {
    /**
     * حساب المبلغ الفعلي للقسط مع مراعاة الدفعات المقدمة
     * @param array $item بيانات القسط
     * @param string $report_ym الشهر والسنة (YYYY-MM)
     * @return float المبلغ الفعلي
     */
    function getEffectiveAmount($item, $report_ym) {
        if ($item['type'] == 'djezzy') return $item['monthly_amount'];
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
     * @param array $items قائمة الاقتطاعات (من $all_items)
     * @param string $report_ym الشهر والسنة (YYYY-MM)
     * @return array المصفوفة المجمعة
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
                    'type' => $it['type'],
                ];
            }
            $amount = ($it['type'] == 'djezzy') ? $it['monthly_amount'] : getEffectiveAmount($it, $report_ym);
            $grouped[$key]['total_amount'] += $amount;
        }
        return array_values($grouped);
    }
}