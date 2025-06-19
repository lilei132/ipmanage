/**
 * 全局错误处理器
 * 专门处理 caches 相关错误和其他常见的 JavaScript 错误
 */

(function() {
    'use strict';

    // 防止重复加载
    if (window.errorHandlerLoaded) {
        return;
    }
    window.errorHandlerLoaded = true;

    // 错误计数器
    let errorCount = 0;
    const MAX_ERRORS = 10;

    // 全局错误处理
    window.addEventListener('error', function(event) {
        if (errorCount >= MAX_ERRORS) {
            return;
        }
        
        const error = event.error;
        const message = event.message;
        
        // 处理 caches 未定义错误
        if (message && message.includes('caches is not defined')) {
            errorCount++;
            console.warn('Cache API 不可用，使用替代方案');
            
            // 尝试修复 caches 错误
            if (!window.caches) {
                window.caches = {
                    open: function(name) {
                        return Promise.resolve({
                            match: function() { return Promise.resolve(undefined); },
                            add: function() { return Promise.resolve(); },
                            addAll: function() { return Promise.resolve(); },
                            put: function() { return Promise.resolve(); },
                            delete: function() { return Promise.resolve(false); },
                            keys: function() { return Promise.resolve([]); }
                        });
                    },
                    match: function() { return Promise.resolve(undefined); },
                    has: function() { return Promise.resolve(false); },
                    delete: function() { return Promise.resolve(false); },
                    keys: function() { return Promise.resolve([]); }
                };
            }
            
            event.preventDefault();
            return;
        }
        
        // 处理其他常见错误
        if (message) {
            if (message.includes('Cannot read property') || 
                message.includes('Cannot read properties') ||
                message.includes('is not a function')) {
                errorCount++;
                console.warn('捕获到 JavaScript 错误:', message);
                
                // 可以在这里添加错误上报逻辑
                if (typeof gtag !== 'undefined') {
                    gtag('event', 'exception', {
                        'description': message,
                        'fatal': false
                    });
                }
                
                event.preventDefault();
                return;
            }
        }
    });

    // Promise 错误处理
    window.addEventListener('unhandledrejection', function(event) {
        if (errorCount >= MAX_ERRORS) {
            return;
        }
        
        const reason = event.reason;
        
        if (reason && reason.message) {
            if (reason.message.includes('caches is not defined')) {
                errorCount++;
                console.warn('Promise 中的 Cache API 错误已处理');
                event.preventDefault();
                return;
            }
        }
        
        console.warn('未处理的 Promise 拒绝:', reason);
    });

    // 提供一个安全的缓存访问函数
    window.safeCache = {
        get: function(key, ttl) {
            try {
                const item = localStorage.getItem('safe_cache_' + key);
                if (item) {
                    const data = JSON.parse(item);
                    if (!ttl || (Date.now() - data.timestamp < ttl)) {
                        return data.value;
                    } else {
                        localStorage.removeItem('safe_cache_' + key);
                    }
                }
            } catch (e) {
                console.warn('安全缓存读取失败:', e);
            }
            return null;
        },
        
        set: function(key, value, ttl) {
            try {
                const data = {
                    value: value,
                    timestamp: Date.now(),
                    ttl: ttl || 3600000 // 默认1小时
                };
                localStorage.setItem('safe_cache_' + key, JSON.stringify(data));
                return true;
            } catch (e) {
                console.warn('安全缓存写入失败:', e);
                return false;
            }
        },
        
        remove: function(key) {
            try {
                localStorage.removeItem('safe_cache_' + key);
                return true;
            } catch (e) {
                console.warn('安全缓存删除失败:', e);
                return false;
            }
        },
        
        clear: function() {
            try {
                const keys = Object.keys(localStorage);
                keys.forEach(key => {
                    if (key.startsWith('safe_cache_')) {
                        localStorage.removeItem(key);
                    }
                });
                return true;
            } catch (e) {
                console.warn('安全缓存清理失败:', e);
                return false;
            }
        }
    };

    // 清理过期缓存
    function cleanExpiredCache() {
        try {
            const keys = Object.keys(localStorage);
            keys.forEach(key => {
                if (key.startsWith('safe_cache_')) {
                    try {
                        const item = localStorage.getItem(key);
                        if (item) {
                            const data = JSON.parse(item);
                            if (data.ttl && (Date.now() - data.timestamp > data.ttl)) {
                                localStorage.removeItem(key);
                            }
                        }
                    } catch (e) {
                        // 清理损坏的缓存项
                        localStorage.removeItem(key);
                    }
                }
            });
        } catch (e) {
            console.warn('缓存清理过程中出错:', e);
        }
    }

    // 每5分钟清理一次过期缓存
    setInterval(cleanExpiredCache, 300000);

    // 页面加载完成后立即清理一次
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', cleanExpiredCache);
    } else {
        cleanExpiredCache();
    }

    console.log('全局错误处理器已加载');
})(); 