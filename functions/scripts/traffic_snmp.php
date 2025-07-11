#!/usr/bin/php
<?php
/**
 * IP地址管理 流量收集通用SNMP模块
 *
 * 提供SNMP查询和数据获取的通用函数
 */

// 引入配置文件
require_once dirname(__FILE__) . '/traffic_config.php';

/**
 * 通用SNMP类
 */
class TrafficSNMP {
    // 基本属性
    private $host;
    private $community;
    private $version;
    private $timeout;
    private $retries;
    private $port = 161;
    private $context = NULL;
    private $vendor = 'unknown';
    private $deviceId = 0;
    
    // 日志和错误处理
    private $last_error = "";
    private $debug = false;
    
    /**
     * 构造函数
     * 
     * @param object $device 设备信息对象
     * @param bool $debug 是否启用调试模式
     */
    public function __construct($device, $debug = false) {
        $this->debug = $debug;
        
        // 检查设备对象
        if (empty($device) || !is_object($device)) {
            throw new Exception("设备数据无效");
        }
        
        // 保存设备ID用于记录日志
        $this->deviceId = isset($device->id) ? $device->id : 0;
        
        // 检查设备IP地址
        if (empty($device->ip_addr)) {
            throw new Exception("设备IP地址不能为空");
        }
        
        // 从设备数据中提取所需信息
        $this->host = $device->ip_addr;
        $this->community = isset($device->snmp_community) && !empty($device->snmp_community) 
                          ? $device->snmp_community : 'public';
        
        // 版本、端口等其他参数
        $this->version = isset($device->snmp_version) ? intval($device->snmp_version) : 2;
        if ($this->version == 0) $this->version = 2; // 将0视为默认值2
        
        $this->port = isset($device->snmp_port) && intval($device->snmp_port) > 0 
                    ? intval($device->snmp_port) : 161;
        
        // 超时和重试设置
        $vendorDefaults = $this->getVendorDefaults('unknown');
        $this->timeout = isset($device->snmp_timeout) && intval($device->snmp_timeout) > 0 
                       ? intval($device->snmp_timeout) * 1000000 
                       : $vendorDefaults['timeout'] * 1000000;
                       
        $this->retries = isset($device->snmp_retries) && intval($device->snmp_retries) > 0 
                       ? intval($device->snmp_retries) 
                       : $vendorDefaults['retries'];
        
        // 日志详细信息
        $this->logMessage("初始化SNMP连接: {$this->host}:{$this->port}, 社区名: {$this->community}, 版本: {$this->version}", 3);
        
        // 识别设备厂商
        $this->identifyVendor($device);
    }
    
    /**
     * 获取指定厂商的默认设置
     * 
     * @param string $vendor 厂商名称
     * @return array 默认设置数组
     */
    private function getVendorDefaults($vendor) {
        $defaults = [
            'timeout' => 5,    // 默认5秒
            'retries' => 3     // 默认3次重试
        ];
        
        // 从配置中获取厂商特定设置
        $vendorConfig = get_traffic_config("device_types.{$vendor}", []);
        
        if (!empty($vendorConfig)) {
            if (isset($vendorConfig['snmp_timeout'])) {
                $defaults['timeout'] = intval($vendorConfig['snmp_timeout']);
            }
            
            if (isset($vendorConfig['snmp_retries'])) {
                $defaults['retries'] = intval($vendorConfig['snmp_retries']);
            }
        }
        
        return $defaults;
    }
    
    /**
     * 识别设备厂商
     * 
     * @param object $device 设备信息
     */
    private function identifyVendor($device) {
        // 初始化为未知厂商
        $this->vendor = 'unknown';
        
        // 首先检查设备中的vendor字段
        if (isset($device->vendor) && !empty($device->vendor)) {
            $vendorName = strtolower(trim($device->vendor));
            if (!empty($vendorName)) {
                $this->vendor = $vendorName;
                $this->logMessage("使用设备指定厂商: {$this->vendor}", 3);
                
                // 应用厂商特定设置
                $this->applyVendorSpecificSettings($this->vendor);
                return;
            }
        }
        
        // 如果没有vendor字段，检查type字段中的厂商信息
        if (isset($device->type_name) && !empty($device->type_name)) {
            $typeName = strtolower($device->type_name);
            
            // 检查类型名称中的厂商信息
            $vendorKeywords = [
                'huawei' => ['huawei', 'h3c', 'quidway'],
                'h3c' => ['h3c', 'comware', 'hpe'],
                'ruijie' => ['ruijie', 'reyee', 'rg'],
                'cisco' => ['cisco', 'ios'],
                'juniper' => ['juniper', 'junos']
            ];
            
            foreach ($vendorKeywords as $vendor => $keywords) {
                foreach ($keywords as $keyword) {
                    if (strpos($typeName, $keyword) !== false) {
                        $this->vendor = $vendor;
                        $this->logMessage("根据设备类型名称识别厂商: {$this->vendor}", 3);
                        
                        // 应用厂商特定设置
                        $this->applyVendorSpecificSettings($this->vendor);
                        return;
                    }
                }
            }
        }
        
        // 使用SNMP探测厂商
        try {
            // 获取系统OID
            $sysObjectID = $this->safeSnmpGet("1.3.6.1.2.1.1.2.0");
            
            if (!empty($sysObjectID)) {
                $this->logMessage("设备系统OID: {$sysObjectID}", 3);
                
                // 根据OID模式识别厂商
                foreach (get_traffic_config('device_types') as $vendor => $config) {
                    if (isset($config['vendor_oid_pattern']) && preg_match($config['vendor_oid_pattern'], $sysObjectID)) {
                        $this->vendor = $vendor;
                        $this->logMessage("根据OID识别厂商: {$this->vendor}", 3);
                        
                        // 应用厂商特定设置
                        $this->applyVendorSpecificSettings($this->vendor);
                        return;
                    }
                }
                
                // 通过关键字匹配识别
                $sysDesc = $this->safeSnmpGet("1.3.6.1.2.1.1.1.0");
                if (!empty($sysDesc)) {
                    $this->logMessage("设备描述: {$sysDesc}", 3);
                    
                    // 各厂商关键字
                    $vendorKeywords = [
                        'huawei' => ['huawei', 'h3c', 'quidway'],
                        'h3c' => ['h3c', 'comware', 'hpe'],
                        'ruijie' => ['ruijie', 'reyee', 'rg'],
                        'cisco' => ['cisco', 'ios'],
                        'juniper' => ['juniper', 'junos']
                    ];
                    
                    foreach ($vendorKeywords as $vendor => $keywords) {
                        foreach ($keywords as $keyword) {
                            if (stripos($sysDesc, $keyword) !== false) {
                                $this->vendor = $vendor;
                                $this->logMessage("根据设备描述识别厂商: {$this->vendor}", 3);
                                
                                // 应用厂商特定设置
                                $this->applyVendorSpecificSettings($this->vendor);
                                return;
                            }
                        }
                    }
                }
            }
        } catch (Exception $e) {
            $this->logMessage("SNMP探测厂商时出错: " . $e->getMessage(), 2);
        }
        
        // 如果未识别出厂商，使用默认值
        $this->logMessage("无法识别设备厂商，使用通用设置", 2);
        $this->applyVendorSpecificSettings('unknown');
    }
    
