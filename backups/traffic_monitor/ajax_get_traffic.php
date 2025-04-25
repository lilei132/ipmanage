<?php
// 防止任何错误显示到输出中
ini_set('display_errors', 0);
error_reporting(0);

/**
 * AJAX获取流量数据
 */

// 设置响应类型为JSON
header('Content-Type: application/json');
// 添加缓存控制头，防止浏览器缓存
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

try {
    # 引入页面头部和检查用户权限
    include_once("../../../functions/functions.php");
    
    # 直接初始化数据库连接
    $Database = new Database_PDO;

    # 用户认证
    $User = new User ($Database);
    if (!$User->check_user_session()) {
        die(json_encode(array("success" => false, "error" => _("请先登录"))));
    }

    # 检查访问权限
    if ($User->get_module_permissions ("devices") < User::ACCESS_R) {
        die(json_encode(array("success" => false, "error" => _("权限不足"))));
    }

    # 获取参数
    $deviceId = isset($_GET['deviceId']) ? $_GET['deviceId'] : null;
    $interfaceId = isset($_GET['interfaceId']) ? $_GET['interfaceId'] : null;
    $timespan = isset($_GET['timespan']) ? $_GET['timespan'] : '7d';
    $noCache = isset($_GET['_']) ? $_GET['_'] : ''; // 获取防缓存参数
    $forceRefresh = isset($_GET['refresh']) ? true : false; // 强制刷新参数

    if (!$deviceId || !$interfaceId) {
        die(json_encode(array("success" => false, "error" => _("参数不完整"))));
    }

    # 实例化Traffic类
    $Traffic = new Traffic($Database);

    // 增加调试日志
    error_log("DEBUG: 尝试获取流量数据: deviceId=$deviceId, interfaceId=$interfaceId, timespan=$timespan, noCache=$noCache, forceRefresh=$forceRefresh");
    
    // 查询数据库中该接口的最新数据
    $latestDataQuery = "SELECT MAX(timestamp) as latest_time FROM port_traffic_history WHERE device_id = ? AND if_index = ?";
    $latestData = $Database->getObjectsQuery("port_traffic_history", $latestDataQuery, [$deviceId, $interfaceId]);
    
    if (!empty($latestData) && isset($latestData[0]->latest_time)) {
        error_log("DEBUG: 数据库中该接口最新数据时间: " . $latestData[0]->latest_time);
    } else {
        error_log("DEBUG: 未找到该接口的数据");
    }

    # 从数据库获取真实流量数据
    $traffic_data = $Traffic->get_interface_history($deviceId, $interfaceId, $timespan);

    // 增加调试日志 - 详细记录每个数据点
    if (!empty($traffic_data)) {
        error_log("DEBUG: 获取到流量数据点详情示例:");
        
        // 记录时间范围
        $first_point = $traffic_data[0];
        $last_point = $traffic_data[count($traffic_data) - 1];
        error_log("DEBUG: 流量数据时间范围: " . $first_point->time_point . " 至 " . $last_point->time_point);
        
        for ($i = 0; $i < min(5, count($traffic_data)); $i++) {
            $point = $traffic_data[$i];
            error_log("DEBUG: 数据点[$i]: time=" . $point->time_point . 
                     ", in=" . $point->in_octets . 
                     ", out=" . $point->out_octets . 
                     ", speed=" . (isset($point->speed) ? $point->speed : 'N/A'));
        }
    }

    // 增加调试日志
    error_log("DEBUG: 获取到真实流量数据: " . count($traffic_data) . " 条记录");
    
    # 如果没有获取到数据，直接返回空结果
    if (empty($traffic_data)) {
        echo json_encode(array(
            "success" => true,
            "in_data" => [],
            "out_data" => [],
            "speed" => 0,
            "count" => 0,
            "raw" => true,
            "is_test_data" => false,
            "timestamp" => time(), // 添加服务器当前时间戳
            "cache_status" => "empty_data"
        ));
        exit;
    }

    # 创建关联数组以处理相同时间戳的数据
    $timestamp_map = [];
    $linkSpeed = 0;
    $first_timestamp = null;
    $last_timestamp = null;

    foreach ($traffic_data as $point) {
        $timestamp = strtotime($point->time_point) * 1000; // 转换为JavaScript时间戳(毫秒)
        
        // 记录第一个和最后一个时间戳
        if ($first_timestamp === null || $timestamp < $first_timestamp) {
            $first_timestamp = $timestamp;
        }
        if ($last_timestamp === null || $timestamp > $last_timestamp) {
            $last_timestamp = $timestamp;
        }
        
        // 更新链路速度（使用最大的速度值）
        if (isset($point->speed) && $point->speed > $linkSpeed) {
            $linkSpeed = (float)$point->speed;
        }
        
        // 检查该时间戳是否已存在
        if (!isset($timestamp_map[$timestamp])) {
            $timestamp_map[$timestamp] = [
                'in' => (float)$point->in_octets * 8,   // 转换为比特
                'out' => (float)$point->out_octets * 8  // 转换为比特
            ];
        }
        // 如果已存在，取平均值
        else {
            $timestamp_map[$timestamp]['in'] = (float)$point->in_octets * 8;
            $timestamp_map[$timestamp]['out'] = (float)$point->out_octets * 8;
        }
    }

    # 格式化数据为Canvas可用的格式
    $in_data = array();
    $out_data = array();
    
    foreach ($timestamp_map as $timestamp => $values) {
        // 负值处理
        $in_value = $values['in'] < 0 ? 0 : $values['in'];
        $out_value = $values['out'] < 0 ? 0 : $values['out'];
        
        $in_data[] = array((int)$timestamp, $in_value);
        $out_data[] = array((int)$timestamp, $out_value);
    }
    
    // 确保数据按时间排序
    usort($in_data, function($a, $b) {
        return $a[0] - $b[0];
    });
    
    usort($out_data, function($a, $b) {
        return $a[0] - $b[0];
    });
    
    // 增加调试信息
    error_log("DEBUG: 最终格式化后数据点: 入向=" . count($in_data) . ", 出向=" . count($out_data));
    error_log("DEBUG: 数据时间范围: " . date('Y-m-d H:i:s', $first_timestamp/1000) . " 至 " . date('Y-m-d H:i:s', $last_timestamp/1000));

    # 返回格式化的流量数据
    echo json_encode(array(
        "success" => true,
        "in_data" => $in_data,
        "out_data" => $out_data,
        "speed" => $linkSpeed,
        "count" => count($in_data),
        "raw" => true, // 标记数据为原始数据，前端不进行平滑处理
        "is_test_data" => false, // 明确标记非测试数据
        "data_range" => [
            "first_time" => $first_timestamp,
            "last_time" => $last_timestamp,
            "first_time_str" => date('Y-m-d H:i:s', $first_timestamp/1000),
            "last_time_str" => date('Y-m-d H:i:s', $last_timestamp/1000)
        ],
        "server_time" => time(),
        "generated_at" => date('Y-m-d H:i:s'),
        "cache_buster" => uniqid(), // 添加一个唯一值防止缓存
        "debug_info" => [
            "device_id" => $deviceId,
            "interface_id" => $interfaceId,
            "query_time" => microtime(true)
        ]
    ));
} catch (Exception $e) {
    // 记录错误到日志而不是显示
    error_log("Traffic data error: " . $e->getMessage() . "\nStack trace: " . $e->getTraceAsString());
    echo json_encode(array(
        "success" => false,
        "error" => "服务器内部错误: " . $e->getMessage(),
        "server_time" => time()
    ));
} 