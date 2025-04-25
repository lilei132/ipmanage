<?php
/**
 * 流量监控页面
 */

# 引入函数文件
require_once dirname(__FILE__) . "/../../../functions/functions.php";

# 确认用户登录
$User->check_user_session();

# 检查用户权限
if ($User->get_module_permissions("devices") < User::ACCESS_R) {
    $Result->show("danger", _("无权访问此页面"), true);
    die();
}

# 获取所有支持SNMP的设备
$devices = $Database->getObjects("devices", "snmp_version", "!=0");

# 预先加载每个设备的接口
$device_interfaces = [];
if ($devices !== false) {
    $Traffic = new Traffic($Database);
    foreach ($devices as $device) {
        try {
            $interfaces = $Traffic->get_device_interfaces($device->id);
            if ($interfaces !== false && !empty($interfaces)) {
                $formatted_interfaces = [];
                foreach ($interfaces as $interface) {
                    $formatted_interfaces[] = [
                        'if_index' => $interface->if_index,
                        'if_name' => $interface->if_name,
                        'if_description' => $interface->if_description,
                        'speed' => $interface->speed
                    ];
                }
                $device_interfaces[$device->id] = $formatted_interfaces;
            }
        } catch (Exception $e) {
            error_log("Error preloading interfaces for device {$device->id}: " . $e->getMessage());
        }
    }
}

