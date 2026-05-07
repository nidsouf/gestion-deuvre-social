<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>نظام إدارة الاقتطاعات</title>
    
    <!-- PWA Manifest -->
    <link rel="manifest" href="/deductions_system/manifest.json">
    
    <!-- Font Awesome 6 (استخدم 5.15.4 كما في الكود الأصلي) -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">    
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    
    <style>
        /* ========== RESET & GLOBAL ========== */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f5;
            color: #2c3e50;
            transition: background 0.3s, color 0.2s;
        }

        /* ========== SIDEBAR ========== */
        .sidebar {
            width: 280px;
            background: linear-gradient(180deg, #99c0ac, #071a10);
            color: white;
            position: fixed;
            top: 0;
            right: 0;
            height: 100vh;
            overflow-y: auto;
            z-index: 100;
            box-shadow: -2px 0 10px rgba(0,0,0,0.1);
            transition: width 0.3s ease;
        }

        .sidebar-header {
            text-align: center;
            padding: 25px 20px;
            border-bottom: 1px solid rgba(44, 181, 19, 0.1);
        }

        .sidebar-header h2 {
            font-size: 22px;
            font-weight: bold;
            white-space: nowrap;
            overflow: hidden;
        }

        .sidebar-header p {
            font-size: 12px;
            opacity: 0.6;
            white-space: nowrap;
        }

        .nav-menu {
            list-style: none;
            padding: 15px 0;
        }

        .nav-item {
            margin: 5px 15px;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 18px;
            color: #e8f5e9;
            text-decoration: none;
            border-radius: 14px;
            transition: all 0.3s ease;
            font-size: 14px;
            white-space: nowrap;
        }

        .nav-link i {
            width: 24px;
            text-align: center;
            font-size: 18px;
        }

        .nav-link:hover {
            background: rgba(255,255,255,0.12);
            transform: translateX(-5px);
        }

        /* Sidebar collapsed */
        .sidebar.collapsed {
            width: 70px;
        }
        .sidebar.collapsed .sidebar-header h2,
        .sidebar.collapsed .sidebar-header p,
        .sidebar.collapsed .nav-link span {
            display: none;
        }
        .sidebar.collapsed .nav-link {
            justify-content: center;
            padding: 12px 0;
        }
        .sidebar.collapsed .nav-link i {
            margin: 0;
        }

        /* ========== MAIN CONTENT ========== */
        .main-content {
            margin-right: 280px;
            padding: 20px;
            min-height: 100vh;
            transition: margin-right 0.3s ease;
        }
        .main-content.expanded {
            margin-right: 70px;
        }

        /* ========== TOP BAR ========== */
        .top-bar {
            background: white;
            border-radius: 20px;
            padding: 15px 25px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border-right: 4px solid #2e7d32;
            flex-wrap: wrap;
            gap: 10px;
        }

        .page-title {
            font-size: 22px;
            font-weight: 700;
            color: #1a3a2a;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .date-badge {
            background: #e8f5e9;
            padding: 8px 18px;
            border-radius: 25px;
            font-size: 13px;
            color: #1b5e20;
        }

        /* ========== CARDS & STATS ========== */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: all 0.3s ease;
            border-bottom: 3px solid;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-card h3 {
            font-size: 14px;
            color: #666;
            margin-bottom: 10px;
        }

        .stat-card .number {
            font-size: 28px;
            font-weight: 700;
        }

        /* ========== SECTIONS & TABLES ========== */
        .section {
            background: white;
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e8f5e9;
        }

        .section-title {
            font-size: 18px;
            font-weight: 600;
            color: #1b5e20;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 16px;
            overflow: hidden;
        }
        .data-table th, .data-table td {
            padding: 12px;
            text-align: center;
            border-bottom: 1px solid #ddd;
        }
        .data-table th {
            background: #2a5298;
            color: white;
        }
        .data-table tr:hover {
            background: #f5f5f5;
        }

        /* ========== FORMS ========== */
        .form-container {
            max-width: 600px;
            margin: 30px auto;
            background: white;
            padding: 25px;
            border-radius: 20px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            font-weight: bold;
            margin-bottom: 8px;
        }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 12px;
        }
        button, .btn {
            background: #2a5298;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 30px;
            cursor: pointer;
            font-weight: bold;
            text-decoration: none;
            display: inline-block;
        }
        button:hover, .btn:hover {
            background: #1e3c72;
        }

        /* ========== BUTTONS & UTILITIES ========== */
        .btn-sm {
            background: #2a5298;
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            text-decoration: none;
            display: inline-block;
            transition: 0.3s;
            border: none;
            cursor: pointer;
        }
        .btn-sm:hover {
            background: #1e3c72;
        }

        .toggle-sidebar, .dark-mode-toggle {
            background: none;
            border: green;
            color: white;
            font-size: 20px;
            cursor: pointer;
            margin-left: 10px;
            transition: 0.2s;
        }
        .dark-mode-toggle {
            color: #ffc107;
        }

        /* ========== DARK MODE ========== */
        body.dark-mode {
            background: #121212;
            color: #e0e0e0;
        }
        body.dark-mode .sidebar {
            background: linear-gradient(180deg, #1e1e1e, #0a0a0a);
        }
        body.dark-mode .top-bar,
        body.dark-mode .stat-card,
        body.dark-mode .section,
        body.dark-mode .data-table,
        body.dark-mode .form-container {
            background: #1e1e1e;
            color: #ddd;
        }
        body.dark-mode .data-table,
        body.dark-mode .grants-table,
        body.dark-mode .loans-table,
        body.dark-mode table {
            background-color: #1e1e1e;
            color: #e0e0e0;
        }
        body.dark-mode .data-table th,
        body.dark-mode .grants-table th,
        body.dark-mode .loans-table th,
        body.dark-mode table th {
            background-color: #2a2a2a;
            color: #ffffff;
            border-color: #444;
        }
        body.dark-mode .data-table td,
        body.dark-mode .grants-table td,
        body.dark-mode .loans-table td,
        body.dark-mode table td {
            background-color: #252525;
            color: #e0e0e0;
            border-color: #3a3a3a;
        }
        body.dark-mode .data-table tr:hover td,
        body.dark-mode .grants-table tr:hover td,
        body.dark-mode .loans-table tr:hover td,
        body.dark-mode table tr:hover td {
            background-color: #2e2e2e;
        }
        body.dark-mode .total-row td,
        body.dark-mode .total-row th {
            background-color: #3a3a3a !important;
            color: #ffd700 !important;
        }
        body.dark-mode .info-box {
            background-color: #1e2a3a;
            color: #e0e0e0;
            border-right-color: #4caf50;
        }
        body.dark-mode .section-title {
            color: #ffd700;
            border-right-color: #ffd700;
        }
        body.dark-mode .btn-sm {
            background: #333;
        }
        body.dark-mode .date-badge {
            background: #2c2c2c;
            color: #ccc;
        }
        body.dark-mode .data-table th {
            background: #333;
        }
        body.dark-mode .data-table tr:hover {
            background: #2a2a2a;
        }

        /* ========== RESPONSIVE ========== */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(100%);
                position: fixed;
                z-index: 1000;
            }
            .sidebar.open-mobile {
                transform: translateX(0);
            }
            .main-content {
                margin-right: 0;
            }
            .top-bar {
                flex-direction: column;
                align-items: flex-start;
            }
        }
        .toggle-sidebar {
            background: #849285;
            color: white;
            border: none;
            font-size: 24px;
            width: 40px;
            height: 40px;
            border-radius: 8px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .toggle-sidebar:hover {
            background: #1b5e20;
        }

        /* ========== TOAST NOTIFICATIONS STYLES ========== */
        .toast-container {
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 9999;
            direction: rtl;
        }
        .toast {
            background: white;
            border-radius: 12px;
            padding: 12px 20px;
            margin-bottom: 10px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 250px;
            max-width: 350px;
            border-right: 5px solid;
            animation: slideIn 0.3s ease, fadeOut 0.5s ease 2.5s forwards;
            font-family: 'Segoe UI', Tahoma, sans-serif;
        }
        .toast.success { border-right-color: #28a745; background: #e8f5e9; }
        .toast.error { border-right-color: #dc3545; background: #ffebee; }
        .toast.warning { border-right-color: #ffc107; background: #fff3e0; }
        .toast.info { border-right-color: #17a2b8; background: #e1f5fe; }
        .toast i { font-size: 20px; }
        .toast .message { flex: 1; font-size: 14px; }
        .toast .close { cursor: pointer; font-weight: bold; font-size: 18px; color: #888; }
        @keyframes slideIn {
            from { transform: translateX(-100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        @keyframes fadeOut {
            to { opacity: 0; visibility: hidden; }
        }
    </style>
</head>

<body>

<!-- ========== TOAST NOTIFICATIONS CONTAINER ========== -->
<div class="toast-container" id="toastContainer"></div>

<?php
// =============================================
// عرض إشعارات الـ Toast (المنبثقة) من الجلسة
// =============================================
if (isset($_SESSION['toast'])) {
    $toast = $_SESSION['toast'];
    echo "<script>
        document.addEventListener('DOMContentLoaded', function() {
            showToast('{$toast['message']}', '{$toast['type']}', {$toast['duration']});
        });
    </script>";
    unset($_SESSION['toast']);
}

// =============================================
// عرض إشعارات الاقتطاعات المنتهية / القريبة
// =============================================
if (file_exists(__DIR__ . '/notification.php')) {
    require_once __DIR__ . '/notification.php';
    // تأكد من وجود متغير $pdo (الاتصال بقاعدة البيانات)
    if (!isset($pdo) && file_exists(__DIR__ . '/../config/database.php')) {
        require_once __DIR__ . '/../config/database.php';
    }
    if (isset($pdo)) {
        echo showNotifications($pdo, '/deductions_system');
    }
}
?>

<!-- ========== SIDEBAR ========== -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <img src="/deductions_system/assets/images/logo.png" alt="شعار المركز" style="width: 200px; margin-bottom: 5px;">
        <h2 style="font-size: 16px; margin: 5px 0;">مركز التكوين والتعليم المهنيين</h2>
        <h3 style="font-size: 14px; margin: 0; color: #ffd700;">الشهيد علي بوسحابة - بكوينين</h3>
        <hr style="margin: 10px 0; border-color: rgba(255,255,255,0.2);">
        <h2 style="font-size: 20px; margin-top: 5px;">لجنة الخدمات الاجتماعية</h2>
        <p style="font-size: 15px;color: #a3ddf4;">إنجاز شـوقي نيـد</p>
    </div>
    <ul class="nav-menu">
        <!-- الروابط الأساسية -->
        <li class="nav-item"><a href="/index.php" class="nav-link"><span>📊</span> <span>لوحة التحكم</span></a></li>
        <li class="nav-item"><a href="/employees/list.php" class="nav-link"><span>👥</span> <span>الموظفون</span></a></li>
        <li class="nav-item"><a href="/employees/add.php" class="nav-link"><span>➕</span> <span>إضافة موظف</span></a></li>
        <li class="nav-item"><a href="/sources/list.php" class="nav-link"><span>🗄️</span> <span>المصادر</span></a></li>
        <li class="nav-item"><a href="/deductions/list.php" class="nav-link"><span>📈</span> <span>الاقتطاعات</span></a></li>
        <li class="nav-item"><a href="/deductions/add.php" class="nav-link"><span>➕</span> <span>إضافة اقتطاع</span></a></li>
        <li class="nav-item"><a href="/grants/list.php" class="nav-link"><span>🎁</span> <span>المنح الاجتماعية</span></a></li>
        <li class="nav-item"><a href="/grants/assign.php" class="nav-link"><span>🤲</span> <span>منح موظف</span></a></li>
        <li class="nav-item"><a href="/grants/employee_list.php" class="nav-link"><span>📋</span> <span>منح الموظفين</span></a></li>
        
        <!-- نظام الوجبات -->
        <li class="nav-item"><a href="/meals/index.php" class="nav-link"><span>🍽️</span> <span>تسجيل وجبات المطعم</span></a></li>
        <li class="nav-item"><a href="/meals/reports.php" class="nav-link"><span>📊</span> <span>تقارير وجبات المطعم</span></a></li>
        <li class="nav-item"><a href="/meals/process_trimester.php" class="nav-link"><span>🔄</span> <span>تأكيد الاقتطاع الثلاثي</span></a></li>
        
        <!-- الميزانية -->
        <li class="nav-item"><a href="/budget/dashboard.php" class="nav-link"><span>🏛️</span> <span>الميزانية</span></a></li>
        <li class="nav-item"><a href="/budget/dashboard.php" class="nav-link"><span>🥧</span> <span>لوحة الميزانية</span></a></li>
        <li class="nav-item"><a href="/budget/simulation.php" class="nav-link"><span>📈</span> <span>محاكاة الميزانية</span></a></li>
        <li class="nav-item"><a href="/budget/create.php" class="nav-link"><span>➕</span> <span>إضافة ميزانية</span></a></li>
        <li class="nav-item"><a href="/budget/report.php" class="nav-link"><span>📄</span> <span>تقرير الميزانية</span></a></li>
        
        <!-- التقارير -->
        <li class="nav-item"><a href="/reports/monthly.php" class="nav-link"><span>📅</span> <span>التقرير الشهري</span></a></li>
        <li class="nav-item"><a href="/reports/meeting_minutes.php" class="nav-link"><span>📝</span> <span>تحرير المحضر الشهري</span></a></li>
        <li class="nav-item"><a href="/reports/quarterly.php" class="nav-link"><span>📅</span> <span>التقرير الثلاثي</span></a></li>
        <li class="nav-item"><a href="/reports/annual.php" class="nav-link"><span>📊</span> <span>التقرير السنوي</span></a></li>
        
        <!-- المدفوعات -->
        <li class="nav-item"><a href="/payments/list.php" class="nav-link"><span>💵</span> <span>المبالغ المسلمة</span></a></li>
        <li class="nav-item"><a href="/payments/report.php" class="nav-link"><span>📈</span> <span>تقرير الشيكات</span></a></li>
        <li class="nav-item"><a href="/payments/reconcile.php" class="nav-link"><span>🔍</span> <span>مطابقة الشيكات</span></a></li>
        
        <!-- أدوات النظام -->
        <li class="nav-item"><a href="/backup.php" class="nav-link"><span>💾</span> <span>النسخ الاحتياطي</span></a></li>
        <li class="nav-item"><a href="/regulations.php" class="nav-link"><span>📘</span> <span>القوانين الداخلية</span></a></li>
        <li class="nav-item"><a href="/settings.php" class="nav-link"><span>⚙️</span> <span>إعدادات النظام</span></a></li>
        
        <!-- تسجيل العمرة وعيد العمال -->
        <li class="nav-item"><a href="/umrah/draw_list.php" class="nav-link"><span>🕋</span> <span>سحب العمرة</span></a></li> 
        <li class="nav-item"><a href="/honors/index.php" class="nav-link"><span>🎖️</span> <span>عيد العمال</span></a></li>
        
        <!-- تسجيل الخروج -->
        <li class="nav-item"><a href="/logout.php" class="nav-link"><span>🚪</span> <span>تسجيل خروج</span></a></li>
    </ul>
</aside>

<!-- ========== MAIN CONTENT ========== -->
<main class="main-content" id="mainContent">
    <div class="top-bar">
        <div class="page-title">
            <button class="toggle-sidebar" id="toggleSidebarBtn" title="تصغير/توسيع القائمة">☰</button>
            <i class="fas fa-tachometer-alt"></i> لجنة الخدمات الاجتماعية - نظام الاقتطاعات
        </div>
        <div style="display: flex; align-items: center; gap: 10px;">
            <button class="dark-mode-toggle" id="darkModeToggle" title="الوضع الليلي">🌙</button>
            <div class="date-badge"><i class="far fa-calendar-alt"></i> <?= date('d F Y') ?></div>
        </div>
    </div>

<script>
    (function() {
        // ========== طي/توسيع القائمة الجانبية ==========
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const toggleBtn = document.getElementById('toggleSidebarBtn');

        if (sidebar && mainContent && toggleBtn) {
            const isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
            if (isCollapsed) {
                sidebar.classList.add('collapsed');
                mainContent.classList.add('expanded');
            }
            toggleBtn.addEventListener('click', function() {
                sidebar.classList.toggle('collapsed');
                mainContent.classList.toggle('expanded');
                localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
            });
        }

        // ========== الوضع الليلي (Dark Mode) ==========
        const darkToggle = document.getElementById('darkModeToggle');
        if (darkToggle) {
            // تحديث الأيقونة بناءً على الحالة الحالية
            function updateDarkModeIcon() {
                const isDark = document.body.classList.contains('dark-mode');
                darkToggle.innerHTML = isDark ? '☀️' : '🌙';
            }

            // استرجاع الوضع المخزن
            if (localStorage.getItem('darkMode') === 'true') {
                document.body.classList.add('dark-mode');
            } else {
                document.body.classList.remove('dark-mode');
            }
            updateDarkModeIcon();

            // عند الضغط على الزر
            darkToggle.addEventListener('click', () => {
                document.body.classList.toggle('dark-mode');
                const isDark = document.body.classList.contains('dark-mode');
                localStorage.setItem('darkMode', isDark);
                updateDarkModeIcon();
            });
        }
    })();

    // ========== TOAST NOTIFICATION FUNCTION ==========
    function showToast(message, type = 'info', duration = 3000) {
        const container = document.getElementById('toastContainer');
        if (!container) return;
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        let icon = '';
        if (type === 'success') icon = '✅';
        else if (type === 'error') icon = '❌';
        else if (type === 'warning') icon = '⚠️';
        else icon = 'ℹ️';
        toast.innerHTML = `
            <i>${icon}</i>
            <div class="message">${message}</div>
            <div class="close">&times;</div>
        `;
        container.appendChild(toast);
        toast.querySelector('.close').onclick = () => toast.remove();
        setTimeout(() => toast.remove(), duration);
    }
</script>