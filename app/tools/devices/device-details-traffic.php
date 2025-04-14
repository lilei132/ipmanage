<?php

/**
 * 设备流量监测页面
 * 完全独立的页面，避免与其他页面的冲突
 */

# verify that user is logged in
$User->check_user_session();
# perm check
$User->check_module_permissions ("devices", User::ACCESS_R, true, false);

# check
is_numeric($GET->subnetId) ? : $Result->show("danger", _("Invalid ID"), true);

# fetch device
$device = (array) $Tools->fetch_object ("devices", "id", $GET->subnetId);

# 创建Traffic对象
try {
    $Traffic = new Traffic($Database);

    # 获取设备所有接口
    $interfaces = $Traffic->get_device_interfaces($GET->subnetId);

    # 检查是否已经收集了数据
    if ($interfaces === false || empty($interfaces)) {
        $Result->show("info", _("No port traffic data collected yet for this device. Please enable traffic collection in Settings."), false);
        return;
    }

    # 获取时间范围参数
    $timespan = isset($_GET['timespan']) ? $_GET['timespan'] : '1d';
    if (!in_array($timespan, array('1h', '1d', '7d'))) {
        $timespan = '1d';
    }

    # 获取特定接口参数
    $if_index = isset($_GET['if_index']) ? $_GET['if_index'] : null;

    # 如果没有指定接口，使用第一个接口
    if ($if_index === null && !empty($interfaces)) {
        $if_index = $interfaces[0]->if_index;
    }

    # 获取最新的流量数据
    $latest_traffic = $Traffic->get_device_latest_traffic($GET->subnetId);

    # 转换为索引数组以便快速查找
    $latest_traffic_indexed = array();
    if ($latest_traffic !== false) {
        foreach ($latest_traffic as $interface) {
            $latest_traffic_indexed[$interface->if_index] = $interface;
        }
    }
} catch (Exception $e) {
    $Result->show("danger", _("Error creating Traffic object: ") . $e->getMessage(), true);
    return;
}

?>
<!-- 自定义样式 -->
<style>
.traffic-panel {
    border: 1px solid #ddd;
    border-radius: 4px;
    box-shadow: 0 1px 3px rgba(0,0,0,.1);
    margin-bottom: 20px;
}
.traffic-panel-heading {
    padding: 10px 15px;
    background-color: #f5f5f5;
    border-bottom: 1px solid #ddd;
    border-top-left-radius: 3px;
    border-top-right-radius: 3px;
}
.traffic-panel-body {
    padding: 15px;
}
.traffic-header {
    margin-top: 0;
    margin-bottom: 20px;
    border-bottom: 1px solid #eee;
    padding-bottom: 10px;
}
.traffic-interface-info {
    background-color: #f9f9f9;
    border: 1px solid #eee;
    padding: 10px;
    margin-bottom: 15px;
    border-radius: 4px;
}
.traffic-canvas-container {
    position: relative;
    height: 370px;
    width: 100%;
    margin-top: 20px;
}
.chart-tooltip {
    position: absolute;
    display: none;
    padding: 10px;
    background: rgba(255, 255, 255, 0.9);
    border: 1px solid #ccc;
    border-radius: 5px;
    pointer-events: none;
    font-size: 14px;
    z-index: 1000;
    box-shadow: 0 0 5px rgba(0,0,0,0.2);
}
.zoom-info {
    text-align: center;
    color: #666;
    font-size: 13px;
    margin-top: 5px;
}
</style>

<!-- 页面标题 -->
<h4><i class="fa fa-area-chart"></i> <?php print _("Device Traffic Monitoring"); ?> - <?php print $device['hostname']; ?></h4>
<hr>

