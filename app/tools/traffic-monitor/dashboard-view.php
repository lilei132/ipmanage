<?php
/**
 * 流量监控看板详情页面
 * 注意：这个文件被 index.php 包含，不需要重复引入函数文件和检查权限
 */

# ID清理函数
function sanitizeId($id) {
    return preg_replace('/[^a-zA-Z0-9_-]/', '_', $id);
}

# 获取看板ID
$dashboard_id = isset($_GET['dashboard_id']) ? intval($_GET['dashboard_id']) : 0;

if ($dashboard_id <= 0) {
    $Result->show("danger", _("无效的看板ID"), true);
    die();
}

# 获取看板信息
$dashboard = $Database->getObjectQuery("traffic_dashboards", "SELECT * FROM traffic_dashboards WHERE id = ? AND is_active = 1", array($dashboard_id));

if (!$dashboard) {
    $Result->show("danger", _("看板不存在"), true);
    die();
}

# 获取看板的所有卡片
$cards = $Database->getObjectsQuery("traffic_dashboard_cards", "SELECT c.*, d.hostname as device_name, d.ip_addr as device_ip 
              FROM traffic_dashboard_cards c 
              LEFT JOIN devices d ON c.device_id = d.id 
              WHERE c.dashboard_id = ? AND c.is_active = 1 
              ORDER BY c.position_y, c.position_x", array($dashboard_id));

# 如果没有获取到卡片，设置为空数组
if (!$cards) {
    $cards = array();
}

# 获取所有支持SNMP的设备
$devices = [];
try {
    $devices = $Database->getObjectsQuery("devices", "SELECT id, hostname, ip_addr FROM devices WHERE snmp_version IS NOT NULL AND snmp_version != '' ORDER BY hostname");
} catch (Exception $e) {
    # 如果查询失败，使用空数组
    $devices = [];
}
?>

<div class="row">
    <div class="col-md-12">
        <!-- 页面标题 -->
        <div class="dashboard-view-header">
            <h4>
                <i class="fa fa-dashboard"></i> 
                <?php echo htmlspecialchars($dashboard->name); ?>
                <small class="text-muted"><?php echo htmlspecialchars($dashboard->description); ?></small>
            </h4>
        </div>
        
        <hr>
        
        <!-- 操作按钮 -->
        <div class="dashboard-view-actions" style="margin-bottom: 20px;">
            <a href="<?php print create_link('tools', 'traffic-monitor'); ?>" class="btn btn-default">
                <i class="fa fa-arrow-left"></i> <?php print _("返回看板列表"); ?>
            </a>
            
            <button class="btn btn-success" data-toggle="modal" data-target="#addCardModal">
                <i class="fa fa-plus"></i> <?php print _("添加流量卡片"); ?>
            </button>
            
            <button class="btn btn-info edit-dashboard" 
                    data-dashboard-id="<?php echo $dashboard->id; ?>"
                    data-dashboard-name="<?php echo htmlspecialchars($dashboard->name); ?>"
                    data-dashboard-description="<?php echo htmlspecialchars($dashboard->description); ?>">
                <i class="fa fa-edit"></i> <?php print _("编辑看板"); ?>
            </button>
            
            <div class="btn-group" style="margin-left: 10px;">
                <button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown">
                    <i class="fa fa-cog"></i> <?php print _("设置"); ?> <span class="caret"></span>
                </button>
                <ul class="dropdown-menu">
                    <li><a href="#" id="refresh-all-cards"><i class="fa fa-refresh"></i> <?php print _("刷新所有卡片"); ?></a></li>
                    <li class="divider"></li>
                    <li><a href="#" id="export-dashboard"><i class="fa fa-download"></i> <?php print _("导出配置"); ?></a></li>
                </ul>
            </div>
        </div>
        
        <!-- 卡片网格 -->
        <div class="row" id="cards-grid">
            <?php if (!empty($cards) && is_array($cards)): ?>
                <?php foreach ($cards as $card): ?>
                    <div class="col-md-6 traffic-card" 
                         data-card-id="<?php echo $card->id; ?>"
                         data-device-id="<?php echo $card->device_id; ?>"
                         data-interface-id="<?php echo $card->interface_id; ?>"
                         style="margin-bottom: 20px;">
                        <div class="panel panel-default">
                        <div class="panel-heading">
                            <div class="pull-right">
                                <div class="btn-group timespan-selector">
                                    <button type="button" class="btn btn-xs btn-default<?php echo $card->timespan === '1h' ? ' active' : ''; ?>" data-timespan="1h">1小时</button>
                                    <button type="button" class="btn btn-xs btn-default<?php echo $card->timespan === '24h' || $card->timespan === '1d' ? ' active' : ''; ?>" data-timespan="24h">1天</button>
                                    <button type="button" class="btn btn-xs btn-default<?php echo $card->timespan === '7d' ? ' active' : ''; ?>" data-timespan="7d">7天</button>
                                </div>
                                <button type="button" class="btn btn-xs btn-danger delete-card" 
                                        data-card-id="<?php echo $card->id; ?>"
                                        data-card-name="<?php echo htmlspecialchars($card->card_name); ?>">
                                    <i class="fa fa-times"></i>
                                </button>
                            </div>
                            <h3 class="panel-title">
                                <i class="fa fa-chart-line"></i> 
                                <?php echo htmlspecialchars($card->card_name); ?>
                            </h3>
                        </div>
                        
                        <div class="panel-body">
                            <div class="traffic-chart" 
                                 id="chart-<?php echo $card->device_id; ?>-<?php echo sanitizeId($card->interface_id); ?>" 
                                 data-device-id="<?php echo $card->device_id; ?>"
                                 data-interface-id="<?php echo $card->interface_id; ?>"
                                 data-timespan="<?php echo $card->timespan; ?>"
                                 style="height: 350px; width: 100%; position: relative;">
                                <canvas id="canvas-<?php echo $card->device_id; ?>-<?php echo sanitizeId($card->interface_id); ?>" 
                                        width="100%" height="350"></canvas>
                            </div>
                            <div class="traffic-info" style="margin-top: 10px;">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="traffic-stats">
                                            <span class="label label-primary">入流量: <span class="in-traffic">0 bps</span></span>
                                            <span class="label label-success">出流量: <span class="out-traffic">0 bps</span></span>
                                        </div>
                                    </div>
                                    <div class="col-md-6 text-right">
                                        <span class="label label-info">链路速度: <span class="link-speed">未知</span></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-dashboard">
                    <div class="alert alert-info text-center">
                        <i class="fa fa-info-circle fa-3x" style="margin-bottom: 10px;"></i>
                        <h4><?php print _("看板为空"); ?></h4>
                        <p><?php print _("这个看板还没有添加任何流量监控卡片。"); ?></p>
                        <button class="btn btn-success" data-toggle="modal" data-target="#addCardModal">
                            <i class="fa fa-plus"></i> <?php print _("添加第一个卡片"); ?>
                        </button>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- 添加卡片模态框 -->
<div class="modal fade" id="addCardModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
                <h4 class="modal-title"><?php print _("添加流量监控卡片"); ?></h4>
            </div>
            <form id="addCardForm">
                <input type="hidden" name="dashboard_id" value="<?php echo $dashboard->id; ?>">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="device_id"><?php print _("设备"); ?> *</label>
                                <select class="form-control" id="device_id" name="device_id" required>
                                    <option value=""><?php print _("请选择设备"); ?></option>
                                    <?php
                                    # 显示支持SNMP的设备
                                    if ($devices && is_array($devices)) {
                                        foreach ($devices as $device) {
                                            echo "<option value='" . $device->id . "' data-hostname='" . htmlspecialchars($device->hostname) . "'>" . htmlspecialchars($device->hostname) . " (" . $device->ip_addr . ")</option>";
                                        }
                                    } else {
                                        echo "<option value=''>" . _("暂无可用设备") . "</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="interface_id"><?php print _("接口"); ?> *</label>
                                <select class="form-control" id="interface_id" name="interface_id" required disabled>
                                    <option value=""><?php print _("请先选择设备"); ?></option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <div class="alert alert-info">
                            <i class="fa fa-info-circle"></i> 
                            卡片名称将自动生成为"设备名 | 接口名 | 接口描述"格式，默认显示最近7天的流量数据。
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal"><?php print _("取消"); ?></button>
                    <button type="submit" class="btn btn-success"><?php print _("添加卡片"); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 编辑看板模态框 -->
<div class="modal fade" id="editDashboardModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
                <h4 class="modal-title"><?php print _("编辑看板"); ?></h4>
            </div>
            <form id="editDashboardForm">
                <input type="hidden" id="edit_dashboard_id" name="dashboard_id">
                <div class="modal-body">
                    <div class="form-group">
                        <label for="edit_dashboard_name"><?php print _("看板名称"); ?> *</label>
                        <input type="text" class="form-control" id="edit_dashboard_name" name="name" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_dashboard_description"><?php print _("看板描述"); ?></label>
                        <textarea class="form-control" id="edit_dashboard_description" name="description" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal"><?php print _("取消"); ?></button>
                    <button type="submit" class="btn btn-primary"><?php print _("保存更改"); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 主题切换按钮 -->
<button class="theme-toggle" id="themeToggle" title="切换主题">
    <i class="fa fa-moon-o"></i> <span class="theme-text">暗色</span>
</button>

<!-- 样式 -->
<style>
/* 主题变量 */
:root {
    --chart-bg-start: #fafafa;
    --chart-bg-end: #f5f5f5;
    --chart-hover-bg-start: #f8f9fa;
    --chart-hover-bg-end: #f0f1f2;
    --chart-border: #ddd;
    --chart-text: #333;
    --chart-grid: #ddd;
}

/* 暗色主题 */
[data-theme="dark"] {
    --chart-bg-start: #2a2a2a;
    --chart-bg-end: #1e1e1e;
    --chart-hover-bg-start: #333333;
    --chart-hover-bg-end: #2a2a2a;
    --chart-border: #444;
    --chart-text: #e0e0e0;
    --chart-grid: #444;
}

.dashboard-view-header h4 {
    margin-bottom: 5px;
}

#cards-grid {
    min-height: 400px;
}

.traffic-card {
    margin-bottom: 20px;
}

@media (max-width: 768px) {
    .traffic-card {
        width: 100%;
    }
}

.traffic-card {
    border: 1px solid var(--chart-border);
    border-radius: 6px;
    background: white;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    transition: transform 0.2s, box-shadow 0.2s;
}

[data-theme="dark"] .traffic-card {
    background: #2a2a2a;
    color: var(--chart-text);
    box-shadow: 0 2px 4px rgba(0,0,0,0.3);
}

.traffic-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.15);
}

