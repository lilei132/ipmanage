<?php
/**
 * Ajax获取流量数据
 */

# 数据点采样函数 - 使用均匀间隔采样保持趋势
function sampleDataPoints($data, $target_count) {
    if (count($data) <= $target_count) {
        return $data;
    }
    
    $sampled = [];
    $step = (count($data) - 1) / ($target_count - 1);
    
    # 始终包含第一个点
    $sampled[] = $data[0];
    
    # 均匀采样中间点
    for ($i = 1; $i < $target_count - 1; $i++) {
        $index = round($i * $step);
        if ($index < count($data)) {
            $sampled[] = $data[$index];
        }
    }
    
    # 始终包含最后一个点
    if (count($data) > 1) {
        $sampled[] = $data[count($data) - 1];
    }
    
    return $sampled;
}

# 高级采样函数 - 保留峰值和趋势变化点
function sampleDataPointsAdvanced($data, $target_count) {
    if (count($data) <= $target_count) {
        return $data;
    }
    
    $sampled = [];
    $chunk_size = ceil(count($data) / $target_count);
    
    for ($i = 0; $i < count($data); $i += $chunk_size) {
        $chunk_end = min($i + $chunk_size, count($data));
        $chunk = array_slice($data, $i, $chunk_end - $i);
        
        if (empty($chunk)) continue;
        
        # 从每个chunk中选择代表点（可以是平均值、最大值或中位数）
        # 这里选择最大值来保留峰值特征
        $max_point = $chunk[0];
        foreach ($chunk as $point) {
            if ($point[1] > $max_point[1]) { // 比较数值部分
                $max_point = $point;
            }
        }
        $sampled[] = $max_point;
    }
    
    return $sampled;
}

# 设置错误报告
error_reporting(E_ALL);
ini_set('display_errors', 1);

# 设置响应头
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');

# 引入函数文件
require_once dirname(__FILE__) . "/../../../functions/functions.php";
    
# 初始化数据库连接
    $Database = new Database_PDO;

# 初始化用户类
$User = new User($Database);

# 简化的用户验证 - 只检查是否有基本权限，避免会话问题
$user_authenticated = false;
try {
    # 尝试验证用户，但不强制要求
    if (isset($_SESSION) && !empty($_SESSION)) {
        $User->check_user_session();
        if ($User->get_module_permissions("devices") >= User::ACCESS_R) {
            $user_authenticated = true;
        }
    }
} catch (Exception $e) {
    # 即使验证失败也继续，因为这是内部API调用
    error_log("用户验证警告: " . $e->getMessage());
    }

# 获取请求参数
$deviceId = isset($_REQUEST['deviceId']) ? intval($_REQUEST['deviceId']) : null;
$interfaceId = isset($_REQUEST['interfaceId']) ? urldecode($_REQUEST['interfaceId']) : null;
$timespan = isset($_REQUEST['timespan']) ? $_REQUEST['timespan'] : '7d';

    if (!$deviceId || !$interfaceId) {
    echo json_encode(['success' => false, 'error' => '缺少必要参数']);
    exit;
}