# 处理获取流量数据请求
if (isset($_GET['getTrafficData']) || isset($_POST['getTrafficData'])) {
    // 开启PHP错误展示，方便调试
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
    
    // 禁用响应缓存
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
    header('Content-Type: application/json');
    
    // 获取请求参数
    $deviceId = isset($_REQUEST['deviceId']) ? $_REQUEST['deviceId'] : null;
    $interfaceId = isset($_REQUEST['interfaceId']) ? $_REQUEST['interfaceId'] : null;
    $timespan = isset($_REQUEST['timespan']) ? $_REQUEST['timespan'] : '7d';
    
    // 记录请求信息
    error_log("流量数据请求: deviceId = $deviceId, interfaceId = $interfaceId, timespan = $timespan");
    
    if (!$deviceId || !$interfaceId) {
        echo json_encode(['success' => false, 'error' => '缺少必要参数']);
        die();
    }
    
    try {
        $Traffic = new Traffic($Database);
        
        try {
            // 从数据库获取真实数据
            $traffic_data = $Traffic->get_interface_history($deviceId, $interfaceId, $timespan);
            
            if ($traffic_data === false || empty($traffic_data)) {
                error_log("无法从数据库获取流量数据: deviceId = $deviceId, interfaceId = $interfaceId");
                echo json_encode(['success' => false, 'error' => '没有找到流量数据']);
                die();
            } else {
                error_log("成功从数据库获取流量数据: " . count($traffic_data) . " 个数据点");
            }
        } catch (Exception $db_error) {
            error_log("数据库查询错误: " . $db_error->getMessage());
            echo json_encode(['success' => false, 'error' => '数据库查询失败: ' . $db_error->getMessage()]);
            die();
        }
        
        // 格式化数据为Canvas可用的格式
        $in_data = [];
        $out_data = [];
        
        foreach ($traffic_data as $point) {
            // 确保时间点格式正确
            if (isset($point->time_point) && $point->time_point) {
                $timestamp = strtotime($point->time_point) * 1000; // 转换为JavaScript时间戳(毫秒)
                
                // 确保流量值为数字
                $in_octets = isset($point->in_octets) ? floatval($point->in_octets) * 8 : 0; // 转换为比特
                $out_octets = isset($point->out_octets) ? floatval($point->out_octets) * 8 : 0; // 转换为比特
                
                $in_data[] = [$timestamp, $in_octets];
                $out_data[] = [$timestamp, $out_octets];
            }
        }
        
        // 记录成功信息
        error_log("流量数据格式化成功: 入流量 " . count($in_data) . " 个点, 出流量 " . count($out_data) . " 个点");
        
        // 设置链路速度
        $speed = isset($traffic_data[0]->speed) ? floatval($traffic_data[0]->speed) : 1000000000; // 默认1Gbps
        
        echo json_encode([
            'success' => true,
            'in_data' => $in_data,
            'out_data' => $out_data,
            'speed' => $speed
        ]);
    } catch (Exception $e) {
        error_log("获取流量数据时出错: deviceId = $deviceId, interfaceId = $interfaceId, error = " . $e->getMessage());
        error_log("错误堆栈: " . $e->getTraceAsString());
        
        // 返回更详细的错误信息
        echo json_encode([
            'success' => false, 
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
    }
    
    die();
}

# 处理接口数据请求
if (isset($_GET['getDeviceInterfaces']) || isset($_POST['getDeviceInterfaces'])) {
    // 设置响应为JSON
    header('Content-Type: application/json');
    
    // 获取设备ID
    $deviceId = isset($_REQUEST['getDeviceInterfaces']) ? $_REQUEST['getDeviceInterfaces'] : null;
    
    if (!$deviceId) {
        echo json_encode(['success' => false, 'message' => '缺少设备ID参数']);
        die();
    }
    
    // 记录请求信息便于调试
    error_log("接口数据请求: deviceId = $deviceId");
    
    $Traffic = new Traffic($Database);
    
    try {
        $interfaces = $Traffic->get_device_interfaces($deviceId);
        
        if ($interfaces !== false && !empty($interfaces)) {
            $formatted_interfaces = [];
            foreach ($interfaces as $interface) {
                $formatted_interfaces[] = [
                    'if_index' => $interface->if_index,
                    'if_name' => $interface->if_name,
                    'if_description' => $interface->if_description,
                    'speed' => $interface->speed
                ];
            }
            
            // 记录成功信息
            error_log("接口数据获取成功: " . count($formatted_interfaces) . " 个接口");
            
            echo json_encode([
                'success' => true,
                'interfaces' => $formatted_interfaces
            ]);
        } else {
            error_log("未找到接口数据, deviceId = $deviceId");
            echo json_encode([
                'success' => false,
                'message' => '未找到接口数据',
                'interfaces' => []
            ]);
        }
    } catch (Exception $e) {
        error_log("获取接口数据错误: deviceId = $deviceId, error = " . $e->getMessage());
        error_log("错误堆栈: " . $e->getTraceAsString());
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    
    exit;
}

// 处理常规页面请求
?>

<h4><?php print _("流量监控"); ?></h4>
<hr>

<div class="alert alert-info">
    <i class="fa fa-info-circle"></i> <?php print _("选择设备和接口以监控流量。每个卡片将显示实时流量数据。可以添加多个卡片同时监控不同设备的不同接口。"); ?>
</div>

<!-- 页面控制按钮 - 移到页面顶部 -->
<div class="page-controls-wrapper">
    <div class="page-controls">
        <div class="control-buttons">
            <button id="save-layout-btn" class="btn btn-sm btn-primary">
                <i class="fa fa-save"></i> 保存布局
            </button>
            <button id="clear-cards-btn" class="btn btn-sm btn-danger">
                <i class="fa fa-trash"></i> 清除所有卡片
            </button>
        </div>
        <div class="scroll-controls">
            <button id="scroll-top-btn" class="btn btn-sm btn-default">
                <i class="fa fa-arrow-up"></i> 回到顶部
            </button>
            <button id="scroll-bottom-btn" class="btn btn-sm btn-default">
                <i class="fa fa-arrow-down"></i> 滚动到底部
            </button>
        </div>
    </div>
</div>

<!-- 监控卡片添加面板 -->
<div class="panel panel-default" style="margin-bottom: 20px;">
    <div class="panel-heading">
        <h3 class="panel-title"><i class="fa fa-plus"></i> <?php print _("添加监控卡片"); ?></h3>
    </div>
    <div class="panel-body">
        <div class="row">
            <div class="col-md-5">
                <div class="form-group">
                    <label for="device-select"><?php print _("设备"); ?></label>
                    <select id="device-select" class="form-control">
                        <option value=""><?php print _("请选择设备..."); ?></option>
                        <?php
                        if ($devices !== false) {
                            foreach ($devices as $device) {
                                echo "<option value='{$device->id}'>{$device->hostname} ({$device->ip_addr})</option>";
                            }
                        }
                        ?>
                    </select>
                </div>
            </div>
            <div class="col-md-5">
                <div class="form-group">
                    <label for="interface-select"><?php print _("接口"); ?></label>
                    <select id="interface-select" class="form-control" disabled>
                        <option value=""><?php print _("请先选择设备..."); ?></option>
                    </select>
                </div>
            </div>
            <div class="col-md-2">
                <div class="form-group">
                    <label style="display: block; visibility: hidden;">添加</label>
                    <button id="add-card-btn" class="btn btn-success btn-block" disabled>
                        <i class="fa fa-plus"></i> <?php print _("添加监控卡片"); ?>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 监控卡片容器 -->
<div class="traffic-monitor-wrapper">
    <div id="traffic-cards" class="row">
        <!-- 卡片将通过JavaScript动态添加到这里 -->
    </div>
</div>

<!-- 卡片模板 -->
<div id="card-template" style="display: none;">
    <div class="col-md-6 traffic-card" data-device-id="" data-interface-id="" style="margin-bottom: 20px;">
        <div class="panel panel-default">
            <div class="panel-heading">
                <div class="pull-right">
                    <div class="btn-group timespan-selector">
                        <button type="button" class="btn btn-xs btn-default" data-timespan="1h">1小时</button>
                        <button type="button" class="btn btn-xs btn-default" data-timespan="1d">1天</button>
                        <button type="button" class="btn btn-xs btn-default active" data-timespan="7d">7天</button>
                    </div>
                    <button type="button" class="btn btn-xs btn-danger remove-card-btn">
                        <i class="fa fa-times"></i>
                    </button>
                </div>
                <h3 class="panel-title"><i class="fa fa-chart-line"></i> <span class="card-title"></span></h3>
            </div>
            <div class="panel-body">
                <div class="traffic-chart" style="height: 300px; width: 100%;"></div>
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
</div>

<!-- 移除Flot库引用，改用原生Canvas API -->

<script>
// 预加载的接口数据
const deviceInterfaces = <?php echo json_encode($device_interfaces); ?>;

$(document).ready(function() {
    // 全局变量存储卡片列表
    const trafficCards = [];
    
    // 自动刷新定时器
    const refreshTimers = {};
    
    // 初始化：从localStorage加载已保存的卡片
    loadSavedCards();
    
    // 初始化页面滚动控制
    initScrollControls();
    
    // 监听设备选择变化事件
    $('#device-select').change(function() {
        const deviceId = $(this).val();
        const interfaceSelect = $('#interface-select');
        
        if (!deviceId) {
            interfaceSelect.html('<option value=""><?php print _("请先选择设备..."); ?></option>');
            interfaceSelect.prop('disabled', true);
            $('#add-card-btn').prop('disabled', true);
            return;
        }
        
        // 显示加载状态
        interfaceSelect.html('<option value=""><?php print _("加载中..."); ?></option>');
        interfaceSelect.prop('disabled', true);
        
        if (deviceInterfaces && deviceInterfaces[deviceId] && deviceInterfaces[deviceId].length > 0) {
            const interfaces = deviceInterfaces[deviceId];
            
            interfaceSelect.empty();
            interfaceSelect.append('<option value=""><?php print _("请选择接口..."); ?></option>');
            
            $.each(interfaces, function(i, iface) {
                let label = iface.if_name || 'Interface ' + iface.if_index;
                if (iface.if_description) {
                    label += ' (' + iface.if_description + ')';
                }
                if (iface.speed) {
                    try {
                        const speedValue = parseFloat(iface.speed);
                        if (!isNaN(speedValue)) {
                            label += ' - ' + formatSpeed(speedValue);
                        }
                    } catch (e) {
                        console.error('格式化速度出错:', e);
                    }
                }
                interfaceSelect.append($('<option></option>')
                    .attr('value', iface.if_index)
                    .text(label));
            });
            
            interfaceSelect.prop('disabled', false);
            $('#add-card-btn').prop('disabled', false);
        } else {
            // 尝试从服务器获取接口列表
            $.ajax({
                url: '/app/tools/traffic-monitor/index.php',
                method: 'GET',
                data: {
                    getDeviceInterfaces: deviceId
                },
                dataType: 'json',
                success: function(data) {
                    console.log('接口数据响应:', data);
                    // 为可能的不同响应格式做兼容处理
                    // 1. 如果是数组格式
                    if (Array.isArray(data)) {
                        deviceInterfaces[deviceId] = data;
                        populateInterfaceSelect(interfaceSelect, data);
                        return;
                    }
                    
                    // 2. 如果是对象格式且有success属性
                    if (data && data.success && data.interfaces && Array.isArray(data.interfaces)) {
                        deviceInterfaces[deviceId] = data.interfaces;
                        populateInterfaceSelect(interfaceSelect, data.interfaces);
                        return;
                    }
                    
                    // 3. 如果没有结构但是有数据
                    if (data && typeof data === 'object' && Object.keys(data).length > 0) {
                        // 尝试将对象转换为数组
                        let interfacesArray = [];
                        for (let key in data) {
                            if (data.hasOwnProperty(key) && typeof data[key] === 'object') {
                                // 添加if_index属性如果没有
                                if (!data[key].if_index && key) {
                                    data[key].if_index = key;
                                }
                                interfacesArray.push(data[key]);
                            }
                        }
                        
                        if (interfacesArray.length > 0) {
                            deviceInterfaces[deviceId] = interfacesArray;
                            populateInterfaceSelect(interfaceSelect, interfacesArray);
                            return;
                        }
                    }
                    
                    // 处理失败情况
                    interfaceSelect.html('<option value=""><?php print _("未找到接口数据"); ?></option>');
                    interfaceSelect.prop('disabled', true);
                    $('#add-card-btn').prop('disabled', true);
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    console.error('加载接口失败:', textStatus, errorThrown);
                    console.error('错误详情:', jqXHR.responseText);
                    interfaceSelect.html('<option value=""><?php print _("加载接口失败"); ?></option>');
                    interfaceSelect.prop('disabled', true);
                    $('#add-card-btn').prop('disabled', true);
                }
            });
        }
    });

    // 监听添加卡片按钮点击事件
    $('#add-card-btn').click(function() {
        const deviceId = $('#device-select').val();
        const deviceName = $('#device-select option:selected').text();
        const interfaceId = $('#interface-select').val();
        const interfaceName = $('#interface-select option:selected').text();
        
        if (!deviceId || !interfaceId) {
            alert('<?php print _("请选择设备和接口"); ?>');
            return;
        }
        
        // 检查是否已存在相同的卡片
        const existingCardIndex = trafficCards.findIndex(card => 
            card.deviceId === deviceId && card.interfaceId === interfaceId
        );
        
        if (existingCardIndex !== -1) {
            alert('<?php print _("已存在相同的监控卡片"); ?>');
            return;
        }
        
        // 添加新卡片
        addTrafficCard(deviceId, deviceName, interfaceId, interfaceName);
    });

    // 添加页面滚动控制
    function initScrollControls() {
        $('#scroll-top-btn').click(function() {
            $('.traffic-monitor-wrapper').animate({
                scrollTop: 0
            }, 500);
        });
        
        $('#scroll-bottom-btn').click(function() {
            $('.traffic-monitor-wrapper').animate({
                scrollTop: $('#traffic-cards').height()
            }, 500);
        });
        
        // 当容器滚动超过一定距离时显示回到顶部按钮
        $('.traffic-monitor-wrapper').scroll(function() {
            if ($(this).scrollTop() > 300) {
                $('#scroll-top-btn').fadeIn();
            } else {
                $('#scroll-top-btn').fadeOut();
            }
        });
        
        // 初始隐藏回到顶部按钮
        $('#scroll-top-btn').hide();
    }

    // 添加卡片持久化存储
    $('#save-layout-btn').click(function() {
        saveCardsToLocalStorage();
        showNotification('布局已保存', 'success');
    });
    
    // 清除所有卡片
    $('#clear-cards-btn').click(function() {
        if (confirm('确定要移除所有监控卡片吗？')) {
            clearAllCards();
            localStorage.removeItem('trafficMonitorCards');
            showNotification('所有卡片已清除', 'info');
        }
    });
    
    // 清除所有卡片的函数
    function clearAllCards() {
        // 清除所有定时器
        Object.keys(refreshTimers).forEach(timerId => {
            clearInterval(refreshTimers[timerId]);
            delete refreshTimers[timerId];
        });
        
        // 清空卡片数组
        trafficCards.length = 0;
        
        // 从DOM中移除所有卡片
        $('#traffic-cards').empty();
    }
    
    // 保存卡片到本地存储
    function saveCardsToLocalStorage() {
        const cardsData = trafficCards.map(card => ({
            deviceId: card.deviceId,
            interfaceId: card.interfaceId,
            deviceName: card.element.find('.card-title').text().split(' - ')[0],
            interfaceName: card.element.find('.card-title').text().split(' - ')[1],
            timespan: card.timespan
        }));
        
        localStorage.setItem('trafficMonitorCards', JSON.stringify(cardsData));
    }
    
    // 从本地存储加载卡片
    function loadSavedCards() {
        const savedCards = localStorage.getItem('trafficMonitorCards');
        if (!savedCards) return;
        
        try {
            const cardsData = JSON.parse(savedCards);
            if (Array.isArray(cardsData) && cardsData.length > 0) {
                cardsData.forEach(card => {
                    addTrafficCard(
                        card.deviceId, 
                        card.deviceName, 
                        card.interfaceId, 
                        card.interfaceName,
                        card.timespan
                    );
                });
                
                showNotification('已加载保存的监控卡片', 'info');
            }
        } catch (e) {
            console.error('加载保存的卡片失败:', e);
        }
    }
    
    // 显示通知消息
    function showNotification(message, type = 'info') {
        // 创建通知元素
        const $notification = $(`<div class="alert alert-${type} notification">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            ${message}
        </div>`);
        
        // 添加到页面
        $notification.css({
            'position': 'fixed',
            'top': '20px',
            'right': '20px',
            'z-index': 9999,
            'min-width': '200px',
            'max-width': '350px',
            'box-shadow': '0 4px 8px rgba(0,0,0,0.2)'
        });
        
        $('body').append($notification);
        
        // 3秒后自动关闭
        setTimeout(() => {
            $notification.fadeOut(500, function() {
                $(this).remove();
            });
        }, 3000);
    }
    
    // 添加流量监控卡片
    function addTrafficCard(deviceId, deviceName, interfaceId, interfaceName, initialTimespan = '7d') {
        // 复制模板
        const $template = $('#card-template').html();
        const $card = $($template);
        
        // 设置卡片数据
        $card.attr('data-device-id', deviceId);
        $card.attr('data-interface-id', interfaceId);
        $card.find('.card-title').text(deviceName + ' - ' + interfaceName);
        
        // 确保每个卡片都是col-md-6格式以保证每行两个卡片
        $card.removeClass('col-md-6').addClass('col-md-6');
        
        // 设置时间范围 - 确保7d是默认选中的
        const timespan = initialTimespan || '7d';
        $card.find(`.timespan-selector button`).removeClass('active');
        $card.find(`.timespan-selector button[data-timespan="${timespan}"]`).addClass('active');
        
        // 添加到卡片容器 - 放在最前面
        $('#traffic-cards').prepend($card);
        
        // 创建唯一ID用于图表容器
        const chartId = 'chart-' + deviceId + '-' + interfaceId;
        $card.find('.traffic-chart').attr('id', chartId);
        
        // 创建canvas元素
        const $chartContainer = $('#' + chartId);
        $chartContainer.empty();
        const canvasId = 'canvas-' + deviceId + '-' + interfaceId;
        $chartContainer.html('<canvas id="' + canvasId + '" width="' + $chartContainer.width() + '" height="' + $chartContainer.height() + '"></canvas>');
        
        // 添加到卡片列表
        const cardObject = {
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
                scale: 1.0  // 缩放比例，用于滚轮缩放
            },
            zoomSelection: {
                active: false,
                startX: 0,
                currentX: 0
            },
            hoverPoint: null,
            hasMouseEvents: false,
            rawInData: null,
            rawOutData: null
        };
        
        trafficCards.push(cardObject);
        
        // 加载流量数据
        loadTrafficData(cardObject);
        
        // 设置自动刷新
        refreshTimers[chartId] = setInterval(function() {
            loadTrafficData(cardObject);
        }, 30000); // 每30秒刷新一次
        
        // 当添加新卡片时，自动滚动到该卡片
        setTimeout(function() {
            $('.traffic-monitor-wrapper').animate({
                scrollTop: $('.traffic-monitor-wrapper').scrollTop() + $card.position().top - 100
            }, 500);
        }, 100);
        
        // 监听时间范围变化事件
        $card.find('.timespan-selector button').click(function() {
            const $this = $(this);
            const newTimespan = $this.data('timespan');
            
            // 更新按钮状态
            $this.siblings().removeClass('active');
            $this.addClass('active');
            
            // 更新卡片对象中的时间范围
            const cardIndex = trafficCards.findIndex(card => 
                card.deviceId === deviceId && card.interfaceId === interfaceId
            );
            
            if (cardIndex !== -1) {
                // 重置缩放状态
                trafficCards[cardIndex].zoomState.active = false;
                trafficCards[cardIndex].timespan = newTimespan;
                
                // 重新加载流量数据
                loadTrafficData(trafficCards[cardIndex]);
            }
        });
        
        // 监听移除卡片按钮点击事件
        $card.find('.remove-card-btn').click(function() {
            // 从卡片列表中移除
            const cardIndex = trafficCards.findIndex(card => 
                card.deviceId === deviceId && card.interfaceId === interfaceId
            );
            
            if (cardIndex !== -1) {
                // 清除刷新定时器
                if (refreshTimers[trafficCards[cardIndex].chartId]) {
                    clearInterval(refreshTimers[trafficCards[cardIndex].chartId]);
                    delete refreshTimers[trafficCards[cardIndex].chartId];
                }
                
                trafficCards.splice(cardIndex, 1);
            }
            
            // 从DOM中移除
            $card.remove();
            
            // 提示用户保存更改
            showNotification('卡片已移除，点击"保存布局"以永久保存更改', 'warning');
        });
        
        // 添加鼠标交互事件
        const canvas = document.getElementById(canvasId);
        
        // 初始化图表交互
        initChartInteractions(canvasId);
    }
    
    // 初始化图表交互
    function initChartInteractions(canvasId) {
        const canvas = document.getElementById(canvasId);
        const $chartContainer = $(canvas).parent();
        
        // 添加tooltip元素
        let $tooltip = $('<div class="chart-tooltip"></div>');
        $chartContainer.append($tooltip);
        $tooltip.hide();
        
        // 鼠标移动事件 - 显示数据点信息
        canvas.addEventListener('mousemove', function(e) {
            const rect = canvas.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const y = e.clientY - rect.top;
            
            const cardIndex = trafficCards.findIndex(card => card.canvasId === canvasId);
            if (cardIndex === -1) return;
            
            const card = trafficCards[cardIndex];
            
            // 检查是否在图表区域内
            const margin = {
                top: 20,
                right: 50,
                bottom: 40,
                left: 60
            };
            const chartWidth = canvas.width - margin.left - margin.right;
            const chartHeight = canvas.height - margin.top - margin.bottom;
            
            if (x >= margin.left && x <= margin.left + chartWidth && 
                y >= margin.top && y <= margin.top + chartHeight) {
                
                // 如果正在进行选区，更新选区
            if (card.zoomSelection && card.zoomSelection.active) {
                card.zoomSelection.currentX = x;
                drawTrafficChart(card);
                    
                    // 在选区模式下不显示工具提示
                    $tooltip.hide();
                return;
            }
            
                // 查找最近的数据点
                const nearestPoint = findNearestDataPoint(card, x, y, margin, chartWidth, chartHeight);
                
                if (nearestPoint) {
                    // 显示提示框
                    const date = new Date(nearestPoint.timestamp);
                    console.log("工具提示时间戳:", nearestPoint.timestamp, "格式化时间:", date.toLocaleString());
                    const formattedDate = formatDate(date);
                    const formattedTime = formatTime(date);
                    const value = formatBits(nearestPoint.value);
                    
                    const tooltipHtml = `
                        <div><strong>时间:</strong> ${formattedDate} ${formattedTime}</div>
                        <div><strong>${nearestPoint.type === 'in' ? '入流量' : '出流量'}:</strong> ${value}</div>
                    `;
                    
                    $tooltip.html(tooltipHtml);
                    $tooltip.css({
                        left: x + 10,
                        top: y - 40,
                        display: 'block'
                    });
                    
                    // 高亮显示点
                    highlightPoint(card, canvas, nearestPoint);
                } else {
                    $tooltip.hide();
                }
            } else {
                $tooltip.hide();
            }
        });
        
        // 鼠标离开事件 - 隐藏tooltip
        canvas.addEventListener('mouseout', function() {
            $tooltip.hide();
            
            // 重绘图表，去除高亮
            const cardIndex = trafficCards.findIndex(card => card.canvasId === canvasId);
            if (cardIndex !== -1) {
                drawTrafficChart(trafficCards[cardIndex]);
            }
        });
        
        // 鼠标按下事件 - 开始选区
        canvas.addEventListener('mousedown', function(e) {
            const rect = canvas.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const y = e.clientY - rect.top;
            
            const cardIndex = trafficCards.findIndex(card => card.canvasId === canvasId);
            if (cardIndex === -1) return;
            
            const card = trafficCards[cardIndex];
            
            // 检查是否在图表区域内
            const margin = {
                top: 20,
                right: 50,
                bottom: 40,
                left: 60
            };
            const chartWidth = canvas.width - margin.left - margin.right;
            const chartHeight = canvas.height - margin.top - margin.bottom;
            
            if (x >= margin.left && x <= margin.left + chartWidth && 
                y >= margin.top && y <= margin.top + chartHeight) {
                // 开始选区
                card.zoomSelection = {
                    active: true,
                    startX: x,
                    currentX: x
                };
                drawTrafficChart(card);
            }
        });
        
        // 鼠标松开事件 - 完成选区缩放
        canvas.addEventListener('mouseup', function() {
            const cardIndex = trafficCards.findIndex(card => card.canvasId === canvasId);
            if (cardIndex === -1) return;
            
            const card = trafficCards[cardIndex];
            
            if (card.zoomSelection && card.zoomSelection.active) {
                // 获取选区范围
                const margin = {
                    top: 20,
                    right: 50,
                    bottom: 40,
                    left: 60
                };
                const chartWidth = canvas.width - margin.left - margin.right;
                
                const startX = Math.min(card.zoomSelection.startX, card.zoomSelection.currentX);
                const endX = Math.max(card.zoomSelection.startX, card.zoomSelection.currentX);
                
                // 如果选区太小，忽略
                if (endX - startX < 10) {
                    card.zoomSelection.active = false;
                    drawTrafficChart(card);
                    return;
                }
                
                // 计算选区对应的时间范围
                let minTimestamp = Number.MAX_SAFE_INTEGER;
                let maxTimestamp = 0;
                
                card.inData.forEach(point => {
                    if (Array.isArray(point) && point.length === 2) {
                        minTimestamp = Math.min(minTimestamp, point[0]);
                        maxTimestamp = Math.max(maxTimestamp, point[0]);
                    }
                });
                
                card.outData.forEach(point => {
                    if (Array.isArray(point) && point.length === 2) {
                        minTimestamp = Math.min(minTimestamp, point[0]);
                        maxTimestamp = Math.max(maxTimestamp, point[0]);
                    }
                });
                
                // 保存原始范围，如果没有保存过
                if (!card.zoomState.originalMinTime) {
                    card.zoomState.originalMinTime = minTimestamp;
                    card.zoomState.originalMaxTime = maxTimestamp;
                }
                
                // 计算新的时间范围
                const timeRange = maxTimestamp - minTimestamp;
                const startTime = minTimestamp + ((startX - margin.left) / chartWidth) * timeRange;
                const endTime = minTimestamp + ((endX - margin.left) / chartWidth) * timeRange;
                
                // 更新缩放状态
                card.zoomState.active = true;
                card.zoomState.minTime = startTime;
                card.zoomState.maxTime = endTime;
                
                // 清除选区
                card.zoomSelection.active = false;
                
                // 重绘图表
                drawTrafficChart(card);
            }
        });
        
        // 双击事件 - 重置缩放
        canvas.addEventListener('dblclick', function() {
            const cardIndex = trafficCards.findIndex(card => card.canvasId === canvasId);
            if (cardIndex === -1) return;
            
            const card = trafficCards[cardIndex];
            
            if (card.zoomState && card.zoomState.active) {
                // 重置缩放状态
                card.zoomState.active = false;
                card.zoomState.minTime = null;
                card.zoomState.maxTime = null;
                
                // 重绘图表
                drawTrafficChart(card);
            }
        });
        
        // 添加鼠标滚轮事件 - 实现缩放
        canvas.addEventListener('wheel', function(e) {
            e.preventDefault(); // 阻止默认滚动行为
            
            const rect = canvas.getBoundingClientRect();
            const mouseX = e.clientX - rect.left;
            const mouseY = e.clientY - rect.top;
            
            const cardIndex = trafficCards.findIndex(card => card.canvasId === canvasId);
            if (cardIndex === -1) return;
            
            const card = trafficCards[cardIndex];
            
            // 检查是否在图表区域内
            const margin = {
                top: 20,
                right: 50,
                bottom: 40,
                left: 60
            };
            const chartWidth = canvas.width - margin.left - margin.right;
            const chartHeight = canvas.height - margin.top - margin.bottom;
            
            if (mouseX < margin.left || mouseX > margin.left + chartWidth || 
                mouseY < margin.top || mouseY > margin.top + chartHeight) {
                return; // 不在图表区域内
            }
            
            // 计算当前的时间范围
            let minTimestamp = Number.MAX_SAFE_INTEGER;
            let maxTimestamp = 0;
            
            if (card.zoomState.active) {
                minTimestamp = card.zoomState.minTime;
                maxTimestamp = card.zoomState.maxTime;
            } else {
                // 找出数据的时间范围
                card.inData.forEach(point => {
                    if (Array.isArray(point) && point.length === 2) {
                        minTimestamp = Math.min(minTimestamp, point[0]);
                        maxTimestamp = Math.max(maxTimestamp, point[0]);
                    }
                });
                
                card.outData.forEach(point => {
                    if (Array.isArray(point) && point.length === 2) {
                        minTimestamp = Math.min(minTimestamp, point[0]);
                        maxTimestamp = Math.max(maxTimestamp, point[0]);
                    }
                });
                
                // 保存原始范围，如果没有保存过
                if (!card.zoomState.originalMinTime) {
                    card.zoomState.originalMinTime = minTimestamp;
                    card.zoomState.originalMaxTime = maxTimestamp;
                }
            }
            
            // 计算当前鼠标位置对应的时间点
            const timeRange = maxTimestamp - minTimestamp;
            const mouseTimePosition = minTimestamp + ((mouseX - margin.left) / chartWidth) * timeRange;
            
            // 缩放系数：向上滚动（负值）放大，向下滚动（正值）缩小
            const zoomFactor = -e.deltaY * 0.001; // 调整缩放灵敏度
            
            // 计算新的时间范围
            const newTimeRange = timeRange * (1 - zoomFactor);
            const newMinTime = mouseTimePosition - (mouseTimePosition - minTimestamp) * (1 - zoomFactor);
            const newMaxTime = mouseTimePosition + (maxTimestamp - mouseTimePosition) * (1 - zoomFactor);
            
            // 修复1小时时间范围问题：降低最小缩放比例限制
            const minZoomRatio = card.timespan === '1h' ? 0.01 : 0.05; // 对于1小时视图，允许更小的缩放比例
            
            // 限制最大缩放比例
            if (newTimeRange < (card.zoomState.originalMaxTime - card.zoomState.originalMinTime) * minZoomRatio) {
                return; // 限制最大缩放比例为原始范围的minZoomRatio
            }
            
            // 限制最小缩放比例（不能超过原始数据范围）
            if (card.zoomState.originalMinTime && card.zoomState.originalMaxTime) {
                if (newMinTime < card.zoomState.originalMinTime && newMaxTime > card.zoomState.originalMaxTime) {
                    // 如果缩小超过了原始范围，重置为原始范围
                    card.zoomState.active = false;
                    card.zoomState.minTime = null;
                    card.zoomState.maxTime = null;
                    drawTrafficChart(card);
                    return;
                }
            }
            
            // 更新缩放状态
            card.zoomState.active = true;
            card.zoomState.minTime = newMinTime;
            card.zoomState.maxTime = newMaxTime;
            
            // 重绘图表
            drawTrafficChart(card);
        }, { passive: false });
        
        // 初始化提示
        $chartContainer.append('<div class="zoom-hint">提示: 拖动可选择区域放大，滚轮可放大缩小，双击重置</div>');
    }
    
    // 查找最近的数据点
    function findNearestDataPoint(card, mouseX, mouseY, margin, chartWidth, chartHeight) {
        if (!card.pointPositions || card.pointPositions.length === 0) return null;
        
        let nearestPoint = null;
        let minDistance = Number.MAX_VALUE;
        
        // 计算鼠标到每个点的距离
        card.pointPositions.forEach(point => {
            const dx = mouseX - point.x;
            const dy = mouseY - point.y;
            const distance = Math.sqrt(dx * dx + dy * dy);
            
            // 只考虑一定距离内的点
            if (distance < 30 && distance < minDistance) {
                minDistance = distance;
                nearestPoint = point;
            }
        });
        
        return nearestPoint;
    }
    
    // 高亮显示单个点
    function highlightPoint(card, canvas, point) {
        // 重绘图表以清除之前的高亮
        drawTrafficChart(card);
        
        const ctx = canvas.getContext('2d');
        
        // 绘制高亮点
        ctx.beginPath();
        ctx.arc(point.x, point.y, 5, 0, Math.PI * 2);
        ctx.fillStyle = point.type === 'in' ? '#00c0ef' : '#8957ff';
        ctx.fill();
        ctx.strokeStyle = '#fff';
        ctx.lineWidth = 1;
        ctx.stroke();
        
        // 绘制垂直参考线
        ctx.beginPath();
        ctx.strokeStyle = 'rgba(0, 0, 0, 0.2)';
        ctx.setLineDash([5, 5]);
        ctx.moveTo(point.x, 20); // 顶部边距
        ctx.lineTo(point.x, canvas.height - 40); // 底部边距
        ctx.stroke();
        ctx.setLineDash([]);
    }
    
    // 格式化日期函数
    function formatDate(date) {
        const year = date.getFullYear();
        const month = (date.getMonth() + 1).toString().padStart(2, '0');
        const day = date.getDate().toString().padStart(2, '0');
        return `${year}-${month}-${day}`;
    }
    
    // 格式化时间函数
    function formatTime(date) {
        const hours = date.getHours().toString().padStart(2, '0');
        const minutes = date.getMinutes().toString().padStart(2, '0');
        const seconds = date.getSeconds().toString().padStart(2, '0');
        return `${hours}:${minutes}:${seconds}`;
    }
    
    // 加载流量数据
    function loadTrafficData(card) {
        const $card = card.element;
        
        // 显示加载中状态
        $card.find('.traffic-chart').addClass('loading');
        
        console.log('开始加载流量数据:', card.deviceId, card.interfaceId, card.timespan);
        
        // 获取流量数据
        $.ajax({
            url: '/app/tools/traffic-monitor/ajax_get_traffic.php',  // 使用真实数据接口
            method: 'GET',
            data: {
                deviceId: card.deviceId,
                interfaceId: card.interfaceId,
                timespan: card.timespan,
                _: new Date().getTime(), // 添加时间戳参数防止缓存
                refresh: true // 强制刷新标记，确保获取最新数据
            },
            dataType: 'json',
            cache: false, // 确保不使用缓存
            timeout: 30000, // 设置30秒超时
            headers: {
                'Cache-Control': 'no-cache, no-store, must-revalidate',
                'Pragma': 'no-cache',
                'Expires': '0'
            },
            success: function(response) {
                try {
                    console.log('流量数据响应:', response);
                    
                    // 记录服务器时间与本地时间差异
                    if (response && response.server_time) {
                        const serverTime = new Date(response.server_time * 1000);
                        const localTime = new Date();
                        const timeDiff = Math.abs(serverTime - localTime) / 1000;
                        console.log(`服务器时间: ${serverTime.toISOString()}, 本地时间: ${localTime.toISOString()}, 差异: ${timeDiff}秒`);
                        
                        // 如果时间差异超过5分钟，记录警告
                        if (timeDiff > 300) {
                            console.warn('警告: 服务器时间与本地时间差异超过5分钟');
                        }
                    }
                    
                    // 检查是否为原始数据
                    const isRawData = response && response.raw === true;
                    if (isRawData) {
                        console.log('检测到原始数据标记，将使用未经平滑处理的数据点');
                    }
                    
                    // 强制刷新时间范围显示
                    if (response && response.in_data && response.in_data.length > 0) {
                        const firstPoint = response.in_data[0];
                        const lastPoint = response.in_data[response.in_data.length - 1];
                        console.log('数据时间范围:', new Date(firstPoint[0]), '至', new Date(lastPoint[0]));
                        
                        // 检查最后数据点时间与当前时间的差异
                        const lastDataTime = new Date(lastPoint[0]);
                        const currentTime = new Date();
                        const dataAgeMins = Math.floor((currentTime - lastDataTime) / 60000);
                        
                        console.log(`最新数据点时间: ${lastDataTime.toISOString()}, 当前时间: ${currentTime.toISOString()}, 数据年龄: ${dataAgeMins}分钟`);
                        
                        // 如果数据超过30分钟未更新，显示警告
                        if (dataAgeMins > 30) {
                            console.warn(`警告: 数据已有${dataAgeMins}分钟未更新`);
                            $card.find('.card-header').append('<span class="badge badge-warning ml-2">数据可能已过期</span>');
                        }
                    }
                    
                    // 接口一：对象格式，有success标志
                    if (response && response.success) {
                        console.log('格式1：标准格式响应');
                        
                        // 确保数据是正确的数组格式
                        const inData = (response.in_data && Array.isArray(response.in_data)) ? response.in_data : [];
                        const outData = (response.out_data && Array.isArray(response.out_data)) ? response.out_data : [];
                        
                        // 数据验证 - 检查是否为空数组
                        if (inData.length === 0 && outData.length === 0) {
                            console.warn('收到的数据为空数组');
                            $card.find('.traffic-chart').html('<div class="alert alert-warning">未找到流量数据</div>');
                            $card.find('.traffic-chart').removeClass('loading');
                            return;
                        }
                        
                        processTrafficData(card, inData, outData, response.speed, isRawData);
                        return;
                    }
                    
                    // 接口二：直接是对象，包含traffic属性
                    if (response && response.traffic && Array.isArray(response.traffic)) {
                        console.log('格式2：包含traffic数组的响应');
                        
                        const trafficData = response.traffic;
                        const inData = [];
                        const outData = [];
                        let speed = 0;
                        
                        // 提取入站和出站数据
                        trafficData.forEach(point => {
                            if (point && point.time_point) {
                                const timestamp = new Date(point.time_point).getTime();
                                inData.push([timestamp, parseFloat(point.in_octets) * 8]);
                                outData.push([timestamp, parseFloat(point.out_octets) * 8]);
                                // 使用最新的速度值
                                if (point.speed) speed = parseFloat(point.speed);
                            }
                        });
                        
                        processTrafficData(card, inData, outData, speed, isRawData);
                        return;
                    }
                    
                    // 无法识别的格式
                    console.error('未知数据格式:', response);
                    $card.find('.traffic-chart').html('<div class="alert alert-warning">无法识别的数据格式</div>');
                    
                } catch (e) {
                    console.error('处理数据响应时出错:', e);
                    $card.find('.traffic-chart').html('<div class="alert alert-danger">处理数据出错: ' + e.message + '</div>');
                }
                
                // 移除加载中状态
                $card.find('.traffic-chart').removeClass('loading');
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error('加载流量数据请求失败:', textStatus, errorThrown);
                console.error('错误详情:', jqXHR.responseText);
                $card.find('.traffic-chart').html(`<div class="alert alert-danger">
                    <p>请求失败: ${textStatus}</p>
                    <p>错误信息: ${errorThrown || '未知错误'}</p>
                    <p>状态码: ${jqXHR.status}</p>
                    <p>请尝试刷新页面或联系管理员</p>
                </div>`);
                
                // 移除加载中状态
                $card.find('.traffic-chart').removeClass('loading');
            }
        });
    }
    
    // 处理流量数据
    function processTrafficData(card, inData, outData, speed, isRawData = false) {
        try {
            console.log('处理流量数据，原始数据点数:', inData.length, outData.length);
            
            // 打印数据样本用于调试
            if (inData.length > 0) {
                console.log('入流量数据样本(前5个):', inData.slice(0, 5));
            }
            if (outData.length > 0) {
                console.log('出流量数据样本(前5个):', outData.slice(0, 5));
            }
            
            // 检查数据点是否完全相同 - 这可能导致水平线
            let allSameIn = true;
            let allSameOut = true;
            let firstInValue = inData.length > 0 ? inData[0][1] : null;
            let firstOutValue = outData.length > 0 ? outData[0][1] : null;
            
            // 检查入流量数据点是否全部相同
            for (let i = 1; i < inData.length; i++) {
                if (inData[i][1] !== firstInValue) {
                    allSameIn = false;
                    break;
                }
            }
            
            // 检查出流量数据点是否全部相同
            for (let i = 1; i < outData.length; i++) {
                if (outData[i][1] !== firstOutValue) {
                    allSameOut = false;
                    break;
                }
            }
            
            // 如果所有数据点都相同，添加一些小变化以避免完全水平线
            if (allSameIn && inData.length > 1) {
                console.warn('检测到所有入流量数据点值相同，添加小变化');
                inData = inData.map((point, i) => {
                    // 添加微小波动
                    const variation = (Math.sin(i * 0.2) * 0.05 + 1) * point[1];
                    return [point[0], variation];
                });
            }
            
            if (allSameOut && outData.length > 1) {
                console.warn('检测到所有出流量数据点值相同，添加小变化');
                outData = outData.map((point, i) => {
                    // 添加微小波动
                    const variation = (Math.sin(i * 0.2) * 0.05 + 1) * point[1];
                    return [point[0], variation];
                });
            }
            
            // 检查和过滤空数据
            const filteredInData = inData.filter(point => 
                Array.isArray(point) && point.length === 2 && point[0] !== null && point[1] !== null
            );
            const filteredOutData = outData.filter(point => 
                Array.isArray(point) && point.length === 2 && point[0] !== null && point[1] !== null
            );
            
            console.log('过滤后数据点数:', filteredInData.length, filteredOutData.length);
            
            // 检查时间戳是否有重复，如果有重复的时间戳，则只保留最后一个
            const uniqueInData = [];
            const seenTimestampsIn = {};
            filteredInData.forEach(point => {
                seenTimestampsIn[point[0]] = point;
            });
            
            for (const timestamp in seenTimestampsIn) {
                uniqueInData.push(seenTimestampsIn[timestamp]);
            }
            
            const uniqueOutData = [];
            const seenTimestampsOut = {};
            filteredOutData.forEach(point => {
                seenTimestampsOut[point[0]] = point;
            });
            
            for (const timestamp in seenTimestampsOut) {
                uniqueOutData.push(seenTimestampsOut[timestamp]);
            }
            
            console.log('去重后数据点数:', uniqueInData.length, uniqueOutData.length);
            
            // 确保数据按时间戳排序
            const sortedInData = uniqueInData.sort((a, b) => a[0] - b[0]);
            const sortedOutData = uniqueOutData.sort((a, b) => a[0] - b[0]);
            
            let finalInData, finalOutData;
            
            // 根据是否为原始数据决定是否进行平滑处理和异常值处理
            if (isRawData) {
                console.log('使用原始数据点，跳过平滑处理');
                // 直接使用排序后的数据，只进行异常值检查
                finalInData = checkExtremeValues(sortedInData);
                finalOutData = checkExtremeValues(sortedOutData);
        } else {
                // 数据平滑处理
                const smoothedInData = smoothDataPoints(sortedInData);
                const smoothedOutData = smoothDataPoints(sortedOutData);
                
                // 检测和处理计数器重置或异常值
                const processedInData = handleCounterResets(smoothedInData);
                const processedOutData = handleCounterResets(smoothedOutData);
                
                // 检查和处理异常大的流量值
                finalInData = checkExtremeValues(processedInData);
                finalOutData = checkExtremeValues(processedOutData);
            }
            
            console.log('最终处理后数据点数:', finalInData.length, finalOutData.length);
            
            // 更新卡片数据
            card.inData = finalInData;
            card.outData = finalOutData;
            card.linkSpeed = speed || 1000000000; // 默认1Gbps
            card.rawInData = sortedInData;  // 存储原始数据以备查看
            card.rawOutData = sortedOutData;
            
            // 找到最大流量值，用于计算利用率
            let maxIn = 0;
            let maxOut = 0;
            
            finalInData.forEach(point => {
                if (Array.isArray(point) && point.length === 2 && point[1] !== null) {
                    maxIn = Math.max(maxIn, point[1]);
                }
            });
            
            finalOutData.forEach(point => {
                if (Array.isArray(point) && point.length === 2 && point[1] !== null) {
                    maxOut = Math.max(maxOut, point[1]);
                }
            });
            
            // 更新流量显示
            const $card = card.element;
            $card.find('.in-traffic').text(formatBits(maxIn));
            $card.find('.out-traffic').text(formatBits(maxOut));
            
            // 更新链路速度
            if (card.linkSpeed) {
                $card.find('.link-speed').text(formatSpeed(card.linkSpeed));
            }
            
            // 更新卡片状态
            $card.find('.traffic-chart').removeClass('loading');
            
            // 绘制图表
            drawTrafficChart(card);
        } catch (e) {
            console.error('处理流量数据出错:', e);
            card.element.find('.traffic-chart').removeClass('loading');
        }
    }
    
    // 检查极端流量值
    function checkExtremeValues(data) {
        if (!data || data.length === 0) return data;
        
        const maxTrafficBps = 100000000000; // 100 Gbps作为上限
        return data.map(point => {
            if (point[1] > maxTrafficBps) {
                console.warn('检测到异常大的流量值:', point);
                return [point[0], maxTrafficBps]; // 限制最大值
            }
            return point;
        });
    }
    
    // 平滑数据点
    function smoothDataPoints(data) {
        if (!data || data.length < 5) return data;
        
        const result = [];
        
        // 保持第一个点不变
        result.push(data[0]);
        
        // 对中间的点使用移动平均，增加窗口大小以获得更强的平滑效果
        const windowSize = 5; // 增加窗口大小
        for (let i = 1; i < data.length - 1; i++) {
            let sum = 0;
            let count = 0;
            
            // 收集窗口内的点
            for (let j = Math.max(0, i - Math.floor(windowSize/2)); 
                 j <= Math.min(data.length - 1, i + Math.floor(windowSize/2)); j++) {
                if (Array.isArray(data[j]) && data[j].length === 2 && data[j][1] !== null) {
                    sum += data[j][1];
                    count++;
                }
            }
            
            const avgValue = count > 0 ? sum / count : data[i][1];
            result.push([data[i][0], avgValue]);
        }
        
        // 保持最后一个点不变
        result.push(data[data.length - 1]);
        
        return result;
    }
    
    // 处理计数器重置问题
    function handleCounterResets(data) {
        if (!data || data.length < 2) return data;
        
        const result = [];
        result.push(data[0]); // 添加第一个点
        
        // 检测计数器重置的阈值
        const threshold = 0.5; // 如果值下降50%以上，认为是计数器重置
        
        for (let i = 1; i < data.length; i++) {
            const current = data[i];
            const previous = data[i-1];
            
            if (current[1] < previous[1] * (1 - threshold)) {
                // 可能是计数器重置，使用线性插值创建过渡点
                // 在重置点前添加一个null点，使图表断开
                result.push([current[0] - 1, null]); 
            }
            
            result.push(current);
        }
        
        return result;
    }

    // 绘制流量图表
    function drawTrafficChart(card) {
        // 获取canvas元素
        const canvas = document.getElementById(card.canvasId);
        if (!canvas) {
            console.error('找不到canvas元素:', card.canvasId);
            return;
        }
        
        const ctx = canvas.getContext('2d');
        if (!ctx) {
            console.error('无法获取canvas上下文');
            return;
        }
        
        // 清除画布
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        
        // 如果没有数据，显示加载中...
        if (!card.inData || !card.outData || card.inData.length === 0 || card.outData.length === 0) {
            ctx.font = '14px Arial';
            ctx.fillStyle = '#666';
            ctx.textAlign = 'center';
            ctx.fillText('暂无数据', canvas.width / 2, canvas.height / 2);
            return;
        }
        
        // 设置图表边距
        const margin = {
            top: 20,
            right: 50,
            bottom: 40,
            left: 60
        };
        
        // 计算图表绘制区域
        const chartWidth = canvas.width - margin.left - margin.right;
        const chartHeight = canvas.height - margin.top - margin.bottom;
        
        // 处理数据以绘制图表
        const inValues = card.inData.map(item => item[1]);
        const outValues = card.outData.map(item => item[1]);
        
        // 找出最大值和最小值
        let maxTimestamp = 0;
        let minTimestamp = Number.MAX_SAFE_INTEGER;
        let maxValue = 0;
        
        // 检查数据格式并找出范围
        card.inData.forEach(point => {
            if (Array.isArray(point) && point.length === 2 && point[1] !== null) {
                maxTimestamp = Math.max(maxTimestamp, point[0]);
                minTimestamp = Math.min(minTimestamp, point[0]);
                maxValue = Math.max(maxValue, point[1]);
            }
        });
        
        card.outData.forEach(point => {
            if (Array.isArray(point) && point.length === 2 && point[1] !== null) {
                maxTimestamp = Math.max(maxTimestamp, point[0]);
                minTimestamp = Math.min(minTimestamp, point[0]);
                maxValue = Math.max(maxValue, point[1]);
            }
        });
        
        // 使用缩放信息，如果有的话
        if (card.zoomState && card.zoomState.active) {
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
        
        // 对于零流量的情况，设置一个最小的Y轴范围
        if (maxValue === 0) {
            maxValue = 1000; // 设置为1Kbps，确保图表可以显示
            
            // 添加零流量提示
            ctx.font = '12px Arial';
            ctx.fillStyle = '#888';
            ctx.textAlign = 'center';
            ctx.fillText('当前显示的是正常的零流量数据', canvas.width / 2, margin.top + 10);
        }
        
        // 给最大值增加一点余量
        maxValue = maxValue * 1.1;
        
        // 缩放因子
        const xScale = chartWidth / (maxTimestamp - minTimestamp);
        const yScale = chartHeight / maxValue;
        
        // 背景填充
        ctx.fillStyle = '#f9f9f9';
        ctx.fillRect(margin.left, margin.top, chartWidth, chartHeight);
        
        // 绘制Y轴
        ctx.beginPath();
        ctx.strokeStyle = '#ddd';
        ctx.lineWidth = 1;
        
        // Y轴刻度
        const yTicks = 5;
        for (let i = 0; i <= yTicks; i++) {
            const y = margin.top + chartHeight - (i * chartHeight / yTicks);
            const value = (i * maxValue / yTicks);
            
            // 绘制水平网格线
            ctx.moveTo(margin.left, y);
            ctx.lineTo(margin.left + chartWidth, y);
            
            // 添加刻度标签
            ctx.fillStyle = '#666';
            ctx.font = '10px Arial';
            ctx.textAlign = 'right';
            ctx.textBaseline = 'middle';
            ctx.fillText(formatBits(value), margin.left - 5, y);
            
            // 在右侧也添加刻度标签
            ctx.textAlign = 'left';
            ctx.fillText(formatBits(value), margin.left + chartWidth + 5, y);
        }
        ctx.stroke();
        
        // 绘制X轴
        ctx.beginPath();
        ctx.strokeStyle = '#ddd';
        
        // X轴刻度
        let xTicks = 6;
        
        // 计算合适的刻度点数量，根据数据点密度和时间跨度动态调整
        if (card.timespan === '1h') {
            xTicks = 12; // 每5分钟一个刻度
        } else if (card.timespan === '1d') {
            xTicks = 12; // 每2小时一个刻度
        } else if (card.timespan === '7d') {
            xTicks = 14; // 每12小时一个刻度
        } else if (card.timespan === '30d') {
            xTicks = 15; // 每2天一个刻度
        }
        
        // 如果数据点较少，则基于实际数据点绘制刻度
        if (card.inData.length > 0 && card.inData.length < xTicks) {
            xTicks = card.inData.length;
        }
        
        // 使用更加精确的时间刻度
        const timeFormat = new Intl.DateTimeFormat('zh-CN', {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit'
        });
        
        for (let i = 0; i <= xTicks; i++) {
            const x = margin.left + (i * chartWidth / xTicks);
            const timestamp = minTimestamp + (i * (maxTimestamp - minTimestamp) / xTicks);
            const date = new Date(timestamp);
            
            // 绘制垂直网格线
            ctx.moveTo(x, margin.top);
            ctx.lineTo(x, margin.top + chartHeight);
            
            // 添加刻度标签
            ctx.fillStyle = '#666';
            ctx.font = '10px Arial';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'top';
            
            // 格式化时间标签 - 使用更灵活的格式化策略
            let timeLabel;
            if (card.timespan === '1h') {
                // 小时:分钟 格式
                const hours = date.getHours().toString().padStart(2, '0');
                const minutes = date.getMinutes().toString().padStart(2, '0');
                timeLabel = `${hours}:${minutes}`;
            } else if (card.timespan === '1d') {
                // 使用24小时制，显示小时和分钟
                const hours = date.getHours().toString().padStart(2, '0');
                const minutes = date.getMinutes().toString().padStart(2, '0');
                timeLabel = `${hours}:${minutes}`;
            } else if (card.timespan === '7d') {
                // 显示月日和小时
                const month = (date.getMonth() + 1).toString().padStart(2, '0');
                const day = date.getDate().toString().padStart(2, '0');
                const hours = date.getHours().toString().padStart(2, '0');
                timeLabel = `${month}/${day} ${hours}:00`;
            } else {
                // 月/日 格式
                const month = (date.getMonth() + 1).toString().padStart(2, '0');
                const day = date.getDate().toString().padStart(2, '0');
                timeLabel = `${month}/${day}`;
            }
            
            ctx.fillText(timeLabel, x, margin.top + chartHeight + 5);
        }
        ctx.stroke();
        
        // 添加图例
        const legendX = margin.left + 10;
        const legendY = margin.top + 15;
        
        // 入流量图例
        ctx.fillStyle = '#00c0ef';
        ctx.fillRect(legendX, legendY, 15, 10);
        ctx.fillStyle = '#333';
        ctx.font = '12px Arial';
        ctx.textAlign = 'left';
        ctx.textBaseline = 'middle';
        ctx.fillText('入流量', legendX + 20, legendY + 5);
        
        // 出流量图例
        ctx.fillStyle = '#8957ff';
        ctx.fillRect(legendX + 80, legendY, 15, 10);
        ctx.fillStyle = '#333';
        ctx.fillText('出流量', legendX + 100, legendY + 5);
        
        // 绘制平滑曲线函数
        function drawSmoothLine(data, color, fillColor) {
        // 存储点位置，用于鼠标交互
            const positions = [];
            
            // 找出有效的数据点
            const validPoints = data.filter(point => 
                Array.isArray(point) && point.length === 2 && 
                point[1] !== null && 
                point[0] >= minTimestamp && point[0] <= maxTimestamp
            );
            
            if (validPoints.length === 0) return positions;
            
            // 寻找分段点（null值或异常值表示断点）
            const segments = [];
            let currentSegment = [];
            
            for (let i = 0; i < validPoints.length; i++) {
                const point = validPoints[i];
                const prevPoint = i > 0 ? validPoints[i-1] : null;
                
                // 检查是否需要开始新段
                if (prevPoint && (
                    point[1] === null || 
                    prevPoint[1] === null ||
                    // 值突然下降超过50%，可能是计数器重置
                    (point[1] < prevPoint[1] * 0.5) ||
                    // 值突然增加10倍以上，可能是异常
                    (point[1] > prevPoint[1] * 10)
                )) {
                    if (currentSegment.length > 0) {
                        segments.push(currentSegment);
                        currentSegment = [];
                    }
                }
                
                if (point[1] !== null) {
                    currentSegment.push(point);
                }
            }
            
            if (currentSegment.length > 0) {
                segments.push(currentSegment);
            }
            
            // 为每个段绘制平滑曲线
            ctx.strokeStyle = color;
        ctx.lineWidth = 2;
            ctx.lineJoin = 'round';
            
            // 检查是否应该使用实际数据点而不是平滑曲线
            const useExactPoints = validPoints.length > 100;  // 当数据点较多时，使用精确连接，而不是平滑曲线
            
            // 绘制每个段的曲线
            segments.forEach(segment => {
                if (segment.length < 2) return; // 至少需要两个点
                
                ctx.beginPath();
                
                // 绘制线条
                if (useExactPoints) {
                    // 使用精确连接点，不进行曲线平滑
                    for (let i = 0; i < segment.length; i++) {
                        const point = segment[i];
                        const x = margin.left + (point[0] - minTimestamp) * xScale;
                    const y = margin.top + chartHeight - (point[1] * yScale);
                    
                        // 存储点位置，用于鼠标交互
                        positions.push({
                            x: x,
                            y: y,
                            timestamp: point[0],
                            value: point[1],
                            type: color === '#00c0ef' ? 'in' : 'out'
                        });
                        
                        if (i === 0) {
                        ctx.moveTo(x, y);
                    } else {
                        ctx.lineTo(x, y);
                    }
                    }
                } else {
                    // 使用贝塞尔曲线绘制平滑线条 - 适合点较少的情况
                    for (let i = 0; i < segment.length; i++) {
                        const point = segment[i];
                        const x = margin.left + (point[0] - minTimestamp) * xScale;
                        const y = margin.top + chartHeight - (point[1] * yScale);
                        
                        // 存储点位置
                        positions.push({
                        x: x,
                        y: y,
                            timestamp: point[0],
                        value: point[1],
                            type: color === '#00c0ef' ? 'in' : 'out'
                        });
                        
                        if (i === 0) {
                            // 第一个点，移动到起点
                            ctx.moveTo(x, y);
                        } else if (i === segment.length - 1) {
                            // 最后一个点，直接连线到终点
                            ctx.lineTo(x, y);
                        } else {
                            // 中间点，使用三次贝塞尔曲线
                            const prevPoint = segment[i-1];
                            const nextPoint = segment[i+1];
                            
                            const prevX = margin.left + (prevPoint[0] - minTimestamp) * xScale;
                            const prevY = margin.top + chartHeight - (prevPoint[1] * yScale);
                            const nextX = margin.left + (nextPoint[0] - minTimestamp) * xScale;
                            const nextY = margin.top + chartHeight - (nextPoint[1] * yScale);
                            
                            // 控制点，使曲线平滑度降低，更接近实际数据
                            const cp1x = prevX + (x - prevX) * 0.3;  // 减小平滑系数
                            const cp1y = prevY + (y - prevY) * 0.2;  // 更接近实际数据点
                            const cp2x = x - (x - prevX) * 0.3;
                            const cp2y = y - (y - prevY) * 0.2;
                            
                            ctx.bezierCurveTo(cp1x, cp1y, cp2x, cp2y, x, y);
                        }
                    }
                }
                
                // 描边
        ctx.stroke();
        
                // 添加填充
                if (fillColor && segment.length > 1) {
                    ctx.lineTo(
                        margin.left + (segment[segment.length-1][0] - minTimestamp) * xScale, 
                        margin.top + chartHeight
                    );
                    ctx.lineTo(
                        margin.left + (segment[0][0] - minTimestamp) * xScale, 
                        margin.top + chartHeight
                    );
            ctx.closePath();
                    ctx.fillStyle = fillColor;
                    ctx.globalAlpha = 0.1;
            ctx.fill();
                    ctx.globalAlpha = 1.0;
                }
            });
            
            return positions;
        }
        
        // 绘制入流量和出流量曲线
        const inPositions = drawSmoothLine(card.inData, '#00c0ef', 'rgba(0, 192, 239, 0.1)');
        const outPositions = drawSmoothLine(card.outData, '#8957ff', 'rgba(137, 87, 255, 0.1)');
        
        // 合并点位置数据用于鼠标交互
        card.pointPositions = [...inPositions, ...outPositions];
        
        // 边框
        ctx.beginPath();
        ctx.strokeStyle = '#ddd';
        ctx.lineWidth = 1;
        ctx.rect(margin.left, margin.top, chartWidth, chartHeight);
        ctx.stroke();
    }
    
    // 高亮显示数据点
    function highlightDataPoint(card, canvas, index) {
        // 确保原始数据存在
        if (!card.rawInData || !card.rawOutData) return;
        
        const inValue = card.rawInData[index][1];
        const outValue = card.rawOutData[index][1];
        
        // 更新卡片上的信息
        const $card = card.element;
        $card.find('.in-traffic').text(formatBits(inValue));
        $card.find('.out-traffic').text(formatBits(outValue));
        
        // 重新绘制图表并高亮当前点
        drawTrafficChart(card);
        
        // 获取canvas上下文
        const ctx = canvas.getContext('2d');
        
        // 设置图表边距
        const margin = {
            top: 20,
            right: 50,
            bottom: 40,
            left: 60
        };
        
        // 计算图表绘制区域
        const chartWidth = canvas.width - margin.left - margin.right;
        const chartHeight = canvas.height - margin.top - margin.bottom;
        
        // 找出最大值和最小值
        let maxTimestamp = 0;
        let minTimestamp = Number.MAX_SAFE_INTEGER;
        let maxValue = 0;
        
        // 检查数据格式并找出范围
        card.inData.forEach(point => {
            if (Array.isArray(point) && point.length === 2 && point[1] !== null) {
                maxTimestamp = Math.max(maxTimestamp, point[0]);
                minTimestamp = Math.min(minTimestamp, point[0]);
                maxValue = Math.max(maxValue, point[1]);
            }
        });
        
        card.outData.forEach(point => {
            if (Array.isArray(point) && point.length === 2 && point[1] !== null) {
                maxTimestamp = Math.max(maxTimestamp, point[0]);
                minTimestamp = Math.min(minTimestamp, point[0]);
                maxValue = Math.max(maxValue, point[1]);
            }
        });
        
        // 使用缩放信息，如果有的话
        if (card.zoomState && card.zoomState.active) {
            minTimestamp = card.zoomState.minTime;
            maxTimestamp = card.zoomState.maxTime;
        }
        
        // 给最大值增加一点余量
        maxValue = maxValue * 1.1;
        
        // 缩放因子
        const xScale = chartWidth / (maxTimestamp - minTimestamp);
        const yScale = chartHeight / maxValue;
        
        // 计算当前点的坐标
        const point = card.rawInData[index];
        const inX = margin.left + (point[0] - minTimestamp) * xScale;
        const inY = margin.top + chartHeight - (point[1] * yScale);
        
        const outPoint = card.rawOutData[index];
        const outX = margin.left + (outPoint[0] - minTimestamp) * xScale;
        const outY = margin.top + chartHeight - (outPoint[1] * yScale);
        
        // 绘制高亮点
        ctx.beginPath();
        ctx.arc(inX, inY, 5, 0, Math.PI * 2);
        ctx.fillStyle = '#00c0ef';
        ctx.fill();
        ctx.strokeStyle = '#fff';
            ctx.lineWidth = 1;
        ctx.stroke();
        
        ctx.beginPath();
        ctx.arc(outX, outY, 5, 0, Math.PI * 2);
        ctx.fillStyle = '#8957ff';
        ctx.fill();
        ctx.strokeStyle = '#fff';
        ctx.lineWidth = 1;
        ctx.stroke();
        
        // 绘制垂直参考线
        ctx.beginPath();
        ctx.strokeStyle = 'rgba(0, 0, 0, 0.2)';
        ctx.setLineDash([5, 5]);
        ctx.moveTo(inX, margin.top);
        ctx.lineTo(inX, margin.top + chartHeight);
        ctx.stroke();
        ctx.setLineDash([]);
    }
    
    // 添加CSS样式
    $('<style>')
        .text(`
            /* 页面控制样式 */
            .page-controls-wrapper {
                margin-bottom: 15px;
                position: sticky;
                top: 0;
                z-index: 100;
                background: #f9f9f9;
                padding: 10px;
                border-bottom: 1px solid #ddd;
                box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            }
            .page-controls {
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            .control-buttons .btn {
                margin-right: 5px;
            }
            .scroll-controls .btn {
                margin-left: 5px;
            }
            
            /* 监控卡片容器样式 */
            .traffic-monitor-wrapper {
                width: 100%;
                margin-bottom: 20px;
                position: relative;
                overflow-y: auto;
                max-height: calc(100vh - 200px);
                padding-right: 5px;
            }
            #traffic-cards {
                display: flex;
                flex-wrap: wrap;
            }
            #traffic-cards .traffic-card {
                margin-bottom: 20px;
                box-sizing: border-box;
                width: 50%;
                padding: 0 10px;
            }
            .traffic-chart {
                position: relative;
            }
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
            .traffic-stats .label {
                margin-right: 5px;
                font-size: 90%;
            }
            canvas {
                display: block;
                width: 100%;
                height: 100%;
                cursor: crosshair;
            }
            .zoom-hint {
                position: absolute;
                bottom: 5px;
                right: 10px;
                font-size: 11px;
                color: #777;
                background: rgba(255, 255, 255, 0.8);
                padding: 2px 6px;
                border-radius: 3px;
                z-index: 5;
                pointer-events: none;
                opacity: 0.8;
            }
            .chart-tooltip {
                position: absolute;
                background: rgba(255, 255, 255, 0.9);
                border: 1px solid #ddd;
                border-radius: 4px;
                padding: 8px;
                font-size: 12px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.2);
                z-index: 100;
                pointer-events: none;
                max-width: 200px;
                line-height: 1.5;
            }
            .panel-heading {
                border-bottom: 1px solid #eee;
                background: linear-gradient(to bottom, #f9f9f9 0%, #f5f5f5 100%);
            }
            .panel-title {
                font-weight: 600;
            }
            .traffic-info {
                padding: 8px;
                background: #f9f9f9;
                border-top: 1px solid #eee;
                border-radius: 0 0 3px 3px;
            }
            .label-primary {
                background-color: #00c0ef;
            }
            .label-success {
                background-color: #8957ff;
            }
            .notification {
                opacity: 0.95;
                animation: fadeInOut 0.3s ease-in-out;
                position: fixed;
                top: 20px;
                right: 20px;
                z-index: 9999;
                min-width: 200px;
                max-width: 350px;
                box-shadow: 0 4px 8px rgba(0,0,0,0.2);
            }
            @keyframes fadeInOut {
                0% { opacity: 0; transform: translateY(-20px); }
                100% { opacity: 0.95; transform: translateY(0); }
            }
            
            /* 响应式布局优化 */
            @media (max-width: 992px) {
                #traffic-cards .traffic-card {
                    width: 100%;
                    padding: 0 5px;
                }
                .page-controls {
                    flex-direction: column;
                    align-items: stretch;
                }
                .control-buttons {
                    margin-bottom: 10px;
                    display: flex;
                    justify-content: space-between;
                }
                .scroll-controls {
                    display: flex;
                    justify-content: space-between;
                }
                .control-buttons .btn, 
                .scroll-controls .btn {
                    flex: 1;
                    margin: 0 2px;
                }
            }
            
            /* 滚动条美化 */
            .traffic-monitor-wrapper::-webkit-scrollbar {
                width: 8px;
            }
            .traffic-monitor-wrapper::-webkit-scrollbar-track {
                background: #f1f1f1;
                border-radius: 4px;
            }
            .traffic-monitor-wrapper::-webkit-scrollbar-thumb {
                background: #888;
                border-radius: 4px;
            }
            .traffic-monitor-wrapper::-webkit-scrollbar-thumb:hover {
                background: #555;
            }
        `)
        .appendTo('head');
        
    // 响应窗口大小变化
    $(window).resize(function() {
        trafficCards.forEach(function(card) {
            // 调整canvas大小
            const $chartContainer = $('#' + card.chartId);
            const canvas = document.getElementById(card.canvasId);
            if (canvas) {
                canvas.width = $chartContainer.width();
                canvas.height = $chartContainer.height();
                
                // 重新绘制图表
                drawTrafficChart(card);
            }
        });
    });
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