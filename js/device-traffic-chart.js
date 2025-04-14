/**
 * 流量历史图表原生实现
 * 使用纯Canvas API绘制，避免库冲突
 */
$(document).ready(function() {
    console.log("原生Canvas流量图表脚本已加载");
    
    // 在流量历史页面执行
    if (window.location.href.indexOf("sPage=traffic") > -1) {
        console.log("检测到流量历史页面");
        
        // 延迟执行，确保页面元素加载完毕
        setTimeout(function() {
            initCanvasTrafficChart();
        }, 800);
    }
    
    // 初始化Canvas流量图表
    function initCanvasTrafficChart() {
        var $container = $("#traffic-chart");
        
        if ($container.length === 0) {
            console.log("错误：未找到图表容器");
            return;
        }
        
        console.log("初始化Canvas流量图表");
        
        // 清空容器并设置样式
        $container.empty();
        $container.css({
            'position': 'relative',
            'height': '370px',
            'width': '100%',
            'margin-top': '20px'
        });
        
        // 从页面获取全局变量的图表数据
        var in_data = window.in_data;
        var out_data = window.out_data;
        
        // 如果数据不存在，尝试从页面获取
        if (typeof in_data === 'undefined' || typeof out_data === 'undefined') {
            try {
                var scripts = document.getElementsByTagName('script');
                for (var i = 0; i < scripts.length; i++) {
                    var content = scripts[i].innerHTML;
                    if (content.indexOf('window.in_data =') > -1) {
                        console.log("在脚本中找到数据");
                        eval(content);
                        break;
                    }
                }
            } catch (e) {
                console.error("获取数据失败", e);
            }
        }
        
        // 检查数据是否有效
        if (!in_data || !in_data.length || !out_data || !out_data.length) {
            $container.html('<div class="alert alert-warning">没有可显示的流量数据</div>');
            return;
        }
        
        console.log("准备绘制图表，数据点数：", in_data.length);
        
        // 创建Canvas元素
        var canvas = document.createElement('canvas');
        var ctx = canvas.getContext('2d');
        
        // 设置Canvas大小
        canvas.width = $container.width() * 1.5; // 高分辨率
        canvas.height = $container.height() * 1.5;
        canvas.style.width = '100%';
        canvas.style.height = '370px';
        
        // 添加Canvas到容器
        $container.append(canvas);
        
        // 获取设备和接口信息
        var deviceName = $("h4").first().text().trim();
        var interfaceName = $("#if_index option:selected").text();
        var timeSpan = $("#timespan option:selected").text();
        
        // 构建图表标题
        var chartTitle = deviceName + ":端口" + interfaceName + ":网络流量(" + (timeSpan || "7天") + ")";
        $container.before('<h4 style="margin-top:20px;">' + chartTitle + '</h4>');
        
        // 准备数据
        prepareAndDrawChart(ctx, canvas.width, canvas.height, in_data, out_data, interfaceName);
        
        // 处理窗口大小变化
        $(window).resize(function() {
            if (canvas.width !== $container.width() * 1.5) {
                canvas.width = $container.width() * 1.5;
                canvas.height = $container.height() * 1.5;
                prepareAndDrawChart(ctx, canvas.width, canvas.height, in_data, out_data, interfaceName);
            }
        });
    }
    
    // 准备数据并绘制图表
    function prepareAndDrawChart(ctx, width, height, in_data, out_data, interfaceName) {
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
    }
    
    // 绘制数据线和填充区域
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
    
    // 绘制图例
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
    
    // 格式化流量数值
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
}); 