    /**
     * 应用厂商特定设置
     * 
     * @param string $vendor 厂商名称
     */
    private function applyVendorSpecificSettings($vendor) {
        $vendorConfig = get_traffic_config("device_types.{$vendor}", []);
        
        if (!empty($vendorConfig)) {
            // 应用超时和重试设置
            if (isset($vendorConfig['snmp_timeout'])) {
                $this->timeout = intval($vendorConfig['snmp_timeout']) * 1000000;
                $this->logMessage("应用{$vendor}厂商特定超时设置: {$vendorConfig['snmp_timeout']}秒", 3);
            }
            
            if (isset($vendorConfig['snmp_retries'])) {
                $this->retries = intval($vendorConfig['snmp_retries']);
                $this->logMessage("应用{$vendor}厂商特定重试设置: {$this->retries}次", 3);
            }
        }
    }
    
    /**
     * 执行SNMP获取 (GET)
     * 
     * @param string $oid 要获取的OID
     * @return mixed 获取的值或false
     */
    public function snmpGet($oid) {
        $this->last_error = "";
        
        try {
            // 记录开始时间
            $start_time = microtime(true);
            
            // 最大执行时间
            $max_exec_time = 5; // 5秒
            
            // 超时标志
            $timeout_flag = false;
            
            // 设置超时处理
            if (function_exists('pcntl_sigtimedwait')) {
                pcntl_signal(SIGALRM, function() use (&$timeout_flag) {
                    $timeout_flag = true;
                }, false);
                pcntl_alarm(5); // 5秒超时
            }
            
            // 根据SNMP版本获取数据
            switch ($this->version) {
                case '1':
                    $result = @snmpget($this->host, $this->community, $oid, $this->timeout, $this->retries);
                    break;
                case '2':
                case '2c':
                    $result = @snmp2_get($this->host, $this->community, $oid, $this->timeout, $this->retries);
                    break;
                case '3':
                    // 暂不支持SNMPv3
                    throw new Exception("暂不支持SNMPv3");
                    break;
                default:
                    $result = @snmp2_get($this->host, $this->community, $oid, $this->timeout, $this->retries);
            }
            
            // 取消闹钟
            if (function_exists('pcntl_sigtimedwait')) {
                pcntl_alarm(0);
            }
            
            // 检查是否超时
            if ($timeout_flag) {
                throw new Exception("SNMP GET操作超时");
            }
            
            // 检查操作时间
            $end_time = microtime(true);
            $exec_time = $end_time - $start_time;
            
            if ($exec_time > $max_exec_time) {
                $this->logMessage("SNMP GET操作执行时间过长: " . round($exec_time, 2) . "秒", 2);
            }
            
            if ($result === false) {
                throw new Exception("SNMP获取失败");
            }
            
            return $this->parseSnmpValue($result);
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            $this->logMessage("SNMP Get错误 (OID: {$oid}): " . $e->getMessage(), 1);
            return false;
        }
    }
    
