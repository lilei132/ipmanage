/**
 * 流量监控图表JavaScript
 */
$(document).ready(function() {
    // 检查是否在流量监控页面
    if (window.location.href.indexOf("traffic-monitor") > -1) {
        // 检查是否有流量图表容器
        if ($("#traffic-chart").length > 0) {
            // 检查是否有流量数据
            if (typeof window.in_data !== 'undefined' && typeof window.out_data !== 'undefined') {
                initFlotChart();
            }
        }
    }
    
    /**
     * 初始化Flot图表
     */
    function initFlotChart() {
        // 确保数据点格式正确
        var in_data = window.in_data || [];
        var out_data = window.out_data || [];
        
        // 缩放状态
        var zoomState = {
            min: null,
            max: null,
            lastMouseX: null,
            isDragging: false,
            selection: null,
            originalMin: null,
            originalMax: null
        };
        
        // 初始化原始数据范围
        if (in_data.length > 0) {
            zoomState.min = in_data[0][0];
            zoomState.max = in_data[in_data.length - 1][0];
            zoomState.originalMin = zoomState.min;
            zoomState.originalMax = zoomState.max;
        }
        
        // 计算流量最大值，确定Y轴范围
        var maxValue = 0;
        for (var i = 0; i < in_data.length; i++) {
            maxValue = Math.max(maxValue, in_data[i][1]);
        }
        for (var i = 0; i < out_data.length; i++) {
            maxValue = Math.max(maxValue, out_data[i][1]);
        }
        
        // 向上取整到最接近的单位，使Y轴更美观
        var yMax = getYAxisMax(maxValue);
        
        // 格式化数据系列
        var series = [
            {
                label: "入流量",
                data: in_data,
                color: "#00c0ef",
                lines: { fill: 0.2, lineWidth: 3 }
            },
            {
                label: "出流量",
                data: out_data,
                color: "#8957ff",
                lines: { fill: 0.2, lineWidth: 3 }
            }
        ];
        
        // Flot图表选项
        var options = {
            series: {
                lines: { 
                    show: true,
                    lineWidth: 3
                },
                points: { show: false },
                shadowSize: 0
            },
            grid: {
                hoverable: true,
                clickable: true,
                borderWidth: 1,
                borderColor: "#ddd",
                backgroundColor: { colors: ["#fff", "#f9f9f9"] }
            },
            xaxis: {
                mode: "time",
                timezone: "browser",
                timeformat: getTimeFormat(),
                minTickSize: getMinTickSize(),
                font: {
                    size: 13,
                    weight: "bold"
                }
            },
            yaxis: {
                min: 0,
                max: yMax,
                tickFormatter: function(val, axis) {
                    return formatBits(val);
                },
                font: {
                    size: 13,
                    weight: "bold"
                }
            },
            legend: {
                position: "nw",
                backgroundColor: "transparent",
                font: {
                    size: 13,
                    weight: "bold"
                }
            },
            tooltip: true,
            tooltipOpts: {
                content: "%s at %x: %y",
                defaultTheme: false,
                shifts: {
                    x: 10,
                    y: -25
                }
            },
            selection: {
                mode: "x",
                color: "rgba(66, 139, 202, 0.3)"
            }
        };
        
        // 创建Flot图表
        var plot = $.plot("#traffic-chart", series, options);
        
        // 添加缩放信息提示
        var zoomInfoText = $("<div class='zoom-info'><i class='fa fa-info-circle'></i> 提示：使用鼠标滚轮可以缩放图表，双击重置视图，拖动可选择区域</div>");
        $("#traffic-chart").after(zoomInfoText);
        
        // 创建提示DIV
        $("<div id='tooltip' class='chart-tooltip'></div>").appendTo("body");
        
        // 添加交互提示
        $("#traffic-chart").bind("plothover", function (event, pos, item) {
            if (item) {
                var x = new Date(item.datapoint[0]);
                var y = item.datapoint[1];
                
                var formattedTime = formatDateForTooltip(x);
                var formattedValue = formatBits(y);
                
                $("#tooltip").html("<strong>" + item.series.label + "</strong><br>" + 
                                   formattedTime + "<br>" + 
                                   formattedValue)
                    .css({top: item.pageY-25, left: item.pageX+10})
                    .fadeIn(200);
            } else {
                $("#tooltip").hide();
            }
        });
        
        // 窗口大小改变时重绘图表
        $(window).resize(function() {
            plot.resize();
            plot.setupGrid();
            plot.draw();
        });
    }
    
    /**
     * 获取时间格式，基于时间范围
     */
    function getTimeFormat() {
        if (window.location.href.indexOf("timespan=1h") > -1) {
            return "%H:%M";
        } else if (window.location.href.indexOf("timespan=1d") > -1) {
            return "%H:%M";
        } else if (window.location.href.indexOf("timespan=7d") > -1) {
            return "%m-%d";
        } else if (window.location.href.indexOf("timespan=30d") > -1) {
            return "%m-%d";
        } else {
            return "%H:%M";
        }
    }
    
    /**
     * 获取最小刻度大小，基于时间范围
     */
    function getMinTickSize() {
        if (window.location.href.indexOf("timespan=1h") > -1) {
            return [5, "minute"];
        } else if (window.location.href.indexOf("timespan=1d") > -1) {
            return [1, "hour"];
        } else if (window.location.href.indexOf("timespan=7d") > -1) {
            return [1, "day"];
        } else if (window.location.href.indexOf("timespan=30d") > -1) {
            return [1, "day"];
        } else {
            return [1, "hour"];
        }
    }
    
    /**
     * 格式化日期，用于提示框
     */
    function formatDateForTooltip(date) {
        var year = date.getFullYear();
        var month = (date.getMonth() + 1).toString().padStart(2, '0');
        var day = date.getDate().toString().padStart(2, '0');
        var hours = date.getHours().toString().padStart(2, '0');
        var minutes = date.getMinutes().toString().padStart(2, '0');
        
        if (window.location.href.indexOf("timespan=1h") > -1) {
            return hours + ":" + minutes;
        } else if (window.location.href.indexOf("timespan=1d") > -1) {
            return month + "-" + day + " " + hours + ":" + minutes;
        } else {
            return year + "-" + month + "-" + day;
        }
    }
    
    /**
     * 获取Y轴最大值
     */
    function getYAxisMax(maxValue) {
        if (maxValue <= 0) {
            return 1000; // 1 Kbps默认值
        }
        
        // 计算合适的刻度单位
        var units = ["", "K", "M", "G", "T"];
        var unit = 0;
        var scaledMax = maxValue;
        
        while (scaledMax >= 1000 && unit < units.length - 1) {
            scaledMax /= 1000;
            unit++;
        }
        
        // 将值向上舍入到合适的整数
        if (scaledMax <= 10) {
            scaledMax = Math.ceil(scaledMax * 10) / 10 * 10;
        } else if (scaledMax <= 100) {
            scaledMax = Math.ceil(scaledMax / 10) * 10;
        } else {
            scaledMax = Math.ceil(scaledMax / 100) * 100;
        }
        
        // 转换回原始单位
        return scaledMax * Math.pow(1000, unit);
    }
    
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
}); 