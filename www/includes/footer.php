</div>
        <div class="footer">
            <p>&copy; <?= date('Y') ?> نظام إدارة الاقتطاعات - جميع الحقوق محفوظة</p>
        </div>
    </div>
<!-- ============================================================
     Mobile Bottom Navigation
============================================================ -->
<nav class="bottom-nav no-print">
    <a href="/index.php" class="bottom-nav-item <?= basename($_SERVER['PHP_SELF']) === 'index.php' ? 'active' : '' ?>">
        <span class="icon">🏠</span>
        <span>الرئيسية</span>
    </a>
    <a href="/employees/list.php" class="bottom-nav-item <?= strpos($_SERVER['PHP_SELF'], '/employees/') !== false ? 'active' : '' ?>">
        <span class="icon">👥</span>
        <span>الموظفون</span>
    </a>
    <a href="/deductions/list.php" class="bottom-nav-item <?= strpos($_SERVER['PHP_SELF'], '/deductions/') !== false ? 'active' : '' ?>">
        <span class="icon">📋</span>
        <span>الاقتطاعات</span>
    </a>
    <a href="/grants/list.php" class="bottom-nav-item <?= strpos($_SERVER['PHP_SELF'], '/grants/') !== false ? 'active' : '' ?>">
        <span class="icon">🎁</span>
        <span>المنح</span>
    </a>
    <a href="/budget/dashboard.php" class="bottom-nav-item <?= strpos($_SERVER['PHP_SELF'], '/budget/') !== false ? 'active' : '' ?>">
        <span class="icon">💰</span>
        <span>الميزانية</span>
    </a>
</nav>
</body>
</html>