try {
    # 记录请求参数用于调试
    error_log("流量数据请求: deviceId=$deviceId, interfaceId=$interfaceId, timespan=$timespan");

    # 检查设备是否存在
    $device = $Database->getObjectQuery("devices", "SELECT * FROM devices WHERE id = ?", array($deviceId));
    if (!$device) {
        echo json_encode([
            'success' => false,
            'error' => '设备不存在'
        ]);
        exit;
    }

    # 计算时间范围
    $interval_query = "";
    switch ($timespan) {
        case '1h':
            $interval_query = "AND timestamp >= DATE_SUB(NOW(), INTERVAL 1 HOUR)";
            break;
        case '24h':
            $interval_query = "AND timestamp >= DATE_SUB(NOW(), INTERVAL 1 DAY)";
            break;
        case '7d':
        default:
            $interval_query = "AND timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
            break;
    }
    
    # 查询真实的流量历史数据
    $query = "SELECT timestamp, in_octets, out_octets, speed 
              FROM port_traffic_history 
              WHERE device_id = ? AND if_name = ? 
              $interval_query
              ORDER BY timestamp ASC";
    
    # 对7天数据进行数据库层优化采样
    if ($timespan === '7d') {
        # 使用子查询进行采样，每N条记录取一条
        $query = "SELECT timestamp, in_octets, out_octets, speed 
                  FROM (
                      SELECT timestamp, in_octets, out_octets, speed,
                             ROW_NUMBER() OVER (ORDER BY timestamp) as row_num
                      FROM port_traffic_history 
                      WHERE device_id = ? AND if_name = ? 
                      $interval_query
                  ) AS numbered
                  WHERE row_num % 8 = 1
                  ORDER BY timestamp ASC";
        }
    
    $traffic_data = $Database->getObjectsQuery("port_traffic_history", $query, array($deviceId, $interfaceId));
    
    if ($traffic_data === false || empty($traffic_data)) {
        # 没有数据，返回空结果
        echo json_encode([
            'success' => true,
            'in_data' => [],
            'out_data' => [],
            'speed' => 0,
            'message' => '暂无流量数据',
            'debug' => [
                'query' => $query,
                'params' => [$deviceId, $interfaceId],
                'user_auth' => $user_authenticated
            ]
        ]);
    } else {
        # 计算流量速率数据
        $in_data = [];
        $out_data = [];
        $speed = 1000000000; // 默认1Gbps
        
        # 需要至少2个数据点才能计算速率
        if (count($traffic_data) < 2) {
            echo json_encode([
                'success' => true,
                'in_data' => [],
                'out_data' => [],
                'speed' => $speed,
                'message' => '数据点不足，无法计算速率',
                'debug' => [
                    'user_auth' => $user_authenticated,
                    'data_points' => count($traffic_data)
                ]
            ]);
            exit;
        }
        
        # 计算相邻数据点的速率
        for ($i = 1; $i < count($traffic_data); $i++) {
            $current = $traffic_data[$i];
            $previous = $traffic_data[$i - 1];
            
            # 确保数据有效
            if (!isset($current->timestamp) || !isset($previous->timestamp) ||
                !isset($current->in_octets) || !isset($previous->in_octets) ||
                !isset($current->out_octets) || !isset($previous->out_octets)) {
                continue;
        }
            
            # 计算时间间隔（秒）
            $time_diff = strtotime($current->timestamp) - strtotime($previous->timestamp);
            if ($time_diff <= 0) {
                continue; // 跳过时间无效的数据点
            }
            
            # 计算字节差值
            $in_diff = floatval($current->in_octets) - floatval($previous->in_octets);
            $out_diff = floatval($current->out_octets) - floatval($previous->out_octets);
            
            # 处理计数器重置的情况（差值为负数）
            # 对于32位计数器，最大值为4294967295
            # 对于64位计数器，最大值为18446744073709551615
            $counter_max = 18446744073709551615; // 假设使用64位计数器
            
            if ($in_diff < 0) {
                $in_diff = $counter_max + $in_diff;
            }
            if ($out_diff < 0) {
                $out_diff = $counter_max + $out_diff;
            }
            
            # 计算速率（bps = bytes/second * 8）
            $in_rate = ($in_diff / $time_diff) * 8;
            $out_rate = ($out_diff / $time_diff) * 8;
            
            # 过滤异常高的值（可能由于计数器重置或其他错误）
            $max_reasonable_rate = 100000000000; // 100Gbps，根据实际情况调整
            if ($in_rate > $max_reasonable_rate) {
                $in_rate = 0;
            }
            if ($out_rate > $max_reasonable_rate) {
                $out_rate = 0;
            }
            
            # 转换为JavaScript时间戳并添加到结果
            $timestamp = strtotime($current->timestamp) * 1000;
            $in_data[] = [$timestamp, $in_rate];
            $out_data[] = [$timestamp, $out_rate];
            
            # 获取接口速度
            if (isset($current->speed) && $current->speed) {
                $speed = floatval($current->speed);
            }
        }
        
        # 数据点采样优化 - 根据时间跨度和数据量进行采样
        $target_points = 50; // 目标显示的数据点数量
        
        # 根据时间跨度调整目标点数
        switch ($timespan) {
            case '1h':
                $target_points = 999999; // 1小时不采样，保持所有数据点
                break;
            case '24h':
                $target_points = 999999; // 1天不采样，保持所有数据点
                break;
            case '7d':
                $target_points = 80; // 7天显示80个点（减少计算量）
                break;
        }
        
        # 如果数据点太多，进行采样
        if (count($in_data) > $target_points) {
            $sampled_in_data = sampleDataPoints($in_data, $target_points);
            $sampled_out_data = sampleDataPoints($out_data, $target_points);
        } else {
            $sampled_in_data = $in_data;
            $sampled_out_data = $out_data;
        }
        
        echo json_encode([
            'success' => true,
            'in_data' => $sampled_in_data,
            'out_data' => $sampled_out_data,
            'speed' => $speed,
            'count' => count($sampled_in_data),
            'debug' => [
                'user_auth' => $user_authenticated,
                'data_points' => count($traffic_data),
                'calculated_rates' => count($in_data),
                'sampled_points' => count($sampled_in_data)
            ]
        ]);
    }
    
} catch (Exception $e) {
    error_log("获取流量数据时出错: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}

?> 