    /**
     * 执行SNMP遍历 (WALK)
     * 
     * @param string $oid 要遍历的OID
     * @return array 遍历结果数组
     */
    public function snmpWalk($oid) {
        $this->last_error = "";
        
        try {
            // 记录开始时间
            $start_time = microtime(true);
            
            // 最大执行时间
            $max_exec_time = 5; // 5秒
            
            // 超时标志
            $timeout_flag = false;
            
            // 设置超时处理
            if (function_exists('pcntl_sigtimedwait')) {
                pcntl_signal(SIGALRM, function() use (&$timeout_flag) {
                    $timeout_flag = true;
                }, false);
                pcntl_alarm(5); // 5秒超时
            }
            
            // 根据SNMP版本遍历数据
            switch ($this->version) {
                case '1':
                    $result = @snmpwalk($this->host, $this->community, $oid, $this->timeout, $this->retries);
                    break;
                case '2':
                case '2c':
                    $result = @snmp2_walk($this->host, $this->community, $oid, $this->timeout, $this->retries);
                    break;
                case '3':
                    // 暂不支持SNMPv3
                    throw new Exception("暂不支持SNMPv3");
                    break;
                default:
                    $result = @snmp2_walk($this->host, $this->community, $oid, $this->timeout, $this->retries);
            }
            
            // 取消闹钟
            if (function_exists('pcntl_sigtimedwait')) {
                pcntl_alarm(0);
            }
            
            // 检查是否超时
            if ($timeout_flag) {
                throw new Exception("SNMP Walk操作超时");
            }
            
            // 检查操作时间
            $end_time = microtime(true);
            $exec_time = $end_time - $start_time;
            
            if ($exec_time > $max_exec_time) {
                $this->logMessage("SNMP WALK操作执行时间过长: " . round($exec_time, 2) . "秒", 2);
            }
            
            if ($result === false) {
                throw new Exception("SNMP遍历失败");
            }
            
            // 处理结果
            $resultArray = array();
            foreach ($result as $key => $value) {
                // 提取OID索引
                $oid_index = preg_replace('/^.*\.([0-9]+)$/', '$1', $key);
                $resultArray["$oid.$oid_index"] = $this->parseSnmpValue($value);
            }
            
            return $resultArray;
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();
            $this->logMessage("SNMP Walk错误 (OID: {$oid}): " . $e->getMessage(), 1);
            return array();
        }
    }
    
    /**
     * 安全执行SNMP遍历 (WALK)，支持备选OID和超时保护
     * 
     * @param string $primaryOid 主要OID
     * @param string $backupOid 备选OID，当主要OID失败时使用
     * @param int $timeout 超时时间（秒）
     * @return array 遍历结果数组
     */
    public function safeSnmpWalk($primaryOid, $backupOid = null, $timeout = 5) {
        // 记录开始时间
        $start_time = microtime(true);
        $this->logMessage("开始安全SNMP遍历 OID: $primaryOid", 3);
        
        try {
            // 设置SNMP超时保护
            if (function_exists('pcntl_alarm')) {
                pcntl_alarm($timeout);
            }
            
            // 尝试主要OID
            $result = $this->snmpWalk($primaryOid);
            
            // 如果主要OID失败且提供了备选OID，尝试备选OID
            if ((empty($result) || $result === false) && !empty($backupOid)) {
                $this->logMessage("主要OID $primaryOid 失败，尝试备选OID $backupOid", 2);
                $result = $this->snmpWalk($backupOid);
            }
            
            // 取消超时
            if (function_exists('pcntl_alarm')) {
                pcntl_alarm(0);
            }
            
            // 记录执行时间
            $end_time = microtime(true);
            $exec_time = round($end_time - $start_time, 2);
            
            // 记录结果
            if (!empty($result)) {
                $this->logMessage("SNMP遍历成功，获取 " . count($result) . " 个结果，耗时 $exec_time 秒", 3);
            } else {
                $this->logMessage("SNMP遍历无数据返回，耗时 $exec_time 秒", 2);
            }
            
            return $result;
        } catch (Exception $e) {
            // 取消超时
            if (function_exists('pcntl_alarm')) {
                pcntl_alarm(0);
            }
            
            $this->logMessage("安全SNMP遍历出错: " . $e->getMessage(), 1);
            return array();
        }
    }
    
    /**
     * 解析SNMP返回值
     * 
     * @param string $value SNMP返回的原始值
     * @return mixed 解析后的值
     */
    private function parseSnmpValue($value) {
        if (empty($value)) {
            return "";
        }
        
        // 解析各种SNMP数据类型
        if (preg_match('/^STRING: (.*)$/', $value, $matches)) {
            return trim(str_replace('"', '', $matches[1]));
        } elseif (preg_match('/^INTEGER: (.*)$/', $value, $matches)) {
            return intval($matches[1]);
        } elseif (preg_match('/^Counter32: (.*)$/', $value, $matches)) {
            return intval($matches[1]);
        } elseif (preg_match('/^Counter64: (.*)$/', $value, $matches)) {
            return $matches[1]; // 保持字符串以避免整数溢出
        } elseif (preg_match('/^Gauge32: (.*)$/', $value, $matches)) {
            return intval($matches[1]);
        } elseif (preg_match('/^Hex-STRING: (.*)$/', $value, $matches)) {
            // 转换十六进制字符串
            $hex = preg_replace('/[^A-Fa-f0-9]/', '', $matches[1]);
            return $hex;
        } elseif (preg_match('/^OID: (.*)$/', $value, $matches)) {
            return $matches[1];
        } elseif (preg_match('/^IpAddress: (.*)$/', $value, $matches)) {
            return $matches[1];
        } elseif (preg_match('/^Timeticks: \((\d+)\).*$/', $value, $matches)) {
            return intval($matches[1]);
        }
        
        // 如果没有匹配任何类型，去除类型前缀并返回
        if (strpos($value, ': ') !== false) {
            $parts = explode(': ', $value, 2);
            return trim($parts[1]);
        }
        
        // 返回原始值
        return $value;
    }
    
