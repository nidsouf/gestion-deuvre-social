<?php
// ============================================
// إعدادات النسخ الاحتياطي لقاعدة البيانات
// ============================================

// المسار الذي تريد حفظ النسخ الاحتياطية فيه
// يمكنك تغيير هذا المسار إلى أي مكان تريده

// أمثلة لمسارات مختلفة:
// 1. داخل مجلد المشروع (المسار الحالي)
define('BACKUP_PATH', 'C:/xampp/htdocs/deductions_system/backups/');

// 2. على سطح المكتب
// define('BACKUP_PATH', 'C:/Users/Admin/Desktop/deductions_backups/');

// 3. على قرص D
// define('BACKUP_PATH', 'D:/backups/deductions/');

// 4. على قرص خارجي
// define('BACKUP_PATH', 'E:/database_backups/');

// 5. مجلد مخصص على النظام
// define('BACKUP_PATH', 'C:/Backups/deductions/');


// عدد النسخ الاحتياطية المراد الاحتفاظ بها
// سيتم حذف النسخ الأقدم تلقائياً عند تجاوز هذا العدد
define('BACKUP_KEEP_COUNT', 30);


// ============================================
// لا تغير ما تحت هذا الخط
// ============================================

// التأكد من وجود斜杠 في نهاية المسار
if (substr(BACKUP_PATH, -1) !== '/' && substr(BACKUP_PATH, -1) !== '\\') {
    define('BACKUP_PATH_FIXED', BACKUP_PATH . '/');
} else {
    define('BACKUP_PATH_FIXED', BACKUP_PATH);
}

// إنشاء مجلد النسخ الاحتياطي إذا لم يكن موجوداً
if (!file_exists(BACKUP_PATH_FIXED)) {
    mkdir(BACKUP_PATH_FIXED, 0777, true);
}
?>