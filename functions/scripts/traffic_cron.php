#!/usr/bin/env php
<?php
/**
 * Traffic Collection Cron Management Script
 * 
 * This script manages the cron jobs for traffic collection and maintenance.
 * It provides functionality to install, remove, and check the status of cron tasks.
 */

// Include configuration file
require_once(dirname(__FILE__) . '/traffic_config.php');
// 注释掉不存在的文件引用
// require_once(dirname(__FILE__) . '/../../includes/global.func.php');

// Define the base paths
define('BASEPATH', dirname(__FILE__) . '/../../');
define('SCRIPT_PATH', realpath(dirname(__FILE__)));

/**
 * 获取交通配置包装函数
 * 
 * @return array 配置数组
 */
function getTrafficConfig() {
    // 调用traffic_config.php中定义的函数
    return get_traffic_config('') ?: [];
}

/**
 * 设置交通配置包装函数
 * 
 * @param string $key 配置键
 * @param mixed $value 配置值
 * @return bool 成功状态
 */
function setTrafficConfig($key, $value) {
    // 调用traffic_config.php中定义的函数
    return set_traffic_config($key, $value);
}

/**
 * Get the current cron configuration
 * 
 * @return array Array of current cron entries related to traffic collection
 */
function getCurrentCronConfig() {
    $output = [];
    exec('crontab -l 2>/dev/null', $output);
    $result = [];
    
    foreach ($output as $line) {
        if (strpos($line, 'traffic_collector.php') !== false || strpos($line, 'traffic_maintenance.php') !== false) {
            $result[] = $line;
        }
    }
    
    return $result;
}

/**
 * Install traffic collection cron
 * 
 * @param int $interval Interval in minutes for traffic collection
 * @return bool Success status
 */
function installCollectorCron($interval = null) {
    // 如果未提供间隔参数，从配置中获取
    if ($interval === null) {
        $interval = get_traffic_config('collection_interval', 5);
    }
    
    // Validate interval
    if ($interval < 1) {
        $interval = 5;
    }
    
    // Create cron schedule based on interval
    if ($interval < 60) {
        $schedule = "*/$interval * * * *";
    } else {
        $hours = floor($interval / 60);
        $schedule = "0 */$hours * * *";
    }
    
    $collectorScript = SCRIPT_PATH . '/traffic_collector.php';
    $logFile = "/var/log/traffic_collector.log";
    
    // Get current crontab
    $output = [];
    exec('crontab -l 2>/dev/null', $output);
    
    // Remove existing collector cron entry if it exists
    foreach ($output as $i => $line) {
        if (strpos($line, 'traffic_collector.php') !== false) {
            unset($output[$i]);
        }
    }
    
    // Add new cron entry
    $output[] = "$schedule php $collectorScript &> $logFile";
    
    // Write back to crontab
    $tempFile = tempnam(sys_get_temp_dir(), 'cron');
    file_put_contents($tempFile, implode("\n", $output) . "\n");
    exec("crontab $tempFile");
    unlink($tempFile);
    
    // Update the config if needed
    $config = getTrafficConfig();
    if ($config['collection_interval'] != $interval) {
        setTrafficConfig('collection_interval', $interval);
    }
    
    return true;
}

/**
 * Install traffic maintenance cron
 * 
 * @return bool Success status
 */
function installMaintenanceCron() {
    $maintenanceScript = SCRIPT_PATH . '/traffic_maintenance.php';
    $logFile = "/var/log/traffic_maintenance.log";
    
    // Get current crontab
    $output = [];
    exec('crontab -l 2>/dev/null', $output);
    
    // Remove existing maintenance cron entry if it exists
    foreach ($output as $i => $line) {
        if (strpos($line, 'traffic_maintenance.php') !== false) {
            unset($output[$i]);
        }
    }
    
    // Add new cron entry - run at 2 AM
    $output[] = "0 2 * * * php $maintenanceScript &> $logFile";
    
    // Write back to crontab
    $tempFile = tempnam(sys_get_temp_dir(), 'cron');
    file_put_contents($tempFile, implode("\n", $output) . "\n");
    exec("crontab $tempFile");
    unlink($tempFile);
    
    return true;
}

/**
 * Remove traffic collection cron
 * 
 * @return bool Success status
 */
