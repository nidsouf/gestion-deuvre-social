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
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="../assets/fontawesome/css/all.min.css">    
    <!-- Toastr CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css">
    
    <!-- Google Font - Cairo (مناسب للغة العربية) -->
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    
    <!-- jQuery (مطلوب لـ Toastr) -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
    
    <style>
        /* =========================================================
           MODERN ADMIN UI - PROFESSIONAL GREEN EDITION
           تصميم احترافي هادئ ومتوازن
        ========================================================= */

        /* ========== ROOT COLORS ========== */
        :root{
            --primary:#1E5A4A;
            --primary-light:#2E7D64;
            --primary-soft:#4E9B84;
            --primary-dark:#0F3D32;

            --background:#F4F7F6;
            --surface:#FFFFFF;

            --text:#18352A;
            --text-light:#5F746B;

            --border:#DDE7E2;

            --hover:#F0F6F3;

            --shadow-sm:0 2px 6px rgba(0,0,0,0.04);
            --shadow-md:0 6px 18px rgba(0,0,0,0.08);
            --shadow-lg:0 10px 28px rgba(0,0,0,0.10);

            --radius:16px;
            --radius-sm:12px;

            --transition:all .25s ease;
        }

        /* ========== RESET ========== */
        *{
            margin:0;
            padding:0;
            box-sizing:border-box;
        }

        html{
            scroll-behavior:smooth;
        }

        body{
            font-family:'Cairo',sans-serif;
            background:var(--background);
            color:var(--text);
            min-height:100vh;
            line-height:1.7;
            overflow-x:hidden;
        }

        /* =========================================================
           SIDEBAR
        ========================================================= */

        .sidebar{
            position:fixed;
            top:0;
            right:0;
            width:280px;
            height:100vh;

            background:linear-gradient(
                180deg,
                #1A4D3F 0%,
                #1E5A4A 45%,
                #2A715D 100%
            );

            color:#fff;
            z-index:1000;
            overflow-y:auto;
            transition:var(--transition);
            box-shadow:-6px 0 25px rgba(0,0,0,0.08);
        }

        /* Scrollbar */
        .sidebar::-webkit-scrollbar{
            width:5px;
        }
        .sidebar::-webkit-scrollbar-track{
            background:rgba(255,255,255,0.08);
            border-radius:10px;
        }
        .sidebar::-webkit-scrollbar-thumb{
            background:rgba(255,255,255,0.25);
            border-radius:10px;
        }
        .sidebar::-webkit-scrollbar-thumb:hover{
            background:rgba(255,255,255,0.35);
        }

        .sidebar-header{
            padding:26px 20px;
            text-align:center;
            border-bottom:1px solid rgba(255,255,255,0.08);
        }

        .sidebar-header h2{
            font-size:17px;
            font-weight:800;
            margin-bottom:6px;
            line-height:1.6;
            letter-spacing:-0.3px;
        }

        .sidebar-header h3{
            font-size:13px;
            font-weight:600;
            color:#D7F1E7;
            margin-bottom:12px;
        }

        .sidebar-header p{
            font-size:12px;
            opacity:.8;
        }

        /* =========================================================
           NAVIGATION
        ========================================================= */

        .nav-menu{
            list-style:none;
            padding:18px 14px;
        }

        .nav-item{
            margin-bottom:10px;
        }

        .nav-dropdown-btn{
            width:100%;
            border:none;
            outline:none;
            background:rgba(255,255,255,0.06);
            color:#fff;
            display:flex;
            align-items:center;
            justify-content:space-between;
            padding:14px 16px;
            border-radius:14px;
            cursor:pointer;
            transition:var(--transition);
            font-family:'Cairo',sans-serif;
            font-size:14px;
            font-weight:700;
        }

        .nav-dropdown-btn:hover{
            background:rgba(255,255,255,0.12);
            transform:translateX(-3px);
        }

        /* تحسين: الزر النشط بشفافية خفيفة بدلاً من الأبيض المبهر */
        .nav-dropdown-btn.active{
            background:rgba(255,255,255,0.15);
            color:#D7F1E7;
            border:1px solid rgba(255,255,255,0.2);
            box-shadow:var(--shadow-sm);
        }

        .nav-dropdown-btn .icon{
            margin-left:10px;
            font-size:16px;
        }

        .nav-dropdown-btn .arrow{
            transition:transform .25s ease;
        }

        .nav-dropdown-btn.active .arrow{
            transform:rotate(180deg);
        }

        /* =========================================================
           DROPDOWN CONTENT
        ========================================================= */

        .nav-dropdown-content{
            max-height:0;
            overflow:hidden;
            transition:max-height .35s ease;
            margin-top:6px;
            padding-right:8px;
        }

        .nav-dropdown-content.show{
            max-height:500px;
        }

        .nav-link{
            display:flex;
            align-items:center;
            gap:10px;
            text-decoration:none;
            color:#EAF5F0;
            padding:11px 14px;
            margin:6px 0;
            border-radius:12px;
            font-size:13px;
            font-weight:600;
            transition:var(--transition);
        }

        .nav-link:hover{
            background:rgba(255,255,255,0.12);
            color:#fff;
            transform:translateX(-3px);
        }

        .nav-link.active{
            background:#fff;
            color:var(--primary);
            box-shadow:var(--shadow-sm);
        }

        .nav-link i{
            width:18px;
            text-align:center;
        }

        /* =========================================================
           SIDEBAR COLLAPSED - مع تحسين الحركة
        ========================================================= */

        .sidebar.collapsed{
            width:78px;
        }

        .sidebar.collapsed .sidebar-header h2,
        .sidebar.collapsed .sidebar-header h3,
        .sidebar.collapsed .sidebar-header p,
        .sidebar.collapsed .nav-dropdown-btn span,
        .sidebar.collapsed .arrow,
        .sidebar.collapsed .nav-dropdown-content{
            display:none;
        }

        .sidebar.collapsed .nav-item{
            display:flex;
            justify-content:center;
        }

        .sidebar.collapsed .nav-dropdown-btn{
            width:50px;
            height:50px;
            padding:0;
            justify-content:center;
            transition:all 0.2s ease;
        }

        .sidebar.collapsed .icon{
            margin:0;
            font-size:18px;
        }

        /* =========================================================
           MAIN CONTENT
        ========================================================= */

        .main-content{
            margin-right:280px;
            padding:24px;
            transition:var(--transition);
        }

        .main-content.expanded{
            margin-right:78px;
        }

        /* =========================================================
           TOP BAR
        ========================================================= */

        .top-bar{
            background:var(--surface);
            border-radius:18px;
            padding:18px 24px;
            display:flex;
            justify-content:space-between;
            align-items:center;
            margin-bottom:25px;
            box-shadow:var(--shadow-md);
            border:1px solid var(--border);
        }

        .page-title{
            display:flex;
            align-items:center;
            gap:12px;
            font-size:24px;
            font-weight:800;
            color:var(--primary);
        }

        .page-title i{
            width:50px;
            height:50px;
            display:flex;
            align-items:center;
            justify-content:center;
            border-radius:14px;
            background:linear-gradient(135deg, var(--primary), var(--primary-light));
            color:#fff;
            font-size:22px;
        }

        .top-actions{
            display:flex;
            align-items:center;
            gap:12px;
        }

        .date-badge{
            background:var(--hover);
            padding:10px 18px;
            border-radius:12px;
            font-size:13px;
            font-weight:700;
            color:var(--primary);
            border:1px solid var(--border);
        }

        /* =========================================================
           BUTTONS - تحسين حجم الخط للهواتف
        ========================================================= */

        .toggle-sidebar,
        .dark-mode-toggle{
            width:44px;
            height:44px;
            border:none;
            outline:none;
            border-radius:12px;
            background:var(--hover);
            color:var(--primary);
            cursor:pointer;
            display:flex;
            align-items:center;
            justify-content:center;
            font-size:18px;
            transition:var(--transition);
            border:1px solid var(--border);
        }

        .toggle-sidebar:hover,
        .dark-mode-toggle:hover{
            background:var(--primary);
            color:#fff;
            transform:translateY(-2px);
        }

        /* =========================================================
           CARDS
        ========================================================= */

        .card{
            background:#fff;
            border-radius:18px;
            padding:22px;
            box-shadow:var(--shadow-sm);
            border:1px solid var(--border);
            transition:var(--transition);
        }

        .card:hover{
            transform:translateY(-3px);
            box-shadow:var(--shadow-md);
        }

        /* =========================================================
           TABLES
        ========================================================= */

        table{
            width:100%;
            border-collapse:collapse;
        }

        table th{
            background:#F6FAF8;
            color:var(--primary);
            font-weight:800;
            padding:14px;
            border-bottom:1px solid var(--border);
        }

        table td{
            padding:14px;
            border-bottom:1px solid #EEF3F1;
            font-size:14px;
        }

        table tr:hover{
            background:#FAFCFB;
        }

        /* =========================================================
           FORM ELEMENTS
        ========================================================= */

        input,
        select,
        textarea{
            width:100%;
            padding:12px 14px;
            border-radius:12px;
            border:1px solid var(--border);
            background:#fff;
            font-family:'Cairo',sans-serif;
            transition:var(--transition);
        }

        input:focus,
        select:focus,
        textarea:focus{
            border-color:var(--primary-light);
            outline:none;
            box-shadow:0 0 0 4px rgba(46,125,100,0.12);
        }

        /* =========================================================
           BUTTONS STYLE
        ========================================================= */

        .btn{
            border:none;
            padding:12px 18px;
            border-radius:12px;
            cursor:pointer;
            font-family:'Cairo',sans-serif;
            font-weight:700;
            transition:var(--transition);
        }

        .btn-primary{
            background:linear-gradient(135deg, var(--primary), var(--primary-light));
            color:#fff;
        }

        .btn-primary:hover{
            transform:translateY(-2px);
            box-shadow:var(--shadow-md);
        }

        /* =========================================================
           TOASTR
        ========================================================= */

        .toast{
            border-radius:14px !important;
            box-shadow:var(--shadow-lg) !important;
            font-family:'Cairo',sans-serif !important;
        }

        .toast-success{
            background:#1E5A4A !important;
        }
        .toast-error{
            background:#C0392B !important;
        }
        .toast-warning{
            background:#D68910 !important;
        }
        .toast-info{
            background:#2471A3 !important;
        }

        /* =========================================================
   DARK MODE - تحسين التباين والوضوح
========================================================= */

