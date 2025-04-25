<?php
/**
 * 流量数据验证工具
 * 用于比较前端显示的流量数据与数据库中的原始数据，确认数据的真实性
 */

// 错误处理设置
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 设置响应头
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

// 导入配置文件
require( dirname(__FILE__) . '/../../../config.php' );
require_once( dirname(__FILE__) . '/../../../functions/functions.php' );

// 必须登录才能使用
$User = new User($Database);
if (!$User->check_user_session()) {
    echo json_encode([
        'success' => false,
        'message' => '请先登录'
    ]);
    exit;
}

// 权限检查
if ($User->get_module_permissions("devices") < User::ACCESS_R) {
    echo json_encode([
        'success' => false,
        'message' => '权限不足'
    ]);
    exit;
}

// 获取参数
$deviceId = isset($_GET['deviceId']) ? intval($_GET['deviceId']) : null;
$interfaceId = isset($_GET['interfaceId']) ? $_GET['interfaceId'] : null;
$timestamp = isset($_GET['timestamp']) ? $_GET['timestamp'] : null;
$frontendValue = isset($_GET['frontendValue']) ? $_GET['frontendValue'] : null;
$timespan = isset($_GET['timespan']) ? $_GET['timespan'] : '1d';

// 验证参数
if (!$deviceId || !$interfaceId || !$timestamp) {
    echo json_encode([
        'success' => false,
        'message' => '缺少必要参数：deviceId, interfaceId, timestamp'
    ]);
    exit;
}

try {
    // 数据库连接
    $conn = new mysqli($db['host'], $db['user'], $db['pass'], $db['name']);
    
    // 检查连接
    if ($conn->connect_error) {
        throw new Exception("数据库连接失败: " . $conn->connect_error);
    }
    
    // 从秒级时间戳转换为日期时间格式
    $timestamp = intval($timestamp / 1000); // 前端时间戳是毫秒，转换为秒
    $datetime = date('Y-m-d H:i:s', $timestamp);
    
    // 查询数据库，获取前后5分钟内的数据点
    $sql = "SELECT * FROM port_traffic_history 
            WHERE device_id = ? 
            AND if_index = ? 
            AND timestamp BETWEEN DATE_SUB(?, INTERVAL 5 MINUTE) AND DATE_ADD(?, INTERVAL 5 MINUTE)
            ORDER BY timestamp ASC";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("准备查询失败: " . $conn->error);
    }
    
    $stmt->bind_param("isss", $deviceId, $interfaceId, $datetime, $datetime);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    
    $conn->close();
    
    // 如果没有找到数据
    if (empty($data)) {
        echo json_encode([
            'success' => false,
            'message' => "没有找到匹配的数据库记录",
            'query_info' => [
                'device_id' => $deviceId,
                'if_index' => $interfaceId,
                'timestamp' => $timestamp,
                'datetime' => $datetime,
                'timespan' => $timespan
            ]
        ]);
        exit;
    }
    
    // 找到最接近请求时间戳的数据点
    $closestPoint = null;
    $minDiff = PHP_INT_MAX;
    
    foreach ($data as $point) {
        $pointTimestamp = strtotime($point['timestamp']);
        $diff = abs($pointTimestamp - $timestamp);
        
        if ($diff < $minDiff) {
            $minDiff = $diff;
            $closestPoint = $point;
        }
    }
    
    // 转换数据库值为比特/秒（与前端显示一致）
    $dbInValue = isset($closestPoint['in_octets']) ? floatval($closestPoint['in_octets']) * 8 : 0;
    $dbOutValue = isset($closestPoint['out_octets']) ? floatval($closestPoint['out_octets']) * 8 : 0;
    
    // 构建响应
    $response = [
        'success' => true,
        'message' => '数据验证完成',
        'data' => [
            'front_end' => [
                'timestamp' => $timestamp * 1000, // 转回毫秒
                'in_value' => $frontendValue ? floatval($frontendValue) : null,
                'formatted_time' => date('Y-m-d H:i:s', $timestamp)
            ],
            'database' => [
                'timestamp' => strtotime($closestPoint['timestamp']) * 1000, // 转为毫秒
                'in_value' => $dbInValue,
                'out_value' => $dbOutValue,
                'timestamp_diff_seconds' => $minDiff,
                'formatted_time' => $closestPoint['timestamp'],
                'raw_data' => $closestPoint
            ],
            'comparison' => [
                'time_diff_seconds' => $minDiff,
                'matching' => $frontendValue ? (abs($dbInValue - floatval($frontendValue)) / $dbInValue < 0.01) : '未提供前端值进行比较'
            ]
        ],
        'all_db_points' => $data
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => '验证数据时出错: ' . $e->getMessage(),
        'query_info' => [
            'device_id' => $deviceId,
            'if_index' => $interfaceId,
            'timestamp' => $timestamp
        ]
    ]);
} 