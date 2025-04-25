<?php
/**
 * IP地址管理 流量收集配置文件
 *
 * 本文件用于配置流量收集的参数和相关设置
 */

// 获取IP地址管理主系统的配置
function get_phpipam_settings() {
    try {
        // 引入IP地址管理配置文件
        $config_file = dirname(dirname(dirname(__FILE__))) . '/config.php';
        if (!file_exists($config_file)) {
            error_log("IP地址管理配置文件不存在: $config_file");
            return false;
        }
        
        // 检查是否定义了数据库连接信息
        global $db;
        if (!isset($db) || !is_array($db)) {
            include($config_file);
        }
        
        // 如果仍然没有数据库信息，返回失败
        if (!isset($db) || !is_array($db)) {
            error_log("IP地址管理配置文件中没有找到数据库配置");
            return false;
        }
        
        // 创建数据库连接并查询设置
        $pdo = new PDO('mysql:host='.$db['host'].';dbname='.$db['name'].';charset=utf8', $db['user'], $db['pass'], array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
        $query = "SELECT * FROM settings WHERE id = 1";
        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $settings = $stmt->fetch(PDO::FETCH_OBJ);
        
        return $settings;
    } catch (Exception $e) {
        error_log("无法读取IP地址管理设置: " . $e->getMessage());
        return false;
    }
}

// 获取IP地址管理系统设置
$phpipam_settings = get_phpipam_settings();

// 如果成功获取到IP地址管理设置，使用这些设置
if ($phpipam_settings) {
    // 计算基于IP地址管理设置的采集间隔（转换为分钟）
    $collection_interval = $phpipam_settings->trafficCollectionInterval / 60;
    // 数据保留天数
    $data_retention_days = $phpipam_settings->trafficHistoryDays;
} else {
    // 默认设置
    $collection_interval = 5; // 默认5分钟
    $data_retention_days = 30; // 默认保留30天
}

// 基本配置
$traffic_config = [
    // 数据保留时间（天）- 从IP地址管理设置读取
    'data_retention_days' => $data_retention_days,
    
    // 收集频率（分钟）- 从IP地址管理设置读取
    'collection_interval' => $collection_interval,
    
    // 日志级别：0=关闭，1=错误，2=警告，3=信息，4=调试
    'log_level' => 4,
    
    // 是否显示详细日志
    'verbose_logging' => true,
    
    // 日志格式：text=文本格式，json=JSON格式
    'log_format' => 'text',
    
    // 日志文件模式：daily=按天，hourly=按小时，single=单文件
    'log_file_pattern' => 'daily',
    
    // 最大日志文件大小（字节）
    'max_log_size' => 10 * 1024 * 1024,  // 10MB
    
    // 最大保留日志文件数
    'max_log_files' => 30,
    
    // 是否压缩旧日志文件
    'compress_logs' => true,
    
    // 接口数据收集相关配置
    'interface' => [
        // 是否收集端口描述信息
        'collect_descriptions' => true,
        
        // 是否跳过无流量的接口
        'skip_zero_traffic' => false,
        
        // 最小有效流量值（字节）- 低于此值的视为噪声
        'min_traffic_threshold' => 0,
        
        // 需要排除的接口名称模式（正则表达式）
        'exclude_patterns' => [
            '/null/i',
            '/loopback/i',
            '/virtual/i',
            '/vlan/i',
            '/tunnel/i',
            '/bridge/i'
        ],
        
        // 值转换处理
        'conversions' => [
            // 最大合理流量值（字节）- 超过此值可能是32位计数器溢出
            'max_traffic_value' => 100000000000000, // 100TB
            
            // 64位计数器最大值 - 用于检测计数器溢出和重置
            'max_counter_64bit' => 18446744073709551615
        ]
    ],
    
    // 设备类型特殊处理
    'device_types' => [
        'huawei' => [
            'snmp_timeout' => 10,  // 秒
            'snmp_retries' => 10,
            'special_methods' => ['get_huawei_interface_traffic', 'get_huawei_me60_traffic'],
            'oid_map' => [
                'if_in_octets' => '1.3.6.1.2.1.31.1.1.1.6',
                'if_out_octets' => '1.3.6.1.2.1.31.1.1.1.10'
            ]
        ],
        'h3c' => [
            'snmp_timeout' => 10,  // 秒
            'snmp_retries' => 5,
            'special_methods' => ['get_h3c_interface_traffic'],
            'oid_map' => [
                'if_in_octets' => '1.3.6.1.2.1.31.1.1.1.6',
                'if_out_octets' => '1.3.6.1.2.1.31.1.1.1.10',
                'h3c_if_in_octets' => '1.3.6.1.4.1.25506.2.6.1.1.1.1.6',
                'h3c_if_out_octets' => '1.3.6.1.4.1.25506.2.6.1.1.1.1.7'
            ]
        ],
        'ruijie' => [
            'snmp_timeout' => 10,  // 秒
            'snmp_retries' => 5,
            'vendor_oid_pattern' => '/^.1.3.6.1.4.1.4881.1/i',
            'use_native_snmp' => true
        ],
        'cisco' => [
            'snmp_timeout' => 5,  // 秒
            'snmp_retries' => 3,
            'vendor_oid_pattern' => '/^.1.3.6.1.4.1.9/i'
        ]
    ],
    
    // 通用SNMP OID映射
    'oid_map' => [
        // 接口名称和描述
        'if_name' => '1.3.6.1.2.1.31.1.1.1.1',
        'if_descr' => '1.3.6.1.2.1.2.2.1.2',
        'if_alias' => '1.3.6.1.2.1.31.1.1.1.18',
        
        // 流量计数器
        'if_in_octets' => '1.3.6.1.2.1.2.2.1.10',
        'if_out_octets' => '1.3.6.1.2.1.2.2.1.16',
        'if_hc_in_octets' => '1.3.6.1.2.1.31.1.1.1.6',
        'if_hc_out_octets' => '1.3.6.1.2.1.31.1.1.1.10',
        
        // 错误计数器
        'if_in_errors' => '1.3.6.1.2.1.2.2.1.14',
        'if_out_errors' => '1.3.6.1.2.1.2.2.1.20',
        
        // 状态和速率
        'if_oper_status' => '1.3.6.1.2.1.2.2.1.8',
        'if_speed' => '1.3.6.1.2.1.2.2.1.5',
        'if_high_speed' => '1.3.6.1.2.1.31.1.1.1.15'
    ]
];

/**
 * 获取流量配置
 * 
 * @param string $key 配置键名，使用点号分隔多级配置，如"interface.collect_descriptions"
 * @param mixed $default 默认值，如果配置不存在则返回此值
 * @return mixed 配置值
 */
function get_traffic_config($key, $default = null) {
    global $traffic_config;
    
    if (isset($traffic_config[$key])) {
        return $traffic_config[$key];
    }
    
    return $default;
}

/**
 * 设置流量配置
 * 
 * @param string $key 配置键名，使用点号分隔多级配置
 * @param mixed $value 配置值
 * @return bool 是否设置成功
 */
function set_traffic_config($key, $value) {
    global $traffic_config;
    
    // 分解键名
    $keys = explode('.', $key);
    $lastKey = array_pop($keys);
    $config = &$traffic_config;
    
    // 逐级查找配置位置
    foreach ($keys as $k) {
        if (!isset($config[$k]) || !is_array($config[$k])) {
            $config[$k] = array();
        }
        $config = &$config[$k];
    }
    
    // 设置值
    $config[$lastKey] = $value;
    return true;
}

/**
 * 保存配置到数据库
 * 
 * @param PDO $db 数据库连接对象
 * @return bool 是否保存成功
 */
function save_traffic_config_to_db($db) {
    global $traffic_config;
    
    try {
        // 将配置转换为JSON
        $config_json = json_encode($traffic_config, JSON_PRETTY_PRINT);
        
        // 检查表是否存在，不存在则创建
        $sql = "CREATE TABLE IF NOT EXISTS `traffic_settings` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `name` varchar(64) NOT NULL,
            `config` text NOT NULL,
            `last_updated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `name` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;";
        
        $db->exec($sql);
        
        // 更新或插入配置
        $sql = "INSERT INTO `traffic_settings` (`name`, `config`) VALUES ('traffic_collector', ?) 
                ON DUPLICATE KEY UPDATE `config` = VALUES(`config`)";
        
        $stmt = $db->prepare($sql);
        $result = $stmt->execute(array($config_json));
        
        return $result;
    } catch (Exception $e) {
        error_log("保存流量配置失败: " . $e->getMessage());
        return false;
    }
}

/**
 * 从数据库加载配置
 * 
 * @param PDO $db 数据库连接对象
 * @return bool 是否加载成功
 */
function load_traffic_config_from_db($db) {
    global $traffic_config;
    
    try {
        // 检查表是否存在
        $sql = "SHOW TABLES LIKE 'traffic_settings'";
        $result = $db->query($sql);
        
        if ($result->rowCount() == 0) {
            // 表不存在，使用默认配置
            return false;
        }
        
        // 查询配置
        $sql = "SELECT `config` FROM `traffic_settings` WHERE `name` = 'traffic_collector' LIMIT 1";
        $stmt = $db->query($sql);
        
        if ($stmt->rowCount() == 0) {
            // 配置不存在，使用默认配置
            return false;
        }
        
        // 读取配置并解码
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $loaded_config = json_decode($row['config'], true);
        
        if (json_last_error() != JSON_ERROR_NONE) {
            // JSON解码失败，使用默认配置
            error_log("解析流量配置失败: " . json_last_error_msg());
            return false;
        }
        
        // 合并配置，保留默认值作为备份
        $traffic_config = array_merge($traffic_config, $loaded_config);
        
        return true;
    } catch (Exception $e) {
        error_log("加载流量配置失败: " . $e->getMessage());
        return false;
    }
} 