    /**
     * 获取设备接口流量数据
     * 
     * @return array 接口数据数组
     */
    public function getInterfaceTraffic() {
        $this->logMessage("开始获取设备接口流量数据", 3);
        
        try {
            // 设置超时警告
            logMessage("开始SNMP操作，如果长时间无响应可能是SNMP库卡住", 2);
            
            // 设置SNMP超时保护
            if (function_exists('pcntl_alarm')) {
                pcntl_alarm(60); // 设置60秒超时
            }
            
            // 基于厂商选择不同的处理方法
            switch ($this->vendor) {
                case 'h3c':
                    $this->logMessage("使用H3C专用方法获取接口数据", 3);
                    $result = $this->getH3CInterfaceTraffic();
                    break;
                    
                case 'huawei':
                    $this->logMessage("使用华为专用方法获取接口数据", 3);
                    $result = $this->getHuaweiInterfaceTraffic();
                    break;
                    
                case 'ruijie':
                    $this->logMessage("使用锐捷专用方法获取接口数据", 3);
                    $result = $this->getRuijieInterfaceTraffic();
                    break;
                    
                default:
                    $this->logMessage("使用标准方法获取接口数据", 3);
                    $result = $this->getStandardInterfaceTraffic();
            }
            
            // 取消超时
            if (function_exists('pcntl_alarm')) {
                pcntl_alarm(0);
            }
            
            return $result;
        } catch (Exception $e) {
            // 取消超时
            if (function_exists('pcntl_alarm')) {
                pcntl_alarm(0);
            }
            
            $this->logMessage("获取接口数据时发生错误: " . $e->getMessage(), 1);
            return [];
        }
    }
    
    /**
     * 获取H3C设备的接口流量数据（适用于H3C系列设备）
     * 
     * @return array 接口流量数据
     */
    private function getH3CInterfaceTraffic() {
        $this->logMessage("开始收集H3C设备的接口流量数据", 3);
        $startTime = microtime(true);
        
        $interfaceTraffic = array();
        
        try {
            // 首先尝试获取H3C专用的ifName (.1.3.6.1.4.1.25506.2.4.1.1.2)
            $interfaceNames = $this->safeSnmpWalk('.1.3.6.1.4.1.25506.2.4.1.1.2');
            if (empty($interfaceNames)) {
                // 如果获取不到，尝试标准ifName
                $interfaceNames = $this->safeSnmpWalk('.1.3.6.1.2.1.2.2.1.2');
                if (empty($interfaceNames)) {
                    $this->logMessage("无法从H3C设备 {$this->host} 获取接口名称", 2);
                    return array();
                }
            }
            
            // 获取接口描述 (可能是H3C专用OID或标准OID)
            $interfaceDescriptions = $this->safeSnmpWalk('.1.3.6.1.4.1.25506.2.4.1.1.4', '.1.3.6.1.2.1.31.1.1.1.18');
            
            // 获取接口速率 (bps)
            $interfaceSpeeds = $this->safeSnmpWalk('.1.3.6.1.2.1.2.2.1.5', '.1.3.6.1.2.1.31.1.1.1.15');
            
            // 获取接口操作状态
            $interfaceOperStatus = $this->safeSnmpWalk('.1.3.6.1.2.1.2.2.1.8');
            
            // 获取接口入流量 (首选64位计数器)
            $interfaceInOctets = $this->safeSnmpWalk('.1.3.6.1.2.1.31.1.1.1.6', '.1.3.6.1.2.1.2.2.1.10');
            
            // 获取接口出流量 (首选64位计数器)
            $interfaceOutOctets = $this->safeSnmpWalk('.1.3.6.1.2.1.31.1.1.1.10', '.1.3.6.1.2.1.2.2.1.16');
            
            // 获取接口入错误数
            $interfaceInErrors = $this->safeSnmpWalk('.1.3.6.1.2.1.2.2.1.14');
            
            // 获取接口出错误数
            $interfaceOutErrors = $this->safeSnmpWalk('.1.3.6.1.2.1.2.2.1.20');
            
            // 构建接口数据
            foreach ($interfaceNames as $index => $name) {
                // 清理索引，例如 ".1.3.6.1.4.1.25506.2.4.1.1.2.1" 中提取 "1"
                $idx = explode('.', $index);
                $interfaceIndex = end($idx);
                
                // 检查接口是否活动
                $operStatus = isset($interfaceOperStatus[".1.3.6.1.2.1.2.2.1.8.$interfaceIndex"]) 
                            ? $interfaceOperStatus[".1.3.6.1.2.1.2.2.1.8.$interfaceIndex"] 
                            : 0;
                
                // 跳过非活动接口和特定类型接口
                if (preg_match('/^(Null|Loopback|NULL|InLoopBack|LoopBack)/i', $name) && $operStatus != 1) {
                    continue;
                }
                
                // 从两个可能的OID中获取描述
                $description = '';
                if (isset($interfaceDescriptions[".1.3.6.1.4.1.25506.2.4.1.1.4.$interfaceIndex"])) {
                    $description = $interfaceDescriptions[".1.3.6.1.4.1.25506.2.4.1.1.4.$interfaceIndex"];
                } elseif (isset($interfaceDescriptions[".1.3.6.1.2.1.31.1.1.1.18.$interfaceIndex"])) {
                    $description = $interfaceDescriptions[".1.3.6.1.2.1.31.1.1.1.18.$interfaceIndex"];
                }
                
                // 获取速率
                $speed = isset($interfaceSpeeds[".1.3.6.1.2.1.2.2.1.5.$interfaceIndex"]) 
                       ? $interfaceSpeeds[".1.3.6.1.2.1.2.2.1.5.$interfaceIndex"] 
                       : (isset($interfaceSpeeds[".1.3.6.1.2.1.31.1.1.1.15.$interfaceIndex"]) 
                         ? $interfaceSpeeds[".1.3.6.1.2.1.31.1.1.1.15.$interfaceIndex"] * 1000000 
                         : 0);
                
                // 获取流量统计
                $inOctets = isset($interfaceInOctets[".1.3.6.1.2.1.31.1.1.1.6.$interfaceIndex"]) 
                          ? $interfaceInOctets[".1.3.6.1.2.1.31.1.1.1.6.$interfaceIndex"] 
                          : (isset($interfaceInOctets[".1.3.6.1.2.1.2.2.1.10.$interfaceIndex"]) 
                            ? $interfaceInOctets[".1.3.6.1.2.1.2.2.1.10.$interfaceIndex"] 
                            : 0);
                
                $outOctets = isset($interfaceOutOctets[".1.3.6.1.2.1.31.1.1.1.10.$interfaceIndex"]) 
                           ? $interfaceOutOctets[".1.3.6.1.2.1.31.1.1.1.10.$interfaceIndex"] 
                           : (isset($interfaceOutOctets[".1.3.6.1.2.1.2.2.1.16.$interfaceIndex"]) 
                             ? $interfaceOutOctets[".1.3.6.1.2.1.2.2.1.16.$interfaceIndex"] 
                             : 0);
                
                // 获取错误统计
                $inErrors = isset($interfaceInErrors[".1.3.6.1.2.1.2.2.1.14.$interfaceIndex"]) 
                          ? $interfaceInErrors[".1.3.6.1.2.1.2.2.1.14.$interfaceIndex"] 
                          : 0;
                
                $outErrors = isset($interfaceOutErrors[".1.3.6.1.2.1.2.2.1.20.$interfaceIndex"]) 
                           ? $interfaceOutErrors[".1.3.6.1.2.1.2.2.1.20.$interfaceIndex"] 
                           : 0;
                
                // 过滤掉无效的结果
                if ($inOctets == "" && $outOctets == "") {
                    continue;
                }
                
                $interfaceTraffic[$interfaceIndex] = array(
                    'name' => $name,
                    'description' => $description,
                    'status' => $operStatus,
                    'speed' => $speed,
                    'in_octets' => $inOctets,
                    'out_octets' => $outOctets,
                    'in_errors' => $inErrors,
                    'out_errors' => $outErrors,
                    'if_index' => $interfaceIndex
                );
            }
            
            $endTime = microtime(true);
            $timeTaken = round($endTime - $startTime, 2);
            $this->logMessage("H3C设备接口流量数据收集完成，共 " . count($interfaceTraffic) . " 个接口，耗时 {$timeTaken} 秒", 3);
            
        } catch (Exception $e) {
            $this->logMessage("收集H3C接口流量数据时出错: " . $e->getMessage(), 1);
        }
        
        return $interfaceTraffic;
    }
    
