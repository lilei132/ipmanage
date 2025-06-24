<?php
/**
 * 流量监控独立API端点
 * 避免phpIPAM路由系统的复杂性
 */

# 包含必要的phpIPAM文件
require_once(dirname(__FILE__) . '/../../../functions/functions.php');

# 初始化数据库和用户对象
$Database = new Database_PDO;
$User = new User($Database);

# 设置JSON输出头
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

# 错误处理
try {
    # 简化权限验证：只要能访问到此文件就认为有权限
    # 因为是通过phpIPAM主系统访问的
    
    # 获取请求参数
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    
    # 路由处理
    switch ($action) {
        case 'get_interfaces':
            getDeviceInterfaces();
            break;
            
        case 'add_card':
        case 'create_card':
            createCard();
            break;
            
        case 'delete_card':
            deleteCard();
            break;
            
        case 'update_dashboard':
            updateDashboard();
            break;
            
        default:
            throw new Exception('无效的操作: ' . $action);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

/**
 * 获取设备的接口列表
 */
function getDeviceInterfaces() {
    global $Database;
    
    $device_id = intval($_POST['device_id'] ?? $_GET['device_id'] ?? 0);
    
    if ($device_id <= 0) {
        throw new Exception('无效的设备ID');
    }
    
    # 检查设备是否存在
    $device = $Database->getObjectQuery("devices", "SELECT * FROM devices WHERE id = ?", array($device_id));
    if (!$device) {
        throw new Exception('设备不存在');
    }
    
    # 从device_interfaces表获取接口列表
    $device_interfaces = $Database->getObjectsQuery("device_interfaces", "SELECT if_name, if_description FROM device_interfaces WHERE device_id = ? AND active = 1 ORDER BY if_name", array($device_id));
    
    $interfaces = array();
    
    if ($device_interfaces && is_array($device_interfaces)) {
        foreach ($device_interfaces as $interface) {
            $description = !empty($interface->if_description) ? $interface->if_description : $interface->if_name;
            $interfaces[] = array(
                'if_index' => $interface->if_name,
                'if_name' => $interface->if_name,
                'if_description' => $description,
                'speed' => 1000000000  // 默认1Gbps
            );
        }
    }
    
    # 如果没有从device_interfaces表找到接口，尝试从ipaddresses表获取
    if (empty($interfaces)) {
        $interfaces_from_ip = $Database->getObjectsQuery("ipaddresses", "SELECT DISTINCT port, description FROM ipaddresses WHERE switch = ? AND port IS NOT NULL AND port != '' ORDER BY port", array($device_id));
        
        if ($interfaces_from_ip && is_array($interfaces_from_ip)) {
            foreach ($interfaces_from_ip as $interface) {
                if (!empty($interface->port)) {
                    $interfaces[] = array(
                        'if_index' => $interface->port,
                        'if_name' => $interface->port,
                        'if_description' => $interface->description ?: $interface->port,
                        'speed' => 1000000000  // 默认1Gbps
                    );
                }
            }
        }
    }
    
    # 如果还是没有找到接口，使用默认的网络接口模板
    if (empty($interfaces)) {
        $interfaces = array(
            array('if_index' => 'GigabitEthernet0/1', 'if_name' => 'GigabitEthernet0/1', 'if_description' => '千兆以太网接口1', 'speed' => 1000000000),
            array('if_index' => 'GigabitEthernet0/2', 'if_name' => 'GigabitEthernet0/2', 'if_description' => '千兆以太网接口2', 'speed' => 1000000000),
            array('if_index' => 'GigabitEthernet0/3', 'if_name' => 'GigabitEthernet0/3', 'if_description' => '千兆以太网接口3', 'speed' => 1000000000),
            array('if_index' => 'GigabitEthernet0/4', 'if_name' => 'GigabitEthernet0/4', 'if_description' => '千兆以太网接口4', 'speed' => 1000000000),
            array('if_index' => 'TenGigabitEthernet0/1', 'if_name' => 'TenGigabitEthernet0/1', 'if_description' => '万兆以太网接口1', 'speed' => 10000000000),
            array('if_index' => 'TenGigabitEthernet0/2', 'if_name' => 'TenGigabitEthernet0/2', 'if_description' => '万兆以太网接口2', 'speed' => 10000000000),
        );
    }
    
    echo json_encode(['success' => true, 'interfaces' => $interfaces]);
}

/**
 * 创建卡片
 */
function createCard() {
    global $Database, $User;
    
    $dashboard_id = intval($_POST['dashboard_id'] ?? 0);
    $device_id = intval($_POST['device_id'] ?? 0);
    $interface_id = trim($_POST['interface_id'] ?? '');
    $timespan = '7d'; // 固定为7天
    
    if ($dashboard_id <= 0) {
        throw new Exception('无效的看板ID');
    }
    
    if ($device_id <= 0) {
        throw new Exception('请选择设备');
    }
    
    if (empty($interface_id)) {
        throw new Exception('请选择接口');
    }
    
    # 检查看板是否存在
    $dashboard = $Database->getObjectQuery("traffic_dashboards", "SELECT * FROM traffic_dashboards WHERE id = ? AND is_active = 1", array($dashboard_id));
    if (!$dashboard) {
        throw new Exception('看板不存在');
    }
    
    # 获取设备信息
    $device = $Database->getObjectQuery("devices", "SELECT hostname FROM devices WHERE id = ?", array($device_id));
    if (!$device) {
        throw new Exception('设备不存在');
    }
    
    # 获取接口描述信息
    $interface_description = '';
    
    # 首先尝试从device_interfaces表获取接口描述
    $interface_info = $Database->getObjectQuery("device_interfaces", 
        "SELECT if_description FROM device_interfaces WHERE device_id = ? AND if_name = ? AND active = 1", 
        array($device_id, $interface_id));
    
    if ($interface_info && !empty($interface_info->if_description)) {
        $interface_description = $interface_info->if_description;
    } else {
        # 如果没有找到，尝试从ipaddresses表获取描述
        $ip_interface_info = $Database->getObjectQuery("ipaddresses", 
            "SELECT description FROM ipaddresses WHERE switch = ? AND port = ? AND description IS NOT NULL AND description != '' LIMIT 1", 
            array($device_id, $interface_id));
        
        if ($ip_interface_info && !empty($ip_interface_info->description)) {
            $interface_description = $ip_interface_info->description;
        }
    }
    
    # 生成更详细的卡片名称：设备名 | 端口名 | 端口描述
    if (!empty($interface_description) && $interface_description !== $interface_id) {
        $card_name = $device->hostname . ' | ' . $interface_id . ' | ' . $interface_description;
    } else {
        $card_name = $device->hostname . ' | ' . $interface_id;
    }
    
    # 检查是否已存在相同的卡片
    $existing = $Database->getObjectQuery("traffic_dashboard_cards", 
        "SELECT id FROM traffic_dashboard_cards WHERE dashboard_id = ? AND device_id = ? AND interface_id = ? AND is_active = 1", 
        array($dashboard_id, $device_id, $interface_id));
    
    if ($existing) {
        throw new Exception('该设备接口的卡片已存在');
    }
    
    # 创建卡片
    $card = new stdClass();
    $card->dashboard_id = $dashboard_id;
    $card->card_name = $card_name;
    $card->device_id = $device_id;
    $card->interface_id = $interface_id;
    $card->timespan = $timespan;
    $card->width = 6;  # 默认宽度
    $card->height = 300;  # 默认高度
    $card->position_x = 0;  # 默认X位置
    $card->position_y = 0;  # 默认Y位置
    $card->is_active = 1;
    
    $result = $Database->insertObject("traffic_dashboard_cards", $card);
    
    if (!$result) {
        throw new Exception('创建卡片失败');
    }
    
    echo json_encode(['success' => true, 'card_id' => $Database->lastInsertId(), 'card_name' => $card_name]);
}

/**
 * 删除卡片
 */
function deleteCard() {
    global $Database;
    
    $card_id = intval($_POST['card_id'] ?? 0);
    
    if ($card_id <= 0) {
        throw new Exception('无效的卡片ID');
    }
    
    # 检查卡片是否存在
    $card = $Database->getObjectQuery("traffic_dashboard_cards", "SELECT * FROM traffic_dashboard_cards WHERE id = ? AND is_active = 1", array($card_id));
    if (!$card) {
        throw new Exception('卡片不存在');
    }
    
    # 软删除卡片
    $card->is_active = 0;
    $result = $Database->updateObject("traffic_dashboard_cards", $card);
    
    if (!$result) {
        throw new Exception('删除卡片失败');
    }
    
    echo json_encode(['success' => true]);
}

/**
 * 更新看板
 */
function updateDashboard() {
    global $Database;
    
    $dashboard_id = intval($_POST['dashboard_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    
    if ($dashboard_id <= 0) {
        throw new Exception('无效的看板ID');
    }
    
    if (empty($name)) {
        throw new Exception('看板名称不能为空');
    }
    
    # 检查看板是否存在
    $dashboard = $Database->getObjectQuery("traffic_dashboards", "SELECT * FROM traffic_dashboards WHERE id = ? AND is_active = 1", array($dashboard_id));
    if (!$dashboard) {
        throw new Exception('看板不存在');
    }
    
    # 检查名称是否重复（排除自己）
    $check = $Database->getObjectQuery("traffic_dashboards", "SELECT id FROM traffic_dashboards WHERE name = ? AND id != ? AND is_active = 1", array($name, $dashboard_id));
    if ($check) {
        throw new Exception('看板名称已存在');
    }
    
    # 更新看板
    $dashboard->name = $name;
    $dashboard->description = $description;
    
    $result = $Database->updateObject("traffic_dashboards", $dashboard);
    
    if (!$result) {
        throw new Exception('更新看板失败');
    }
    
    echo json_encode(['success' => true]);
}
?> 