body.dark-mode{
    --background:#0F1714;
    --surface:#1A2A24;      /* أفتح قليلاً من السابق */
    --text:#F0F7F4;         /* أبيض مائل للأخضر الفاتح */
    --text-light:#C5DDD4;   /* رمادي فاتح للوضوح */
    --border:#2A423A;       /* حدود أفتح قليلاً */
    --hover:#243A32;
}

body.dark-mode .sidebar{
    background:linear-gradient(180deg, #0F2A22, #14362C, #1A4538);
}

body.dark-mode .top-bar,
body.dark-mode .card,
body.dark-mode .stat-card,
body.dark-mode .filters,
body.dark-mode .form-container,
body.dark-mode .data-table{
    background:var(--surface);
    color:var(--text);
}

/* تحسين الجداول في الوضع الليلي */
body.dark-mode .data-table th{
    background:#1E3A32;
    color:#E0F0EB;
    font-weight:700;
}

body.dark-mode .data-table td{
    color:var(--text);
    border-bottom-color:var(--border);
}

body.dark-mode .data-table tr:hover{
    background:var(--hover);
}

/* تحسين حقول الإدخال */
body.dark-mode input,
body.dark-mode select,
body.dark-mode textarea{
    background:#0F1F1A;
    color:#F0F7F4;
    border-color:var(--border);
}

body.dark-mode input::placeholder,
body.dark-mode select::placeholder,
body.dark-mode textarea::placeholder{
    color:#8AAFA3;
}

body.dark-mode input:focus,
body.dark-mode select:focus,
body.dark-mode textarea:focus{
    border-color:var(--primary-light);
    box-shadow:0 0 0 3px rgba(46,125,100,0.2);
}

/* تحسين البطاقات الإحصائية */
body.dark-mode .stat-card{
    background:var(--surface);
    border-bottom-color:var(--primary-light);
}

body.dark-mode .stat-card .number{
    color:#E0F0EB;
    font-weight:800;
}

body.dark-mode .stat-card .label{
    color:#B7CCC3;
}

/* تحسين الأزرار */
body.dark-mode .btn-edit,
body.dark-mode .btn-delete,
body.dark-mode .btn-add,
body.dark-mode .btn-sm{
    opacity:0.9;
}

body.dark-mode .btn-edit:hover,
body.dark-mode .btn-delete:hover,
body.dark-mode .btn-add:hover{
    opacity:1;
}

/* تحسين مربع البحث والفلاتر */
body.dark-mode .filters select,
body.dark-mode .filters input{
    background:#0F1F1A;
    color:#F0F7F4;
}

/* تحسين رسائل Toast في الوضع الليلي */
body.dark-mode .toast-success{
    background:#1E5A4A !important;
    color:#fff !important;
}
body.dark-mode .toast-error{
    background:#A93226 !important;
    color:#fff !important;
}
body.dark-mode .toast-warning{
    background:#B9770E !important;
    color:#fff !important;
}
body.dark-mode .toast-info{
    background:#1A5276 !important;
    color:#fff !important;
}

/* تحسين الروابط في القائمة الجانبية */
body.dark-mode .nav-link{
    color:#D7F1E7;
}

body.dark-mode .nav-link:hover{
    color:#FFFFFF;
    background:rgba(255,255,255,0.12);
}

body.dark-mode .nav-link.active{
    background:#2E7D64;
    color:#FFFFFF;
}

/* تحسين العدادات والباجات */
body.dark-mode .status-badge{
    font-weight:600;
}

body.dark-mode .status-active{
    background:#1B5E4A;
    color:#E0F0EB;
}

body.dark-mode .status-expiring{
    background:#7D6608;
    color:#FFF3CD;
}

body.dark-mode .status-expired{
    background:#7B241C;
    color:#F5B7B1;
}
        /* =========================================================
           MOBILE RESPONSIVE - تحسين الخطوط للأجهزة الصغيرة
        ========================================================= */

        @media(max-width:992px){
            .sidebar{
                transform:translateX(100%);
            }
            .sidebar.open-mobile{
                transform:translateX(0);
            }
            .main-content{
                margin-right:0;
                padding:18px;
            }
            .top-bar{
                flex-direction:column;
                align-items:flex-start;
                gap:15px;
            }
            .page-title{
                font-size:20px;
            }
        }

        @media(max-width:768px){
            .nav-dropdown-btn{
                font-size:13px;
                padding:12px 12px;
            }
        }

        @media(max-width:576px){
            .top-bar{
                padding:16px;
            }
            .page-title{
                font-size:18px;
            }
            .page-title i{
                width:42px;
                height:42px;
                font-size:18px;
            }
            .date-badge{
                width:100%;
                text-align:center;
            }
        }
    </style>
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

<!-- ========== SIDEBAR (الشريط الجانبي الأخضر الاحترافي) ========== -->
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
                <!-- ... الروابط الحالية ... -->
                <a href="/payments/list.php" class="nav-link"><i class="fas fa-list"></i> قائمة الشيكات</a>
                <a href="/payments/add.php" class="nav-link"><i class="fas fa-plus-circle"></i> إضافة شيك</a>
                <a href="/payments/reconcile.php" class="nav-link"><i class="fas fa-balance-scale"></i> مطابقة الشيكات</a>
                <a href="/payments/report.php" class="nav-link"><i class="fas fa-chart-line"></i> تقرير الشيكات</a>
            </div>
            <!-- داخل قسم المالية -->
            
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

<!-- وجبات المطعم (النظام الجديد) -->
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

<!-- التصدير -->
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

        <!-- النظام -->
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
        <!-- سجل التدقيق -->
        <a href="/audit_log.php" class="nav-link">    <i class="fas fa-history"></i> 📜 سجل التدقيق</a>
        <!-- محرك القواعد -->
        <a href="/rules_engine.php" class="nav-link">    <i class="fas fa-cogs"></i> ⚙️ محرك القواعد</a>
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