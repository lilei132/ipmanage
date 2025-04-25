<?php
/**
 * Traffic Monitor widget for dashboard
 *
 * Displays traffic monitoring cards that were saved on the traffic-monitor page
 */

# required functions
if(!isset($User)) {
	require_once( dirname(__FILE__) . '/../../../functions/functions.php' );
	# classes
	$Database	= new Database_PDO;
	$User 		= new User ($Database);
	$Tools 		= new Tools ($Database);
	$Subnets 	= new Subnets ($Database);
	$Addresses 	= new Addresses ($Database);
	$Result 	= new Result ();
}

# user must be authenticated
$User->check_user_session ();

# if direct request that redirect to traffic-monitor page
if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || $_SERVER['HTTP_X_REQUESTED_WITH'] != "XMLHttpRequest")	{
	header("Location: ".create_link("tools", "traffic-monitor"));
}

# determine if it's a widget or a direct call
if(isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] == "XMLHttpRequest")	{
	$widget = true;
}
else {
	$widget = false;
}
?>

<!-- CSS -->
<style type="text/css">
.traffic-widget {
    margin-bottom: 10px;
}
.traffic-widget .panel {
    margin-bottom: 10px;
    position: relative;
}
.traffic-widget-chart {
    height: 150px;
    position: relative;
    overflow: hidden;
}
.traffic-widget .panel-body {
    padding: 8px;
}
.traffic-widget-stats {
    display: flex;
    justify-content: space-between;
    margin-top: 5px;
    font-size: 12px;
}
.traffic-widget .card-title {
    font-size: 13px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    padding-right: 50px; /* 增加右侧内边距，为拖拽手柄和删除按钮留出更多空间 */
}
.traffic-widget .panel-heading {
    padding: 8px 10px;
    position: relative;
}
.traffic-widget .remove-card {
    position: absolute;
    top: 8px;
    right: 10px;
    cursor: pointer;
    color: #d9534f;
    font-size: 14px;
    width: 20px;
    height: 20px;
    text-align: center;
    line-height: 20px;
    border-radius: 50%;
    background-color: #f5f5f5;
    transition: all 0.2s ease;
}
.traffic-widget .remove-card:hover {
    background-color: #d9534f;
    color: #fff;
    transform: scale(1.1);
}
.traffic-widget .drag-handle {
    position: absolute;
    top: 8px;
    right: 35px;
    cursor: move;
    color: #666;
    font-size: 14px;
    width: 20px;
    height: 20px;
    text-align: center;
    line-height: 20px;
}
.traffic-widget .drag-handle:hover {
    color: #337ab7;
}
.traffic-widget .no-cards {
    text-align: center;
    padding: 20px;
    color: #777;
}
.traffic-widget .label {
    margin-right: 3px;
    display: inline-block;
    margin-bottom: 3px;
}
.traffic-widget .traffic-more {
    text-align: center;
    margin-top: 10px;
}
.traffic-widget canvas {
    display: block;
    width: 100%;
    height: 100%;
}
.traffic-widget .traffic-grid {
    display: flex;
    flex-wrap: wrap;
    margin: 0 -8px;
}
.traffic-widget .traffic-grid-item {
    width: 50%;
    padding: 0 8px;
    margin-bottom: 15px;
    box-sizing: border-box;
}
.traffic-widget .widget-controls {
    margin-bottom: 10px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.traffic-widget .ui-sortable-helper {
    box-shadow: 0 5px 15px rgba(0,0,0,0.2);
    opacity: 0.9;
}
.traffic-widget .ui-sortable-placeholder {
    visibility: visible !important;
    background-color: #f9f9f9;
    border: 2px dashed #ddd;
    border-radius: 4px;
    margin-bottom: 10px;
    min-height: 230px;
}
.traffic-widget .sort-mode-active .panel {
    cursor: move;
}
.traffic-widget .sort-mode-active .panel:hover {
    border-color: #337ab7;
}
</style>

<!-- JS -->
<script>
$(document).ready(function() {
    console.log('流量监控小部件初始化中...');
    
    // 获取保存的卡片从localStorage
    var savedCards = localStorage.getItem('trafficMonitorCards');
    var trafficCards = [];
    
    if (savedCards) {
        try {
            console.log('发现保存的卡片数据');
            trafficCards = JSON.parse(savedCards);
            console.log('加载到 ' + trafficCards.length + ' 个卡片');
        } catch (e) {
            console.error('Error loading saved traffic cards:', e);
        }
    } else {
        console.log('未找到保存的卡片数据');
    }
    
    // 卡片容器
    const $container = $('#traffic-widget-container');
    
    // 检查是否有卡片
    if (!trafficCards || trafficCards.length === 0) {
        console.log('没有卡片，显示提示信息');
        $container.html('<div class="no-cards"><?php print _("No traffic monitoring cards saved. Add cards on the traffic monitor page."); ?></div>');
        return;
    }
    
    // 添加控制按钮和网格容器
    $container.html(`
        <div class="widget-controls">
            <div>
                <button class="btn btn-xs btn-primary toggle-sort-btn">
                    <i class="fa fa-arrows"></i> <?php print _("排序卡片"); ?>
                </button>
                <button class="btn btn-xs btn-success save-order-btn" style="display:none;">
                    <i class="fa fa-check"></i> <?php print _("保存排序"); ?>
                </button>
                <button class="btn btn-xs btn-default cancel-sort-btn" style="display:none;">
                    <i class="fa fa-times"></i> <?php print _("取消"); ?>
                </button>
            </div>
            <div>
                <a href="<?php print create_link("tools", "traffic-monitor"); ?>" class="btn btn-xs btn-default">
                    <i class="fa fa-external-link"></i> <?php print _("管理监控卡片"); ?>
                </a>
            </div>
        </div>
        <div class="traffic-grid" id="traffic-card-grid"></div>
    `);
    
    const $grid = $('#traffic-card-grid');
    
    // 加载所有卡片
    trafficCards.forEach(function(card, index) {
        const cardHtml = createTrafficCard(card, index);
        $grid.append(cardHtml);
    });
    
    // 如果卡片数量超过4个，添加"查看更多"链接
    if (trafficCards.length > 4) {
        $container.append(`
            <div class="traffic-more">
                <a href="<?php print create_link("tools", "traffic-monitor"); ?>" class="btn btn-xs btn-default">
                    <?php print _("查看全部"); ?> (${trafficCards.length} <?php print _("张卡片"); ?>)
                </a>
            </div>
        `);
    }
    
    // 加载每张卡片的数据
    trafficCards.forEach(function(card, index) {
        loadTrafficData(card, index);
    });
    
    // 排序功能
    let isSortMode = false;
    let originalOrder = [];
    
    // 开启排序模式
    $('.toggle-sort-btn').click(function() {
        isSortMode = true;
        originalOrder = [];
        
        // 保存原始顺序
        $('.traffic-grid-item').each(function() {
            originalOrder.push($(this).attr('id'));
        });
        
        // 显示拖拽手柄
        $('.drag-handle').show();
        
        // 隐藏和显示相关按钮
        $(this).hide();
        $('.save-order-btn, .cancel-sort-btn').show();
        
        // 添加排序类
        $grid.addClass('sort-mode-active');
        
        // 初始化排序
        $grid.sortable({
            items: '.traffic-grid-item',
            handle: '.drag-handle',
            placeholder: 'traffic-grid-item ui-sortable-placeholder',
            tolerance: 'pointer',
            start: function(event, ui) {
                ui.item.addClass('ui-sortable-helper');
            },
            stop: function(event, ui) {
                ui.item.removeClass('ui-sortable-helper');
            }
        }).disableSelection();
        
        showNotification('<?php print _("排序模式已启用，拖拽卡片重新排序"); ?>', 'info');
    });
    
    // 保存排序
    $('.save-order-btn').click(function() {
        // 获取新顺序
        const newOrder = [];
        $('.traffic-grid-item').each(function() {
            const cardId = $(this).attr('id');
            const index = parseInt(cardId.replace('traffic-grid-item-', ''));
            newOrder.push(trafficCards[index]);
        });
        
        // 更新localStorage
        localStorage.setItem('trafficMonitorCards', JSON.stringify(newOrder));
        
        // 退出排序模式
        exitSortMode();
        showNotification('<?php print _("卡片顺序已保存"); ?>', 'success');
        
        // 刷新页面以显示新顺序
        setTimeout(function() {
            location.reload();
        }, 1000);
    });
    
    // 取消排序
    $('.cancel-sort-btn').click(function() {
        // 恢复原始顺序
        const $items = $('.traffic-grid-item').detach();
        originalOrder.forEach(function(id) {
            $grid.append($items.filter('#' + id));
        });
        
        // 退出排序模式
        exitSortMode();
    });
    
    // 退出排序模式的函数
    function exitSortMode() {
        isSortMode = false;
        $('.drag-handle').hide();
        $('.toggle-sort-btn').show();
        $('.save-order-btn, .cancel-sort-btn').hide();
        $grid.removeClass('sort-mode-active');
        
        if ($grid.hasClass('ui-sortable')) {
            $grid.sortable('destroy');
        }
    }
    
    // 创建流量卡片HTML
    function createTrafficCard(card, index) {
        const cardId = 'traffic-widget-card-' + index;
        const chartId = 'traffic-widget-chart-' + index;
        const canvasId = 'traffic-widget-canvas-' + index;
        
        return `
            <div class="traffic-grid-item" id="traffic-grid-item-${index}">
                <div class="panel panel-default" id="${cardId}" data-device-id="${card.deviceId}" data-interface-id="${card.interfaceId}">
                    <div class="panel-heading">
                        <h3 class="panel-title card-title">${card.deviceName} - ${card.interfaceName}</h3>
                        <i class="fa fa-bars drag-handle" title="<?php print _("拖拽排序"); ?>" style="display:none;"></i>
                        <i class="fa fa-times remove-card" title="<?php print _("移除卡片"); ?>"></i>
                    </div>
                    <div class="panel-body">
                        <div class="traffic-widget-chart" id="${chartId}">
                            <canvas id="${canvasId}"></canvas>
                        </div>
                        <div class="traffic-widget-stats">
                            <div>
                                <span class="label label-primary in-traffic-${index}">0 bps</span>
                                <span class="label label-success out-traffic-${index}">0 bps</span>
                            </div>
                            <div>
                                <span class="label label-info time-${index}"></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }
    
    // 加载流量数据
    function loadTrafficData(card, index) {
        // 获取图表容器
        const $chartContainer = $('#traffic-widget-chart-' + index);
        const canvasId = 'traffic-widget-canvas-' + index;
        
        // 显示加载中
        $chartContainer.html('<canvas id="' + canvasId + '"></canvas>');
        
        // 从API加载数据
        $.ajax({
            url: '/app/tools/traffic-monitor/ajax_get_traffic.php',
            method: 'GET',
            data: {
                deviceId: card.deviceId,
                interfaceId: card.interfaceId,
                timespan: card.timespan || '1d',
                _: new Date().getTime() // 添加时间戳防止缓存
            },
            dataType: 'json',
            cache: false,
            success: function(response) {
                if (response && response.success) {
                    // 获取数据
                    const inData = response.in_data || [];
                    const outData = response.out_data || [];
                    
                    // 绘制图表
                    drawSimpleChart(canvasId, inData, outData);
                    
                    // 更新统计数据
                    if (inData.length > 0) {
                        $('.in-traffic-' + index).text(formatBits(inData[inData.length - 1][1]));
                    }
                    if (outData.length > 0) {
                        $('.out-traffic-' + index).text(formatBits(outData[outData.length - 1][1]));
                    }
                    
                    // 更新时间 - 使用服务器返回的时间范围或当前时间
                    let timeText = '';
                    if (response.data_range && response.data_range.last_time_str) {
                        // 直接使用服务器返回的格式化时间
                        const lastTimeStr = response.data_range.last_time_str;
                        // 将服务器格式 YYYY-MM-DD HH:MM:SS 转换为小部件格式 MM-DD HH:MM
                        const parts = lastTimeStr.split(' ');
                        if (parts.length === 2) {
                            const dateParts = parts[0].split('-');
                            const timeParts = parts[1].split(':');
                            if (dateParts.length === 3 && timeParts.length >= 2) {
                                timeText = dateParts[1] + '-' + dateParts[2] + ' ' + 
                                           timeParts[0] + ':' + timeParts[1];
                            } else {
                                timeText = lastTimeStr;
                            }
                        } else {
                            timeText = lastTimeStr;
                        }
                    } else {
                        // 使用当前时间作为备选
                        const now = new Date();
                        timeText = (now.getMonth() + 1) + '-' + now.getDate() + ' ' + 
                                   now.getHours() + ':' + (now.getMinutes() < 10 ? '0' : '') + now.getMinutes();
                    }
                    $('.time-' + index).text(timeText);
                } else {
                    // 查询失败，显示错误信息
                    const errorMsg = response.error || '获取数据失败';
                    $chartContainer.html('<div class="alert alert-warning" style="margin:0;padding:5px;font-size:12px;">' + errorMsg + '</div>');
                    
                    // 清空统计数据
                    $('.in-traffic-' + index).text('- bps');
                    $('.out-traffic-' + index).text('- bps');
                    $('.time-' + index).text('无数据');
                }
            },
            error: function(xhr, status, error) {
                // 请求失败
                $chartContainer.html('<div class="alert alert-danger" style="margin:0;padding:5px;font-size:12px;">请求失败: ' + (error || status) + '</div>');
                
                // 清空统计数据
                $('.in-traffic-' + index).text('- bps');
                $('.out-traffic-' + index).text('- bps');
                $('.time-' + index).text('请求错误');
            }
        });
    }
    
    // 格式化比特为人类可读格式
    function formatBits(bits) {
        if (bits === 0) return '0 bps';
        
        const units = ['bps', 'Kbps', 'Mbps', 'Gbps', 'Tbps'];
        let i = 0;
        
        while (bits >= 1000 && i < units.length - 1) {
            bits /= 1000;
            i++;
        }
        
        return bits.toFixed(1) + ' ' + units[i];
    }
    
    // 绘制简单图表
    function drawSimpleChart(canvasId, inData, outData) {
        const canvas = document.getElementById(canvasId);
        if (!canvas) return;
        
        const ctx = canvas.getContext('2d');
        
        // 确保画布尺寸正确设置
        canvas.width = canvas.parentElement.offsetWidth;
        canvas.height = canvas.parentElement.offsetHeight;
        
        // 清除画布
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        
        // 检查数据
        if (!inData || !outData || inData.length === 0 || outData.length === 0) {
            ctx.font = '12px Arial';
            ctx.fillStyle = '#999';
            ctx.textAlign = 'center';
            ctx.fillText('No data available', canvas.width / 2, canvas.height / 2);
            return;
        }
        
        // 设置边距
        const margin = { top: 5, right: 5, bottom: 5, left: 5 };
        const chartWidth = canvas.width - margin.left - margin.right;
        const chartHeight = canvas.height - margin.top - margin.bottom;
        
        // 查找最小/最大值
        let minTimestamp = Number.MAX_SAFE_INTEGER;
        let maxTimestamp = 0;
        let maxValue = 0;
        
        inData.forEach(point => {
            if (Array.isArray(point) && point.length === 2) {
                minTimestamp = Math.min(minTimestamp, point[0]);
                maxTimestamp = Math.max(maxTimestamp, point[0]);
                maxValue = Math.max(maxValue, point[1]);
            }
        });
        
        outData.forEach(point => {
            if (Array.isArray(point) && point.length === 2) {
                minTimestamp = Math.min(minTimestamp, point[0]);
                maxTimestamp = Math.max(maxTimestamp, point[0]);
                maxValue = Math.max(maxValue, point[1]);
            }
        });
        
        // 添加到最大值的余量
        maxValue = maxValue * 1.1;
        
        // 缩放因子
        const xScale = chartWidth / (maxTimestamp - minTimestamp);
        const yScale = chartHeight / maxValue;
        
        // 绘制背景
        ctx.fillStyle = '#f9f9f9';
        ctx.fillRect(margin.left, margin.top, chartWidth, chartHeight);
        
        // 绘制网格线（简化版）
        ctx.beginPath();
        ctx.strokeStyle = '#eeeeee';
        ctx.lineWidth = 0.5;
        
        // 水平网格线（3条）
        for (let i = 1; i <= 3; i++) {
            const y = margin.top + (chartHeight / 3) * i;
            ctx.moveTo(margin.left, y);
            ctx.lineTo(margin.left + chartWidth, y);
        }
        
        // 垂直网格线（3条）
        for (let i = 1; i <= 3; i++) {
            const x = margin.left + (chartWidth / 3) * i;
            ctx.moveTo(x, margin.top);
            ctx.lineTo(x, margin.top + chartHeight);
        }
        
        ctx.stroke();
        
        // 绘制入流量线
        ctx.beginPath();
        ctx.strokeStyle = '#00c0ef';
        ctx.lineWidth = 1.5;
        
        let firstPoint = true;
        inData.forEach(point => {
            if (Array.isArray(point) && point.length === 2) {
                const x = margin.left + (point[0] - minTimestamp) * xScale;
                const y = margin.top + chartHeight - (point[1] * yScale);
                
                if (firstPoint) {
                    ctx.moveTo(x, y);
                    firstPoint = false;
                } else {
                    ctx.lineTo(x, y);
                }
            }
        });
        
        ctx.stroke();
        
        // 为入流量添加浅色填充
        if (!firstPoint) {
            ctx.lineTo(margin.left + chartWidth, margin.top + chartHeight);
            ctx.lineTo(margin.left, margin.top + chartHeight);
            ctx.closePath();
            ctx.fillStyle = 'rgba(0, 192, 239, 0.1)';
            ctx.fill();
        }
        
        // 绘制出流量线
        ctx.beginPath();
        ctx.strokeStyle = '#8957ff';
        ctx.lineWidth = 1.5;
        
        firstPoint = true;
        outData.forEach(point => {
            if (Array.isArray(point) && point.length === 2) {
                const x = margin.left + (point[0] - minTimestamp) * xScale;
                const y = margin.top + chartHeight - (point[1] * yScale);
                
                if (firstPoint) {
                    ctx.moveTo(x, y);
                    firstPoint = false;
                } else {
                    ctx.lineTo(x, y);
                }
            }
        });
        
        ctx.stroke();
        
        // 为出流量添加浅色填充
        if (!firstPoint) {
            ctx.lineTo(margin.left + chartWidth, margin.top + chartHeight);
            ctx.lineTo(margin.left, margin.top + chartHeight);
            ctx.closePath();
            ctx.fillStyle = 'rgba(137, 87, 255, 0.1)';
            ctx.fill();
        }
        
        // 绘制边框
        ctx.strokeStyle = '#ddd';
        ctx.lineWidth = 1;
        ctx.strokeRect(margin.left, margin.top, chartWidth, chartHeight);
    }

    // 添加点击事件处理卡片删除
    $grid.on('click', '.remove-card', function(e) {
        // 如果在排序模式下，不允许删除
        if (isSortMode) {
            showNotification('<?php print _("排序模式下不能删除卡片，请先保存或取消排序"); ?>', 'warning');
            return false;
        }
        
        e.preventDefault();
        e.stopPropagation();
        
        const $card = $(this).closest('.panel');
        const deviceId = $card.data('device-id');
        const interfaceId = $card.data('interface-id');
        
        console.log('删除卡片:', deviceId, interfaceId);
        
        // 从localStorage中读取卡片
        let savedCards = localStorage.getItem('trafficMonitorCards');
        if (savedCards) {
            try {
                // 解析保存的卡片
                let cards = JSON.parse(savedCards);
                console.log('当前卡片数量:', cards.length);
                
                // 过滤掉要删除的卡片
                cards = cards.filter(card => 
                    !(card.deviceId == deviceId && card.interfaceId == interfaceId)
                );
                console.log('删除后卡片数量:', cards.length);
                
                // 保存回localStorage
                localStorage.setItem('trafficMonitorCards', JSON.stringify(cards));
                console.log('更新后的卡片已保存到localStorage');
                
                // 显示成功消息
                showNotification('<?php print _("卡片已成功移除"); ?>', 'success');
                
                // 移除卡片元素
                $(this).closest('.traffic-grid-item').fadeOut('fast', function() {
                    $(this).remove();
                    
                    // 如果删除后没有卡片了，显示无卡片提示
                    if ($('#traffic-card-grid .traffic-grid-item').length === 0) {
                        $('#traffic-widget-container').html('<div class="no-cards"><?php print _("No traffic monitoring cards saved. Add cards on the traffic monitor page."); ?></div>');
                    }
                    
                    // 刷新"查看全部"按钮
                    updateShowAllButton(cards.length);
                });
            } catch (e) {
                console.error('Error processing saved cards:', e);
                showNotification('<?php print _("处理卡片数据时出错"); ?>', 'danger');
            }
        }
    });

    // 更新"查看全部"按钮
    function updateShowAllButton(totalCards) {
        $('.traffic-more').remove();
        if (totalCards > 4) {
            $container.append(`
                <div class="traffic-more">
                    <a href="<?php print create_link("tools", "traffic-monitor"); ?>" class="btn btn-xs btn-default">
                        <?php print _("查看全部"); ?> (${totalCards} <?php print _("张卡片"); ?>)
                    </a>
                </div>
            `);
        }
    }
    
    // 显示通知消息
    function showNotification(message, type = 'info') {
        // 创建通知元素
        const $notification = $(`<div class="alert alert-${type}" style="position:fixed;top:20px;right:20px;z-index:9999;min-width:200px;max-width:350px;box-shadow:0 4px 8px rgba(0,0,0,0.2);">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            ${message}
        </div>`);
        
        // 添加到页面
        $('body').append($notification);
        
        // 3秒后自动关闭
        setTimeout(() => {
            $notification.fadeOut('slow', function() {
                $(this).remove();
            });
        }, 3000);
    }
    
    // 响应窗口大小变化
    $(window).resize(function() {
        trafficCards.forEach(function(card, index) {
            const canvasId = 'traffic-widget-canvas-' + index;
            const canvas = document.getElementById(canvasId);
            const $chartContainer = $('#traffic-widget-chart-' + index);
            
            if (canvas && $chartContainer.length) {
                canvas.width = $chartContainer.width();
                canvas.height = $chartContainer.height();
                
                // 重新加载数据
                loadTrafficData(card, index);
            }
        });
    });
});
</script>

<div class="traffic-widget">
    <div id="traffic-widget-container"></div>
</div> 