[data-theme="dark"] .traffic-card:hover {
    box-shadow: 0 4px 8px rgba(0,0,0,0.4);
}

.card-header {
    padding: 12px 15px;
    border-bottom: 1px solid #eee;
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #f8f9fa;
    border-radius: 6px 6px 0 0;
}

[data-theme="dark"] .card-header {
    background: #333;
    border-bottom: 1px solid #444;
    color: var(--chart-text);
}

.card-title {
    font-weight: 600;
    color: var(--chart-text);
}

.card-actions {
    display: flex;
    gap: 5px;
}

.card-body {
    padding: 15px;
}

.card-content {
    margin-bottom: 10px;
}

.card-info {
    border-top: 1px solid #eee;
    padding-top: 10px;
    margin-top: 10px;
}

[data-theme="dark"] .card-info {
    border-top: 1px solid #444;
}

.empty-dashboard {
    grid-column: 1 / -1;
    padding: 40px;
}

.traffic-chart {
    width: 100%;
    height: 350px; /* 固定高度 */
    position: relative;
    background: linear-gradient(135deg, var(--chart-bg-start) 0%, var(--chart-bg-end) 100%);
    border-radius: 4px;
    overflow: hidden;
    transition: background 0.3s ease;
}

/* 图表容器悬停效果 */
.traffic-card:hover .traffic-chart {
    background: linear-gradient(135deg, var(--chart-hover-bg-start) 0%, var(--chart-hover-bg-end) 100%);
}

.card-loading {
    text-align: center;
    padding: 20px;
    color: #666;
}

/* 流量图表样式 */
.traffic-chart.loading::before {
    content: "加载中...";
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    background: rgba(255, 255, 255, 0.8);
    padding: 10px;
    border-radius: 5px;
    box-shadow: 0 0 5px rgba(0, 0, 0, 0.2);
    z-index: 10;
}

[data-theme="dark"] .traffic-chart.loading::before {
    background: rgba(42, 42, 42, 0.9);
    color: var(--chart-text);
}

.traffic-stats .label {
    margin-right: 5px;
    font-size: 90%;
}

canvas {
    display: block;
    width: 100%;
    height: 100%;
    cursor: crosshair;
    /* 抗锯齿优化 */
    image-rendering: -moz-crisp-edges;
    image-rendering: -webkit-crisp-edges;
    image-rendering: pixelated;
    image-rendering: crisp-edges;
    /* 平滑渲染 */
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
}

.chart-tooltip {
    position: absolute;
    background: rgba(255, 255, 255, 0.95);
    border: 1px solid var(--chart-border);
    border-radius: 4px;
    padding: 8px;
    font-size: 12px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
    z-index: 100;
    pointer-events: none;
    max-width: 200px;
    line-height: 1.5;
    backdrop-filter: blur(10px);
    transition: all 0.2s ease;
}

[data-theme="dark"] .chart-tooltip {
    background: rgba(42, 42, 42, 0.95);
    color: var(--chart-text);
    border-color: #555;
}

.label-primary {
    background-color: #00c0ef;
}

.label-success {
    background-color: #8957ff;
}

.label-info {
    background-color: #17a2b8;
}

.timespan-selector .btn {
    margin-left: 2px;
}

.timespan-selector .btn.active {
    background-color: #007cba;
    color: white;
    border-color: #007cba;
}

/* 主题切换按钮 */
.theme-toggle {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 1000;
    padding: 8px 12px;
    background: var(--chart-bg-start);
    border: 1px solid var(--chart-border);
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.3s ease;
    font-size: 14px;
    color: var(--chart-text);
}

.theme-toggle:hover {
    background: var(--chart-hover-bg-start);
    transform: scale(1.05);
}

