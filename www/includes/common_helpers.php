<?php
/**
 * common_helpers.php - دوال مشتركة لجميع وحدات النظام
 * ============================================================
 */

// ============================================================
// دوال التواريخ
// ============================================================

if (!function_exists('safeFormatDate')) {
    function safeFormatDate($date) {
        if (empty($date) || $date === '0000-00-00') return '—';
        return date('d/m/Y', strtotime($date));
    }
}

if (!function_exists('formatDateTime')) {
    function formatDateTime($datetime) {
        if (empty($datetime)) return '—';
        return date('d/m/Y H:i', strtotime($datetime));
    }
}

if (!function_exists('getMonthNameArabic')) {
    function getMonthNameArabic($month) {
        $months = [1=>'جانفي',2=>'فيفري',3=>'مارس',4=>'أفريل',5=>'ماي',6=>'جوان',7=>'جويلية',8=>'أوت',9=>'سبتمبر',10=>'أكتوبر',11=>'نوفمبر',12=>'ديسمبر'];
        return $months[(int)$month] ?? '';
    }
}

if (!function_exists('getArabicDays')) {
    function getArabicDays() {
        return [
            'Monday' => 'الاثنين',
            'Tuesday' => 'الثلاثاء',
            'Wednesday' => 'الأربعاء',
            'Thursday' => 'الخميس',
            'Friday' => 'الجمعة',
            'Saturday' => 'السبت',
            'Sunday' => 'الأحد'
        ];
    }
}

if (!function_exists('formatDateArabic')) {
    function formatDateArabic($date) {
        if (empty($date) || $date === '0000-00-00') return '—';
        $ts = strtotime($date);
        $dayEn = date('l', $ts);
        $days = getArabicDays();
        $dayAr = $days[$dayEn] ?? $dayEn;
        $dayNum = date('d', $ts);
        $monthNum = (int)date('m', $ts);
        $monthAr = getMonthNameArabic($monthNum);
        $year = date('Y', $ts);
        return "$dayAr $dayNum $monthAr $year";
    }
}

// ============================================================
// دوال التنسيق (مبالغ، أرقام)
// ============================================================

if (!function_exists('formatAmount')) {
    function formatAmount($amount, $currency = 'دج') {
        return number_format($amount, 2) . ' ' . $currency;
    }
}

if (!function_exists('formatNumber')) {
    function formatNumber($number, $decimals = 2) {
        return number_format($number, $decimals);
    }
}

// ============================================================
// دوال التوجيه
// ============================================================

if (!function_exists('redirectTo')) {
    function redirectTo($path, $params = []) {
        $query = !empty($params) ? '?' . http_build_query($params) : '';
        header("Location: $path$query");
        exit;
    }
}

if (!function_exists('redirectBack')) {
    function redirectBack() {
        if (isset($_SERVER['HTTP_REFERER'])) {
            header("Location: " . $_SERVER['HTTP_REFERER']);
        } else {
            header("Location: index.php");
        }
        exit;
    }
}

// ============================================================
// دوال الجلسة (Session)
// ============================================================

if (!function_exists('setToast')) {
    function setToast($message, $type = 'success', $duration = 3000) {
        $_SESSION['toast'] = [
            'message' => $message,
            'type' => $type,
            'duration' => $duration
        ];
    }
}

if (!function_exists('hasToast')) {
    function hasToast() {
        return isset($_SESSION['toast']);
    }
}

if (!function_exists('getToast')) {
    function getToast() {
        if (isset($_SESSION['toast'])) {
            $toast = $_SESSION['toast'];
            unset($_SESSION['toast']);
            return $toast;
        }
        return null;
    }
}

// ============================================================
// دوال التجميع والتصفية
// ============================================================

if (!function_exists('groupByKey')) {
    function groupByKey($items, $key) {
        $grouped = [];
        foreach ($items as $item) {
            $k = $item[$key] ?? 'unknown';
            if (!isset($grouped[$k])) {
                $grouped[$k] = [];
            }
            $grouped[$k][] = $item;
        }
        return $grouped;
    }
}

if (!function_exists('sumColumn')) {
    function sumColumn($items, $column) {
        return array_sum(array_column($items, $column));
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

if (!function_exists('totalAmount')) {
    function totalAmount($items) {
        return array_sum(array_column($items, 'total_amount'));
    }
}

// ============================================================
// دوال البادجات (للأيقونات)
// ============================================================

if (!function_exists('badgeStatus')) {
    function badgeStatus($type, $text) {
        $classes = [
            'active' => 'badge-active',
            'paid' => 'badge-paid',
            'postponed' => 'badge-postponed',
            'expired' => 'badge-expired',
            'expiring' => 'badge-expiring',
            'djezzy' => 'badge-djezzy',
            'loan' => 'badge-loan',
            'normal' => 'badge-normal',
            'percentage' => 'badge-percentage',
            'fixed' => 'badge-fixed',
        ];
        $class = $classes[$type] ?? 'badge-default';
        return '<span class="' . $class . '">' . htmlspecialchars($text) . '</span>';
    }
}

// ============================================================
// دوال إضافية (مفيدة)
// ============================================================

if (!function_exists('sanitizeInput')) {
    function sanitizeInput($input) {
        return trim(htmlspecialchars($input, ENT_QUOTES, 'UTF-8'));
    }
}

if (!function_exists('sanitizeArray')) {
    function sanitizeArray($array) {
        $result = [];
        foreach ($array as $key => $value) {
            $result[$key] = is_string($value) ? sanitizeInput($value) : $value;
        }
        return $result;
    }
}

if (!function_exists('isActiveStatus')) {
    function isActiveStatus($end_date) {
        return strtotime($end_date) >= strtotime(date('Y-m-d'));
    }
}

if (!function_exists('getRemainingDays')) {
    function getRemainingDays($end_date) {
        return (int)((strtotime($end_date) - strtotime(date('Y-m-d'))) / (60 * 60 * 24));
    }
}

if (!function_exists('generateRandomString')) {
    function generateRandomString($length = 10) {
        return substr(str_shuffle('0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'), 0, $length);
    }
}