    /**
     * 获取华为设备接口流量
     * 
     * @return array 接口数据数组
     */
    private function getHuaweiInterfaceTraffic() {
        $this->logMessage("开始获取华为设备接口流量数据", 3);
        
        // 尝试使用华为特有OID
        $huaweiInterfaces = $this->tryHuaweiSpecificOIDs();
        
        // 如果华为特有OID方法失败，使用标准方法
        if (empty($huaweiInterfaces)) {
            $this->logMessage("华为特有OID方法失败，尝试标准SNMP OID", 3);
            return $this->getStandardInterfaceTraffic();
        }
        
        $this->logMessage("成功获取 " . count($huaweiInterfaces) . " 个华为设备接口", 3);
        return $huaweiInterfaces;
    }
    
    /**
     * 尝试使用华为特有的OID获取接口数据
     * 
     * @return array 接口数据数组
     */
    private function tryHuaweiSpecificOIDs() {
        $interfaces = [];
        
        try {
            // 华为特有的接口描述OID
            $huaweiIfDescr = "1.3.6.1.4.1.2011.5.25.31.1.1.1.1.1";
            $huaweiIfName = "1.3.6.1.4.1.2011.5.25.31.1.1.1.1.7";
            
            // 尝试获取接口名称和描述
            $ifNames = $this->safeSnmpWalk($huaweiIfName);
            if (empty($ifNames)) {
                $ifNames = $this->safeSnmpWalk(get_traffic_config('oid_map.if_name'));
            }
            
            $ifDescriptions = $this->safeSnmpWalk($huaweiIfDescr);
            if (empty($ifDescriptions)) {
                $ifDescriptions = $this->safeSnmpWalk(get_traffic_config('oid_map.if_descr'));
            }
            
            // 如果都获取失败，返回空数组
            if (empty($ifNames) && empty($ifDescriptions)) {
                $this->logMessage("无法获取华为设备接口名称和描述", 2);
                return [];
            }
            
            // 合并接口信息
            $interfaceIndices = array_unique(array_merge(array_keys($ifNames), array_keys($ifDescriptions)));
            
            if (empty($interfaceIndices)) {
                $this->logMessage("未找到任何接口索引", 2);
                return [];
            }
            
            $this->logMessage("获取到 " . count($interfaceIndices) . " 个接口索引", 3);
            
            // 获取流量数据
            $inOctets = $this->safeSnmpWalk(get_traffic_config('oid_map.if_hc_in_octets'), get_traffic_config('oid_map.if_in_octets'));
            
            $outOctets = $this->safeSnmpWalk(get_traffic_config('oid_map.if_hc_out_octets'), get_traffic_config('oid_map.if_out_octets'));
            
            // 获取错误数据
            $inErrors = $this->safeSnmpWalk(get_traffic_config('oid_map.if_in_errors'));
            $outErrors = $this->safeSnmpWalk(get_traffic_config('oid_map.if_out_errors'));
            
            // 获取状态和速率
            $operStatus = $this->safeSnmpWalk(get_traffic_config('oid_map.if_oper_status'));
            $speed = $this->safeSnmpWalk(get_traffic_config('oid_map.if_speed'));
            
            // 构建接口数据
            foreach ($interfaceIndices as $index) {
                $name = isset($ifNames[$index]) ? $ifNames[$index] : "Interface-{$index}";
                $description = isset($ifDescriptions[$index]) ? $ifDescriptions[$index] : "";
                
                // 处理接口状态
                $status = 'unknown';
                if (isset($operStatus[$index])) {
                    $statusVal = $operStatus[$index];
                    if (is_numeric($statusVal)) {
                        switch ($statusVal) {
                            case '1': $status = 'up'; break;
                            case '2': $status = 'down'; break;
                            default: $status = 'unknown';
                        }
                    }
                }
                
                $interfaces[$index] = [
                    'if_index' => $index,
                    'name' => $name,
                    'description' => $description,
                    'in_octets' => isset($inOctets[$index]) ? $inOctets[$index] : 0,
                    'out_octets' => isset($outOctets[$index]) ? $outOctets[$index] : 0,
                    'in_errors' => isset($inErrors[$index]) ? $inErrors[$index] : 0,
                    'out_errors' => isset($outErrors[$index]) ? $outErrors[$index] : 0,
                    'speed' => isset($speed[$index]) ? $speed[$index] : 0,
                    'oper_status' => $status
                ];
            }
            
            $this->logMessage("成功构建 " . count($interfaces) . " 个华为设备接口数据", 3);
            return $interfaces;
        } catch (Exception $e) {
            $this->logMessage("处理华为设备接口时出错: " . $e->getMessage(), 1);
            return [];
        }
    }
    
