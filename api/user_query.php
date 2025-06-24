<?php
/**
 * 用户信息查询API
 * 根据工号查询姓名和院系信息
 * 集成南信大数据中台API
 */

# include required scripts
require_once( dirname(__FILE__) . '/../functions/functions.php' );

# initialize required objects
$Database = new Database_PDO;
$Result = new Result;
$User = new User ($Database);

# verify that user is logged in
$User->check_user_session();

# 设置返回内容类型为JSON
header('Content-Type: application/json; charset=utf-8');

# 数据中台API配置
define('DATACENTER_BASE_URL', 'https://dcm.nuist.edu.cn');
define('DATACENTER_KEY', '20250612508519330741860209868806429');
define('DATACENTER_SECRET', '66e29991c99c72af3438f7be62fac9926ecca511');

/**
 * 获取数据中台访问Token
 */
function getDataCenterToken() {
    $url = DATACENTER_BASE_URL . '/open_api/authentication/get_access_token';
    $params = [
        'key' => DATACENTER_KEY,
        'secret' => DATACENTER_SECRET
    ];
    
    $query = http_build_query($params);
    $request_url = $url . '?' . $query;
    
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 10,
            'header' => [
                'User-Agent: IPAM-UserQuery/1.0',
                'Accept: application/json'
            ]
        ]
    ]);
    
    $response = file_get_contents($request_url, false, $context);
    
    if ($response === false) {
        return false;
    }
    
    $data = json_decode($response, true);
    
    if ($data && isset($data['code']) && $data['code'] == 10000) {
        return $data['result']['access_token'];
    }
    
    return false;
}

/**
 * 查询教职工信息
 */
function queryEmployeeInfo($employee_id, $token) {
    $url = DATACENTER_BASE_URL . '/open_api/customization/vjzg_alpha/full';
    
    $params = [
        'jgh' => $employee_id,
        'access_token' => $token,
        'page' => 1,
        'per_page' => 10
    ];
    
    # 尝试GET方式，因为文档说支持GET和POST两种方式
    $query = http_build_query($params);
    $request_url = $url . '?' . $query;
    
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 10,
            'header' => [
                'User-Agent: IPAM-UserQuery/1.0',
                'Accept: application/json'
            ]
        ]
    ]);
    
    $response = file_get_contents($request_url, false, $context);
    
    if ($response === false) {
        return false;
    }
    
    return json_decode($response, true);
}

# 获取输入参数
$action = isset($_GET['action']) ? $_GET['action'] : '';
$employee_id = isset($_GET['employee_id']) ? trim($_GET['employee_id']) : '';
$use_mock = isset($_GET['use_mock']) ? $_GET['use_mock'] === 'true' : false;

# 验证参数
if (empty($action)) {
    echo json_encode(['error' => '缺少action参数']);
    exit;
}

if ($action === 'query_user') {
    if (empty($employee_id)) {
        echo json_encode(['error' => '工号不能为空']);
        exit;
    }
    
    # 如果启用模拟模式，使用模拟数据
    if ($use_mock) {
        $mock_users = [
            '2023001' => [
                'name' => '张三',
                'department' => '计算机科学系',
                'college' => '信息科学技术学院'
            ],
            '2023002' => [
                'name' => '李四', 
                'department' => '软件工程系',
                'college' => '信息科学技术学院'
            ],
            '2023003' => [
                'name' => '王五',
                'department' => '气象学系', 
                'college' => '大气科学学院'
            ]
        ];
        
        if (isset($mock_users[$employee_id])) {
            $user_info = $mock_users[$employee_id];
            echo json_encode([
                'success' => true,
                'data' => [
                    'employee_id' => $employee_id,
                    'name' => $user_info['name'],
                    'department' => $user_info['department'],
                    'college' => $user_info['college'],
                    'full_department' => $user_info['college'] . ' - ' . $user_info['department']
                ],
                'source' => 'mock'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'error' => '工号不存在',
                'source' => 'mock'
            ]);
        }
        exit;
    }
    
    # 使用真实API
    try {
        # 1. 获取访问Token
        $token = getDataCenterToken();
        if (!$token) {
            echo json_encode([
                'success' => false,
                'error' => '数据中台服务不可用，请联系管理员',
                'debug_info' => 'Token获取失败',
                'fallback' => true
            ]);
            exit;
        }
        
        # 2. 查询教职工信息
        $api_response = queryEmployeeInfo($employee_id, $token);
        if (!$api_response) {
            echo json_encode([
                'success' => false,
                'error' => '数据中台服务异常，请稍后重试',
                'debug_info' => 'API调用失败',
                'fallback' => true
            ]);
            exit;
        }
        
        # 3. 解析API响应
        if (isset($api_response['code']) && $api_response['code'] == 10000) {
            $data = $api_response['result']['data'];
            
            if (empty($data)) {
                echo json_encode([
                    'success' => false,
                    'error' => '工号不存在'
                ]);
                exit;
            }
            
            # 取第一条记录
            $user_data = $data[0];
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'employee_id' => $user_data['jgh'],
                    'name' => $user_data['xm'],
                    'department' => isset($user_data['yjdwjc']) ? $user_data['yjdwjc'] : '',
                    'college' => isset($user_data['yjdwmc']) ? $user_data['yjdwmc'] : '',
                    'full_department' => (isset($user_data['yjdwmc']) ? $user_data['yjdwmc'] : '') . 
                                       (isset($user_data['yjdwjc']) && $user_data['yjdwjc'] !== $user_data['yjdwmc'] ? ' - ' . $user_data['yjdwjc'] : '')
                ],
                'source' => 'datacenter'
            ]);
        } else {
            # 检查是否是数据库错误，如果是则提供更友好的错误信息
            $error_msg = isset($api_response['message']) ? $api_response['message'] : '查询失败';
            $is_db_error = isset($api_response['description']) && 
                          (strpos($api_response['description'], 'column') !== false || 
                           strpos($api_response['description'], 'does not exist') !== false);
            
            if ($is_db_error) {
                echo json_encode([
                    'success' => false,
                    'error' => '数据中台正在维护中，请稍后重试或联系管理员',
                    'debug_info' => $api_response,
                    'fallback' => true
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'error' => $error_msg,
                    'debug_info' => $api_response
                ]);
            }
        }
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => '系统异常，请稍后重试',
            'debug_info' => $e->getMessage(),
            'fallback' => true
        ]);
    }
    
} else {
    echo json_encode(['error' => '不支持的操作']);
}
?> 