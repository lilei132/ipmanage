<?php
/**
 * phpIPAM 流量数据维护脚本
 *
 * 功能：
 * - 清理旧的流量数据
 * - 自动检测新设备并添加SNMP配置
 * - 优化数据库表以提高性能
 * - 监控数据收集状态
 *
 * 建议通过cron每天运行一次此脚本
 */

// 设置错误报告
error_reporting(E_ALL & ~E_NOTICE);
ini_set('display_errors', 1);

// 设置时区
date_default_timezone_set('Asia/Shanghai');

// 脚本开始时间
$scriptStartTime = microtime(true);

// 加载配置文件
require_once dirname(__FILE__) . '/traffic_config.php';

// 引入phpIPAM核心函数
require_once dirname(__FILE__) . '/../functions.php';

// 初始化数据库连接
$database = new Database_PDO;
$common = new Common;

// 初始化日志
logMessage("流量数据维护脚本开始执行", 3);

// 1. 清理旧数据
cleanOldData($database);

// 2. 自动检测和添加新设备
autoDetectNewDevices($database);

// 3. 优化数据库表
optimizeDatabase($database);

// 4. 监控数据收集状态
monitorDataCollectionStatus($database);

// 脚本执行统计
$scriptEndTime = microtime(true);
$executionTime = round($scriptEndTime - $scriptStartTime, 2);
logMessage("流量数据维护脚本执行完成，耗时: {$executionTime} 秒", 3);

exit(0);

/**
 * 清理旧的流量数据
 * 
 * @param Database_PDO $database 数据库连接
 */
function cleanOldData($database) {
    // 获取保留数据的天数（默认30天）
    $daysToKeep = get_traffic_config('data_retention_days', 30);
    
    logMessage("开始清理超过 {$daysToKeep} 天的流量数据", 3);
    
    try {
        // 计算截止日期
        $cutoffDate = date('Y-m-d', strtotime("-{$daysToKeep} days"));
        
        // 查询要删除的记录数
        $countQuery = "SELECT COUNT(*) AS total FROM `port_traffic_history` WHERE `timestamp` < ?";
        $result = $database->getObjectsQuery($countQuery, array($cutoffDate));
        $recordsToDelete = $result[0]->total ?? 0;
        
        if ($recordsToDelete > 0) {
            logMessage("将删除 {$recordsToDelete} 条超过 {$daysToKeep} 天的历史记录", 3);
            
            // 执行删除
            $deleteQuery = "DELETE FROM `port_traffic_history` WHERE `timestamp` < ?";
            $database->runQuery($deleteQuery, array($cutoffDate));
            
            logMessage("成功清理了 {$recordsToDelete} 条历史数据", 3);
        } else {
            logMessage("没有超过 {$daysToKeep} 天的历史数据需要清理", 3);
        }
    } catch (Exception $e) {
        logMessage("清理旧数据时出错: " . $e->getMessage(), 1);
    }
}

/**
 * 自动检测网络中的新设备并添加SNMP配置
 * 
 * @param Database_PDO $database 数据库连接
 */
function autoDetectNewDevices($database) {
    // 检查是否启用自动检测
    $autoDetectEnabled = get_traffic_config('auto_detect_devices', false);
    if (!$autoDetectEnabled) {
        logMessage("自动检测新设备功能已禁用", 3);
        return;
    }
    
    logMessage("开始自动检测网络中的新设备", 3);
    
    try {
        // 获取设备表中所有IP和主机名，用于避免重复添加
        $existingDevices = $database->getObjectsQuery("SELECT `ip_addr` FROM `devices`");
        $existingIPs = array();
        foreach ($existingDevices as $device) {
            $existingIPs[] = $device->ip_addr;
        }
        
        // 获取配置的SNMP社区
        $snmpCommunities = get_traffic_config('snmp_communities', array('public', 'private'));
        $defaultSNMPVersion = get_traffic_config('default_snmp_version', 2);
        
        // 获取配置的网络扫描范围
        $scanRanges = get_traffic_config('scan_ip_ranges', array());
        if (empty($scanRanges)) {
            logMessage("未配置网络扫描范围，跳过自动检测", 2);
            return;
        }
        
        $newDevicesAdded = 0;
        $scannedIPs = 0;
        
        // 扫描每个配置的IP范围
        foreach ($scanRanges as $range) {
            // 解析IP范围
            list($start, $end) = parseIPRange($range);
            if (!$start || !$end) {
                logMessage("无效的IP扫描范围: {$range}", 2);
                continue;
            }
            
            $startLong = ip2long($start);
            $endLong = ip2long($end);
            
            logMessage("扫描IP范围: {$start} - {$end}", 3);
            
            // 扫描范围内的每个IP
            for ($ipLong = $startLong; $ipLong <= $endLong; $ipLong++) {
                $ip = long2ip($ipLong);
                $scannedIPs++;
                
                // 跳过已存在的设备
                if (in_array($ip, $existingIPs)) {
                    continue;
                }
                
                // 先ping测试IP是否可达
                if (!pingTest($ip)) {
                    continue;
                }
                
                // 尝试使用不同的社区名称进行SNMP测试
                $validDevice = false;
                $hostname = '';
                $community = '';
                
                foreach ($snmpCommunities as $testCommunity) {
                    if (testSNMP($ip, $testCommunity, $defaultSNMPVersion, $hostname)) {
                        $validDevice = true;
                        $community = $testCommunity;
                        break;
                    }
                }
                
                // 如果设备有效，添加到数据库
                if ($validDevice) {
                    addNewDevice($database, $ip, $hostname, $community, $defaultSNMPVersion);
                    $newDevicesAdded++;
                    $existingIPs[] = $ip; // 防止重复添加
                }
            }
        }
        
        logMessage("设备扫描完成: 扫描了 {$scannedIPs} 个IP，添加了 {$newDevicesAdded} 个新设备", 3);
        
    } catch (Exception $e) {
        logMessage("自动检测新设备时出错: " . $e->getMessage(), 1);
    }
}