<!-- 选择表单 -->
<div class="row" style="margin-bottom: 20px;">
    <div class="col-xs-12 col-md-8">
        <form id="trafficForm" class="form-inline">
            <input type="hidden" name="page" value="tools">
            <input type="hidden" name="section" value="devices">
            <input type="hidden" name="subnetId" value="<?php print $device['id']; ?>">
            
            <div class="form-group">
                <label for="if_index"><?php print _("Interface"); ?>: </label>
                <select name="if_index" id="if_index" class="form-control input-sm">
                    <?php
                    foreach ($interfaces as $interface) {
                        $selected = ($interface->if_index == $if_index) ? 'selected' : '';
                        print "<option value='{$interface->if_index}' $selected>{$interface->if_name} - {$interface->if_description}</option>";
                    }
                    ?>
                </select>
            </div>
            
            <div class="form-group" style="margin-left: 20px;">
                <label for="timespan"><?php print _("Time Span"); ?>: </label>
                <select name="timespan" id="timespan" class="form-control input-sm">
                    <option value="1h" <?php if ($timespan == '1h') print 'selected'; ?>><?php print _("Last Hour"); ?></option>
                    <option value="1d" <?php if ($timespan == '1d') print 'selected'; ?>><?php print _("Last Day"); ?></option>
                    <option value="7d" <?php if ($timespan == '7d') print 'selected'; ?>><?php print _("Last Week"); ?></option>
                </select>
            </div>
            
            <button type="submit" class="btn btn-sm btn-default" style="margin-left: 10px;"><?php print _("Update"); ?></button>
        </form>
    </div>
    
    <div class="col-xs-12 col-md-4 text-right">
        <div class="btn-group">
            <a href="<?php print create_link("tools", "devices", $device['id']); ?>" class="btn btn-sm btn-default">
                <i class="fa fa-angle-left"></i> <?php print _("Back to Device Details"); ?>
            </a>
        </div>
    </div>
</div>

