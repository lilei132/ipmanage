/**
 * Cache API Polyfill
 * 解决 "caches is not defined" 错误
 * 为不支持 Cache API 的环境提供兼容性
 */

// 检查是否存在 caches API，如果不存在则提供 polyfill
if (typeof window !== 'undefined' && !window.caches) {
    // 简单的内存缓存实现
    class SimpleCache {
        constructor(name) {
            this.name = name;
            this.storage = new Map();
        }

        async add(request) {
            const response = await fetch(request);
            await this.put(request, response.clone());
            return response;
        }

        async addAll(requests) {
            const promises = requests.map(request => this.add(request));
            return Promise.all(promises);
        }

        async delete(request, options) {
            const key = this._getKey(request);
            return this.storage.delete(key);
        }

        async keys(request, options) {
            if (request) {
                const key = this._getKey(request);
                return this.storage.has(key) ? [new Request(key)] : [];
            }
            return Array.from(this.storage.keys()).map(key => new Request(key));
        }

        async match(request, options) {
            const key = this._getKey(request);
            const cached = this.storage.get(key);
            
            if (cached) {
                // 检查是否过期（简单的TTL实现）
                if (cached.expiry && Date.now() > cached.expiry) {
                    this.storage.delete(key);
                    return undefined;
                }
                return new Response(cached.body, {
                    status: cached.status,
                    statusText: cached.statusText,
                    headers: cached.headers
                });
            }
            return undefined;
        }

        async matchAll(request, options) {
            const result = await this.match(request, options);
            return result ? [result] : [];
        }

        async put(request, response) {
            const key = this._getKey(request);
            const clonedResponse = response.clone();
            
            // 读取响应体
            const body = await clonedResponse.text();
            
            // 存储响应数据，包含1小时的TTL
            this.storage.set(key, {
                body: body,
                status: response.status,
                statusText: response.statusText,
                headers: Object.fromEntries(response.headers.entries()),
                expiry: Date.now() + 3600000 // 1小时
            });
            
            return undefined;
        }

        _getKey(request) {
            return typeof request === 'string' ? request : request.url;
        }
    }

    // CacheStorage 实现
    class SimpleCacheStorage {
        constructor() {
            this.caches = new Map();
        }

        async open(cacheName) {
            if (!this.caches.has(cacheName)) {
                this.caches.set(cacheName, new SimpleCache(cacheName));
            }
            return this.caches.get(cacheName);
        }

        async has(cacheName) {
            return this.caches.has(cacheName);
        }

        async delete(cacheName) {
            return this.caches.delete(cacheName);
        }

        async keys() {
            return Array.from(this.caches.keys());
        }

        async match(request, options) {
            for (const cache of this.caches.values()) {
                const response = await cache.match(request, options);
                if (response) {
                    return response;
                }
            }
            return undefined;
        }
    }

    // 在全局对象上添加 caches
    window.caches = new SimpleCacheStorage();

    // 同时为服务端渲染或其他环境提供支持
    if (typeof global !== 'undefined' && !global.caches) {
        global.caches = new SimpleCacheStorage();
    }

    console.log('Cache API polyfill loaded - caches is now available');
}

// 导出供其他模块使用
if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        SimpleCache,
        SimpleCacheStorage
    };
} 