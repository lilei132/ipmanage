/**
 * Dashboard 小部件排序功能调试脚本
 * 用于诊断和修复小部件重新排序问题
 */

(function() {
    'use strict';
    
    console.log('Dashboard 调试脚本已加载');

    // 等待 DOM 准备就绪
    $(document).ready(function() {
        console.log('DOM 准备就绪，开始调试...');
        
        // 检查必需的库和元素
        function checkDependencies() {
            const checks = {
                jQuery: typeof $ !== 'undefined',
                jQueryUI: typeof $.ui !== 'undefined',
                sortable: typeof $.fn.sortable !== 'undefined',
                dashboardElement: $('#dashboard').length > 0,
                rowFluidElement: $('#dashboard .row-fluid').length > 0,
                wLockElement: $('.w-lock').length > 0,
                widgetElements: $('.widget-dash').length > 0
            };
            
            console.log('依赖检查结果:', checks);
            
            // 显示详细信息
            Object.keys(checks).forEach(key => {
                if (!checks[key]) {
                    console.error(`❌ ${key} 不可用或未找到`);
                } else {
                    console.log(`✅ ${key} 可用`);
                }
            });
            
            return checks;
        }
        
        // 检查依赖
        const deps = checkDependencies();
        
        // 如果 jQuery UI sortable 不可用，尝试加载
        if (!deps.sortable) {
            console.warn('jQuery UI sortable 不可用，尝试手动初始化...');
            
            // 创建简单的拖拽功能作为备用
            if (deps.dashboardElement && deps.rowFluidElement) {
                console.log('创建备用拖拽功能...');
                createFallbackSortable();
            }
        }
        
        // 增强点击事件处理
        function enhanceClickHandlers() {
            // 移除原有的事件处理器以避免重复
            $(document).off('click', '.w-lock');
            $(document).off('click', '.w-unlock');
            
            // 重新绑定带调试信息的事件处理器
            $(document).on('click', '.w-lock', function(e) {
                console.log('点击了排序按钮 (.w-lock)');
                e.preventDefault();
                
                const $this = $(this);
                console.log('当前元素:', $this);
                
                // 检查必要元素是否存在
                if ($('#dashboard .row-fluid').length === 0) {
                    console.error('找不到 #dashboard .row-fluid 元素');
                    alert('小部件容器未找到，请刷新页面重试。');
                    return false;
                }
                
                // 更改按钮状态
                $this.removeClass('w-lock').addClass('w-unlock');
                $this.find('i').removeClass('fa-dashboard').addClass('fa-check');
                $this.find('a').addClass('btn-success');
                $this.find('a').attr('title', 'Click to save widgets order');
                
                // 显示拖拽元素
                $('#dashboard .inner i.remove-widget').fadeIn('fast');
                $('#dashboard .add-widgets').fadeIn('fast');
                $('#dashboard .inner').addClass('movable');
                
                console.log('启用排序模式...');
                
                // 初始化 sortable
                try {
                    if (typeof $.fn.sortable === 'function') {
                        $('#dashboard .row-fluid').sortable({
                            connectWith: ".row-fluid",
                            tolerance: "pointer",
                            placeholder: "widget-placeholder",
                            forcePlaceholderSize: true,
                            items: ".widget-dash",
                            start: function(event, ui) {
                                const iid = $(ui.item).attr('id');
                                console.log('开始拖拽:', iid);
                                $('#' + iid).addClass('drag');
                                ui.placeholder.html('<div class="placeholder-content">放置小部件到这里</div>');
                            },
                            stop: function(event, ui) {
                                const iid = $(ui.item).attr('id');
                                console.log('结束拖拽:', iid);
                                $('#' + iid).removeClass('drag');
                            },
                            update: function(event, ui) {
                                console.log('小部件顺序已更改');
                            }
                        });
                        console.log('✅ Sortable 初始化成功');
                    } else {
                        console.error('❌ jQuery UI sortable 不可用');
                        createFallbackSortable();
                    }
                } catch (error) {
                    console.error('Sortable 初始化失败:', error);
                    createFallbackSortable();
                }
                
                return false;
            });
            
            // 保存排序按钮点击
            $(document).on('click', '.w-unlock', function(e) {
                console.log('点击了保存按钮 (.w-unlock)');
                e.preventDefault();
                
                const $this = $(this);
                
                // 更改按钮状态
                $this.removeClass('w-unlock').addClass('w-lock');
                $this.find('i').removeClass('fa-check').addClass('fa-dashboard');
                $this.find('a').removeClass('btn-success');
                $this.find('a').attr('title', 'Click to reorder widgets');
                
                // 隐藏拖拽元素
                $('#dashboard .inner .icon-action').fadeOut('fast');
                $('#dashboard .add-widgets').fadeOut('fast');
                $('#dashboard .inner').removeClass('movable');
                
                // 获取小部件顺序
                const widgets = $('#dashboard .widget-dash').map(function(i, n) {
                    return $(n).attr('id').slice(2);
                }).get().join(';');
                
                console.log('保存小部件顺序:', widgets);
                
                // 保存到服务器
                $.post('app/tools/user-menu/user-widgets-set.php', {
                    widgets: widgets,
                    csrf_cookie: $('meta[name="csrf-token"]').attr('content') || ''
                }, function(data) {
                    console.log('服务器响应:', data);
                    // 显示成功消息
                    if (typeof showNotification === 'function') {
                        showNotification('小部件顺序已保存', 'success');
                    } else {
                        alert('小部件顺序已保存！');
                    }
                }).fail(function(xhr, status, error) {
                    console.error('保存失败:', error);
                    alert('保存失败，请重试。');
                });
                
                // 销毁 sortable
                try {
                    if ($('#dashboard .row-fluid').hasClass('ui-sortable')) {
                        $('#dashboard .row-fluid').sortable("destroy");
                        console.log('Sortable 已销毁');
                    }
                } catch (error) {
                    console.error('销毁 sortable 失败:', error);
                }
                
                return false;
            });
            
            console.log('✅ 事件处理器已增强');
        }
        
        // 创建备用拖拽功能
        function createFallbackSortable() {
            console.log('创建备用拖拽功能...');
            
            // 简单的拖拽实现
            let draggedElement = null;
            
            $(document).on('mousedown', '#dashboard .widget-dash', function(e) {
                if (!$('#dashboard .inner').hasClass('movable')) return;
                
                draggedElement = this;
                $(this).css('opacity', '0.5');
                console.log('开始拖拽 (备用):', $(this).attr('id'));
            });
            
            $(document).on('mouseup', '#dashboard .widget-dash', function(e) {
                if (!draggedElement) return;
                
                $(draggedElement).css('opacity', '1');
                
                if (draggedElement !== this) {
                    console.log('交换位置 (备用):', $(draggedElement).attr('id'), '<->', $(this).attr('id'));
                    
                    // 交换元素位置
                    const temp = $('<div>').insertAfter(draggedElement);
                    $(draggedElement).insertAfter(this);
                    $(this).insertAfter(temp);
                    temp.remove();
                }
                
                draggedElement = null;
            });
            
            console.log('✅ 备用拖拽功能已创建');
        }
        
        // 添加CSS样式
        function addDebugStyles() {
            const styles = `
                <style id="dashboard-debug-styles">
                .widget-placeholder {
                    height: 200px;
                    background: #f9f9f9;
                    border: 2px dashed #ddd;
                    border-radius: 4px;
                    margin-bottom: 15px;
                }
                .placeholder-content {
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    height: 100%;
                    color: #999;
                    font-size: 16px;
                }
                .widget-dash.drag {
                    opacity: 0.7;
                    transform: scale(1.02);
                    box-shadow: 0 5px 15px rgba(0,0,0,0.2);
                }
                .inner.movable {
                    cursor: move;
                }
                .inner.movable:hover {
                    background-color: #f8f9fa;
                }
                </style>
            `;
            $('head').append(styles);
            console.log('✅ 调试样式已添加');
        }
        
        // 初始化调试功能
        function initDebug() {
            console.log('初始化 dashboard 调试功能...');
            
            addDebugStyles();
            enhanceClickHandlers();
            
            // 添加调试信息到页面
            if ($('#dashboard').length > 0) {
                const debugInfo = $(`
                    <div id="debug-info" style="background: #f8f9fa; padding: 10px; margin: 10px 0; border-radius: 4px; font-size: 12px; display: none;">
                        <strong>调试信息:</strong>
                        <ul>
                            <li>Dashboard 容器: ${$('#dashboard').length > 0 ? '✅' : '❌'}</li>
                            <li>小部件数量: ${$('.widget-dash').length}</li>
                            <li>排序按钮: ${$('.w-lock').length > 0 ? '✅' : '❌'}</li>
                            <li>jQuery UI: ${typeof $.ui !== 'undefined' ? '✅' : '❌'}</li>
                        </ul>
                        <button onclick="$('#debug-info').hide()" style="margin-top: 5px;">关闭</button>
                    </div>
                `);
                $('#dashboard').before(debugInfo);
            }
            
            console.log('✅ Dashboard 调试功能初始化完成');
        }
        
        // 启动调试
        initDebug();
        
        // 暴露调试函数到全局
        window.dashboardDebug = {
            checkDependencies: checkDependencies,
            showDebugInfo: function() {
                $('#debug-info').show();
            },
            testSortable: function() {
                console.log('测试 sortable 功能...');
                if ($('#dashboard .row-fluid').length > 0) {
                    $('.w-lock').click();
                } else {
                    console.error('找不到 dashboard 容器');
                }
            }
        };
    });
})(); 