<?php
/**
 * Traffic Monitor widget for dashboard
 *
 * Displays traffic monitoring cards from the first dashboard in database
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

# 获取第一个看板的前4个卡片
$traffic_cards_data = [];
try {
    // 获取第一个活跃的看板
    $first_dashboard = $Database->getObjectQuery("traffic_dashboards", "SELECT id FROM traffic_dashboards WHERE is_active = 1 ORDER BY id ASC LIMIT 1");
    
    if ($first_dashboard) {
        // 获取该看板的前4个卡片
        $cards = $Database->getObjectsQuery("traffic_dashboard_cards", 
            "SELECT c.*, d.hostname as device_name, d.ip_addr as device_ip 
             FROM traffic_dashboard_cards c 
             LEFT JOIN devices d ON c.device_id = d.id 
             WHERE c.dashboard_id = ? AND c.is_active = 1 
             ORDER BY c.position_y, c.position_x 
             LIMIT 4", 
            array($first_dashboard->id));
        
        if ($cards) {
            foreach ($cards as $card) {
                $traffic_cards_data[] = [
                    'deviceId' => $card->device_id,
                    'interfaceId' => $card->interface_id,
                    'deviceName' => $card->device_name,
                    'interfaceName' => $card->interface_id, // 只传接口名，用于查询
                    'cardName' => $card->card_name,
                    'timespan' => $card->timespan
                ];
            }
        }
    }
} catch (Exception $e) {
    // 出错时使用空数组
    $traffic_cards_data = [];
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
    // 从PHP获取卡片数据
    var trafficCards = <?php echo json_encode($traffic_cards_data); ?>;
    
    // 卡片容器
    const $container = $('#traffic-widget-container');
    
    // 检查是否有卡片
    if (!trafficCards || trafficCards.length === 0) {
        $container.html('<div class="no-cards"><?php print _("暂无流量监控卡片。请前往流量监控页面添加卡片。"); ?></div>');
        return;
    }
    
    // 添加控制按钮和网格容器
    $container.html(`
        <div class="widget-controls">
            <div>
                <span class="text-muted"><?php print _("显示前"); ?> ${trafficCards.length} <?php print _("个监控卡片"); ?></span>
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
    
    // 加载所有卡片（最多4个）
    trafficCards.forEach(function(card, index) {
        const cardHtml = createTrafficCard(card, index);
        $grid.append(cardHtml);
    });
    
    // 加载每张卡片的数据
    trafficCards.forEach(function(card, index) {
        loadTrafficData(card, index);
    });
});

// 创建流量卡片HTML
function createTrafficCard(card, index) {
    const cardId = 'traffic-widget-card-' + index;
    const chartId = 'traffic-widget-chart-' + index;
    const canvasId = 'traffic-widget-canvas-' + index;
    
    return `
        <div class="traffic-grid-item" id="traffic-grid-item-${index}">
            <div class="panel panel-default" id="${cardId}" data-device-id="${card.deviceId}" data-interface-id="${card.interfaceId}">
                <div class="panel-heading">
                    <h3 class="panel-title card-title">${card.cardName || (card.deviceName + ' - ' + card.interfaceName)}</h3>
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
    
    // 从API加载数据 - 只传接口名
    $.ajax({
        url: '/app/tools/traffic-monitor/ajax_get_traffic.php',
        method: 'GET',
        data: {
            deviceId: card.deviceId,
            interfaceId: card.interfaceName, // 只传接口名用于查询
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
                
                // 更新时间
                let timeText = '';
                if (response.data_range && response.data_range.last_time_str) {
                    const lastTimeStr = response.data_range.last_time_str;
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
                    const now = new Date();
                    timeText = (now.getMonth() + 1) + '-' + now.getDate() + ' ' + 
                               now.getHours() + ':' + (now.getMinutes() < 10 ? '0' : '') + now.getMinutes();
                }
                $('.time-' + index).text(timeText);
            } else {
                const errorMsg = response.error || '获取数据失败';
                $chartContainer.html('<div class="alert alert-warning" style="margin:0;padding:5px;font-size:12px;">' + errorMsg + '</div>');
                $('.in-traffic-' + index).text('- bps');
                $('.out-traffic-' + index).text('- bps');
                $('.time-' + index).text('无数据');
            }
        },
        error: function(xhr, status, error) {
            $chartContainer.html('<div class="alert alert-danger" style="margin:0;padding:5px;font-size:12px;">请求失败: ' + (error || status) + '</div>');
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
        ctx.fillText('暂无数据', canvas.width / 2, canvas.height / 2);
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
    
    // 添加最大值余量
    maxValue = maxValue * 1.1;
    
    // 缩放因子
    const xScale = chartWidth / (maxTimestamp - minTimestamp);
    const yScale = chartHeight / maxValue;
    
    // 绘制背景
    ctx.fillStyle = '#f9f9f9';
    ctx.fillRect(margin.left, margin.top, chartWidth, chartHeight);
    
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
    
    // 绘制边框
    ctx.strokeStyle = '#ddd';
    ctx.lineWidth = 1;
    ctx.strokeRect(margin.left, margin.top, chartWidth, chartHeight);
}
</script>

<!-- 流量监控小部件容器 -->
<div class="traffic-widget" id="traffic-widget-container">
    <div class="text-center">
        <i class="fa fa-spinner fa-spin"></i> 加载中...
    </div>
</div> 