function removeCollectorCron() {
    // Get current crontab
    $output = [];
    exec('crontab -l 2>/dev/null', $output);
    
    // Remove collector cron entry
    $found = false;
    foreach ($output as $i => $line) {
        if (strpos($line, 'traffic_collector.php') !== false) {
            unset($output[$i]);
            $found = true;
        }
    }
    
    if ($found) {
        // Write back to crontab
        $tempFile = tempnam(sys_get_temp_dir(), 'cron');
        file_put_contents($tempFile, implode("\n", $output) . "\n");
        exec("crontab $tempFile");
        unlink($tempFile);
        return true;
    }
    
    return false;
}

/**
 * Remove traffic maintenance cron
 * 
 * @return bool Success status
 */
function removeMaintenanceCron() {
    // Get current crontab
    $output = [];
    exec('crontab -l 2>/dev/null', $output);
    
    // Remove maintenance cron entry
    $found = false;
    foreach ($output as $i => $line) {
        if (strpos($line, 'traffic_maintenance.php') !== false) {
            unset($output[$i]);
            $found = true;
        }
    }
    
    if ($found) {
        // Write back to crontab
        $tempFile = tempnam(sys_get_temp_dir(), 'cron');
        file_put_contents($tempFile, implode("\n", $output) . "\n");
        exec("crontab $tempFile");
        unlink($tempFile);
        return true;
    }
    
    return false;
}

/**
 * Show help message
 */
function showHelp() {
    echo "流量采集Cron管理工具\n";
    echo "用法: php " . basename(__FILE__) . " [命令] [参数]\n\n";
    echo "命令:\n";
    echo "  status              - 显示当前Cron状态\n";
    echo "  install             - 安装采集和维护Cron (使用默认5分钟间隔)\n";
    echo "  install-collector   - 仅安装采集Cron\n";
    echo "  install-maintenance - 仅安装维护Cron\n";
    echo "  remove              - 移除所有流量相关Cron\n";
    echo "  remove-collector    - 仅移除采集Cron\n";
    echo "  remove-maintenance  - 仅移除维护Cron\n";
    echo "  set-interval [分钟]  - 设置采集间隔 (默认: 5分钟)\n";
    echo "  help                - 显示此帮助信息\n";
    echo "\n";
}

/**
 * Display current cron status
 */
function showStatus() {
    $crons = getCurrentCronConfig();
    $config = getTrafficConfig();
    $collectorFound = false;
    $maintenanceFound = false;
    
    echo "流量采集Cron任务状态:\n";
    echo "================================\n";
    
    foreach ($crons as $cron) {
        if (strpos($cron, 'traffic_collector.php') !== false) {
            echo "流量采集任务: 已安装\n";
            echo $cron . "\n";
            echo "当前采集间隔: " . $config['collection_interval'] . " 分钟\n\n";
            $collectorFound = true;
        } elseif (strpos($cron, 'traffic_maintenance.php') !== false) {
            echo "数据维护任务: 已安装\n";
            echo $cron . "\n";
            $maintenanceFound = true;
        }
    }
    
    if (!$collectorFound) {
        echo "流量采集任务: 未安装\n\n";
    }
    
    if (!$maintenanceFound) {
        echo "数据维护任务: 未安装\n";
    }
    
    echo "================================\n";
}

// Main script
if ($argc < 2) {
    showHelp();
    exit(1);
}

$command = strtolower($argv[1]);

switch ($command) {
    case 'status':
        showStatus();
        break;
        
    case 'install':
        $interval = isset($argv[2]) ? intval($argv[2]) : 5;
        installCollectorCron($interval);
        installMaintenanceCron();
        echo "已安装全部Cron任务, 采集间隔: $interval 分钟\n";
        break;
        
    case 'install-collector':
        $interval = isset($argv[2]) ? intval($argv[2]) : 5;
        installCollectorCron($interval);
        echo "已安装采集Cron任务, 采集间隔: $interval 分钟\n";
        break;
        
    case 'install-maintenance':
        installMaintenanceCron();
        echo "已安装维护Cron任务\n";
        break;
        
    case 'remove':
        removeCollectorCron();
        removeMaintenanceCron();
        echo "已移除全部Cron任务\n";
        break;
        
    case 'remove-collector':
        removeCollectorCron();
        echo "已移除采集Cron任务\n";
        break;
        
    case 'remove-maintenance':
        removeMaintenanceCron();
        echo "已移除维护Cron任务\n";
        break;
        
    case 'set-interval':
        $interval = isset($argv[2]) ? intval($argv[2]) : 5;
        if ($interval < 1) {
            echo "无效的间隔时间, 已设置为默认值: 5 分钟\n";
            $interval = 5;
        }
        installCollectorCron($interval);
        echo "已更新采集间隔: $interval 分钟\n";
        break;
        
    case 'help':
    default:
        showHelp();
        break;
}

exit(0); 