    /**
     * 获取锐捷设备接口流量
     * 
     * @return array 接口数据数组
     */
    private function getRuijieInterfaceTraffic() {
        $this->logMessage("开始尝试获取锐捷设备接口流量数据", 3);
        
        // 尝试不同的SNMP社区名
        $alternative_communities = ['public', 'private', 'njxxgc', 'ruijie'];
        $original_community = $this->community;
        
        // 先使用默认社区名尝试
        $interfaces = $this->getStandardInterfaceTraffic();
        
        // 如果默认社区名失败，尝试其他社区名
        if (empty($interfaces)) {
            $this->logMessage("使用默认社区名 '{$this->community}' 获取锐捷设备接口失败，尝试其他社区名", 2);
            
            foreach ($alternative_communities as $community) {
                if ($community == $original_community) continue;
                
                $this->logMessage("尝试使用社区名: {$community}", 3);
                $this->community = $community;
                
                // 尝试获取系统描述，测试连接
                $sysDescr = $this->safeSnmpGet("1.3.6.1.2.1.1.1.0");
                if ($sysDescr !== false) {
                    $this->logMessage("使用社区名 '{$community}' 成功连接到锐捷设备", 3);
                    $interfaces = $this->getStandardInterfaceTraffic();
                    if (!empty($interfaces)) {
                        break;
                    }
                }
            }
            
            // 恢复原始社区名
            $this->community = $original_community;
        }
        
        if (empty($interfaces)) {
            $this->logMessage("无法获取锐捷设备的接口数据，请确认SNMP配置正确", 2);
        } else {
            $this->logMessage("成功获取 " . count($interfaces) . " 个锐捷设备接口", 3);
        }
        
        return $interfaces;
    }
    
