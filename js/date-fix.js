// 日期修复脚本
// 强制使用当前日期
document.addEventListener('DOMContentLoaded', function() { if (typeof window.formatDate === 'function') { const originalFormatDate = window.formatDate; window.formatDate = function() { const now = new Date(); return now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0'); }; console.log('已修复日期显示'); } });
