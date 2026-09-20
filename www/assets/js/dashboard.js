/**
 * dashboard.js - سكربت لوحة التحكم الرئيسية
 */

(function() {
    'use strict';
    
    const YEAR = window.DASHBOARD_YEAR || new Date().getFullYear();
    const CSRF = window.CSRF_TOKEN || '';
    
    // ============================================================
    // 1. تبديل التبويبات
    // ============================================================
    function initTabs() {
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const tabId = this.getAttribute('data-tab');
                const url = new URL(window.location.href);
                url.searchParams.set('tab', tabId);
                window.history.pushState({}, '', url);
                
                document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                
                const target = document.getElementById(`tab-${tabId}`);
                if (target) target.classList.add('active');
                
                // إعادة رسم الرسوم البيانية عند التبديل
                if (tabId === 'charts' && window.chartsReady) {
                    window.chartsReady.forEach(c => c.resize());
                }
            });
        });
    }
    
    // ============================================================
    // 2. إشعارات: تحديد كمقروء
    // ============================================================
    function initNotifications() {
        // تحديد إشعار واحد كمقروء
        document.querySelectorAll('.mark-read-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const id = this.dataset.id;
                const item = this.closest('.notification-item');
                
                this.disabled = true;
                this.textContent = '⏳';
                
                fetch('/api/notifications.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: `action=mark_read&id=${id}&csrf_token=${encodeURIComponent(CSRF)}`
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        item.classList.remove('unread');
                        this.remove();
                        updateNotificationBadge(-1);
                    } else {
                        this.disabled = false;
                        this.textContent = '📖 تحديد كمقروء';
                    }
                })
                .catch(() => {
                    this.disabled = false;
                    this.textContent = '📖 تحديد كمقروء';
                });
            });
        });
        
        // تحديد الكل كمقروء
        document.getElementById('markAllReadBtn')?.addEventListener('click', function() {
            if (!confirm('تحديد جميع الإشعارات كمقروءة؟')) return;
            
            this.disabled = true;
            this.textContent = '⏳ جاري...';
            
            fetch('/api/notifications.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=mark_all_read&csrf_token=${encodeURIComponent(CSRF)}`
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    document.querySelectorAll('.notification-item').forEach(item => {
                        item.classList.remove('unread');
                        item.querySelector('.mark-read-btn')?.remove();
                    });
                    updateNotificationBadge(0);
                }
                this.disabled = false;
                this.textContent = '✅ تحديد الكل كمقروء';
            })
            .catch(() => {
                this.disabled = false;
                this.textContent = '✅ تحديد الكل كمقروء';
            });
        });
    }
    
    // ============================================================
    // 3. شارة الإشعارات
    // ============================================================
    function updateNotificationBadge(delta) {
        const badge = document.querySelector('.notification-badge');
        if (!badge) return;
        
        let count = parseInt(badge.textContent) || 0;
        count = Math.max(0, count + delta);
        
        badge.textContent = count;
        badge.style.display = count > 0 ? 'inline-flex' : 'none';
    }
    
    function refreshUnreadCount() {
        fetch('/api/notifications.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `action=get_unread_count&csrf_token=${encodeURIComponent(CSRF)}`
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const badge = document.querySelector('.notification-badge');
                if (badge) {
                    badge.textContent = data.count;
                    badge.style.display = data.count > 0 ? 'inline-flex' : 'none';
                }
            }
        })
        .catch(() => {});
    }
    
    // ============================================================
    // 4. تحديث تلقائي لآخر المعاملات
    // ============================================================
    function initAutoRefreshTransactions() {
        const tbody = document.getElementById('transactions-tbody');
        if (!tbody) return;
        
        setInterval(() => {
            fetch(`/api/recent_transactions.php?year=${YEAR}`)
                .then(r => r.json())
                .then(data => {
                    if (!data.success || !data.transactions) return;
                    
                    let html = '';
                    if (data.transactions.length === 0) {
                        html = '<tr><td colspan="4" class="no-data">لا توجد معاملات حديثة</td></tr>';
                    } else {
                        data.transactions.forEach(t => {
                            html += `<tr>
                                <td>${t.transaction_date || ''}</td>
                                <td><span class="badge ${t.type_class}">${t.type_ar}</span></td>
                                <td>${escapeHtml(t.description || '—')}</td>
                                <td>${formatNumber(t.amount)} دج</td>
                            </tr>`;
                        });
                    }
                    tbody.innerHTML = html;
                })
                .catch(() => {});
        }, 30000); // كل 30 ثانية
    }
    
    // ============================================================
    // 5. البحث الموحّد (Ctrl+K)
    // ============================================================
    function initGlobalSearch() {
        // إنشاء الـ Modal ديناميكياً
        const modalHtml = `
            <div id="searchModal" class="search-modal-overlay">
                <div class="search-modal-box">
                    <div class="search-input-wrap">
                        <span class="search-icon">🔍</span>
                        <input type="text" id="globalSearchInput" placeholder="ابحث عن موظف، اقتطاع، منحة، شيك..." autocomplete="off">
                        <button id="closeSearchBtn" class="search-close">✕</button>
                    </div>
                    <div id="searchResults" class="search-results">
                        <div class="search-hint">اكتب حرفين على الأقل للبحث...</div>
                    </div>
                    <div class="search-footer">
                        <kbd>↑</kbd><kbd>↓</kbd> للتنقل • <kbd>Enter</kbd> للفتح • <kbd>Esc</kbd> للإغلاق
                    </div>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', modalHtml);
        
        const modal = document.getElementById('searchModal');
        const input = document.getElementById('globalSearchInput');
        const resultsBox = document.getElementById('searchResults');
        let selectedIndex = -1;
        let currentResults = [];
        let debounceTimer;
        
        // فتح بـ Ctrl+K
        document.addEventListener('keydown', (e) => {
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                openSearch();
            }
            if (e.key === 'Escape' && modal.classList.contains('active')) {
                closeSearch();
            }
        });
        
        document.getElementById('closeSearchBtn').addEventListener('click', closeSearch);
        modal.addEventListener('click', (e) => {
            if (e.target === modal) closeSearch();
        });
        
        input.addEventListener('input', function() {
            clearTimeout(debounceTimer);
            const q = this.value.trim();
            
            if (q.length < 2) {
                resultsBox.innerHTML = '<div class="search-hint">اكتب حرفين على الأقل للبحث...</div>';
                return;
            }
            
            resultsBox.innerHTML = '<div class="search-loading">⏳ جاري البحث...</div>';
            
            debounceTimer = setTimeout(() => {
                fetch(`/api/search.php?q=${encodeURIComponent(q)}`)
                    .then(r => r.json())
                    .then(data => {
                        currentResults = data.results || [];
                        renderResults(currentResults);
                    })
                    .catch(() => {
                        resultsBox.innerHTML = '<div class="search-empty">❌ خطأ في البحث</div>';
                    });
            }, 300);
        });
        
        input.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                selectedIndex = Math.min(selectedIndex + 1, currentResults.length - 1);
                highlightResult();
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                selectedIndex = Math.max(selectedIndex - 1, -1);
                highlightResult();
            } else if (e.key === 'Enter' && selectedIndex >= 0) {
                e.preventDefault();
                window.location.href = currentResults[selectedIndex].url;
            }
        });
        
        function openSearch() {
            modal.classList.add('active');
            input.value = '';
            input.focus();
            selectedIndex = -1;
            currentResults = [];
            resultsBox.innerHTML = '<div class="search-hint">اكتب حرفين على الأقل للبحث...</div>';
        }
        
        function closeSearch() {
            modal.classList.remove('active');
        }
        
        function renderResults(results) {
            if (results.length === 0) {
                resultsBox.innerHTML = '<div class="search-empty">لا توجد نتائج</div>';
                return;
            }
            
            let html = '';
            results.forEach((r, i) => {
                html += `
                    <a href="${r.url}" class="search-result-item" data-index="${i}">
                        <span class="search-result-icon">${r.icon}</span>
                        <div class="search-result-content">
                            <div class="search-result-title">${escapeHtml(r.title)}</div>
                            <div class="search-result-subtitle">${escapeHtml(r.subtitle || '')}</div>
                        </div>
                    </a>
                `;
            });
            resultsBox.innerHTML = html;
            selectedIndex = -1;
        }
        
        function highlightResult() {
            resultsBox.querySelectorAll('.search-result-item').forEach((el, i) => {
                el.classList.toggle('selected', i === selectedIndex);
                if (i === selectedIndex) el.scrollIntoView({block: 'nearest'});
            });
        }
    }
    
    // ============================================================
    // 6. الوضع الداكن
    // ============================================================
    function initDarkMode() {
        const saved = localStorage.getItem('dashboard-theme');
        if (saved === 'dark' || (!saved && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.body.classList.add('dark-mode');
        }
        
        // زر التبديل
        const btn = document.getElementById('themeToggle');
        if (btn) {
            updateToggleIcon();
            btn.addEventListener('click', () => {
                document.body.classList.toggle('dark-mode');
                const isDark = document.body.classList.contains('dark-mode');
                localStorage.setItem('dashboard-theme', isDark ? 'dark' : 'light');
                updateToggleIcon();
            });
        }
        
        function updateToggleIcon() {
            if (!btn) return;
            const isDark = document.body.classList.contains('dark-mode');
            btn.textContent = isDark ? '☀️' : '🌙';
            btn.title = isDark ? 'وضع نهاري' : 'وضع ليلي';
        }
    }
    
    // ============================================================
    // 7. اختصارات لوحة المفاتيح
    // ============================================================
    function initKeyboardShortcuts() {
        document.addEventListener('keydown', (e) => {
            // Alt + رقم للتبديل بين التبويبات
            if (e.altKey && !e.ctrlKey && !e.metaKey) {
                const tabs = {
                    '1': 'nav',
                    '2': 'overview',
                    '3': 'charts',
                    '4': 'transactions',
                    '5': 'minutes',
                    '6': 'notifications'
                };
                if (tabs[e.key]) {
                    e.preventDefault();
                    const url = new URL(window.location.href);
                    url.searchParams.set('tab', tabs[e.key]);
                    window.location.href = url.toString();
                }
            }
        });
    }
    
    // ============================================================
    // 8. أدوات مساعدة
    // ============================================================
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    function formatNumber(num) {
        return parseFloat(num || 0).toLocaleString('fr-DZ', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }
    
    // ============================================================
    // تشغيل عند الجاهزية
    // ============================================================
    document.addEventListener('DOMContentLoaded', function() {
        initTabs();
        initNotifications();
        initAutoRefreshTransactions();
        initGlobalSearch();
        initDarkMode();
        initKeyboardShortcuts();
        
        // تحديث شارة الإشعارات كل 60 ثانية
        refreshUnreadCount();
        setInterval(refreshUnreadCount, 60000);
    });
    
})();