/* 响应式优化 */
@media (max-width: 768px) {
    .theme-toggle {
        position: relative;
        top: auto;
        right: auto;
        margin: 10px;
        float: right;
    }
}
</style>

<!-- JavaScript -->
<script>
$(document).ready(function() {
    // 全局变量存储卡片列表
    const trafficCards = [];
    
    // 自动刷新定时器
    const refreshTimers = {};
    
    // 工具函数：清理ID中的特殊字符
    function sanitizeId(id) {
        return id.replace(/[^a-zA-Z0-9_-]/g, '_');
    }
    
    // 初始化所有现有的卡片
    $('.traffic-card').each(function() {
        var $card = $(this);
        var deviceId = $card.data('device-id');
        var interfaceId = $card.data('interface-id');
        var cardId = $card.data('card-id');
        var timespan = $card.find('.timespan-selector .active').data('timespan') || '7d';
        
        console.log('发现卡片:', { deviceId, interfaceId, cardId, timespan });
        
        if (deviceId && interfaceId) {
            console.log('初始化卡片:', deviceId, interfaceId);
            initializeCard($card, deviceId, interfaceId, cardId, timespan);
        } else {
            console.warn('卡片数据不完整:', { deviceId, interfaceId });
        }
    });
    
    function initializeCard($card, deviceId, interfaceId, cardId, timespan) {
        // 清理接口ID中的特殊字符，确保可以安全用作HTML ID
        var cleanInterfaceId = sanitizeId(interfaceId);
        var canvasId = 'canvas-' + deviceId + '-' + cleanInterfaceId;
        var chartId = 'chart-' + deviceId + '-' + cleanInterfaceId;
        
        // 创建卡片对象
        var cardObject = {
            deviceId: deviceId,
            interfaceId: interfaceId,
            element: $card,
            timespan: timespan,
            canvasId: canvasId,
            chartId: chartId,
            inData: [],
            outData: [],
            pointPositions: [],
            zoomState: {
                active: false,
                minTime: null,
                maxTime: null,
                originalMinTime: null,
                originalMaxTime: null,
                scale: 1.0
            },
            zoomSelection: {
                active: false,
                startX: 0,
                currentX: 0
            },
            hoverPoint: null,
            hasMouseEvents: false,
            rawInData: null,
            rawOutData: null,
            // 新增交互状态
            interaction: {
                hoveredLine: null, // 'in' | 'out' | null
                isHovering: false,
                animation: {
                    progress: 0,
                    isAnimating: false,
                    startTime: null
                }
            }
        };
        
        trafficCards.push(cardObject);
        
        // 初始化Canvas尺寸
        var canvas = document.getElementById(canvasId);
        if (canvas) {
            var $chartContainer = $card.find('.traffic-chart');
            // 确保容器有固定高度
            if ($chartContainer.height() < 100) {
                $chartContainer.height(350);
            }
        }
        
        // 加载流量数据
        loadTrafficData(cardObject);
        
        // 设置自动刷新
        refreshTimers[chartId] = setInterval(function() {
            loadTrafficData(cardObject);
        }, 600000); // 每10分钟刷新一次
        
        // 初始化图表交互
        initChartInteractions(canvasId);
        
        // 监听时间范围变化事件
        $card.find('.timespan-selector button').click(function() {
            var $this = $(this);
            var newTimespan = $this.data('timespan');
            
            // 更新按钮状态
            $this.siblings().removeClass('active');
            $this.addClass('active');
            
            // 更新卡片对象中的时间范围
            cardObject.zoomState.active = false;
            cardObject.timespan = newTimespan;
            
            // 重新加载流量数据
            loadTrafficData(cardObject);
        });
    }
    
    // 加载流量数据
    function loadTrafficData(card) {
        var $card = card.element;
        
        // 显示加载中状态
        $card.find('.traffic-chart').addClass('loading');
        
        // 获取流量数据
        $.ajax({
            url: '/app/tools/traffic-monitor/ajax_get_traffic.php',
            method: 'GET',
            data: {
                deviceId: card.deviceId,
                interfaceId: card.interfaceId,
                timespan: card.timespan,
                _: new Date().getTime()
            },
            dataType: 'json',
            cache: false,
            success: function(response) {
                console.log('API响应:', response);
                try {
                    if (response && response.success) {
                        var inData = (response.in_data && Array.isArray(response.in_data)) ? response.in_data : [];
                        var outData = (response.out_data && Array.isArray(response.out_data)) ? response.out_data : [];
                        
                        // 检查是否有数据
                        if (inData.length === 0 && outData.length === 0) {
                            // 没有数据时显示提示信息
                            var canvas = document.getElementById(card.canvasId);
                            if (canvas) {
                                var ctx = canvas.getContext('2d');
                                ctx.clearRect(0, 0, canvas.width, canvas.height);
                                ctx.font = '16px Arial';
                                ctx.fillStyle = '#666';
                                ctx.textAlign = 'center';
                                ctx.fillText('暂无流量数据', canvas.width / 2, canvas.height / 2);
                                
                                if (response.message) {
                                    ctx.font = '12px Arial';
                                    ctx.fillText(response.message, canvas.width / 2, canvas.height / 2 + 25);
                                }
                            }
                            
                            // 更新UI显示为0
                            $card.find('.in-traffic').text('0 bps');
                            $card.find('.out-traffic').text('0 bps');
                            $card.find('.link-speed').text('未知');
                        } else {
                            processTrafficData(card, inData, outData, response.speed);
                        }
                    } else {
                        // 显示错误信息
                        var canvas = document.getElementById(card.canvasId);
                        if (canvas) {
                            var ctx = canvas.getContext('2d');
                            ctx.clearRect(0, 0, canvas.width, canvas.height);
                            ctx.font = '14px Arial';
                            ctx.fillStyle = '#d9534f';
                            ctx.textAlign = 'center';
                            ctx.fillText('数据获取失败', canvas.width / 2, canvas.height / 2);
                            
                            if (response && response.error) {
                                ctx.font = '12px Arial';
                                ctx.fillText(response.error, canvas.width / 2, canvas.height / 2 + 20);
                            }
                        }
                    }
                } catch (e) {
                    console.error('处理数据响应时出错:', e);
                    var canvas = document.getElementById(card.canvasId);
                    if (canvas) {
                        var ctx = canvas.getContext('2d');
                        ctx.clearRect(0, 0, canvas.width, canvas.height);
                        ctx.font = '14px Arial';
                        ctx.fillStyle = '#d9534f';
                        ctx.textAlign = 'center';
                        ctx.fillText('处理数据出错', canvas.width / 2, canvas.height / 2);
                    }
                }
                
                $card.find('.traffic-chart').removeClass('loading');
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error('加载流量数据请求失败:', textStatus, errorThrown);
                $card.find('.traffic-chart').html('<div class="alert alert-danger">请求失败，请刷新重试</div>');
                $card.find('.traffic-chart').removeClass('loading');
            }
        });
    }
    
    // 处理流量数据
    function processTrafficData(card, inData, outData, speed) {
        try {
            // 数据有效性检查
            var validInData = inData?.filter(point => Array.isArray(point) && point.length === 2 && point[0] !== null && point[1] !== null) || [];
            var validOutData = outData?.filter(point => Array.isArray(point) && point.length === 2 && point[0] !== null && point[1] !== null) || [];
            
            // 排序数据
            var finalInData = validInData.sort((a, b) => a[0] - b[0]);
            var finalOutData = validOutData.sort((a, b) => a[0] - b[0]);
            
            // 更新卡片数据
            card.inData = finalInData;
            card.outData = finalOutData;
            card.linkSpeed = speed || 1000000000; // 默认1Gbps
            
            // 找到最大流量值
            var maxIn = Math.max(...finalInData.map(p => p[1]), 0);
            var maxOut = Math.max(...finalOutData.map(p => p[1]), 0);
            
            // 更新UI显示
            var $card = card.element;
            $card.find('.in-traffic').text(formatBits(maxIn));
            $card.find('.out-traffic').text(formatBits(maxOut));
            $card.find('.link-speed').text(formatSpeed(card.linkSpeed));
            $card.find('.traffic-chart').removeClass('loading');
            
            // 绘制图表
            drawTrafficChart(card);
        } catch (e) {
            console.error('处理流量数据出错:', e);
            card.element.find('.traffic-chart').removeClass('loading');
        }
    }
    
    // 绘制流量图表
    function drawTrafficChart(card) {
        var canvas = document.getElementById(card.canvasId);
        if (!canvas) return;
        
        var ctx = canvas.getContext('2d');
        
        // 获取容器实际尺寸
        var $container = $(canvas).parent();
        var containerWidth = $container.width();
        var containerHeight = $container.height() || 350; // 默认高度350px
        
        // 设置Canvas显示尺寸
        canvas.style.width = containerWidth + 'px';
        canvas.style.height = containerHeight + 'px';
        
        // 设置Canvas内部分辨率（考虑设备像素比）
        var devicePixelRatio = window.devicePixelRatio || 1;
        canvas.width = containerWidth * devicePixelRatio;
        canvas.height = containerHeight * devicePixelRatio;
        
        // 缩放绘图上下文以匹配设备像素比
        ctx.scale(devicePixelRatio, devicePixelRatio);
        
        // Canvas渲染优化 - 提高线条清晰度
        ctx.imageSmoothingEnabled = true;
        ctx.imageSmoothingQuality = 'high';
        ctx.lineJoin = 'round';
        ctx.lineCap = 'round';
        
        // 优化文本渲染
        ctx.textRenderingOptimization = 'optimizeQuality';
        
        // 启用亚像素渲染
        ctx.translate(0.5, 0.5);
        
        // 清除画布
        ctx.clearRect(0, 0, containerWidth, containerHeight);
        
        if (!card.inData || !card.outData || card.inData.length === 0) {
            // 显示加载状态
            ctx.fillStyle = getComputedStyle(document.documentElement).getPropertyValue('--chart-text').trim() || '#666';
            ctx.font = '14px Arial';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText('加载中...', containerWidth / 2, containerHeight / 2);
            return;
        }
        
        // 计算图表区域 - 固定边距
        var margin = {
            top: 30,
            right: 50,
            bottom: 50,
            left: 80
        };
        
        var chartWidth = containerWidth - margin.left - margin.right;
        var chartHeight = containerHeight - margin.top - margin.bottom;
        
        // 计算数据范围
        var minTimestamp = Number.MAX_SAFE_INTEGER;
        var maxTimestamp = 0;
        var maxValue = 0;
        
        // 提取时间和数值范围
        var processDataRange = function(data) {
            data.forEach(function(point) {
                if (Array.isArray(point) && point.length === 2 && point[1] !== null) {
                    minTimestamp = Math.min(minTimestamp, point[0]);
                    maxTimestamp = Math.max(maxTimestamp, point[0]);
                    maxValue = Math.max(maxValue, point[1]);
                }
            });
        };
        
        processDataRange(card.inData);
        processDataRange(card.outData);
        
        // 应用缩放状态
        if (card.zoomState?.active) {
            minTimestamp = card.zoomState.minTime;
            maxTimestamp = card.zoomState.maxTime;
        }
        
        // 确保有有效的数据范围
        if (maxTimestamp <= minTimestamp) {
            ctx.font = '14px Arial';
            ctx.fillStyle = '#666';
            ctx.textAlign = 'center';
            ctx.fillText('数据范围无效', canvas.width / 2, canvas.height / 2);
            return;
        }
        
        // 处理零流量情况
        if (maxValue === 0) {
            maxValue = 1000; // 设置最小Y轴范围
        }
        
        // 给最大值增加10%余量
        maxValue *= 1.1;
        
        // 计算缩放比例
        var xScale = chartWidth / (maxTimestamp - minTimestamp);
        var yScale = chartHeight / maxValue;
        
        // 绘制背景和网格
        ctx.fillStyle = '#f9f9f9';
        ctx.fillRect(margin.left, margin.top, chartWidth, chartHeight);
        
        // 绘制Y轴网格和标签
        drawYAxis(ctx, margin, chartWidth, chartHeight, maxValue);
        
        // 绘制X轴网格和时间标签
        drawXAxis(ctx, margin, chartWidth, chartHeight, minTimestamp, maxTimestamp, card.timespan);
        
        // 添加图例
        drawLegend(ctx, margin);
        
        // 绘制水位图
        drawWaterLevel(ctx, card.inData, '#00c0ef', margin, chartWidth, chartHeight, minTimestamp, maxTimestamp, xScale, yScale);
        drawWaterLevel(ctx, card.outData, '#8957ff', margin, chartWidth, chartHeight, minTimestamp, maxTimestamp, xScale, yScale);
        
        // 绘制曲线
        var inPositions = drawAdvancedDataLine(ctx, card.inData, '#00c0ef', margin, chartWidth, chartHeight, minTimestamp, maxTimestamp, xScale, yScale, {
            gradientLine: true,
            showDataPoints: true,
            smoothing: 'bezier',
            lineWidth: 2.5,
            pointSize: 1.8,
            pointOpacity: 0.6,
            dynamicGradient: true,
            isHovered: card.interaction && card.interaction.hoveredLine === 'in',
            responsiveScale: card.responsiveScale || 1
        });
        var outPositions = drawAdvancedDataLine(ctx, card.outData, '#8957ff', margin, chartWidth, chartHeight, minTimestamp, maxTimestamp, xScale, yScale, {
            gradientLine: true,
            showDataPoints: true,
            smoothing: 'bezier',
            lineWidth: 2.5,
            pointSize: 1.8,
            pointOpacity: 0.6,
            dynamicGradient: true,
            isHovered: card.interaction && card.interaction.hoveredLine === 'out',
            responsiveScale: card.responsiveScale || 1
        });
        
        // 存储点位置用于交互
        card.pointPositions = [...inPositions, ...outPositions];
        
        // 绘制选择区域（如果有）
        if (card.zoomSelection?.active) {
            drawSelectionArea(ctx, card.zoomSelection, margin, chartHeight);
        }
        
        // 绘制边框
        ctx.beginPath();
        var isDark = document.documentElement.hasAttribute('data-theme');
        ctx.strokeStyle = isDark ? '#444' : '#ddd';
        ctx.lineWidth = 1;
        ctx.rect(margin.left, margin.top, chartWidth, chartHeight);
        ctx.stroke();
    }
    
    // 绘制Y轴
    function drawYAxis(ctx, margin, chartWidth, chartHeight, maxValue) {
        ctx.beginPath();
        var isDark = document.documentElement.hasAttribute('data-theme');
        ctx.strokeStyle = isDark ? '#444' : '#ddd';
        ctx.lineWidth = 1;
        
        var yTicks = 5;
        for (var i = 0; i <= yTicks; i++) {
            var y = margin.top + chartHeight - (i * chartHeight / yTicks);
            var value = (i * maxValue / yTicks);
            
            // 水平网格线
            ctx.moveTo(margin.left, y);
            ctx.lineTo(margin.left + chartWidth, y);
            
            // 左侧刻度标签
            ctx.fillStyle = isDark ? '#e0e0e0' : '#666';
            ctx.font = '10px Arial';
            ctx.textAlign = 'right';
            ctx.textBaseline = 'middle';
            ctx.fillText(formatBits(value), margin.left - 5, y);
        }
        ctx.stroke();
    }
    
    // 绘制X轴
    function drawXAxis(ctx, margin, chartWidth, chartHeight, minTimestamp, maxTimestamp, timespan) {
        ctx.beginPath();
        var isDark = document.documentElement.hasAttribute('data-theme');
        ctx.strokeStyle = isDark ? '#444' : '#ddd';
        
        var xTicks = timespan === '1h' ? 12 : 
                     timespan === '24h' ? 12 : 
                     timespan === '7d' ? 14 : 15;
        
        for (var i = 0; i <= xTicks; i++) {
            var x = margin.left + (i * chartWidth / xTicks);
            var timestamp = minTimestamp + (i * (maxTimestamp - minTimestamp) / xTicks);
            var date = new Date(timestamp);
            
            // 垂直网格线
            ctx.moveTo(x, margin.top);
            ctx.lineTo(x, margin.top + chartHeight);
            
            // 格式化时间标签
            var timeLabel;
            if (timespan === '1h') {
                timeLabel = date.getHours().toString().padStart(2, '0') + ':' + date.getMinutes().toString().padStart(2, '0');
            } else if (timespan === '24h') {
                timeLabel = date.getHours().toString().padStart(2, '0') + ':' + date.getMinutes().toString().padStart(2, '0');
            } else if (timespan === '7d') {
                timeLabel = (date.getMonth() + 1).toString().padStart(2, '0') + '/' + date.getDate().toString().padStart(2, '0') + ' ' + date.getHours().toString().padStart(2, '0') + ':00';
            }
            
            // 绘制标签
            ctx.fillStyle = isDark ? '#e0e0e0' : '#666';
            ctx.font = '10px Arial';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'top';
            ctx.fillText(timeLabel, x, margin.top + chartHeight + 5);
        }
        ctx.stroke();
    }
    
    // 绘制图例
    function drawLegend(ctx, margin) {
        var isDark = document.documentElement.hasAttribute('data-theme');
        var legendX = margin.left + 10;
        var legendY = margin.top + 15;
        
        // 入流量
        ctx.fillStyle = '#00c0ef';
        ctx.fillRect(legendX, legendY, 15, 10);
        ctx.fillStyle = isDark ? '#e0e0e0' : '#333';
        ctx.font = '12px Arial';
        ctx.textAlign = 'left';
        ctx.textBaseline = 'middle';
        ctx.fillText('入流量', legendX + 20, legendY + 5);
        
        // 出流量
        ctx.fillStyle = '#8957ff';
        ctx.fillRect(legendX + 80, legendY, 15, 10);
        ctx.fillStyle = isDark ? '#e0e0e0' : '#333';
        ctx.fillText('出流量', legendX + 100, legendY + 5);
    }
    
    // 绘制水位图
    function drawWaterLevel(ctx, data, color, margin, chartWidth, chartHeight, minTimestamp, maxTimestamp, xScale, yScale) {
        if (!data || data.length === 0) return;
        
        // 筛选有效点
        var validPoints = data.filter(function(point) {
            return Array.isArray(point) && point.length === 2 && 
                   point[1] !== null && 
                   point[0] >= minTimestamp && point[0] <= maxTimestamp;
        }).sort(function(a, b) { return a[0] - b[0]; });
        
        if (validPoints.length === 0) return;
        
        // 创建渐变
        var gradient = ctx.createLinearGradient(
            margin.left, margin.top + chartHeight, margin.left, margin.top
        );
        
        if (color === '#00c0ef') {
            gradient.addColorStop(0, 'rgba(0, 192, 239, 0.04)');
            gradient.addColorStop(0.5, 'rgba(0, 192, 239, 0.1)');
            gradient.addColorStop(1, 'rgba(0, 192, 239, 0.2)');
        } else {
            gradient.addColorStop(0, 'rgba(137, 87, 255, 0.04)');
            gradient.addColorStop(0.5, 'rgba(137, 87, 255, 0.1)');
            gradient.addColorStop(1, 'rgba(137, 87, 255, 0.2)');
        }
        
        // 绘制水位图路径
        ctx.beginPath();
        
        // 起点（底部）
        var firstPoint = validPoints[0];
        var firstX = margin.left + (firstPoint[0] - minTimestamp) * xScale;
        ctx.moveTo(firstX, margin.top + chartHeight);
        
        // 绘制所有点
        validPoints.forEach(function(point) {
            var x = margin.left + (point[0] - minTimestamp) * xScale;
            var y = margin.top + chartHeight - (point[1] * yScale);
            ctx.lineTo(x, y);
        });
        
        // 闭合路径（回到底部）
        var lastPoint = validPoints[validPoints.length - 1];
        var lastX = margin.left + (lastPoint[0] - minTimestamp) * xScale;
        ctx.lineTo(lastX, margin.top + chartHeight);
        ctx.closePath();
        
        // 填充
        ctx.fillStyle = gradient;
        ctx.fill();
    }
    
    // 高级美观数据线绘制（包含多种优化选项）
    function drawAdvancedDataLine(ctx, data, color, margin, chartWidth, chartHeight, minTimestamp, maxTimestamp, xScale, yScale, options) {
        var positions = [];
        options = options || {};
        
        // 默认配置 - 优化线条清晰度
        var config = {
            showDataPoints: options.showDataPoints !== false, // 是否显示数据点
            lineWidth: (options.lineWidth || 2.5) * (options.responsiveScale || 1), // 响应式线条宽度 - 减少宽度提高清晰度
            shadowEnabled: false, // 禁用阴影避免模糊
            gradientLine: options.gradientLine || false, // 是否使用渐变线条
            smoothing: options.smoothing || 'bezier', // 平滑算法: 'bezier', 'monotonic', 'none'
            pointSize: (options.pointSize || 1.8) * (options.responsiveScale || 1), // 响应式点大小
            pointOpacity: options.pointOpacity || 0.5, // 增加点透明度
            glowEffect: false, // 禁用发光效果避免模糊
            dynamicGradient: options.dynamicGradient || false // 是否使用动态渐变
        };
        
        // 过滤有效点
        var validPoints = data.filter(function(point) {
            return Array.isArray(point) && point.length === 2 && 
                   point[1] !== null && 
                   point[0] >= minTimestamp && point[0] <= maxTimestamp;
        });
        
        if (validPoints.length === 0) return positions;
        
        // 转换数据点为画布坐标
        var chartPoints = validPoints.map(function(point) {
            var x = margin.left + (point[0] - minTimestamp) * xScale;
            var y = margin.top + chartHeight - (point[1] * yScale);
            
            positions.push({
                x: x, y: y, timestamp: point[0], value: point[1],
                type: color === '#00c0ef' ? 'in' : 'out'
            });
            
            return { x: x, y: y };
        });
        
        // 绘制发光效果（如果启用）
        if (config.glowEffect) {
            ctx.shadowColor = color;
            ctx.shadowBlur = 8;
            ctx.shadowOffsetX = 0;
            ctx.shadowOffsetY = 0;
            
            // 绘制外层发光
            ctx.strokeStyle = color.replace(')', ', 0.3)').replace('rgb', 'rgba');
            ctx.lineWidth = config.lineWidth + 4;
            drawCurvePath(ctx, chartPoints, config.smoothing);
            
            // 清除发光
            ctx.shadowColor = 'transparent';
            ctx.shadowBlur = 0;
        }
        
        // 绘制主要线条
        if (config.gradientLine && chartPoints.length >= 2) {
            // 创建动态渐变
            var gradient;
            
            if (config.dynamicGradient) {
                // 计算数据强度来调整渐变
                var maxValue = Math.max(...validPoints.map(p => p[1]));
                var intensity = Math.min(maxValue / 1000000000, 1); // 归一化到0-1，假设1Gbps为满强度
                
                gradient = ctx.createLinearGradient(
                    chartPoints[0].x, chartPoints[0].y,
                    chartPoints[chartPoints.length - 1].x, chartPoints[chartPoints.length - 1].y
                );
                
                if (color === '#00c0ef') {
                    var alpha = 0.6 + (intensity * 0.4); // 透明度从0.6到1.0
                    gradient.addColorStop(0, `rgba(0, 192, 239, ${alpha})`);
                    gradient.addColorStop(0.3, `rgba(0, 153, 204, ${alpha * 0.9})`);
                    gradient.addColorStop(0.7, `rgba(0, 123, 184, ${alpha * 0.8})`);
                    gradient.addColorStop(1, `rgba(0, 102, 163, ${alpha * 0.7})`);
                } else {
                    var alpha = 0.6 + (intensity * 0.4);
                    gradient.addColorStop(0, `rgba(137, 87, 255, ${alpha})`);
                    gradient.addColorStop(0.3, `rgba(112, 66, 204, ${alpha * 0.9})`);
                    gradient.addColorStop(0.7, `rgba(93, 53, 163, ${alpha * 0.8})`);
                    gradient.addColorStop(1, `rgba(74, 40, 122, ${alpha * 0.7})`);
                }
            } else {
                // 标准渐变
                gradient = ctx.createLinearGradient(
                    chartPoints[0].x, chartPoints[0].y,
                    chartPoints[chartPoints.length - 1].x, chartPoints[chartPoints.length - 1].y
                );
                
                if (color === '#00c0ef') {
                    gradient.addColorStop(0, '#00c0ef');
                    gradient.addColorStop(0.5, '#0099cc');
                    gradient.addColorStop(1, '#007bb8');
                } else {
                    gradient.addColorStop(0, '#8957ff');
                    gradient.addColorStop(0.5, '#7042cc');
                    gradient.addColorStop(1, '#5d35a3');
                }
            }
            
            ctx.strokeStyle = gradient;
        } else {
            ctx.strokeStyle = color;
        }
        
        ctx.lineWidth = config.lineWidth;
        
        // 绘制曲线
        drawCurvePath(ctx, chartPoints, config.smoothing);
        
        // 绘制数据点（如果启用）
        if (config.showDataPoints) {
            var pointColor = color.replace(')', ', ' + config.pointOpacity + ')').replace('rgb', 'rgba');
            ctx.fillStyle = pointColor;
            
            chartPoints.forEach(function(point) {
                ctx.beginPath();
                ctx.arc(point.x, point.y, config.pointSize, 0, 2 * Math.PI);
                ctx.fill();
            });
        }
        
        // 交互高亮效果
        if (options.isHovered) {
            // 绘制高亮线条
            ctx.strokeStyle = color;
            ctx.lineWidth = config.lineWidth + 1; // 减少高亮加粗效果
            
            drawCurvePath(ctx, chartPoints, config.smoothing);
            
            // 高亮数据点
            ctx.fillStyle = color;
            chartPoints.forEach(function(point) {
                ctx.beginPath();
                ctx.arc(point.x, point.y, config.pointSize + 0.5, 0, 2 * Math.PI);
                ctx.fill();
            });
        }
        
        return positions;
    }
    
    // 通用曲线路径绘制
    function drawCurvePath(ctx, points, smoothingType) {
        if (points.length < 2) return;
        
        ctx.beginPath();
        ctx.moveTo(points[0].x, points[0].y);
        
        switch (smoothingType) {
            case 'monotonic':
                drawMonotonicPath(ctx, points);
                break;
            case 'bezier':
                drawBezierPath(ctx, points);
                break;
            case 'none':
            default:
                for (var i = 1; i < points.length; i++) {
                    ctx.lineTo(points[i].x, points[i].y);
                }
                break;
        }
        
        ctx.stroke();
    }
    
    // 贝塞尔路径（改进版）
    function drawBezierPath(ctx, points) {
        for (var i = 0; i < points.length - 1; i++) {
            var current = points[i];
            var next = points[i + 1];
            
            if (i === points.length - 2) {
                ctx.lineTo(next.x, next.y);
            } else {
                var afterNext = points[i + 2];
                var dx1 = next.x - current.x;
                var dx2 = afterNext.x - next.x;
                
                var cp1x = current.x + dx1 * 0.4;
                var cp1y = current.y;
                var cp2x = next.x - dx2 * 0.3;
                var cp2y = next.y;
                
                ctx.bezierCurveTo(cp1x, cp1y, cp2x, cp2y, next.x, next.y);
            }
        }
    }
    
    // 单调路径
    function drawMonotonicPath(ctx, points) {
        var slopes = calculateSlopes(points);
        
        for (var i = 0; i < points.length - 1; i++) {
            var p0 = points[i];
            var p1 = points[i + 1];
            var dx = (p1.x - p0.x) / 3;
            
            var cp1x = p0.x + dx;
            var cp1y = p0.y + slopes[i] * dx;
            var cp2x = p1.x - dx;
            var cp2y = p1.y - slopes[i + 1] * dx;
            
            ctx.bezierCurveTo(cp1x, cp1y, cp2x, cp2y, p1.x, p1.y);
        }
    }
    
    // 计算斜率
    function calculateSlopes(points) {
        var slopes = [];
        for (var i = 0; i < points.length; i++) {
            if (i === 0) {
                slopes[i] = (points[1].y - points[0].y) / (points[1].x - points[0].x);
            } else if (i === points.length - 1) {
                slopes[i] = (points[i].y - points[i-1].y) / (points[i].x - points[i-1].x);
            } else {
                var slope1 = (points[i].y - points[i-1].y) / (points[i].x - points[i-1].x);
                var slope2 = (points[i+1].y - points[i].y) / (points[i+1].x - points[i].x);
                slopes[i] = slope1 * slope2 <= 0 ? 0 : (slope1 + slope2) / 2;
            }
        }
        return slopes;
    }
    
    // 绘制选择区域
    function drawSelectionArea(ctx, zoomSelection, margin, chartHeight) {
        if (!zoomSelection.active) return;
        
        var startX = zoomSelection.startX;
        var currentX = zoomSelection.currentX;
        
        // 计算左右边界
        var leftX = Math.min(startX, currentX);
        var rightX = Math.max(startX, currentX);
        
        // 绘制半透明选择区域
        ctx.fillStyle = 'rgba(0, 123, 255, 0.2)';
        ctx.fillRect(leftX, margin.top, rightX - leftX, chartHeight);
        
        // 绘制边框
        ctx.strokeStyle = 'rgba(0, 123, 255, 0.5)';
        ctx.lineWidth = 1;
        ctx.strokeRect(leftX, margin.top, rightX - leftX, chartHeight);
    }
    
    // 初始化图表交互
    function initChartInteractions(canvasId) {
        var canvas = document.getElementById(canvasId);
        if (!canvas) return;
        
        var $chartContainer = $(canvas).parent();
        
        // 添加tooltip元素
        var $tooltip = $('<div class="chart-tooltip"></div>');
        $chartContainer.append($tooltip);
        $tooltip.hide();
        
        // 鼠标移动事件
        canvas.addEventListener('mousemove', function(e) {
            var rect = canvas.getBoundingClientRect();
            var x = e.clientX - rect.left;
            var y = e.clientY - rect.top;
            
            var cardIndex = trafficCards.findIndex(function(card) {
                return card.canvasId === canvasId;
            });
            if (cardIndex === -1) return;
            
            var card = trafficCards[cardIndex];
            
            // 查找最近的数据点
            var nearestPoint = findNearestDataPoint(card, x, y);
            var previousHovered = card.interaction.hoveredLine;
            
            // 检测是否悬停在线条上
            var hoveredLine = detectHoveredLine(card, x, y);
            card.interaction.hoveredLine = hoveredLine;
            card.interaction.isHovering = hoveredLine !== null;
            
            // 如果高亮状态改变，重绘图表
            if (previousHovered !== hoveredLine) {
                drawTrafficChart(card);
            }
            
            if (nearestPoint) {
                // 显示提示框
                var date = new Date(nearestPoint.timestamp);
                var formattedDate = date.toLocaleDateString();
                var formattedTime = date.toLocaleTimeString();
                var value = formatBits(nearestPoint.value);
                
                var tooltipHtml = '<div><strong>时间:</strong> ' + formattedDate + ' ' + formattedTime + '</div>' +
                                  '<div><strong>' + (nearestPoint.type === 'in' ? '入流量' : '出流量') + ':</strong> ' + value + '</div>';
                
                $tooltip.html(tooltipHtml);
                $tooltip.css({
                    left: x + 10,
                    top: y - 40,
                    display: 'block'
                });
            } else {
                $tooltip.hide();
            }
        });
        
        // 鼠标离开事件
        canvas.addEventListener('mouseout', function() {
            $tooltip.hide();
        });
        
        // 双击重置缩放
        canvas.addEventListener('dblclick', function() {
            var cardIndex = trafficCards.findIndex(function(card) {
                return card.canvasId === canvasId;
            });
            if (cardIndex === -1) return;
            
            var card = trafficCards[cardIndex];
            
            if (card.zoomState && card.zoomState.active) {
                // 重置缩放状态
                card.zoomState.active = false;
                card.zoomState.minTime = null;
                card.zoomState.maxTime = null;
                
                // 重绘图表
                drawTrafficChart(card);
            }
        });
    }
    
    // 查找最近的数据点
    function findNearestDataPoint(card, mouseX, mouseY) {
        if (!card.pointPositions || card.pointPositions.length === 0) return null;
        
        var nearestPoint = null;
        var minDistance = Number.MAX_VALUE;
        
        // 计算鼠标到每个点的距离
        card.pointPositions.forEach(function(point) {
            var dx = mouseX - point.x;
            var dy = mouseY - point.y;
            var distance = Math.sqrt(dx * dx + dy * dy);
            
            // 只考虑一定距离内的点
            if (distance < 30 && distance < minDistance) {
                minDistance = distance;
                nearestPoint = point;
            }
        });
        
        return nearestPoint;
    }
    
    // 检测鼠标是否悬停在线条上
    function detectHoveredLine(card, mouseX, mouseY) {
        if (!card.pointPositions || card.pointPositions.length === 0) return null;
        
        var threshold = 15; // 检测距离阈值
        var inPoints = card.pointPositions.filter(p => p.type === 'in');
        var outPoints = card.pointPositions.filter(p => p.type === 'out');
        
        // 检测入流量线
        if (isNearLine(inPoints, mouseX, mouseY, threshold)) {
            return 'in';
        }
        
        // 检测出流量线
        if (isNearLine(outPoints, mouseX, mouseY, threshold)) {
            return 'out';
        }
        
        return null;
    }
    
    // 检测点是否靠近线条
    function isNearLine(points, x, y, threshold) {
        for (var i = 0; i < points.length - 1; i++) {
            var p1 = points[i];
            var p2 = points[i + 1];
            
            var distance = distanceToLineSegment(x, y, p1.x, p1.y, p2.x, p2.y);
            if (distance <= threshold) {
                return true;
            }
        }
        return false;
    }
    
    // 计算点到线段的距离
    function distanceToLineSegment(px, py, x1, y1, x2, y2) {
        var A = px - x1;
        var B = py - y1;
        var C = x2 - x1;
        var D = y2 - y1;
        
        var dot = A * C + B * D;
        var lenSq = C * C + D * D;
        var param = -1;
        
        if (lenSq !== 0) {
            param = dot / lenSq;
        }
        
        var xx, yy;
        
        if (param < 0) {
            xx = x1;
            yy = y1;
        } else if (param > 1) {
            xx = x2;
            yy = y2;
        } else {
            xx = x1 + param * C;
            yy = y1 + param * D;
        }
        
        var dx = px - xx;
        var dy = py - yy;
        return Math.sqrt(dx * dx + dy * dy);
    }
    
    // 监听删除卡片按钮
    $(document).on('click', '.delete-card', function() {
        var cardId = $(this).data('card-id');
        var cardName = $(this).data('card-name');
        
        if (confirm('确定要删除卡片 "' + cardName + '" 吗？')) {
            $.post('/app/tools/traffic-monitor/api.php', {
                action: 'delete_card',
                card_id: cardId
            })
            .done(function(response) {
                if (response && response.success) {
                    location.reload();
                } else {
                    alert('删除失败: ' + (response ? response.error : '未知错误'));
                }
            })
            .fail(function() {
                alert('网络错误，请重试');
            });
        }
    });
    
    // 处理设备选择变化
    $('#device_id').change(function() {
        var deviceId = $(this).val();
        var $interfaceSelect = $('#interface_id');
        
        if (!deviceId) {
            $interfaceSelect.html('<option value="">请先选择设备</option>');
            $interfaceSelect.prop('disabled', true);
            return;
        }
        
        // 显示加载状态
        $interfaceSelect.html('<option value="">加载中...</option>');
        $interfaceSelect.prop('disabled', true);
        
        // 获取设备的接口列表
        $.ajax({
            url: '/app/tools/traffic-monitor/api.php',
            method: 'GET',
            data: {
                action: 'get_interfaces',
                device_id: deviceId
            },
            dataType: 'json',
            success: function(response) {
                console.log('接口数据响应:', response);
                
                $interfaceSelect.empty();
                $interfaceSelect.append('<option value="">请选择接口</option>');
                
                if (response && response.success && response.interfaces && response.interfaces.length > 0) {
                    response.interfaces.forEach(function(interface) {
                        var label = interface.if_name || 'Interface ' + interface.if_index;
                        if (interface.if_description) {
                            label += ' (' + interface.if_description + ')';
                        }
                        if (interface.speed && interface.speed > 0) {
                            label += ' - ' + formatSpeed(interface.speed);
                        }
                        
                        $interfaceSelect.append($('<option></option>')
                            .attr('value', interface.if_name || interface.if_index)
                            .text(label));
                    });
                    
                    $interfaceSelect.prop('disabled', false);
                } else {
                    $interfaceSelect.html('<option value="">未找到接口</option>');
                    $interfaceSelect.prop('disabled', true);
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error('加载接口失败:', textStatus, errorThrown);
                $interfaceSelect.html('<option value="">加载接口失败</option>');
                $interfaceSelect.prop('disabled', true);
            }
        });
    });
    
    // 处理添加卡片表单提交
    $('#addCardForm').submit(function(e) {
        e.preventDefault();
        
        var formData = {
            action: 'add_card',
            dashboard_id: $('input[name="dashboard_id"]').val(),
            device_id: $('#device_id').val(),
            interface_id: $('#interface_id').val()
        };
        
        // 验证必填字段
        if (!formData.device_id || !formData.interface_id) {
            alert('请填写所有必填字段');
            return;
        }
        
        // 提交表单
        $.post('/app/tools/traffic-monitor/api.php', formData)
            .done(function(response) {
                if (response && response.success) {
                    $('#addCardModal').modal('hide');
                    location.reload(); // 重新加载页面以显示新添加的卡片
                } else {
                    alert('添加失败: ' + (response ? response.error : '未知错误'));
                }
            })
            .fail(function() {
                alert('网络错误，请重试');
            });
    });
    
    // 处理编辑看板表单提交
    $('#editDashboardForm').submit(function(e) {
        e.preventDefault();
        
        var formData = {
            action: 'update_dashboard',
            dashboard_id: $('#edit_dashboard_id').val(),
            name: $('#edit_dashboard_name').val(),
            description: $('#edit_dashboard_description').val()
        };
        
        $.post('/app/tools/traffic-monitor/api.php', formData)
            .done(function(response) {
                if (response && response.success) {
                    $('#editDashboardModal').modal('hide');
                    location.reload();
                } else {
                    alert('更新失败: ' + (response ? response.error : '未知错误'));
                }
            })
            .fail(function() {
                alert('网络错误，请重试');
            });
    });
    
    // 处理编辑看板按钮点击
    $('#edit-dashboard-btn').click(function() {
        $('#edit_dashboard_id').val(<?php echo $dashboard->id; ?>);
        $('#edit_dashboard_name').val('<?php echo addslashes($dashboard->name); ?>');
        $('#edit_dashboard_description').val('<?php echo addslashes($dashboard->description ?? ''); ?>');
        $('#editDashboardModal').modal('show');
    });
    
    // 主题切换功能
    function initThemeToggle() {
        var $themeToggle = $('#themeToggle');
        var $themeText = $('.theme-text');
        var $themeIcon = $themeToggle.find('i');
        
        // 从本地存储读取主题设置
        var currentTheme = localStorage.getItem('traffic-monitor-theme') || 'light';
        setTheme(currentTheme);
        
        $themeToggle.click(function() {
            var newTheme = currentTheme === 'light' ? 'dark' : 'light';
            setTheme(newTheme);
            currentTheme = newTheme;
            localStorage.setItem('traffic-monitor-theme', newTheme);
        });
        
        function setTheme(theme) {
            if (theme === 'dark') {
                document.documentElement.setAttribute('data-theme', 'dark');
                $themeIcon.removeClass('fa-moon-o').addClass('fa-sun-o');
                $themeText.text('明亮');
            } else {
                document.documentElement.removeAttribute('data-theme');
                $themeIcon.removeClass('fa-sun-o').addClass('fa-moon-o');
                $themeText.text('暗色');
            }
            
            // 重绘所有图表以适应新主题
            trafficCards.forEach(function(card) {
                drawTrafficChart(card);
            });
        }
    }
    
    // 响应式Canvas大小调整
    function initResponsiveCanvas() {
        var resizeTimeout;
        
        $(window).resize(function() {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(function() {
                trafficCards.forEach(function(card) {
                    var canvas = document.getElementById(card.canvasId);
                    var $chartContainer = card.element.find('.traffic-chart');
                    
                    if (canvas && $chartContainer.length) {
                        // 根据屏幕大小调整线条宽度
                        var containerWidth = $chartContainer.width();
                        var scaleFactor = Math.min(containerWidth / 600, 1.2); // 基准宽度600px
                        card.responsiveScale = scaleFactor;
                        
                        // 重新绘制图表（Canvas大小在drawTrafficChart中处理）
                        drawTrafficChart(card);
                    }
                });
            }, 250); // 防抖动，250ms延迟
        });
    }
    
    // 数据密度自适应
    function getAdaptiveDataDensity() {
        var screenWidth = window.innerWidth;
        var devicePixelRatio = window.devicePixelRatio || 1;
        
        // 根据屏幕分辨率调整数据点密度
        if (screenWidth < 768) {
            return 0.6; // 移动端减少数据点
        } else if (screenWidth < 1200) {
            return 0.8; // 平板端中等数据点
        } else {
            return 1.0; // 桌面端完整数据点
        }
    }
    
    // 初始化主题和响应式功能
    initThemeToggle();
    initResponsiveCanvas();
});

/**
 * 格式化比特值为可读形式
 */
function formatBits(bits) {
    if (bits === 0) return "0 bps";
    
    var sizes = ['bps', 'Kbps', 'Mbps', 'Gbps', 'Tbps'];
    var i = Math.floor(Math.log(bits) / Math.log(1000));
    
    if (i >= sizes.length) i = sizes.length - 1;
    
    return (bits / Math.pow(1000, i)).toFixed(2) + ' ' + sizes[i];
}

/**
 * 格式化接口速率为可读形式
 */
function formatSpeed(speed) {
    if (!speed || speed === 0) return "未知";
    
    var sizes = ['bps', 'Kbps', 'Mbps', 'Gbps', 'Tbps'];
    var i = Math.floor(Math.log(speed) / Math.log(1000));
    
    if (i >= sizes.length) i = sizes.length - 1;
    
    return (speed / Math.pow(1000, i)).toFixed(0) + ' ' + sizes[i];
}
</script> 