    /**
     * 获取标准设备的接口流量数据
     * 
     * @return array 接口流量数据
     */
    private function getStandardInterfaceTraffic() {
        $this->logMessage("开始收集标准设备的接口流量数据", 3);
        $startTime = microtime(true);
        
        $interfaceTraffic = array();
        
        try {
            // 获取接口名称 (.1.3.6.1.2.1.2.2.1.2)
            $interfaceNames = $this->safeSnmpWalk('.1.3.6.1.2.1.2.2.1.2');
            if (empty($interfaceNames)) {
                $this->logMessage("无法从设备 {$this->host} 获取接口名称", 2);
                return array();
            }
            
            // 获取接口描述 (.1.3.6.1.2.1.31.1.1.1.18)
            $interfaceDescriptions = $this->safeSnmpWalk('.1.3.6.1.2.1.31.1.1.1.18');
            
            // 获取接口带宽 (.1.3.6.1.2.1.2.2.1.5)
            $interfaceSpeeds = $this->safeSnmpWalk('.1.3.6.1.2.1.2.2.1.5', '.1.3.6.1.2.1.31.1.1.1.15');
            
            // 获取接口操作状态 (.1.3.6.1.2.1.2.2.1.8) - 1:up, 2:down
            $interfaceOperStatus = $this->safeSnmpWalk('.1.3.6.1.2.1.2.2.1.8');
            
            // 获取接口入流量 (.1.3.6.1.2.1.31.1.1.1.6)
            $interfaceInOctets = $this->safeSnmpWalk('.1.3.6.1.2.1.31.1.1.1.6', '.1.3.6.1.2.1.2.2.1.10');
            
            // 获取接口出流量 (.1.3.6.1.2.1.31.1.1.1.10)
            $interfaceOutOctets = $this->safeSnmpWalk('.1.3.6.1.2.1.31.1.1.1.10', '.1.3.6.1.2.1.2.2.1.16');
            
            // 获取接口入错误数 (.1.3.6.1.2.1.2.2.1.14)
            $interfaceInErrors = $this->safeSnmpWalk('.1.3.6.1.2.1.2.2.1.14');
            
            // 获取接口出错误数 (.1.3.6.1.2.1.2.2.1.20)
            $interfaceOutErrors = $this->safeSnmpWalk('.1.3.6.1.2.1.2.2.1.20');
            
            // 构建接口数据
            foreach ($interfaceNames as $index => $name) {
                // 清理索引，例如 ".1.3.6.1.2.1.2.2.1.2.1" 中提取 "1"
                $idx = explode('.', $index);
                $interfaceIndex = end($idx);
                
                // 只处理操作状态为up的接口
                $operStatus = isset($interfaceOperStatus[".1.3.6.1.2.1.2.2.1.8.$interfaceIndex"]) 
                            ? $interfaceOperStatus[".1.3.6.1.2.1.2.2.1.8.$interfaceIndex"] 
                            : 0;
                
                // 排除某些接口类型
                if (preg_match('/^(Null|Loopback|Vlan|Tunnel)/i', $name) && $operStatus != 1) {
                    continue;
                }
                
                $description = isset($interfaceDescriptions[".1.3.6.1.2.1.31.1.1.1.18.$interfaceIndex"]) 
                             ? $interfaceDescriptions[".1.3.6.1.2.1.31.1.1.1.18.$interfaceIndex"] 
                             : '';
                
                $speed = isset($interfaceSpeeds[".1.3.6.1.2.1.2.2.1.5.$interfaceIndex"]) 
                       ? $interfaceSpeeds[".1.3.6.1.2.1.2.2.1.5.$interfaceIndex"] 
                       : (isset($interfaceSpeeds[".1.3.6.1.2.1.31.1.1.1.15.$interfaceIndex"]) 
                         ? $interfaceSpeeds[".1.3.6.1.2.1.31.1.1.1.15.$interfaceIndex"] 
                         : 0);
                
                $inOctets = isset($interfaceInOctets[".1.3.6.1.2.1.31.1.1.1.6.$interfaceIndex"]) 
                          ? $interfaceInOctets[".1.3.6.1.2.1.31.1.1.1.6.$interfaceIndex"] 
                          : (isset($interfaceInOctets[".1.3.6.1.2.1.2.2.1.10.$interfaceIndex"]) 
                            ? $interfaceInOctets[".1.3.6.1.2.1.2.2.1.10.$interfaceIndex"] 
                            : 0);
                
                $outOctets = isset($interfaceOutOctets[".1.3.6.1.2.1.31.1.1.1.10.$interfaceIndex"]) 
                           ? $interfaceOutOctets[".1.3.6.1.2.1.31.1.1.1.10.$interfaceIndex"] 
                           : (isset($interfaceOutOctets[".1.3.6.1.2.1.2.2.1.16.$interfaceIndex"]) 
                             ? $interfaceOutOctets[".1.3.6.1.2.1.2.2.1.16.$interfaceIndex"] 
                             : 0);
                
                $inErrors = isset($interfaceInErrors[".1.3.6.1.2.1.2.2.1.14.$interfaceIndex"]) 
                          ? $interfaceInErrors[".1.3.6.1.2.1.2.2.1.14.$interfaceIndex"] 
                          : 0;
                
                $outErrors = isset($interfaceOutErrors[".1.3.6.1.2.1.2.2.1.20.$interfaceIndex"]) 
                           ? $interfaceOutErrors[".1.3.6.1.2.1.2.2.1.20.$interfaceIndex"] 
                           : 0;
                
                $interfaceTraffic[$interfaceIndex] = array(
                    'name' => $name,
                    'description' => $description,
                    'status' => $operStatus,
                    'speed' => $speed,
                    'in_octets' => $inOctets,
                    'out_octets' => $outOctets,
                    'in_errors' => $inErrors,
                    'out_errors' => $outErrors,
                    'if_index' => $interfaceIndex
                );
            }
            
            $endTime = microtime(true);
            $timeTaken = round($endTime - $startTime, 2);
            $this->logMessage("标准设备接口流量数据收集完成，共 " . count($interfaceTraffic) . " 个接口，耗时 {$timeTaken} 秒", 3);
            
        } catch (Exception $e) {
            $this->logMessage("收集标准接口流量数据时出错: " . $e->getMessage(), 1);
        }
        
        return $interfaceTraffic;
    }
    
    /**
     * 记录日志消息
     * 
     * @param string $message 日志消息
     * @param int $level 日志级别
     */
    private function logMessage($message, $level = 3) {
        $log_level = get_traffic_config('log_level', 3);
        
        // 如果当前日志级别小于配置的级别，不记录
        if ($level > $log_level) {
            return;
        }
        
        // 根据级别确定前缀
        $prefix = '';
        switch ($level) {
            case 1: $prefix = '[错误]'; break;
            case 2: $prefix = '[警告]'; break;
            case 3: $prefix = '[信息]'; break;
            case 4: $prefix = '[调试]'; break;
        }
        
        // 记录设备信息
        $device_info = "{$this->vendor}设备({$this->host})";
        if ($this->deviceId > 0) {
            $device_info .= " #" . $this->deviceId;
        }
        
        // 构建完整日志消息
        $log_message = $prefix . " " . $device_info . ": " . $message;
        
        // 输出到标准输出或错误日志
        if ($this->debug || get_traffic_config('verbose_logging', false)) {
            echo $log_message . "\n";
        }
        
        // 对错误和警告级别的消息记录到错误日志
        if ($level <= 2) {
            error_log($log_message);
        }
    }
    
