<?php
/**
 * دوال الإشعارات للتنبيه بالاقتطاعات المنتهية أو المنتهية قريباً
 * متوافقة مع SQLite
 */

/**
 * جلب الاقتطاعات التي تنتهي خلال 30 يوماً القادمة
 */
function getExpiringDeductions($pdo) {
    $today = date('Y-m-d');
    $nextMonth = date('Y-m-d', strtotime('+30 days'));
    
    $stmt = $pdo->prepare("
        SELECT 
            e.name as employee_name,
            d.monthly_amount,
            d.end_date,
            d.id as deduction_id,
            s.name as source_name,
            CAST(julianday(d.end_date) - julianday(:today) AS INTEGER) as days_left
        FROM deductions d
        JOIN employees e ON d.employee_id = e.id
        JOIN sources s ON d.source_id = s.id
        WHERE d.end_date BETWEEN :today AND :nextMonth
        ORDER BY d.end_date ASC
    ");
    $stmt->execute([
        ':today' => $today,
        ':nextMonth' => $nextMonth
    ]);
    return $stmt->fetchAll();
}

/**
 * جلب الاقتطاعات المنتهية (تاريخ النهاية قبل اليوم)
 */
function getOverdueDeductions($pdo) {
    $today = date('Y-m-d');
    
    $stmt = $pdo->prepare("
        SELECT 
            e.name as employee_name,
            d.monthly_amount,
            d.end_date,
            d.id as deduction_id,
            s.name as source_name,
            CAST(julianday(:today) - julianday(d.end_date) AS INTEGER) as days_overdue
        FROM deductions d
        JOIN employees e ON d.employee_id = e.id
        JOIN sources s ON d.source_id = s.id
        WHERE d.end_date < :today
        ORDER BY d.end_date ASC
    ");
    $stmt->execute([':today' => $today]);
    return $stmt->fetchAll();
}

/**
 * عرض الإشعارات في شريط جانبي (أعلى يمين الصفحة)
 */
function showNotifications($pdo, $basePath = '/deductions_system') {
    $expiring = getExpiringDeductions($pdo);
    $overdue = getOverdueDeductions($pdo);
    
    if (empty($expiring) && empty($overdue)) {
        return '';
    }
    
    $html = '<div style="position: fixed; top: 80px; left: 20px; z-index: 1000; max-width: 350px; direction: rtl;">';
    
    // الإشعارات المنتهية (أولوية أعلى - تظهر بالأحمر)
    foreach ($overdue as $o) {
        $html .= '
        <div style="background-color: #dc3545; color: white; padding: 15px; margin-bottom: 10px; border-radius: 16px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); border-right: 4px solid #ffc107;">
            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                <span style="font-size: 20px;">⚠️</span>
                <strong style="font-size: 16px;">اقتطاع منتهي!</strong>
            </div>
            <div style="margin-right: 28px;">
                <div>👤 الموظف: ' . htmlspecialchars($o['employee_name']) . '</div>
                <div>📁 المصدر: ' . htmlspecialchars($o['source_name']) . '</div>
                <div>📅 تاريخ النهاية: ' . date('d/m/Y', strtotime($o['end_date'])) . '</div>
                <div style="margin: 8px 0; font-weight: bold;">⏰ متأخر ' . $o['days_overdue'] . ' يوماً</div>
                <a href="' . $basePath . '/deductions/postpone.php?id=' . $o['deduction_id'] . '" 
                   style="display: inline-block; margin-top: 5px; color: #ffc107; text-decoration: underline; font-weight: bold;">
                   ⏰ تأجيل هذا الاقتطاع
                </a>
            </div>
        </div>';
    }
    
    // الإشعارات المنتهية قريباً (لون برتقالي)
    foreach ($expiring as $e) {
        $html .= '
        <div style="background-color: #ffc107; color: #333; padding: 15px; margin-bottom: 10px; border-radius: 16px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); border-right: 4px solid #dc3545;">
            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                <span style="font-size: 20px;">🔔</span>
                <strong style="font-size: 16px;">ينتهي قريباً!</strong>
            </div>
            <div style="margin-right: 28px;">
                <div>👤 الموظف: ' . htmlspecialchars($e['employee_name']) . '</div>
                <div>📁 المصدر: ' . htmlspecialchars($e['source_name']) . '</div>
                <div>📅 تاريخ النهاية: ' . date('d/m/Y', strtotime($e['end_date'])) . '</div>
                <div style="margin: 8px 0; font-weight: bold;">⏰ متبقي ' . $e['days_left'] . ' يوماً</div>
                <a href="' . $basePath . '/deductions/postpone.php?id=' . $e['deduction_id'] . '" 
                   style="display: inline-block; margin-top: 5px; color: #b45309; text-decoration: underline; font-weight: bold;">
                   ⏰ تأجيل هذا الاقتطاع
                </a>
            </div>
        </div>';
    }
    
    $html .= '</div>';
    return $html;
}
?>