<?php
// 如果选择了接口
if ($if_index !== null) {
    // 获取选定接口的流量历史数据
    $traffic_history = $Traffic->get_interface_history($device['id'], $if_index, $timespan);
    
    // 获取接口的最新数据
    $current_interface = isset($latest_traffic_indexed[$if_index]) ? $latest_traffic_indexed[$if_index] : null;
    
    // 当前接口信息
    if ($current_interface !== null) {
        ?>
        <div class="traffic-panel">
            <div class="traffic-panel-heading">
                <strong><?php print _("Interface"); ?>: <?php print $current_interface->if_name; ?> 
                <?php if (!empty($current_interface->if_description)) print " - " . $current_interface->if_description; ?></strong>
            </div>
            <div class="traffic-panel-body">
                <div class="row">
                    <div class="col-md-3">
                        <strong><?php print _("Status"); ?>:</strong> 
                        <span class="label label-<?php print ($current_interface->oper_status == 'up' ? 'success' : 'default'); ?>">
                            <?php print $current_interface->oper_status; ?>
                        </span>
                    </div>
                    <div class="col-md-3">
                        <strong><?php print _("Speed"); ?>:</strong> 
                        <?php 
                        if ($current_interface->speed > 0) {
                            if ($current_interface->speed >= 1000000000) {
                                print round($current_interface->speed / 1000000000, 2) . ' Gbps';
                            } else if ($current_interface->speed >= 1000000) {
                                print round($current_interface->speed / 1000000, 2) . ' Mbps';
                            } else {
                                print round($current_interface->speed / 1000, 2) . ' Kbps';
                            }
                        } else {
                            print _("Unknown");
                        }
                        ?>
                    </div>
                    <div class="col-md-3">
                        <strong><?php print _("In Errors"); ?>:</strong> <?php print $current_interface->in_errors; ?>
                    </div>
                    <div class="col-md-3">
                        <strong><?php print _("Out Errors"); ?>:</strong> <?php print $current_interface->out_errors; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    // 如果有历史数据
    if ($traffic_history !== false && !empty($traffic_history)) {
        // 准备图表数据
        $in_data = array();
        $out_data = array();
        
        foreach ($traffic_history as $point) {
            // 时间戳（毫秒）
            $timestamp = strtotime($point->time_point) * 1000;
            
            // 计算速率（将字节转换为比特/秒）
            $in_rate = (float)$point->in_octets * 8;  // 转换为比特
            $out_rate = (float)$point->out_octets * 8; // 转换为比特
            
            // 添加数据点 [timestamp, value]
            $in_data[] = array($timestamp, $in_rate);
            $out_data[] = array($timestamp, $out_rate);
        }

        if (empty($in_data)) {
            echo "<div class='alert alert-warning'>"._("No data points found for the selected time range.")."</div>";
        } else {
            ?>
            <!-- 流量图表 -->
            <div class="traffic-panel">
                <div class="traffic-panel-heading">
                    <strong>
                        <?php print _("Traffic History"); ?> - 
                        <?php 
                        switch ($timespan) {
                            case '1h': print _("Last Hour"); break;
                            case '1d': print _("Last Day"); break;
                            case '7d': print _("Last Week"); break;
                        }
                        ?>
                    </strong>
                </div>
                <div class="traffic-panel-body">
                    <div id="traffic-canvas-container" class="traffic-canvas-container"></div>
                </div>
            </div>
            
            <script>
            $(document).ready(function() {
                console.log("准备流量图表数据");
                
                // 准备图表数据
                var in_data = <?php echo json_encode($in_data); ?>;
                var out_data = <?php echo json_encode($out_data); ?>;
                
                console.log("图表数据已准备:", in_data.length, out_data.length);
                
                // 创建并绘制图表
                createTrafficChart(in_data, out_data);
                
                // 添加缩放信息提示
                $("#traffic-canvas-container").after(
                    "<div class='zoom-info'><i class='fa fa-info-circle'></i> 提示：使用鼠标滚轮可以缩放图表，双击重置视图</div>"
                );
                
                // 等待图表加载完成
                setTimeout(function() {
                    // 增大图表字体
                    $(".flot-x-axis .flot-tick-label").css("font-size", "13px");
                    $(".flot-y-axis .flot-tick-label").css("font-size", "13px");
                    
                    // 获取图表对象
                    var plot = $("#traffic-canvas-container .flot-base").parents(".flot-container").data("plot");
                    if (!plot) return;
                    
                    // 缩放状态对象
                    var zoomState = {
                        min: null,
                        max: null,
                        originalMin: null,
                        originalMax: null
                    };
                    
                    // 初始化缩放状态
                    var xaxis = plot.getXAxes()[0];
                    zoomState.min = xaxis.min;
                    zoomState.max = xaxis.max;
                    zoomState.originalMin = xaxis.min;
                    zoomState.originalMax = xaxis.max;
                    
                    // 鼠标滚轮事件
                    $("#traffic-canvas-container").bind("wheel", function(event) {
                        event.preventDefault();
                        
                        var offset = $(this).offset();
                        var plotOffset = plot.getPlotOffset();
                        var mouseX = event.pageX - offset.left - plotOffset.left;
                        var xaxis = plot.getXAxes()[0];
                        
                        // 确保鼠标在图表区域内
                        if (mouseX < 0 || mouseX > plot.width()) return;
                        
                        // 获取鼠标位置对应的X轴值
                        var x = xaxis.c2p(mouseX);
                        
                        // 当前可见范围
                        var currentMin = xaxis.min;
                        var currentMax = xaxis.max;
                        var currentRange = currentMax - currentMin;
                        
                        // 缩放因子 (向上滚动缩小，向下滚动放大)
                        var factor = event.originalEvent.deltaY < 0 ? 0.8 : 1.25;
                        
                        // 新的范围
                        var newRange = currentRange * factor;
                        
                        // 鼠标在当前范围中的比例
                        var ratio = (x - currentMin) / currentRange;
                        
                        // 新的最小最大值
                        var newMin = x - ratio * newRange;
                        var newMax = newMin + newRange;
                        
                        // 限制范围不超过原始数据范围
                        if (newMin < zoomState.originalMin) {
                            newMin = zoomState.originalMin;
                            newMax = newMin + newRange;
                        }
                        
                        if (newMax > zoomState.originalMax) {
                            newMax = zoomState.originalMax;
                            newMin = newMax - newRange;
                        }
                        
                        // 确保最小范围
                        if (newMax - newMin < 60000) return; // 至少1分钟
                        
                        // 更新缩放状态
                        zoomState.min = newMin;
                        zoomState.max = newMax;
                        
                        // 更新图表选项
                        var options = plot.getOptions();
                        $.extend(true, options.xaxes[0], {
                            min: newMin,
                            max: newMax
                        });
                        
                        // 重绘图表
                        plot.setupGrid();
                        plot.draw();
                    });
                    
                    // 双击重置缩放
                    $("#traffic-canvas-container").bind("dblclick", function() {
                        zoomState.min = zoomState.originalMin;
                        zoomState.max = zoomState.originalMax;
                        
                        var options = plot.getOptions();
                        $.extend(true, options.xaxes[0], {
                            min: zoomState.min,
                            max: zoomState.max
                        });
                        
                        plot.setupGrid();
                        plot.draw();
                    });
                }, 1000); // 等待图表完全加载
            });
            
            function createTrafficChart(in_data, out_data) {
                var container = document.getElementById('traffic-canvas-container');
                
                // 清空容器
                container.innerHTML = '';
                
                // 创建Canvas元素
                var canvas = document.createElement('canvas');
                var ctx = canvas.getContext('2d');
                
                // 设置Canvas大小
                canvas.width = container.offsetWidth * 1.5; // 高分辨率
                canvas.height = container.offsetHeight * 1.5;
                canvas.style.width = '100%';
                canvas.style.height = '100%';
                
                // 添加Canvas到容器
                container.appendChild(canvas);
                
                // 获取设备和接口信息
                var deviceName = "<?php echo $device['hostname']; ?>";
                var interfaceName = $("#if_index option:selected").text();
                var timeSpan = $("#timespan option:selected").text();
                
                // 准备图表数据
                drawTrafficChart(ctx, canvas.width, canvas.height, in_data, out_data, interfaceName);
                
                // Create tooltip element
                var tooltip = document.createElement('div');
                tooltip.id = 'traffic-tooltip';
                tooltip.style.display = 'none';
                tooltip.style.position = 'absolute';
                tooltip.style.backgroundColor = 'rgba(0,0,0,0.8)';
                tooltip.style.color = '#fff';
                tooltip.style.padding = '8px';
                tooltip.style.borderRadius = '4px';
                tooltip.style.fontSize = '12px';
                tooltip.style.pointerEvents = 'none';
                tooltip.style.zIndex = '1000';
                tooltip.style.boxShadow = '0 2px 5px rgba(0,0,0,0.2)';
                container.appendChild(tooltip);
                
                // Add mouse event listeners
                canvas.addEventListener('mousemove', function(event) {
                    handleMouseMove(event, canvas, ctx, margin, in_data, out_data, xScale, yScale, chartHeight, tooltip);
                });
                
                canvas.addEventListener('mouseout', function() {
                    tooltip.style.display = 'none';
                    // Redraw chart to clear any highlights
                    drawTrafficChart(ctx, canvas.width, canvas.height, in_data, out_data, interfaceName);
                });
                
                // 处理窗口大小变化
                $(window).resize(function() {
                    // 更新大小
                    canvas.width = container.offsetWidth * 1.5;
                    canvas.height = container.offsetHeight * 1.5;
                    // 重绘图表
                    drawTrafficChart(ctx, canvas.width, canvas.height, in_data, out_data, interfaceName);
                });
            }
            
            function drawTrafficChart(ctx, width, height, in_data, out_data, interfaceName) {
                // 确保数据排序
                in_data.sort(function(a, b) { return a[0] - b[0]; });
                out_data.sort(function(a, b) { return a[0] - b[0]; });
                
                // 提取数据值
                var timestamps = [];
                var in_values = [];
                var out_values = [];
                var time_labels = [];
                
                for (var i = 0; i < in_data.length; i++) {
                    var date = new Date(in_data[i][0]);
                    timestamps.push(date);
                    in_values.push(in_data[i][1]);
                    out_values.push(out_data[i][1]);
                    
                    // 格式化时间标签
                    var month = date.getMonth() + 1;
                    var day = date.getDate();
                    var hours = date.getHours();
                    var timeLabel = (month < 10 ? '0' + month : month) + '-' + 
                                   (day < 10 ? '0' + day : day) + ' ' +
                                   (hours < 10 ? '0' + hours : hours);
                    time_labels.push(timeLabel);
                }
                
                // 寻找最大值，用于Y轴缩放
                var maxIn = Math.max.apply(null, in_values);
                var maxOut = Math.max.apply(null, out_values);
                var maxValue = Math.max(maxIn, maxOut) * 1.1; // 增加10%空间
                
                // 清空Canvas
                ctx.clearRect(0, 0, width, height);
                
                // 设置尺寸比例
                var dpr = window.devicePixelRatio || 1;
                
                // 设置边距
                var margin = {
                    top: 30 * dpr,
                    right: 40 * dpr,
                    bottom: 70 * dpr,
                    left: 70 * dpr
                };
                
                // 计算绘图区域
                var chartWidth = width - margin.left - margin.right;
                var chartHeight = height - margin.top - margin.bottom;
                
                // 背景
                ctx.fillStyle = '#ffffff';
                ctx.fillRect(margin.left, margin.top, chartWidth, chartHeight);
                
                // X轴和Y轴比例尺
                var xScale = chartWidth / (in_data.length - 1);
                var yScale = chartHeight / maxValue;
                
                // 绘制网格线
                ctx.beginPath();
                ctx.strokeStyle = '#dddddd';
                ctx.lineWidth = 1 * dpr;
                ctx.setLineDash([5, 5]);
                
                // Y轴网格线（水平）
                var yTickCount = 5;
                var yTickStep = maxValue / yTickCount;
                
                for (var i = 0; i <= yTickCount; i++) {
                    var y = margin.top + chartHeight - (i * yTickStep * yScale);
                    ctx.moveTo(margin.left, y);
                    ctx.lineTo(margin.left + chartWidth, y);
                    
                    // Y轴刻度文本
                    ctx.font = (12 * dpr) + 'px Arial';
                    ctx.fillStyle = '#666666';
                    ctx.textAlign = 'right';
                    ctx.textBaseline = 'middle';
                    
                    var yValue = i * yTickStep;
                    ctx.fillText(formatTraffic(yValue), margin.left - 10 * dpr, y);
                }
                
                // X轴网格线（垂直）和刻度
                var xLabelInterval = Math.ceil(in_data.length / 10); // 控制标签数量，避免过多
                
                for (var i = 0; i < in_data.length; i += xLabelInterval) {
                    var x = margin.left + i * xScale;
                    
                    // 垂直网格线
                    ctx.moveTo(x, margin.top);
                    ctx.lineTo(x, margin.top + chartHeight);
                    
                    // X轴刻度文本
                    ctx.save();
                    ctx.translate(x, margin.top + chartHeight + 10 * dpr);
                    ctx.rotate(-Math.PI / 6); // 倾斜标签
                    ctx.font = (11 * dpr) + 'px Arial';
                    ctx.fillStyle = '#666666';
                    ctx.textAlign = 'right';
                    ctx.fillText(time_labels[i], 0, 0);
                    ctx.restore();
                }
                
                ctx.stroke();
                ctx.setLineDash([]);
                
                // 绘制X轴和Y轴
                ctx.beginPath();
                ctx.strokeStyle = '#000000';
                ctx.lineWidth = 1 * dpr;
                
                // X轴
                ctx.moveTo(margin.left, margin.top + chartHeight);
                ctx.lineTo(margin.left + chartWidth, margin.top + chartHeight);
                
                // Y轴
                ctx.moveTo(margin.left, margin.top);
                ctx.lineTo(margin.left, margin.top + chartHeight);
                
                ctx.stroke();
                
                // Y轴标题
                ctx.save();
                ctx.translate(margin.left - 55 * dpr, margin.top + chartHeight / 2);
                ctx.rotate(-Math.PI / 2);
                ctx.font = 'bold ' + (14 * dpr) + 'px Arial';
                ctx.fillStyle = '#333333';
                ctx.textAlign = 'center';
                ctx.fillText('流量速率', 0, 0);
                ctx.restore();
                
                // 绘制数据线和区域 - 入站流量
                drawDataLine(ctx, margin, in_data, in_values, xScale, yScale, chartHeight, '#00D8D8', '端口' + interfaceName + ':入流量[平均]');
                
                // 绘制数据线和区域 - 出站流量
                drawDataLine(ctx, margin, out_data, out_values, xScale, yScale, chartHeight, '#B192DC', '端口' + interfaceName + ':出流量[平均]');
                
                // 添加图例
                drawLegend(ctx, margin, chartWidth, chartHeight, interfaceName);
                
                // Make these variables global for hover access
                window.margin = margin;
                window.xScale = xScale;
                window.yScale = yScale;
                window.chartHeight = chartHeight;
                window.timestamps = timestamps;
                window.in_values = in_values;
                window.out_values = out_values;
                window.interfaceName = interfaceName;
            }
            
            function drawDataLine(ctx, margin, data, values, xScale, yScale, chartHeight, color, label) {
                var dpr = window.devicePixelRatio || 1;
                
                // 绘制填充区域
                ctx.beginPath();
                ctx.fillStyle = color;
                ctx.globalAlpha = 0.1;
                
                // 起始点
                ctx.moveTo(margin.left, margin.top + chartHeight);
                
                // 数据点
                for (var i = 0; i < values.length; i++) {
                    var x = margin.left + i * xScale;
                    var y = margin.top + chartHeight - values[i] * yScale;
                    ctx.lineTo(x, y);
                }
                
                // 闭合路径
                ctx.lineTo(margin.left + (values.length - 1) * xScale, margin.top + chartHeight);
                ctx.closePath();
                ctx.fill();
                
                // 重置透明度
                ctx.globalAlpha = 1.0;
                
                // 绘制线条
                ctx.beginPath();
                ctx.strokeStyle = color;
                ctx.lineWidth = 2 * dpr;
                
                for (var i = 0; i < values.length; i++) {
                    var x = margin.left + i * xScale;
                    var y = margin.top + chartHeight - values[i] * yScale;
                    
                    if (i === 0) {
                        ctx.moveTo(x, y);
                    } else {
                        // 使用二次贝塞尔曲线使线条更平滑
                        var prevX = margin.left + (i - 1) * xScale;
                        var prevY = margin.top + chartHeight - values[i - 1] * yScale;
                        
                        var cpX = (x + prevX) / 2;
                        ctx.quadraticCurveTo(cpX, prevY, x, y);
                    }
                }
                
                ctx.stroke();
            }
            
            function drawLegend(ctx, margin, chartWidth, chartHeight, interfaceName) {
                var dpr = window.devicePixelRatio || 1;
                var legends = [
                    { color: '#00D8D8', label: '端口' + interfaceName + ':入流量[平均]' },
                    { color: '#B192DC', label: '端口' + interfaceName + ':出流量[平均]' }
                ];
                
                var legendWidth = 200 * dpr;
                var legendHeight = 50 * dpr;
                var legendX = margin.left + chartWidth / 2 - legendWidth / 2;
                var legendY = margin.top + chartHeight + 45 * dpr;
                
                // 图例背景
                ctx.fillStyle = 'rgba(255, 255, 255, 0.8)';
                ctx.fillRect(legendX, legendY, legendWidth, legendHeight);
                
                ctx.font = (12 * dpr) + 'px Arial';
                
                for (var i = 0; i < legends.length; i++) {
                    var y = legendY + 20 * dpr + i * 20 * dpr;
                    
                    // 图例颜色块
                    ctx.fillStyle = legends[i].color;
                    ctx.fillRect(legendX + 10 * dpr, y - 8 * dpr, 15 * dpr, 3 * dpr);
                    
                    // 图例文本
                    ctx.fillStyle = '#333333';
                    ctx.textAlign = 'left';
                    ctx.fillText(legends[i].label, legendX + 30 * dpr, y);
                }
            }
            
            function formatTraffic(value) {
                if (value > 1000000000) {
                    return (value/1000000000).toFixed(2) + " Gbps";
                } else if (value > 1000000) {
                    return (value/1000000).toFixed(2) + " Mbps";
                } else if (value > 1000) {
                    return (value/1000).toFixed(2) + " Kbps";
                } else {
                    return value.toFixed(0) + " bps";
                }
            }
            
            function handleMouseMove(event, canvas, ctx, margin, in_data, out_data, xScale, yScale, chartHeight, tooltip) {
                // Get mouse position relative to canvas
                var rect = canvas.getBoundingClientRect();
                var x = (event.clientX - rect.left) * (canvas.width / rect.width);
                var y = (event.clientY - rect.top) * (canvas.height / rect.height);
                
                // Check if mouse is in chart area
                if (x >= margin.left && x <= canvas.width - margin.right &&
                    y >= margin.top && y <= margin.top + chartHeight) {
                    
                    // Find closest data point index
                    var dataIndex = Math.round((x - margin.left) / xScale);
                    
                    // Ensure index is within bounds
                    if (dataIndex >= 0 && dataIndex < in_data.length) {
                        // Get values for the point
                        var timestamp = new Date(in_data[dataIndex][0]);
                        var inValue = in_data[dataIndex][1];
                        var outValue = out_data[dataIndex][1];
                        
                        // Format the time
                        var formattedTime = timestamp.toLocaleString();
                        
                        // Create tooltip content
                        tooltip.innerHTML = 
                            '<strong>Time:</strong> ' + formattedTime + '<br>' +
                            '<span style="color:#00D8D8"><strong>In:</strong> ' + formatTraffic(inValue) + '</span><br>' +
                            '<span style="color:#B192DC"><strong>Out:</strong> ' + formatTraffic(outValue) + '</span>';
                        
                        // Position the tooltip
                        tooltip.style.left = (event.clientX + 10) + 'px';
                        tooltip.style.top = (event.clientY + 10) + 'px';
                        tooltip.style.display = 'block';
                        
                        // Highlight the data point
                        highlightDataPoint(ctx, canvas, margin, dataIndex, in_data, out_data, xScale, yScale, chartHeight);
                    }
                } else {
                    // Hide tooltip if mouse is outside chart area
                    tooltip.style.display = 'none';
                }
            }
            
            function highlightDataPoint(ctx, canvas, margin, dataIndex, in_data, out_data, xScale, yScale, chartHeight) {
                // Calculate coordinates
                var x = margin.left + dataIndex * xScale;
                var inY = margin.top + chartHeight - in_data[dataIndex][1] * yScale;
                var outY = margin.top + chartHeight - out_data[dataIndex][1] * yScale;
                
                // Redraw the chart to clear previous highlights
                drawTrafficChart(ctx, canvas.width, canvas.height, in_data, out_data, window.interfaceName || 'Interface');
                
                // Draw highlight circles
                var dpr = window.devicePixelRatio || 1;
                
                // Highlight inbound point
                ctx.beginPath();
                ctx.arc(x, inY, 6 * dpr, 0, 2 * Math.PI);
                ctx.fillStyle = '#00D8D8';
                ctx.fill();
                ctx.strokeStyle = '#ffffff';
                ctx.lineWidth = 2 * dpr;
                ctx.stroke();
                
                // Highlight outbound point
                ctx.beginPath();
                ctx.arc(x, outY, 6 * dpr, 0, 2 * Math.PI);
                ctx.fillStyle = '#B192DC';
                ctx.fill();
                ctx.strokeStyle = '#ffffff';
                ctx.lineWidth = 2 * dpr;
                ctx.stroke();
            }
            </script>
            <?php
        }
    } else {
        $Result->show("info", _("No traffic history data available for the selected interface and time span."), false);
    }
} else {
    $Result->show("info", _("Please select an interface to view traffic history."), false);
}
?> 