    /**
     * 获取最后一次错误
     * 
     * @return string 错误消息
     */
    public function getLastError() {
        return $this->last_error;
    }
    
    /**
     * 获取当前识别的厂商
     * 
     * @return string 厂商名称
     */
    public function getVendor() {
        return $this->vendor;
    }
    
    /**
     * 尝试更新设备的SNMP社区
     * 
     * @param object $device 设备对象
     * @param string $new_community 新的社区名
     * @return bool 是否更新成功
     */
    public function updateDeviceCommunity($device, $new_community) {
        if (empty($device) || empty($device->id) || empty($new_community)) {
            $this->logMessage("无法更新社区名：设备ID或社区名为空", 2);
            return false;
        }
        
        // 如果社区名相同，不需要更新
        if (isset($device->snmp_community) && $device->snmp_community === $new_community) {
            $this->logMessage("设备 {$device->hostname} 已使用该社区名 '{$new_community}'，无需更新", 3);
            return true;
        }
        
        try {
            global $Database;
            
            if (!$Database) {
                $this->logMessage("数据库连接不可用，无法更新设备社区名", 1);
                return false;
            }
            
            // 准备更新语句
            $sql = "UPDATE `devices` SET `snmp_community` = ? WHERE `id` = ?";
            $params = array($new_community, $device->id);
            
            // 执行更新
            $this->logMessage("更新设备ID {$device->id} ({$device->hostname}) 的SNMP社区名为 '{$new_community}'", 3);
            $result = $Database->runQuery($sql, $params);
            
            if ($result) {
                $this->logMessage("成功更新设备 {$device->hostname} 的SNMP社区为 '{$new_community}'", 3);
                
                // 更新设备对象的社区名，确保后续操作使用新社区名
                $device->snmp_community = $new_community;
                return true;
            } else {
                $this->logMessage("更新设备 {$device->hostname} 的SNMP社区失败：数据库操作未成功", 2);
                return false;
            }
        } catch (Exception $e) {
            $this->logMessage("更新SNMP社区时出错: " . $e->getMessage(), 1);
            return false;
        }
    }
    
    /**
     * 检查SNMP连接性
     * 
     * @param string $community 自定义社区名（可选）
     * @return bool 连接是否成功
     */
    public function checkConnectivity($community = null) {
        // 如果提供了自定义社区名，暂时使用它
        $originalCommunity = $this->community;
        if ($community !== null) {
            $this->community = $community;
        }
        
        $this->logMessage("测试SNMP连接: {$this->host} (社区名: {$this->community})", 3);
        
        // 尝试获取sysObjectID，这是大多数设备都支持的基本OID
        $result = $this->safeSnmpGet("1.3.6.1.2.1.1.2.0");
        
        // 如果sysObjectID失败，尝试获取sysDescr
        if ($result === false) {
            $result = $this->safeSnmpGet("1.3.6.1.2.1.1.1.0");
        }
        
        // 如果sysDescr也失败，尝试获取sysUpTime
        if ($result === false) {
            $result = $this->safeSnmpGet("1.3.6.1.2.1.1.3.0");
        }
        
        // 恢复原始社区名
        if ($community !== null) {
            $this->community = $originalCommunity;
        }
        
        // 记录结果
        if ($result !== false) {
            $this->logMessage("SNMP连接测试成功", 3);
            return true;
        } else {
            $this->logMessage("SNMP连接测试失败", 2);
            return false;
        }
    }
    
    /**
     * 安全执行SNMP查询 (GET)，支持超时保护
     * 
     * @param string $oid 要查询的OID
     * @param int $timeout 超时时间（秒）
     * @return mixed 查询结果，失败返回false
     */
    public function safeSnmpGet($oid, $timeout = 5) {
        // 记录开始时间
        $start_time = microtime(true);
        $this->logMessage("开始安全SNMP查询 OID: $oid", 3);
        
        try {
            // 设置SNMP超时保护
            if (function_exists('pcntl_alarm')) {
                pcntl_alarm($timeout);
            }
            
            // 执行查询
            $result = $this->snmpGet($oid);
            
            // 取消超时
            if (function_exists('pcntl_alarm')) {
                pcntl_alarm(0);
            }
            
            // 记录执行时间
            $end_time = microtime(true);
            $exec_time = round($end_time - $start_time, 2);
            
            // 记录结果
            if ($result !== false) {
                $this->logMessage("SNMP查询成功，结果: " . substr(print_r($result, true), 0, 100) . "，耗时 $exec_time 秒", 3);
            } else {
                $this->logMessage("SNMP查询失败，耗时 $exec_time 秒", 2);
            }
            
            return $result;
        } catch (Exception $e) {
            // 取消超时
            if (function_exists('pcntl_alarm')) {
                pcntl_alarm(0);
            }
            
            $this->logMessage("安全SNMP查询出错: " . $e->getMessage(), 1);
            return false;
        }
    }
} 