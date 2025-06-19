<?php
/**
 * 获取设备接口的Ajax接口
 */

# 引入函数文件
require_once dirname(__FILE__) . "/../../../functions/functions.php";

# 初始化数据库连接
$Database = new Database_PDO;

# 设置响应为JSON和缓存优化
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: max-age=60, private'); // 缓存1分钟
header('X-Response-Time: ' . microtime(true));

# 获取设备ID
$deviceId = isset($_REQUEST['deviceId']) ? intval($_REQUEST['deviceId']) : null;

if (!$deviceId) {
    echo json_encode(['success' => false, 'message' => '缺少设备ID参数']);
    exit;
}

# 记录请求信息便于调试
error_log("接口数据请求: deviceId = $deviceId");

try {
    $Traffic = new Traffic($Database);
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
        
        # 记录成功信息
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
?> 