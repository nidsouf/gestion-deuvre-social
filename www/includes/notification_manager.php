<?php
/**
 * notification_manager.php - نظام إدارة الإشعارات المتقدم
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/security.php';

class NotificationManager {
    private $pdo;
    private $user_id;
    
    public function __construct($pdo, $user_id = null) {
        $this->pdo = $pdo;
        $this->user_id = $user_id ?? ($_SESSION['user_id'] ?? null);
    }
    
    /**
     * إنشاء إشعار جديد
     * @param string $title عنوان الإشعار
     * @param string $message نص الإشعار
     * @param string $type نوع الإشعار (info, success, warning, error, alert)
     * @param string $priority الأولوية (low, normal, high, critical)
     * @param int|null $target_user معرف المستخدم المستهدف (null للجميع)
     * @param string|null $link رابط إضافي
     * @return int معرف الإشعار
     */
    public function create($title, $message, $type = 'info', $priority = 'normal', $target_user = null, $link = null) {
        $stmt = $this->pdo->prepare("
            INSERT INTO notifications (user_id, title, message, type, priority, link, created_at)
            VALUES (?, ?, ?, ?, ?, ?, datetime('now'))
        ");
        $stmt->execute([$target_user, $title, $message, $type, $priority, $link]);
        
        // تسجيل في سجل التدقيق
        audit('NOTIFICATION_CREATED', "Type: $type, Priority: $priority");
        
        // إرسال بريد إلكتروني للإشعارات الحرجة
        if ($priority === 'critical' && $target_user) {
            $this->sendEmailNotification($target_user, $title, $message);
        }
        
        return $this->pdo->lastInsertId();
    }
    
    /**
     * إشعار نجاح سريع
     */
    public function success($title, $message, $target_user = null) {
        return $this->create($title, $message, 'success', 'normal', $target_user);
    }
    
    /**
     * إشعار خطأ سريع
     */
    public function error($title, $message, $target_user = null) {
        return $this->create($title, $message, 'error', 'high', $target_user);
    }
    
    /**
     * إشعار تحذيري سريع
     */
    public function warning($title, $message, $target_user = null) {
        return $this->create($title, $message, 'warning', 'normal', $target_user);
    }
    
    /**
     * إشعار معلوماتي سريع
     */
    public function info($title, $message, $target_user = null) {
        return $this->create($title, $message, 'info', 'low', $target_user);
    }
    
    /**
     * إشعار عاجل (حرج)
     */
    public function alert($title, $message, $target_user = null) {
        return $this->create($title, $message, 'alert', 'critical', $target_user);
    }
    
    /**
     * الحصول على إشعارات المستخدم (غير المقروءة أولاً)
     * @param int $limit عدد الإشعارات
     * @return array قائمة الإشعارات
     */
    public function getNotifications($limit = 50) {
        $sql = "
            SELECT * FROM notifications 
            WHERE user_id IS NULL OR user_id = ? 
            ORDER BY is_read ASC, priority DESC, created_at DESC 
            LIMIT ?
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$this->user_id, $limit]);
        return $stmt->fetchAll();
    }
    
    /**
     * الحصول على الإشعارات غير المقروءة فقط
     */
    public function getUnread($limit = 20) {
        $stmt = $this->pdo->prepare("
            SELECT * FROM notifications 
            WHERE (user_id IS NULL OR user_id = ?) AND is_read = 0 
            ORDER BY priority DESC, created_at DESC 
            LIMIT ?
        ");
        $stmt->execute([$this->user_id, $limit]);
        return $stmt->fetchAll();
    }
    
    /**
     * عدد الإشعارات غير المقروءة
     */
    public function getUnreadCount() {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM notifications 
            WHERE (user_id IS NULL OR user_id = ?) AND is_read = 0
        ");
        $stmt->execute([$this->user_id]);
        return (int)$stmt->fetchColumn();
    }
    
    /**
     * تحديد إشعار كمقروء
     */
    public function markAsRead($notification_id) {
        $stmt = $this->pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?");
        return $stmt->execute([$notification_id]);
    }
    
    /**
     * تحديد جميع إشعارات المستخدم كمقروءة
     */
    public function markAllAsRead() {
        $stmt = $this->pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id IS NULL OR user_id = ?");
        return $stmt->execute([$this->user_id]);
    }
    
    /**
     * حذف إشعار
     */
    public function delete($notification_id) {
        $stmt = $this->pdo->prepare("DELETE FROM notifications WHERE id = ?");
        return $stmt->execute([$notification_id]);
    }
    
    /**
     * حذف الإشعارات القديمة (أكثر من 30 يوم)
     */
    public function cleanupOldNotifications($days = 30) {
        $stmt = $this->pdo->prepare("DELETE FROM notifications WHERE created_at < datetime('now', '-' || ? || ' days')");
        return $stmt->execute([$days]);
    }
    
    /**
     * إرسال إشعار بريد إلكتروني (للاستخدام المستقبلي)
     */
    private function sendEmailNotification($user_id, $title, $message) {
        // يمكن إضافة SMTP هنا لاحقاً
        error_log("CRITICAL NOTIFICATION for user $user_id: $title - $message");
        return true;
    }
    
    /**
     * عرض الإشعارات في واجهة المستخدم (HTML)
     * @param int $limit عدد الإشعارات
     * @return string HTML جاهز
     */
    public function renderNotifications($limit = 10) {
        $notifications = $this->getNotifications($limit);
        if (empty($notifications)) {
            return '<div class="no-notifications">لا توجد إشعارات جديدة</div>';
        }
        
        $html = '<div class="notifications-list">';
        foreach ($notifications as $notif) {
            $icon = $this->getIconByType($notif['type']);
            $bgClass = $notif['is_read'] ? 'read' : 'unread';
            
            $html .= "
                <div class=\"notification-item {$bgClass}\" data-id=\"{$notif['id']}\">
                    <div class=\"notification-icon\">{$icon}</div>
                    <div class=\"notification-content\">
                        <div class=\"notification-title\">" . escape($notif['title']) . "</div>
                        <div class=\"notification-message\">" . escape($notif['message']) . "</div>
                        <div class=\"notification-time\">" . date('d/m/Y H:i', strtotime($notif['created_at'])) . "</div>
                    </div>
                    " . ($notif['is_read'] ? '' : "<button class=\"mark-read-btn\" onclick=\"markNotificationRead({$notif['id']})\">✓</button>") . "
                </div>
            ";
        }
        $html .= '</div>';
        
        if ($this->getUnreadCount() > 0) {
            $html .= '<div class="notifications-footer"><button onclick="markAllNotificationsRead()" class="btn-sm">تحديد الكل كمقروء</button></div>';
        }
        
        return $html;
    }
    
    /**
     * الحصول على أيقونة حسب نوع الإشعار
     */
    private function getIconByType($type) {
        switch ($type) {
            case 'success': return '✅';
            case 'error': return '❌';
            case 'warning': return '⚠️';
            case 'alert': return '🔴';
            default: return 'ℹ️';
        }
    }
    
    /**
     * إحصائيات الإشعارات
     */
    public function getStats() {
        $stmt = $this->pdo->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread,
                SUM(CASE WHEN type = 'success' THEN 1 ELSE 0 END) as success_count,
                SUM(CASE WHEN type = 'error' THEN 1 ELSE 0 END) as error_count,
                SUM(CASE WHEN type = 'warning' THEN 1 ELSE 0 END) as warning_count,
                SUM(CASE WHEN priority = 'critical' THEN 1 ELSE 0 END) as critical_count
            FROM notifications 
            WHERE user_id IS NULL OR user_id = ?
        ");
        $stmt->execute([$this->user_id]);
        return $stmt->fetch();
    }
    
    /**
     * البحث في الإشعارات
     */
    public function search($keyword, $limit = 20) {
        $stmt = $this->pdo->prepare("
            SELECT * FROM notifications 
            WHERE (user_id IS NULL OR user_id = ?) 
              AND (title LIKE ? OR message LIKE ?)
            ORDER BY created_at DESC LIMIT ?
        ");
        $stmt->execute([$this->user_id, "%$keyword%", "%$keyword%", $limit]);
        return $stmt->fetchAll();
    }
}

// إنشاء كائن مدير الإشعارات للاستخدام السريع
$notificationManager = new NotificationManager($pdo);

// دالة مساعدة لإضافة إشعار بسرعة
function add_notification($title, $message, $type = 'info', $priority = 'normal') {
    global $notificationManager;
    return $notificationManager->create($title, $message, $type, $priority);
}

// دالة عرض الإشعارات في أي صفحة
function render_notifications($limit = 10) {
    global $notificationManager;
    return $notificationManager->renderNotifications($limit);
}
?>