/**
 * 解析IP范围
 * 
 * @param string $range IP范围 (格式: "192.168.1.1-192.168.1.255" 或 "192.168.1.0/24")
 * @return array [起始IP, 结束IP]
 */
function parseIPRange($range) {
    // 处理CIDR格式 (如 "192.168.1.0/24")
    if (strpos($range, '/') !== false) {
        list($ip, $cidr) = explode('/', $range);
        $cidr = intval($cidr);
        
        if ($cidr < 0 || $cidr > 32) {
            return [false, false];
        }
        
        $ipLong = ip2long($ip);
        $netmask = ~((1 << (32 - $cidr)) - 1);
        $startLong = $ipLong & $netmask;
        $endLong = $startLong + (1 << (32 - $cidr)) - 1;
        
        return [long2ip($startLong), long2ip($endLong)];
    }
    
    // 处理范围格式 (如 "192.168.1.1-192.168.1.255")
    elseif (strpos($range, '-') !== false) {
        list($start, $end) = explode('-', $range);
        $start = trim($start);
        $end = trim($end);
        
        if (!filter_var($start, FILTER_VALIDATE_IP) || !filter_var($end, FILTER_VALIDATE_IP)) {
            return [false, false];
        }
        
        return [$start, $end];
    }
    
    // 单个IP
    elseif (filter_var($range, FILTER_VALIDATE_IP)) {
        return [$range, $range];
    }
    
    return [false, false];
}

/**
 * 对IP地址进行ping测试
 * 
 * @param string $ip IP地址
 * @return bool 是否可达
 */
function pingTest($ip) {
    // 跳过ping测试的选项
    if (!get_traffic_config('ping_before_scan', true)) {
        return true;
    }
    
    // 检测操作系统并构建适当的ping命令
    $cmd = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') 
           ? "ping -n 1 -w 500 " . escapeshellarg($ip) . " > NUL 2>&1"
           : "ping -c 1 -W 1 " . escapeshellarg($ip) . " > /dev/null 2>&1";
    
    // 执行ping命令
    exec($cmd, $output, $result);
    
    // 结果为0表示成功
    return ($result === 0);
}

/**
 * 测试SNMP连接是否有效
 * 
 * @param string $ip IP地址
 * @param string $community SNMP社区
 * @param int $version SNMP版本
 * @param string &$hostname 返回的主机名
 * @return bool 是否有效
 */
function testSNMP($ip, $community, $version, &$hostname) {
    $timeout = 1000000; // 1秒
    $retries = 1;
    
    try {
        switch ($version) {
            case 1:
                $sysDescr = @snmpget($ip, $community, "1.3.6.1.2.1.1.1.0", $timeout, $retries);
                $sysName = @snmpget($ip, $community, "1.3.6.1.2.1.1.5.0", $timeout, $retries);
                break;
            case 2:
            case 3:
            default:
                $sysDescr = @snmp2_get($ip, $community, "1.3.6.1.2.1.1.1.0", $timeout, $retries);
                $sysName = @snmp2_get($ip, $community, "1.3.6.1.2.1.1.5.0", $timeout, $retries);
                break;
        }
        
        if ($sysDescr !== false) {
            // 获取主机名
            if ($sysName !== false) {
                // 清理SNMP返回值中的"STRING: "前缀
                $hostname = preg_replace('/^STRING: /', '', $sysName);
                $hostname = trim(str_replace('"', '', $hostname));
            } else {
                $hostname = $ip; // 默认使用IP作为主机名
            }
            
            return true;
        }
    } catch (Exception $e) {
        // SNMP错误，视为测试失败
    }
    
    return false;
}

