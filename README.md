# 📋 نظام إدارة الاقتطاعات والمنح الاجتماعية

<div align="center">

![Version](https://img.shields.io/badge/version-2.0-blue)
![PHP](https://img.shields.io/badge/PHP-8.0+-purple)
![SQLite](https://img.shields.io/badge/Database-SQLite-green)
![PWA](https://img.shields.io/badge/PWA-Ready-orange)
![License](https://img.shields.io/badge/license-Proprietary-red)

**نظام متكامل لإدارة الاقتطاعات والمنح الاجتماعية**

**لجنة الخدمات الاجتماعية – مركز التكوين المهني والتمهين الشهيد علي بوسحابة بكوينين**

</div>

---

## 📖 جدول المحتويات

1. [نظرة عامة](#-نظرة-عامة)
2. [المميزات الرئيسية](#-المميزات-الرئيسية)
3. [البنية التقنية](#-البنية-التقنية)
4. [بنية المشروع](#-بنية-المشروع)
5. [الوحدات الأساسية](#-الوحدات-الأساسية)
6. [PWA – التطبيق القابل للتثبيت](#-pwa--التطبيق-القابل-للتثبيت)
7. [REST API](#-rest-api)
8. [مزامنة Google Forms](#-مزامنة-google-forms)
9. [قاعدة البيانات](#-قاعدة-البيانات)
10. [الأمان](#-الأمان)
11. [التثبيت والتشغيل](#-التثبيت-والتشغيل)
12. [الإصلاحات الحديثة](#-الإصلاحات-الحديثة)
13. [التطوير المستقبلي](#-التطوير-المستقبلي)

---

## 🏢 نظرة عامة

نظام إدارة الاقتطاعات والمنح الاجتماعية هو **تطبيق ويب متكامل** مصمم خصيصاً للجنة الخدمات الاجتماعية، يعمل **بدون إنترنت** على بيئة **PHP Desktop**.

### 🎯 الأهداف

- 📊 رقمنة جميع العمليات المالية والإدارية
- 🎨 واجهة عربية أنيقة وسهلة الاستخدام
- 🔒 أمان عالٍ (CSRF، Rate Limiting، Audit Log)
- 📱 قابل للتثبيت على Desktop والجوال (PWA)
- 🔄 متزامن مع Google Forms
- 🤖 تحليل ذكي بـ Gemini/Ollama
- 📴 يعمل بدون إنترنت

### 📌 معلومات المشروع

| الحقل | القيمة |
|-------|--------|
| **الإصدار** | 2.0 |
| **البيئة** | PHP Desktop |
| **جذر المشروع** | `C:\gestiondeuvresocial\PHPDesktop\www` |
| **الرابط المحلي** | `http://127.0.0.1:57489/` |
| **قاعدة البيانات** | `data/deductions.db` |
| **آخر تحديث** | سبتمبر 2026 |

---

## ✨ المميزات الرئيسية

### 🎨 واجهة المستخدم

- ✅ تصميم عربي RTL كامل
- ✅ بطاقات إحصائية ملونة ومتجاوبة
- ✅ فلاتر ذكية (النقر على البطاقة يفلتر الجدول)
- ✅ الوضع الداكن المتكامل (بنفسجي/أزرق)
- ✅ Modals أنيقة بدل `confirm()`
- ✅ Popover لتفاصيل المعاملات
- ✅ شريط تقدم ملون بصرياً
- ✅ أزرار أيقونات مع Tooltips

### 🔧 الوظائف

- 📋 12 وحدة متكاملة
- 📊 إحصائيات فورية ومحدثة
- 🖨️ طباعة احترافية A4
- 📄 تقارير شهرية/ثلاثية/سنوية
- 💰 إدارة ميزانية سنوية
- 📦 نظام أرشفة بدل الحذف
- 🗄️ نسخ احتياطي واستعادة
- 🧠 تحليل ذكي بالذكاء الاصطناعي

### 🔒 الأمان

- 🛡️ CSRF Token لكل نموذج
- ⏱️ Rate Limiting للعمليات الحساسة
- 📜 Audit Log لكل عملية
- 🔐 Argon2id لتشفير كلمات المرور
- 🚫 Session Validation (IP + UA)
- 🧱 Content Security Policy

### 📱 PWA

- 📲 قابل للتثبيت على Desktop
- 📱 قابل للتثبيت على الجوال
- 📴 يعمل Offline جزئياً
- 🎯 نافذة مستقلة
- 🔗 اختصارات سريعة

---

## 🏗️ البنية التقنية

| المكون | التقنية |
|--------|---------|
| **اللغة الخلفية** | PHP 8+ |
| **قاعدة البيانات** | SQLite 3 |
| **الواجهة الأمامية** | HTML5 + CSS3 + Bootstrap 5 RTL |
| **الأيقونات** | Font Awesome 6 |
| **JavaScript** | jQuery 3.6 + Vanilla JS |
| **الإشعارات** | Toastr |
| **الرسوم البيانية** | Chart.js 4.4 |
| **الذكاء الاصطناعي** | Gemini API + Ollama |
| **PWA** | Service Worker + Manifest |
| **الخطوط** | Cairo (Google Fonts) |

---

## 📁 بنية المشروع

```
www/
├── 📄 index.php                    # لوحة التحكم الرئيسية
├── 📄 login.php                    # تسجيل الدخول
├── 📄 logout.php                   # تسجيل الخروج
├── 📄 manifest.json                # PWA Manifest
├── 📄 sw.js                        # Service Worker
├── 📄 register-sw.js               # تسجيل SW
├── 📄 offline.html                 # صفحة عدم الاتصال
├── 📄 backup.php                   # النسخ الاحتياطي
├── 📄 database_optimize.php        # تحسين قاعدة البيانات
├── 📄 archive_view.php             # عرض الأرشيف
├── 📄 system_info.php              # معلومات النظام
├── 📄 settings.php                 # الإعدادات
├── 📄 audit_log.php                # سجل التدقيق
├── 📄 rules_engine.php             # محرك القواعد
├── 📄 regulations.php              # القوانين الداخلية
├── 📄 API_DOCS.md                  # توثيق API
│
├── 📁 api/
│   └── 📁 v1/
│       ├── config.php              # إعدادات API
│       ├── middleware.php          # التحقق من التوكن
│       ├── auth.php                # تسجيل دخول/خروج
│       ├── me.php                  # معلومات المستخدم
│       ├── dashboard.php           # إحصائيات
│       ├── employees.php           # الموظفون
│       ├── deductions.php          # الاقتطاعات
│       ├── grants.php              # المنح
│       ├── budget.php              # الميزانية
│       ├── requests.php            # الطلبات
│       └── notifications.php       # الإشعارات
│
├── 📁 assets/
│   ├── 📁 css/
│   │   ├── header.css              # الأنماط الرئيسية
│   │   ├── dashboard.css           # لوحة التحكم
│   │   ├── budget.css
│   │   ├── deductions.css
│   │   ├── employees.css
│   │   ├── grants.css
│   │   ├── payments.css
│   │   ├── requests.css
│   │   ├── mobile.css              # أنماط الجوال
│   │   └── dashboard-improvements.css
│   │
│   ├── 📁 js/
│   │   ├── dashboard.js            # سكربت لوحة التحكم
│   │   └── chart.umd.min.js        # Chart.js محلي
│   │
│   ├── 📁 icons/                   # أيقونات PWA
│   │   ├── icon-72.png
│   │   ├── icon-96.png
│   │   ├── icon-128.png
│   │   ├── icon-144.png
│   │   ├── icon-152.png
│   │   ├── icon-192.png
│   │   ├── icon-384.png
│   │   └── icon-512.png
│   │
│   └── 📁 fontawesome/             # Font Awesome محلي
│
├── 📁 config/
│   ├── database.php                # إعدادات PDO
│   └── google_sheets.php           # رابط Google Sheets
│
├── 📁 includes/
│   ├── auth_check.php              # التحقق من الجلسة
│   ├── security.php                # دوال الأمان
│   ├── functions.php               # دوال مساعدة
│   ├── header.php                  # الرأس والقائمة
│   ├── footer.php                  # التذييل
│   ├── budget_helpers.php
│   ├── common_helpers.php
│   ├── grant_helpers.php
│   ├── grant_table.php
│   ├── requests_helpers.php
│   ├── employee_phone_helpers.php
│   └── google_sheet_checker.php    # فحص Google Sheets
│
├── 📁 employees/                   # وحدة الموظفين
│   ├── list.php
│   ├── add.php
│   ├── edit.php
│   ├── view.php
│   └── 📁 phone_numbers/           # أرقام الهواتف
│
├── 📁 deductions/                  # وحدة الاقتطاعات
│   ├── list.php
│   ├── add.php
│   ├── edit.php
│   ├── view.php
│   ├── delete.php
│   ├── print.php                   # طباعة وصل
│   ├── postpone_installment.php
│   ├── postpone_period.php
│   └── early_payment.php
│
├── 📁 grants/                      # وحدة المنح
│   ├── list.php                    # أنواع المنح
│   ├── add.php
│   ├── edit.php
│   ├── delete.php
│   ├── assign.php                  # توزيع منحة
│   ├── employee_list.php           # منح الموظفين
│   ├── edit_employee_grant.php
│   └── delete_employee_grant.php
│
├── 📁 budget/                      # وحدة الميزانية
│   ├── index.php
│   ├── dashboard.php
│   ├── create.php
│   ├── edit.php
│   ├── report.php
│   ├── recalculate.php
│   └── simulation.php
│
├── 📁 payments/                    # وحدة الشيكات
│   ├── list.php
│   ├── add.php
│   ├── edit.php
│   ├── reconcile.php
│   └── report.php
│
├── 📁 requests/                    # وحدة الطلبات
│   ├── index.php
│   ├── add.php
│   ├── view.php
│   ├── review.php
│   ├── approve.php
│   ├── reject.php
│   ├── execute.php
│   ├── cancel.php
│   ├── add_comment.php
│   ├── save_request.php
│   ├── my_requests.php
│   ├── google_requests.php
│   └── sync_from_google.php        # مزامنة Google
│
├── 📁 reports/                     # التقارير
│   ├── monthly.php
│   ├── monthly_comparison.php
│   ├── quarterly.php
│   ├── annual.php
│   ├── meeting_minutes.php
│   └── print_minute.php
│
├── 📁 meals/                       # وحدة المطعم
├── 📁 umrah/                       # وحدة العمرة
├── 📁 honors/                      # وحدة عيد العمال
├── 📁 sources/                     # المصادر
│
├── 📁 data/
│   └── deductions.db               # قاعدة البيانات
│
├── 📁 backups/                     # النسخ الاحتياطية
├── 📁 logs/                        # السجلات
└── 📁 sql/                         # استعلامات SQL
```

---

## 📚 الوحدات الأساسية

### 1️⃣ الموظفون (`employees/`)

**المميزات:**
- قائمة كاملة مع بطاقات إحصائية
- إضافة/تعديل/حذف
- عرض التفاصيل مع **بطاقات اقتطاعات نشطة حسب المصدر**
- إدارة أرقام الهواتف (جيزي/هاتف)
- بحث وفلترة متقدمة

**الملفات الرئيسية:** `list.php`, `add.php`, `edit.php`, `view.php`

---

### 2️⃣ الاقتطاعات (`deductions/`)

**المميزات:**
- سلف وقروض واقتطاعات شهرية
- أقساط تلقائية مع تقدم بصري
- **تأجيل قسط** (نقل إلى آخر المدة)
- **تعديل الفترة** (معاينة قبل التطبيق)
- **تسديد مقدم**
- **طباعة وصل** احترافي مع QR
- تجميع الاقتطاعات حسب المصدر

**الملفات الرئيسية:** `list.php`, `view.php`, `print.php`, `postpone_installment.php`, `postpone_period.php`

---

### 3️⃣ المنح الاجتماعية (`grants/`)

**المميزات:**
- أنواع المنح: **ثابتة** أو **نسبة مئوية مع حد أقصى**
- توزيع على الموظفين
- **بطاقات إحصائية لكل نوع** على حدة
- **ترتيب زمني** (الأحدث أولاً)
- حذف بـ 3 خيارات (استرجاع/بدون استرجاع)
- **طباعة وصل** للمنحة

**الملفات الرئيسية:** `list.php`, `employee_list.php`, `assign.php`

---

### 4️⃣ الميزانية (`budget/`)

**البطاقات الإحصائية:**
- ✅ الميزانية المتبقية
- 💳 إجمالي الشيكات المدفوعة
- 💎 **صافي الميزانية المتاحة** (بعد خصم التزامات المصادر)
- 📌 إجمالي الالتزامات للمصادر
- ✅ صافي الفائض/العجز
- 💸 المصروفات، 💰 الإيرادات
- 📦 استرجاعات الأقساط

**المميزات:**
- **تحليل ذكي** بـ Gemini/Ollama
- **إعادة حساب الميزانية**
- **محاكاة الميزانية**
- تقرير كامل مع Popover

**الملفات الرئيسية:** `dashboard.php`, `report.php`, `recalculate.php`

---

### 5️⃣ الشيكات (`payments/`)

**المميزات:**
- إضافة/تعديل/حذف
- **مطابقة مع الاقتطاعات** (`reconcile.php`)
- **تصنيف الشيكات**: اقتطاع / مشتريات / أخرى
- ربط تلقائي بالميزانية
- تقرير كامل

---

### 6️⃣ طلبات الموظفين (`requests/`)

**المميزات:**
- تقديم/دراسة/موافقة/رفض/تنفيذ
- **مزامنة Google Forms**
- **إشعار المدير** (بريدي + داخلي)
- دعم CSV من Google Sheets
- تعليقات وتاريخ التغييرات

---

### 7️⃣ المحاضر الشهرية (`reports/meeting_minutes.php`)

**المميزات:**
- تحرير شامل للمحضر
- **طباعة A4 مضبوطة**
- **رقم تسلسلي** (`0005/2026`)
- توقيع رئيس اللجنة
- دعم الهواتف + الشيكات + المكرمين + سحب العمرة

---

### 8️⃣ التقارير (`reports/`)

- 📊 **شهري** – الأقساط المستحقة
- 📊 **ثلاثي** – حسب المصدر
- 📊 **سنوي** – ملخص أرباع
- 📊 **مقارنة الأشهر**

---

### 9️⃣ الصيانة

**`backup.php`**:
- إنشاء/استعادة/حذف
- اكتشاف تلقائي لمسار DB
- نسخة أمان قبل كل استعادة

**`database_optimize.php`**:
- VACUUM + ANALYZE
- **أرشفة** بدل الحذف
- حذف الإشعارات القديمة
- نسخة احتياطية إلزامية

**`archive_view.php`**:
- عرض السجلات المؤرشفة
- استعادة/حذف نهائي

---

## 📱 PWA – التطبيق القابل للتثبيت

### 📄 الملفات الرئيسية

| الملف | الوصف |
|-------|-------|
| `manifest.json` | بيانات التطبيق + الأيقونات |
| `sw.js` | Service Worker (Offline) |
| `register-sw.js` | تسجيل + Install Prompt |
| `offline.html` | صفحة عدم الاتصال |
| `assets/icons/*` | أيقونات PWA |

### 🎯 المميزات

- 📲 **زر تثبيت** يظهر تلقائياً
- 📴 **Offline mode** للصفحات المخزّنة
- 🎯 **نافذة مستقلة** بدون شريط Chrome
- 🔗 **اختصارات** في Start Menu
- 🚀 **فتح سريع**

### 🧪 طريقة التثبيت

**الطريقة الأولى (التلقائية):**
- افتح الموقع في Chrome
- انتظر ظهور زر **"📲 تثبيت التطبيق"**
- اضغط عليه

**الطريقة الثانية (اليدوية):**
- ⋮ → **"تثبيت الاقتطاعات..."**

**الطريقة الثالثة:**
- `chrome://apps` → **Install**

### ⚠️ ملاحظات مهمة

- 🔒 **HTTPS مطلوب** للتثبيت على الجوال
- ✅ يعمل على `127.0.0.1` بدون HTTPS للاختبار
- 🌐 استخدم **Cloudflare Tunnel** للجوال
- 💡 **افتح التطبيق من Start Menu** وليس سطح المكتب

---

## 🔌 REST API

### 🔗 Base URL

```
http://127.0.0.1:57489/api/v1/
```

### 🔐 المصادقة

**POST** `/auth.php?action=login`

```json
{
  "username": "admin",
  "password": "your_password",
  "device_name": "Samsung Galaxy S21",
  "device_type": "mobile"
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "token": "abc123...",
    "expires_at": "2026-10-17 10:00:00",
    "user": {
      "id": 1,
      "username": "admin",
      "role": "admin"
    }
  }
}
```

**استخدام التوكن:**
```
Authorization: Bearer abc123...
```

### 📋 قائمة Endpoints

| Method | Endpoint | الوصف |
|--------|----------|-------|
| POST | `/auth.php?action=login` | تسجيل الدخول |
| POST | `/auth.php?action=logout` | تسجيل الخروج |
| POST | `/auth.php?action=refresh` | تجديد التوكن |
| GET | `/me.php` | معلومات المستخدم |
| GET | `/dashboard.php?year=2026` | إحصائيات |
| GET | `/employees.php` | الموظفون |
| GET | `/employees.php?id=5` | موظف واحد |
| POST | `/employees.php` | إضافة موظف |
| PUT | `/employees.php?id=5` | تعديل موظف |
| DELETE | `/employees.php?id=5` | حذف موظف |
| GET | `/deductions.php` | الاقتطاعات |
| GET | `/deductions.php?id=5&installments=1` | مع الأقساط |
| GET | `/requests.php?status=pending` | الطلبات |
| POST | `/requests.php` | تقديم طلب |
| GET | `/notifications.php` | الإشعارات |
| POST | `/notifications.php` | تحديد كمقروء |

### ⏱️ Rate Limiting

- **Login**: 10 محاولات / 5 دقائق
- **باقي endpoints**: 60 طلب / دقيقة

---

## 🔄 مزامنة Google Forms

### 📊 كيف يعمل؟

```
[موظف يملأ الاستمارة]
      ↓
[Google Form]
      ↓
[Google Sheet] ← يُحدَّث تلقائياً
      ↓
[مزامنة يدوية من التطبيق]
      ↓
[قاعدة البيانات]
```

### 🛠️ الملفات الرئيسية

| الملف | الوصف |
|-------|-------|
| `requests/sync_from_google.php` | صفحة المزامنة |
| `config/google_sheets.php` | رابط CSV |
| `includes/google_sheet_checker.php` | فحص دوري |
| `debug_sync.php` | أداة تشخيص |

### 🔧 دالة القراءة المرنة

```php
function getColValue($row, $header, $names, $fallbackIndex = -1) {
    // 1. البحث بالاسم الدقيق
    // 2. البحث المرن (يحتوي على)
    // 3. الفهرس الاحتياطي
}
```

### 📧 إشعار المدير (Apps Script)

```javascript
const ADMIN_EMAIL = 'admin@example.com';

function onFormSubmit(e) {
    // 1. حفظ في Google Sheet
    // 2. إرسال بريد للمدير
    // 3. تحديث حالة المزامنة
}
```

### 🎯 الأعمدة المدعومة

| المتوقع | البدائل |
|---------|---------|
| `اللقب و الاسم` | `الاسم واللقب`, `الاسم` |
| `نوع الطلب` | — |
| `نوع المنحة` | — |
| `المبلغ المطلوب` | `المبلغ` |
| `رقم الهاتف` | — |
| `ملاحظات إضافية` | `ملاحظات` |
| `Horodateur` | `الطابع الزمني` |

---

## 🗃️ قاعدة البيانات

### الجداول الرئيسية

```sql
employees                    -- الموظفون
deductions                   -- الاقتطاعات
monthly_installments         -- الأقساط الشهرية
installment_postponements    -- التأجيلات
early_payments               -- الدفعات المقدمة
grants                       -- أنواع المنح
employee_grants              -- المنح الموزعة
social_budget                -- الميزانية السنوية
budget_transactions          -- المعاملات المالية
meeting_minutes              -- المحاضر
requests                     -- طلبات الموظفين
request_comments             -- تعليقات الطلبات
sync_log                     -- سجل المزامنة
api_tokens                   -- توكنات API
api_rate_limits              -- حدود API
notifications                -- الإشعارات
audit_logs                   -- سجل التدقيق
system_rules                 -- قواعد النظام
source_payments              -- الشيكات
employee_phone_numbers       -- أرقام الهواتف
```

### جداول الأرشفة

```sql
deductions_archive           -- اقتطاعات مؤرشفة
monthly_installments_archive -- أقساط مؤرشفة
```

---

## 🔒 الأمان

| الطبقة | التقنية |
|--------|---------|
| **CSRF** | Token لكل نموذج |
| **XSS** | `htmlspecialchars()` + CSP |
| **SQL Injection** | Prepared Statements (PDO) |
| **Session** | IP + User-Agent Validation |
| **Passwords** | Argon2id |
| **Rate Limiting** | جدول `api_rate_limits` |
| **Audit Log** | جدول `audit_logs` |
| **CSP** | منع السكربتات الخارجية |

---

## ⚙️ التثبيت والتشغيل

### 📥 المتطلبات

- **PHP Desktop** (يحتوي على PHP 8+ و SQLite)
- **متصفح حديث** (Chrome / Edge / Brave)

### 🚀 خطوات التشغيل

#### 1. تحضير المشروع

```
C:\gestiondeuvresocial\PHPDesktop\www\
```

#### 2. تشغيل PHP Desktop

افتح `PHPDesktop.exe` → سيعمل الخادم على المنفذ المعرّف (مثلاً 57489)

#### 3. فتح التطبيق

```
http://127.0.0.1:57489/
```

#### 4. تسجيل الدخول

- **Username:** admin
- **Password:** (المعرّفة في قاعدة البيانات)

#### 5. المزامنة الأولى

- افتح `requests/sync_from_google.php`
- اضغط **"🔄 ابدأ المزامنة"**

### 📦 التثبيت كتطبيق

1. افتح التطبيق في Chrome
2. ⋮ → **"تثبيت الاقتطاعات..."**
3. افتح من **Start Menu**

---

## 🔧 الإصلاحات الحديثة

| # | المشكلة | الحل |
|---|---------|------|
| 1 | `convertUnderThousand` معرّفة مرتين | حماية بـ `function_exists` |
| 2 | `updated_at` غير موجود | استبدال بـ `last_updated` |
| 3 | `deductions` بلا عمود `type` | استخدام `is_loan` |
| 4 | `requests` بلا `employee_name` | إضافة الأعمدة |
| 5 | `employee_id` NOT NULL | إعادة بناء الجدول |
| 6 | زر "تنفيذ الطلب" لا يعمل | استقلالية `executeRequest` |
| 7 | `confirm()` في PHP Desktop | Modals بديلة |
| 8 | CSP يحجب jsdelivr | استخدام cdnjs |
| 9 | `<script>` داخل `<script>` | نقل خارجي |
| 10 | المزامنة 0 طلب | `getColValue()` + Cache Buster |
| 11 | مبالغ "50000 دج" | `cleanAmount()` |
| 12 | `requests` مكررة | `row_hash` محسّن |
| 13 | شيكات لا تظهر | تصنيف + ربط |
| 14 | فارق خاطئ في Reconcile | تصنيف الشيكات |
| 15 | صافي الميزانية غير واضح | بطاقة "صافي متاح" |
| 16 | التوقيع يمين بدل يسار | `direction: ltr` |
| 17 | A4 مضبوط | تحجيم mm |
| 18 | رقم تسلسلي للوصل | `id/year` |
| 19 | ترتيب المنح | `sortGrantsByDateDesc` |
| 20 | PWA Manifest | استبدال القديم |

---

## 🚀 التطوير المستقبلي

### أولوية عالية 🔴

- [ ] 📲 تثبيت PWA على الجوال (Cloudflare Tunnel)
- [ ] 🔔 Push Notifications عبر FCM
- [ ] 📊 تصدير Excel/PDF للتقارير
- [ ] 🔐 2FA (التحقق بخطوتين)

### أولوية متوسطة 🟡

- [ ] 📱 تطبيق React Native / Flutter
- [ ] 📈 مقارنة سنة بسنة
- [ ] 🗓️ جدولة تلقائية للمزامنة
- [ ] 🤖 Chat Widget للتفاعل

### أولوية منخفضة 🟢

- [ ] 🌐 دعم متعدد اللغات (عربي/فرنسي)
- [ ] 📧 إشعارات واتساب/تيليجرام
- [ ] 📊 Chart.js في جميع الوحدات
- [ ] 🧪 PHPUnit للاختبارات

---

## 📊 إحصائيات النظام

| المقياس | العدد |
|---------|-------|
| **ملفات PHP** | 100+ |
| **ملفات API** | 11 |
| **ملفات CSS** | 12 |
| **ملفات JS** | 3 |
| **جداول قاعدة البيانات** | 25+ |
| **وحدات النظام** | 12 |
| **ميزات PWA** | 7 |
| **ميزات أمان** | 8 |
| **تقارير** | 6 |
| **سطور الكود** | 20,000+ |

---

## 💡 نصائح مهمة

### للتشغيل اليومي

- ✅ افتح التطبيق من **Start Menu** أو **Taskbar**
- ❌ تجنّب اختصار سطح المكتب (هش)
- 🔄 شغّل المزامنة **يومياً**

### للصيانة الدورية

- 🔄 **شهرياً:** `database_optimize.php`
- 💾 **أسبوعياً:** نسخة احتياطية
- 📊 **ربع سنوي:** أرشفة الاقتطاعات المنتهية

### للـ PWA

- 🔒 يحتاج **HTTPS** للتثبيت على الجوال
- ✅ يعمل على `127.0.0.1` بدون HTTPS
- 🌐 استخدم **Cloudflare Tunnel** للجوال

---

## 📞 الدعم

**المطوّر:** إنجاز شوقي نيد
**الجهة:** لجنة الخدمات الاجتماعية
**المركز:** مركز التكوين المهني والتمهين الشهيد علي بوسحابة بكوينين

---

## 📌 الخلاصة

**نظام إدارة الاقتطاعات والمنح الاجتماعية** هو تطبيق ويب متكامل، آمن، وسهل الاستخدام، يدير جميع العمليات المالية والإدارية للجنة الخدمات الاجتماعية، مع:

- ✅ **12 وحدة متكاملة**
- ✅ **PWA قابل للتثبيت**
- ✅ **API للجوال**
- ✅ **مزامنة Google Forms**
- ✅ **ذكاء اصطناعي**
- ✅ **أمان عالي**
- ✅ **واجهة عربية أنيقة**

**النسخة:** 2.0 – مستقرة ومتكاملة
**آخر تحديث:** سبتمبر 2026

---

<div align="center">

**🎉 شكراً لك على المتابعة الدقيقة!**

⭐ إذا أعجبك المشروع، لا تنسَ مشاركته مع الآخرين

</div>