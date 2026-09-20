/**
 * register-sw.js - تسجيل Service Worker + PWA Install Prompt
 */
(function() {
    'use strict';
    
    const BASE_PATH = window.PWA_BASE_PATH || '';
    
    // ============================================================
    // 1. تسجيل Service Worker
    // ============================================================
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register(`${BASE_PATH}/sw.js`, {
                scope: `${BASE_PATH}/`
            })
            .then(registration => {
                console.log('✅ SW registered:', registration.scope);
                
                // فحص التحديثات
                registration.addEventListener('updatefound', () => {
                    const newWorker = registration.installing;
                    newWorker.addEventListener('statechange', () => {
                        if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                            if (typeof toastr !== 'undefined') {
                                toastr.info('يوجد إصدار جديد. <a href="#" onclick="location.reload()">إعادة التحميل</a>', 'تحديث متوفر', {timeOut: 0});
                            }
                        }
                    });
                });
            })
            .catch(err => console.error('❌ SW registration failed:', err));
        });
    }
    
    // ============================================================
    // 2. Install Prompt
    // ============================================================
    let deferredPrompt = null;
    
    window.addEventListener('beforeinstallprompt', (e) => {
        console.log('💡 beforeinstallprompt fired');
        e.preventDefault();
        deferredPrompt = e;
        showInstallButton();
    });
    
    window.addEventListener('appinstalled', () => {
        console.log('✅ PWA installed');
        deferredPrompt = null;
        hideInstallButton();
        if (typeof toastr !== 'undefined') {
            toastr.success('تم تثبيت التطبيق بنجاح!');
        }
    });
    
    function showInstallButton() {
        if (document.getElementById('pwa-install-btn')) return;
        
        const btn = document.createElement('button');
        btn.id = 'pwa-install-btn';
        btn.innerHTML = '📲 تثبيت التطبيق';
        btn.style.cssText = `
            position: fixed;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            padding: 14px 30px;
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            border: none;
            border-radius: 30px;
            font-family: 'Cairo', sans-serif;
            font-weight: 700;
            font-size: 15px;
            cursor: pointer;
            box-shadow: 0 6px 25px rgba(40, 167, 69, 0.5);
            z-index: 999999;
            animation: pwaPulse 2s infinite;
            transition: all 0.3s;
        `;
        
        btn.addEventListener('click', async () => {
            if (!deferredPrompt) return;
            deferredPrompt.prompt();
            const { outcome } = await deferredPrompt.userChoice;
            console.log('User choice:', outcome);
            deferredPrompt = null;
            hideInstallButton();
        });
        
        document.body.appendChild(btn);
        
        if (!document.getElementById('pwa-styles')) {
            const style = document.createElement('style');
            style.id = 'pwa-styles';
            style.textContent = `
                @keyframes pwaPulse {
                    0%, 100% { transform: translateX(-50%) scale(1); }
                    50% { transform: translateX(-50%) scale(1.05); }
                }
            `;
            document.head.appendChild(style);
        }
    }
    
    function hideInstallButton() {
        const btn = document.getElementById('pwa-install-btn');
        if (btn) btn.remove();
    }
    
    // ============================================================
    // 3. حالة الاتصال
    // ============================================================
    window.addEventListener('online', () => {
        if (typeof toastr !== 'undefined') toastr.success('✅ تم استعادة الاتصال');
    });
    
    window.addEventListener('offline', () => {
        if (typeof toastr !== 'undefined') toastr.warning('⚠️ انقطع الاتصال بالإنترنت');
    });
    
})();