/**
 * 将新设备添加到数据库
 * 
 * @param Database_PDO $database 数据库连接
 * @param string $ip IP地址
 * @param string $hostname 主机名
 * @param string $community SNMP社区
 * @param int $version SNMP版本
 */
function addNewDevice($database, $ip, $hostname, $community, $version) {
    try {
        $values = array(
            'hostname'        => !empty($hostname) ? $hostname : $ip,
            'ip_addr'         => $ip,
            'description'     => '自动发现的设备 - ' . date('Y-m-d H:i:s'),
            'snmp_community'  => $community,
            'snmp_version'    => $version,
            'snmp_port'       => 161,
            'snmp_timeout'    => 5,
            'snmp_queries'    => '',
            'snmp_retries'    => 3
        );
        
        // 插入新设备
        $result = $database->insertObject('devices', $values);
        
        if ($result) {
            logMessage("成功添加新设备: {$ip} (主机名: {$hostname})", 3);
        } else {
            logMessage("添加新设备失败: {$ip}", 2);
        }
    } catch (Exception $e) {
        logMessage("添加设备时出错: " . $e->getMessage(), 1);
    }
}

/**
 * 优化数据库表以提高性能
 * 
 * @param Database_PDO $database 数据库连接
 */
function optimizeDatabase($database) {
    logMessage("开始优化流量数据表", 3);
    
    try {
        // 分析和优化port_traffic_history表
        $database->runQuery("ANALYZE TABLE `port_traffic_history`");
        $database->runQuery("OPTIMIZE TABLE `port_traffic_history`");
        
        logMessage("流量数据表优化完成", 3);
    } catch (Exception $e) {
        logMessage("优化数据库表时出错: " . $e->getMessage(), 1);
    }
}

/**
 * 监控数据收集状态
 * 
 * @param Database_PDO $database 数据库连接
 */
function monitorDataCollectionStatus($database) {
    logMessage("检查数据收集状态", 3);
    
    try {
        // 获取最近的数据记录时间
        $query = "SELECT MAX(`timestamp`) AS latest FROM `port_traffic_history`";
        $result = $database->getObjectsQuery($query);
        
        if (isset($result[0]->latest)) {
            $latestTimestamp = strtotime($result[0]->latest);
            $now = time();
            $minutesSinceLastUpdate = ($now - $latestTimestamp) / 60;
            
            // 配置的采集间隔
            $collectInterval = get_traffic_config('collect_interval', 5);
            
            // 如果超过预期采集间隔的3倍，发出警告
            if ($minutesSinceLastUpdate > ($collectInterval * 3)) {
                $formattedTime = date('Y-m-d H:i:s', $latestTimestamp);
                logMessage("警告: 流量数据收集可能出现问题! 最后记录时间: {$formattedTime} ({$minutesSinceLastUpdate} 分钟前)", 2);
            } else {
                logMessage("数据收集正常，最后更新时间: " . date('Y-m-d H:i:s', $latestTimestamp), 3);
            }
            
            // 汇总统计
            $statsQuery = "SELECT COUNT(*) AS total_records, 
                          COUNT(DISTINCT device_id) AS device_count, 
                          COUNT(DISTINCT if_index) AS interface_count 
                          FROM `port_traffic_history` 
                          WHERE `timestamp` >= DATE_SUB(NOW(), INTERVAL 24 HOUR)";
            
            $stats = $database->getObjectsQuery($statsQuery);
            
            if (isset($stats[0])) {
                $st = $stats[0];
                logMessage("过去24小时统计: {$st->total_records} 条记录, {$st->device_count} 个设备, {$st->interface_count} 个接口", 3);
            }
        } else {
            logMessage("数据库中没有流量记录", 2);
        }
    } catch (Exception $e) {
        logMessage("监控数据收集状态时出错: " . $e->getMessage(), 1);
    }
}

/**
 * 记录日志消息
 * 
 * @param string $message 日志消息
 * @param int $level 日志级别（1=错误，2=警告，3=信息，4=调试）
 */
function logMessage($message, $level = 3) {
    // 获取日志级别配置
    $logLevel = get_traffic_config('log_level', 3);
    
    // 如果当前级别高于配置的级别，不记录
    if ($level > $logLevel) {
        return;
    }
    
    // 日志级别前缀
    $prefix = '';
    switch ($level) {
        case 1: $prefix = '[错误]'; break;
        case 2: $prefix = '[警告]'; break;
        case 3: $prefix = '[信息]'; break;
        case 4: $prefix = '[调试]'; break;
        default: $prefix = '[未知]';
    }
    
    // 添加时间戳
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "{$timestamp} {$prefix} {$message}";
    
    // 输出日志
    if (get_traffic_config('verbose_logging', false)) {
        echo $logMessage . PHP_EOL;
    }
    
    // 记录到错误日志
    if ($level <= 2) {
        error_log($logMessage);
    }
} 