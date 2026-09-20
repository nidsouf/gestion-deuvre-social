<?php
/**
 * config/google_sheets.php - إعدادات Google Sheets
 */

// ============================================================
// 🔗 رابط Google Sheet المنشور بصيغة CSV
// ============================================================
// ⚠️ استبدل الرابط أدناه بالرابط الفعلي من Google Sheet
// 
// للحصول على الرابط:
//   1. افتح Google Sheet المرتبط بالاستمارة
//   2. Fichier → Partager → Publier sur le Web
//   3. اختر: Feuille 1 + Format CSV
//   4. اضغط Publier، وانسخ الرابط
//
define('GOOGLE_SHEET_CSV_URL', 'https://docs.google.com/spreadsheets/d/e/2PACX-1vSd2cO-Nf2A6VqVqaA7ZIfgkKew0U7ewpsv9SZMovHnhpioIhOu8fJb2V9kFRqPd8h4XUX-zv0RrCnU/pub?gid=2063996846&single=true&output=csv');

// ============================================================
// ⏱️ مهلة الاتصال بـ Google (بالثواني)
// ============================================================
define('GOOGLE_FETCH_TIMEOUT', 30);

// ============================================================
// 📋 خريطة أسماء الأعمدة (اختياري - للتطوير المستقبلي)
// ============================================================
// يمكن استخدامها إذا أردت ربط الأعمدة برمجياً
// (حالياً لا تُستخدم، لكنها مفيدة للمرجعية)
define('GOOGLE_SHEET_COLUMN_MAP', json_encode([
    'الطابع الزمني'      => 'timestamp',
    'الاسم واللقب'       => 'employee_name',
    'رقم التأجير'        => 'employee_number',
    'نوع الطلب'          => 'request_type',
    'نوع المنحة'         => 'grant_type',
    'المبلغ المطلوب'     => 'amount',
    'رقم الهاتف'         => 'phone',
    'البريد الإلكتروني'  => 'email',
    'رابط الوثيقة'       => 'document_url',
    'ملاحظات إضافية'     => 'notes',
    'حالة المزامنة'      => 'synced'
]));