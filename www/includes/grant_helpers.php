<?php
/**
 * grant_helpers.php - دوال مساعدة لقائمة المنح
 * ============================================================
 */

if (!function_exists('getGrantStatusLabel')) {
    /**
     * الحصول على تسمية حالة المنحة
     */
    function getGrantStatusLabel($item) {
        if ($item['calculation_type'] == 'percentage') {
            return [
                'text' => '🧮 محسوبة من الفاتورة',
                'class' => 'badge-percentage'
            ];
        }
        return [
            'text' => '✅ محدث',
            'class' => 'badge-fixed'
        ];
    }
}

if (!function_exists('getGrantBadge')) {
    /**
     * الحصول على بادج نوع المنحة (ثابت / نسبة مئوية)
     */
    function getGrantBadge($item) {
        if ($item['calculation_type'] == 'percentage') {
            return '<span class="badge-percentage">نسبة</span>';
        }
        return '<span class="badge-fixed">ثابت</span>';
    }
}

if (!function_exists('getGrantTypeLabel')) {
    /**
     * الحصول على تسمية نوع المنحة (سلفة / اقتطاع)
     */
    function getGrantTypeLabel($item) {
        if ($item['is_loan']) {
            return '<span class="badge-loan">💰 سلفة</span>';
        }
        return '<span class="badge-normal">📌 اقتطاع</span>';
    }
}

if (!function_exists('formatGrantAmount')) {
    /**
     * تنسيق مبلغ المنحة مع العملة
     */
    function formatGrantAmount($amount) {
        return number_format($amount, 2) . ' دج';
    }
}

if (!function_exists('sortGrantsByName')) {
    /**
     * ترتيب المنح حسب اسم الموظف
     */
    function sortGrantsByName(&$items) {
        usort($items, fn($a, $b) => strcmp($a['employee_name'], $b['employee_name']));
    }
}

if (!function_exists('filterGrantsByCategory')) {
    /**
     * تصفية المنح حسب فئة الموظف (دائم / متعاقد)
     */
    function filterGrantsByCategory($items, $category) {
        return array_values(array_filter($items, fn($x) => $x['category'] == $category));
    }
}

if (!function_exists('calculateGrantTotal')) {
    /**
     * حساب إجمالي مبالغ المنح (باستخدام stored_amount أو current_amount)
     */
    function calculateGrantTotal($items) {
        $total = 0;
        foreach ($items as $item) {
            $amount = ($item['stored_amount'] > 0) ? $item['stored_amount'] : $item['current_amount'];
            $total += $amount;
        }
        return $total;
    }
}

if (!function_exists('redirectGrants')) {
    /**
     * إعادة التوجيه إلى قائمة المنح مع الحفاظ على الفلاتر
     */
    function redirectGrants($search = '', $grant_filter = 0) {
        $params = http_build_query([
            'search' => $search,
            'grant_id' => $grant_filter
        ]);
        header("Location: employee_list.php?$params");
        exit;
    }
}