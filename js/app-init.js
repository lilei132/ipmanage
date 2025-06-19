/**
 * 应用程序初始化脚本
 * 确保所有必要的修复和 polyfill 都正确加载
 */

(function() {
    'use strict';
    
    console.log('开始应用程序初始化...');

    // 初始化状态
    const initState = {
        cachePolyfillLoaded: false,
        errorHandlerLoaded: false,
        domReady: false
    };

    // 检查必要的 API 和功能
    function checkRequirements() {
        const checks = {
            caches: typeof window.caches !== 'undefined',
            localStorage: typeof window.localStorage !== 'undefined',
            fetch: typeof window.fetch !== 'undefined',
            Promise: typeof window.Promise !== 'undefined',
            safeCache: typeof window.safeCache !== 'undefined'
        };

        console.log('API 支持检查:', checks);

        // 如果任何关键 API 缺失，尝试提供备用方案
        if (!checks.fetch && typeof XMLHttpRequest !== 'undefined') {
            console.warn('Fetch API 不可用，但 XMLHttpRequest 可用');
        }

        if (!checks.Promise) {
            console.error('Promise 不可用，应用可能无法正常工作');
        }

        if (!checks.localStorage) {
            console.warn('localStorage 不可用，缓存功能受限');
        }

        return checks;
    }

    // 应用程序就绪回调
    function onAppReady() {
        console.log('应用程序初始化完成！');
        
        // 触发自定义事件
        const event = new CustomEvent('appReady', {
            detail: {
                timestamp: Date.now(),
                checks: checkRequirements()
            }
        });
        window.dispatchEvent(event);

        // 清理过期缓存
        if (window.safeCache && typeof window.safeCache.clear === 'function') {
            try {
                // 清理过期项目而不是全部清除
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
                            localStorage.removeItem(key);
                        }
                    }
                });
                console.log('过期缓存清理完成');
            } catch (e) {
                console.warn('缓存清理失败:', e);
            }
        }
    }

    // 检查初始化状态
    function checkInitState() {
        // 检查缓存 polyfill
        if (typeof window.caches !== 'undefined') {
            initState.cachePolyfillLoaded = true;
        }

        // 检查错误处理器
        if (typeof window.errorHandlerLoaded !== 'undefined' && window.errorHandlerLoaded) {
            initState.errorHandlerLoaded = true;
        }

        // 检查 DOM
        if (document.readyState === 'loading') {
            initState.domReady = false;
        } else {
            initState.domReady = true;
        }

        console.log('初始化状态:', initState);

        // 如果所有必需组件都已加载
        if (initState.cachePolyfillLoaded && initState.domReady) {
            onAppReady();
        }
    }

    // DOM 就绪处理
    function handleDOMReady() {
        initState.domReady = true;
        checkRequirements();
        checkInitState();
    }

    // 错误恢复机制
    function errorRecovery() {
        console.log('启动错误恢复机制...');

        // 如果 caches 仍然未定义，提供最小实现
        if (typeof window.caches === 'undefined') {
            window.caches = {
                open: function(name) {
                    return Promise.resolve({
                        match: function() { return Promise.resolve(undefined); },
                        add: function() { return Promise.resolve(); },
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
            console.log('应急缓存 API 已启用');
        }

        // 如果 safeCache 不存在，提供基本实现
        if (typeof window.safeCache === 'undefined') {
            window.safeCache = {
                get: function() { return null; },
                set: function() { return false; },
                remove: function() { return false; },
                clear: function() { return false; }
            };
            console.log('应急安全缓存已启用');
        }
    }

    // 监听 DOM 事件
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', handleDOMReady);
    } else {
        setTimeout(handleDOMReady, 0);
    }

    // 延迟检查，确保所有脚本都已加载
    setTimeout(function() {
        if (!initState.cachePolyfillLoaded || !initState.errorHandlerLoaded) {
            console.warn('某些组件未正确加载，启动错误恢复...');
            errorRecovery();
            setTimeout(checkInitState, 100);
        }
    }, 1000);

    // 导出初始化函数
    window.appInit = {
        checkRequirements: checkRequirements,
        getInitState: function() { return Object.assign({}, initState); },
        forceReady: onAppReady
    };

    console.log('应用程序初始化脚本已加载');
})(); 