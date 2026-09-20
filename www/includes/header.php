<?php
// =============================================
// إضافة الأمان عند بدء الصفحة
// =============================================
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/functions.php';

// إرسال رؤوس الأمان
sendSecurityHeaders();

// التحقق من صحة الجلسة إذا كان المستخدم مسجلاً دخولاً
if (isset($_SESSION['user_id']) && !validateSession()) {
    destroySession();
    header("Location: /login.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>نظام إدارة الاقتطاعات</title>
    
    <!-- ✅ مسموح -->
<link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.rtl.min.css" rel="stylesheet"><script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="../assets/fontawesome/css/all.min.css">    
    <!-- Toastr CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css">
    
    <!-- Google Font - Cairo (مناسب للغة العربية) -->
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    
    <!-- ===== أنماط الرأس والشريط الجانبي والوضع الداكن (ملف خارجي) ===== -->
    <link rel="stylesheet" href="../assets/css/header.css">
    
    <!-- jQuery (مطلوب لـ Toastr) -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    
    <!-- Toastr -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
    <!-- ============================================================
     PWA Meta Tags
============================================================ -->
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#2a5298">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="الاقتطاعات">

<!-- Apple Touch Icons -->
<link rel="apple-touch-icon" href="/assets/icons/icon-152.png">
<link rel="apple-touch-icon" sizes="192x192" href="/assets/icons/icon-192.png">
<link rel="icon" type="image/png" sizes="192x192" href="/assets/icons/icon-192.png">

<!-- Mobile Viewport (محسّن) -->
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=yes">

<!-- Mobile CSS -->
<link rel="stylesheet" href="/assets/css/mobile.css">

</head>
<body>

<!-- Toast Container -->
<div id="toast-container" style="position: fixed; top: 20px; left: 20px; z-index: 9999;"></div>

<?php
// عرض رسائل toast المخزنة في الجلسة باستخدام Toastr
if (isset($_SESSION['toast'])) {
    $toast = $_SESSION['toast'];
    echo "<script>
        $(document).ready(function() {
            toastr.options = {
                'closeButton': true,
                'progressBar': true,
                'positionClass': 'toast-top-left',
                'timeOut': " . ($toast['duration'] ?? 3000) . ",
                'rtl': true
            };
            toastr.{$toast['type']}('" . addslashes($toast['message']) . "');
        });
    </script>";
    unset($_SESSION['toast']);
}
?>

<!-- ========== SIDEBAR (الشريط الجانبي) ========== -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <h2>مركز التكوين والتعليم المهنيين</h2>
        <h3>الشهيد علي بوسحابة - بكوينين</h3>
        <hr style="margin: 10px 0; border-color: rgba(255,255,255,0.1);">
        <h2>لجنة الخدمات الاجتماعية</h2>
        <p>إنجاز شـوقي نيـد</p>
    </div>
    
    <ul class="nav-menu">
        <!-- ========== الرئيسية ========== -->
        <li class="nav-item">
            <button class="nav-dropdown-btn" onclick="toggleDropdown(this)">
                <span><i class="fas fa-tachometer-alt icon"></i> الرئيسية</span>
                <i class="fas fa-chevron-down arrow"></i>
            </button>
            <div class="nav-dropdown-content">
                <a href="/index.php" class="nav-link"><i class="fas fa-home"></i> لوحة التحكم</a>
            </div>
        </li>

        <!-- ========== الموارد البشرية ========== -->
        <li class="nav-item">
            <button class="nav-dropdown-btn" onclick="toggleDropdown(this)">
                <span><i class="fas fa-users icon"></i> الموارد البشرية</span>
                <i class="fas fa-chevron-down arrow"></i>
            </button>
            <div class="nav-dropdown-content">
                <a href="/employees/list.php" class="nav-link"><i class="fas fa-list"></i> قائمة الموظفين</a>
                <a href="/employees/add.php" class="nav-link"><i class="fas fa-user-plus"></i> إضافة موظف</a>
                <!-- ========== أرقام الهواتف (جيزي) ========== -->
                <a href="/employees/phone_numbers/index.php" class="nav-link"><i class="fas fa-phone"></i> أرقام الهواتف</a>
            </div>
            
        </li>

        <!-- ========== المالية ========== -->
        <li class="nav-item">
            <button class="nav-dropdown-btn" onclick="toggleDropdown(this)">
                <span><i class="fas fa-chart-line icon"></i> المالية</span>
                <i class="fas fa-chevron-down arrow"></i>
            </button>
            <div class="nav-dropdown-content">
                <a href="/sources/list.php" class="nav-link"><i class="fas fa-database"></i> المصادر</a>
                <a href="/deductions/list.php" class="nav-link"><i class="fas fa-hand-holding-usd"></i> الاقتطاعات</a>
                <a href="/deductions/add.php" class="nav-link"><i class="fas fa-plus-circle"></i> إضافة اقتطاع</a>
                <a href="/grants/list.php" class="nav-link"><i class="fas fa-gift"></i> المنح الاجتماعية</a>
                <a href="/grants/assign.php" class="nav-link"><i class="fas fa-user-check"></i> منح موظف</a>
                <a href="/grants/employee_list.php" class="nav-link"><i class="fas fa-clipboard-list"></i> منح الموظفين</a>
                <a href="/payments/list.php" class="nav-link"><i class="fas fa-list"></i> قائمة الشيكات</a>
                <a href="/payments/add.php" class="nav-link"><i class="fas fa-plus-circle"></i> إضافة شيك</a>
                <a href="/payments/reconcile.php" class="nav-link"><i class="fas fa-balance-scale"></i> مطابقة الشيكات</a>
                <a href="/payments/report.php" class="nav-link"><i class="fas fa-chart-line"></i> تقرير الشيكات</a>
            </div>
        </li>

        <!-- ========== الميزانية ========== -->
        <li class="nav-item">
            <button class="nav-dropdown-btn" onclick="toggleDropdown(this)">
                <span><i class="fas fa-wallet icon"></i> الميزانية</span>
                <i class="fas fa-chevron-down arrow"></i>
            </button>
            <div class="nav-dropdown-content">
                <a href="/budget/dashboard.php" class="nav-link"><i class="fas fa-chart-pie"></i> لوحة الميزانية</a>
                <a href="/budget/simulation.php" class="nav-link"><i class="fas fa-chart-line"></i> محاكاة الميزانية</a>
                <a href="/budget/create.php" class="nav-link"><i class="fas fa-plus-circle"></i> إضافة ميزانية</a>
                <a href="/budget/report.php" class="nav-link"><i class="fas fa-file-alt"></i> تقرير الميزانية</a>
            </div>
        </li>

        <!-- ========== التقارير ========== -->
        <li class="nav-item">
            <button class="nav-dropdown-btn" onclick="toggleDropdown(this)">
                <span><i class="fas fa-chart-bar icon"></i> التقارير</span>
                <i class="fas fa-chevron-down arrow"></i>
            </button>
            <div class="nav-dropdown-content">
                <a href="/reports/monthly.php" class="nav-link"><i class="fas fa-calendar-alt"></i> التقرير الشهري</a>
                <a href="/reports/monthly_comparison.php" class="nav-link"><i class="fas fa-chart-bar"></i> مقارنة الأشهر</a>
                <a href="/reports/meeting_minutes.php" class="nav-link"><i class="fas fa-file-signature"></i> تحرير المحضر الشهري</a>
                <a href="/reports/quarterly.php" class="nav-link"><i class="fas fa-chart-line"></i> التقرير الثلاثي</a>
                <a href="/reports/annual.php" class="nav-link"><i class="fas fa-chart-line"></i> التقرير السنوي</a>
            </div>
        </li>

        <!-- ========== الطلبات ========== -->
        <li class="nav-item">
            <button class="nav-dropdown-btn" onclick="toggleDropdown(this)">
                <span><i class="fas fa-clipboard-list icon"></i> الطلبات</span>
                <i class="fas fa-chevron-down arrow"></i>
            </button>
            <div class="nav-dropdown-content">
                <a href="/requests/index.php" class="nav-link"><i class="fas fa-tasks"></i> إدارة الطلبات</a>
                <a href="/requests/add.php" class="nav-link"><i class="fas fa-plus-circle"></i> تقديم طلب جديد</a>
                <a href="/requests/my_requests.php" class="nav-link"><i class="fas fa-user"></i> طلباتي</a>
                <hr style="margin: 5px 0; border-color: rgba(255,255,255,0.1);">
                <a href="/requests/google_requests.php" class="nav-link"><i class="fab fa-google"></i> طلبات Google Forms</a>
                <a href="/requests/sync_from_google.php" class="nav-link">🔄 مزامنة Google Forms</a>
            </div>
        </li>

        <!-- ========== الخدمات الاجتماعية ========== -->
        <li class="nav-item">
            <button class="nav-dropdown-btn" onclick="toggleDropdown(this)">
                <span><i class="fas fa-hand-holding-heart icon"></i> الخدمات الاجتماعية</span>
                <i class="fas fa-chevron-down arrow"></i>
            </button>
            <div class="nav-dropdown-content">
                <a href="/umrah/draw_list.php" class="nav-link"><i class="fas fa-mosque"></i> سحب العمرة</a>
                <a href="/honors/index.php" class="nav-link"><i class="fas fa-trophy"></i> عيد العمال</a>
            </div>
        </li>

        <!-- ========== وجبات المطعم ========== -->
        <li class="nav-item">
            <button class="nav-dropdown-btn" onclick="toggleDropdown(this)">
                <span><i class="fas fa-utensils icon"></i> وجبات المطعم</span>
                <i class="fas fa-chevron-down arrow"></i>
            </button>
            <div class="nav-dropdown-content">
                <a href="/meals/dashboard.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> لوحة الوجبات</a>
                <a href="/meals/import_monthly.php" class="nav-link"><i class="fas fa-file-import"></i> استيراد المستفيدين</a>
                <a href="/meals/report.php" class="nav-link"><i class="fas fa-chart-line"></i> تقرير منح الوجبات</a>
                <a href="/meals/employee_report.php" class="nav-link"><i class="fas fa-user-chart"></i> سجل منح الموظف</a>
                <a href="/meals/generate_grant.php?month=<?= date('m') ?>&year=<?= date('Y') ?>" class="nav-link" onclick="return confirm('⚠️ توليد منح الوجبات لهذا الشهر؟')">
                    <i class="fas fa-gift"></i> توليد منحة الوجبات
                </a>
            </div>
        </li>

        <!-- ========== التصدير ========== -->
        <li class="nav-item">
            <button class="nav-dropdown-btn" onclick="toggleDropdown(this)">
                <span><i class="fas fa-file-export icon"></i> التصدير</span>
                <i class="fas fa-chevron-down arrow"></i>
            </button>
            <div class="nav-dropdown-content">
                <a href="/meals/export_manager.php" class="nav-link"><i class="fas fa-cog"></i> إدارة التصدير</a>
                <a href="/meals/export_manager.php" class="nav-link"><i class="fas fa-users"></i> تصدير قائمة الموظفين</a>
                <a href="/meals/export_manager.php" class="nav-link"><i class="fas fa-utensils"></i> تصدير المستفيدين</a>
                <a href="/meals/export_manager.php" class="nav-link"><i class="fas fa-chart-line"></i> تصدير تقرير شهري</a>
            </div>
        </li>

        <!-- ========== النظام ========== -->
        <li class="nav-item">
            <button class="nav-dropdown-btn" onclick="toggleDropdown(this)">
                <span><i class="fas fa-cog icon"></i> النظام</span>
                <i class="fas fa-chevron-down arrow"></i>
            </button>
            <div class="nav-dropdown-content">
                <a href="/regulations.php" class="nav-link"><i class="fas fa-book"></i> القوانين الداخلية</a>
                <a href="/backup.php" class="nav-link"><i class="fas fa-database"></i> النسخ الاحتياطي</a>
                <a href="/system_info.php" class="nav-link"><i class="fas fa-info-circle"></i> معلومات النظام</a>
                <a href="/database_optimize.php" class="nav-link"><i class="fas fa-wrench"></i> تحسين قاعدة البيانات</a>
                <a href="/settings.php" class="nav-link"><i class="fas fa-sliders-h"></i> إعدادات النظام</a>
                <a href="/audit_log.php" class="nav-link"><i class="fas fa-history"></i> 📜 سجل التدقيق</a>
                <a href="/rules_engine.php" class="nav-link"><i class="fas fa-cogs"></i> ⚙️ محرك القواعد</a>
            </div>
        </li>

        <!-- ========== الخروج ========== -->
        <li class="nav-item">
            <button class="nav-dropdown-btn" onclick="toggleDropdown(this)">
                <span><i class="fas fa-sign-out-alt icon"></i> حسابي</span>
                <i class="fas fa-chevron-down arrow"></i>
            </button>
            <div class="nav-dropdown-content">
                <a href="/logout.php" class="nav-link"><i class="fas fa-door-open"></i> تسجيل خروج</a>
            </div>
        </li>
    </ul>
</aside>

<!-- ========== MAIN CONTENT ========== -->
<main class="main-content" id="mainContent">
    <div class="top-bar">
        <div class="page-title">
            <button class="toggle-sidebar" id="toggleSidebarBtn" title="تصغير/توسيع القائمة">☰</button>
            <i class="fas fa-tachometer-alt"></i> لجنة الخدمات الاجتماعية - نظام الاقتطاعات
        </div>
        <div class="top-actions">
            <button class="dark-mode-toggle" id="darkModeToggle" title="الوضع الليلي">🌙</button>
            <div class="date-badge"><i class="far fa-calendar-alt"></i> <?= date('d F Y') ?></div>
        </div>
    </div>

<script>
    // ========== تبويبات القوائم المنسدلة ==========
    function toggleDropdown(btn) {
        btn.classList.toggle('active');
        const content = btn.nextElementSibling;
        content.classList.toggle('show');
    }

    // فتح القائمة النشطة حسب الصفحة الحالية
    const currentUrl = window.location.pathname;
    document.querySelectorAll('.nav-dropdown-content .nav-link').forEach(link => {
        if (link.getAttribute('href') === currentUrl) {
            const parentBtn = link.closest('.nav-item').querySelector('.nav-dropdown-btn');
            if (parentBtn) {
                parentBtn.classList.add('active');
                parentBtn.nextElementSibling.classList.add('show');
            }
            link.classList.add('active');
        }
    });

    // ========== طي القائمة الجانبية ==========
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.getElementById('mainContent');
    const toggleBtn = document.getElementById('toggleSidebarBtn');

    if (sidebar && mainContent && toggleBtn) {
        const isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
        if (isCollapsed) {
            sidebar.classList.add('collapsed');
            mainContent.classList.add('expanded');
        }
        toggleBtn.addEventListener('click', () => {
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
            localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
        });
    }

    // ========== دعم الشاشات الصغيرة ==========
    if (window.innerWidth <= 992) {
        sidebar.classList.add('open-mobile');
    }

    // ========== الوضع الليلي ==========
    const darkToggle = document.getElementById('darkModeToggle');
    if (darkToggle) {
        const isDark = localStorage.getItem('darkMode') === 'true';
        if (isDark) document.body.classList.add('dark-mode');
        darkToggle.innerHTML = isDark ? '☀️' : '🌙';
        
        darkToggle.addEventListener('click', () => {
            document.body.classList.toggle('dark-mode');
            const dark = document.body.classList.contains('dark-mode');
            localStorage.setItem('darkMode', dark);
            darkToggle.innerHTML = dark ? '☀️' : '🌙';
        });
    }
</script>
 <!-- ============================================================
     PWA Service Worker Registration
============================================================ -->
<script src="/register-sw.js" defer></script>