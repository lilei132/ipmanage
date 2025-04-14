<?php
/**
 * phpIPAM 流量采集配置命令行工具
 * 
 * 用法：
 *   php traffic_config_cli.php                     # 显示当前配置
 *   php traffic_config_cli.php --list              # 显示所有配置项
 *   php traffic_config_cli.php --get key           # 获取指定配置项的值
 *   php traffic_config_cli.php --set key value     # 设置配置项的值
 *   php traffic_config_cli.php --interval minutes  # 设置采集间隔（分钟）
 *   php traffic_config_cli.php --retention days    # 设置数据保留时间（天）
 *   php traffic_config_cli.php --run               # 立即执行采集
 *   php traffic_config_cli.php --help              # 显示帮助信息
 * 
 * 示例：
 *   php traffic_config_cli.php --interval 10       # 设置采集间隔为10分钟
 *   php traffic_config_cli.php --retention 60      # 设置数据保留60天
 */

// 设置错误报告
error_reporting(E_ALL & ~E_NOTICE);
ini_set('display_errors', 1);

// 引入phpIPAM核心函数
require_once dirname(__FILE__) . '/../functions.php';

// 加载配置文件
require_once dirname(__FILE__) . '/traffic_config.php';

// 初始化数据库连接
try {
    $database = new Database_PDO;
    $dbConnected = true;
} catch (Exception $e) {
    echo "警告: 无法连接到数据库: " . $e->getMessage() . "\n";
    $dbConnected = false;
}

// 帮助信息
function showHelp() {
    echo <<<HELP
phpIPAM 流量采集配置命令行工具

用法:
  php traffic_config_cli.php                     # 显示当前配置
  php traffic_config_cli.php --list              # 显示所有配置项
  php traffic_config_cli.php --get key           # 获取指定配置项的值
  php traffic_config_cli.php --set key value     # 设置配置项的值
  php traffic_config_cli.php --interval minutes  # 设置采集间隔（分钟）
  php traffic_config_cli.php --retention days    # 设置数据保留时间（天）
  php traffic_config_cli.php --run               # 立即执行采集
  php traffic_config_cli.php --help              # 显示帮助信息

示例:
  php traffic_config_cli.php --interval 10       # 设置采集间隔为10分钟
  php traffic_config_cli.php --retention 60      # 设置数据保留60天
  php traffic_config_cli.php --set log_level 3   # 设置日志级别为3(信息)

HELP;
    exit(0);
}

// 显示流量采集配置的主要设置
function showConfig() {
    $collection_interval = get_traffic_config('collection_interval', 5);
    $data_retention_days = get_traffic_config('data_retention_days', 30);
    $log_level = get_traffic_config('log_level', 3);
    $verbose_logging = get_traffic_config('verbose_logging', true) ? '启用' : '禁用';
    
    echo "\n流量采集配置信息:\n";
    echo "================================\n";
    echo "采集时间间隔: {$collection_interval} 分钟\n";
    echo "数据保留时间: {$data_retention_days} 天\n";
    echo "日志级别: {$log_level} (0=关闭, 1=错误, 2=警告, 3=信息, 4=调试)\n";
    echo "详细日志记录: {$verbose_logging}\n";
    
    // 获取cron任务信息
    $cron_output = [];
    exec('crontab -l | grep traffic_collector.php', $cron_output);
    if (!empty($cron_output)) {
        echo "\nCron 任务设置:\n";
        echo implode("\n", $cron_output) . "\n";
    } else {
        echo "\nCron 任务设置: 未找到\n";
        echo "建议添加: */5 * * * * php /var/www/html/functions/scripts/traffic_collector.php\n";
    }
    
    // 获取数据统计信息
    global $dbConnected, $database;
    if ($dbConnected) {
        try {
            $query = "SELECT COUNT(*) as total_records, 
                    MIN(timestamp) as oldest_record, 
                    MAX(timestamp) as newest_record,
                    COUNT(DISTINCT device_id) as device_count
            FROM port_traffic_history";
            
            // 确保查询不为空
            if (!empty($query)) {
                $stats = $database->getObjectsQuery($query);
                
                if (!empty($stats)) {
                    $stats = $stats[0];
                    echo "\n数据统计:\n";
                    echo "总记录数: " . number_format($stats->total_records) . "\n";
                    echo "设备数量: {$stats->device_count}\n";
                    echo "最早记录: {$stats->oldest_record}\n";
                    echo "最新记录: {$stats->newest_record}\n";
                }
            }
        } catch (Exception $e) {
            echo "\n获取数据统计信息失败: " . $e->getMessage() . "\n";
        }
    }
    
    echo "================================\n";
}

// 显示所有配置项
function listAllConfig() {
    $config = get_traffic_config('', []);
    
    echo "\n所有流量采集配置项:\n";
    echo "================================\n";
    printConfigArray($config);
    echo "================================\n";
}

// 递归打印配置数组
function printConfigArray($array, $prefix = '') {
    foreach ($array as $key => $value) {
        $fullKey = $prefix ? "$prefix.$key" : $key;
        
        if (is_array($value)) {
            echo "{$fullKey}:\n";
            printConfigArray($value, $fullKey);
        } else {
            echo "{$fullKey} = ";
            if (is_bool($value)) {
                echo $value ? 'true' : 'false';
            } else {
                echo $value;
            }
            echo "\n";
        }
    }
}

// 获取指定配置项的值
function getConfigValue($key) {
    $value = get_traffic_config($key, null);
    
    if ($value === null) {
        echo "错误: 配置项 '{$key}' 不存在\n";
        exit(1);
    }
    
    echo "{$key} = ";
    if (is_array($value)) {
        echo json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } elseif (is_bool($value)) {
        echo $value ? 'true' : 'false';
    } else {
        echo $value;
    }
    echo "\n";
}

// 设置配置项的值
function setConfigValue($key, $value) {
    // 尝试转换为适当的类型
    if ($value === 'true') {
        $value = true;
    } elseif ($value === 'false') {
        $value = false;
    } elseif (is_numeric($value)) {
        if (strpos($value, '.') !== false) {
            $value = (float)$value;
        } else {
            $value = (int)$value;
        }
    }
    
    $oldValue = get_traffic_config($key, null);
    if ($oldValue === null) {
        echo "警告: 创建新配置项 '{$key}'\n";
    }
    
    set_traffic_config($key, $value);
    global $dbConnected, $database;
    if ($dbConnected) {
        if (save_traffic_config_to_db($database)) {
            echo "成功: 配置项 '{$key}' 已更新为 '{$value}' 并保存到数据库\n";
        } else {
            echo "错误: 配置保存到数据库失败\n";
            exit(1);
        }
    } else {
        echo "警告: 配置已更新，但未能保存到数据库（数据库连接失败）\n";
    }
}

// 立即执行采集脚本
function runCollector() {
    echo "执行流量采集脚本...\n";
    $output = [];
    $return_var = 0;
    exec('php ' . dirname(__FILE__) . '/traffic_collector.php 2>&1', $output, $return_var);
    
    if ($return_var === 0) {
        echo "成功: 流量采集脚本已执行\n";
        echo "输出:\n";
        echo implode("\n", $output) . "\n";
    } else {
        echo "错误: 执行流量采集脚本失败，错误代码: {$return_var}\n";
        echo "输出:\n";
        echo implode("\n", $output) . "\n";
        exit(1);
    }
}

// 主函数
function main($argv) {
    if (count($argv) === 1) {
        // 无参数，显示当前配置
        showConfig();
        return;
    }
    
    $action = $argv[1];
    
    switch ($action) {
        case '--help':
        case '-h':
            showHelp();
            break;
            
        case '--list':
        case '-l':
            listAllConfig();
            break;
            
        case '--get':
        case '-g':
            if (!isset($argv[2])) {
                echo "错误: 请指定要获取的配置项名称\n";
                exit(1);
            }
            getConfigValue($argv[2]);
            break;
            
        case '--set':
        case '-s':
            if (!isset($argv[2]) || !isset($argv[3])) {
                echo "错误: 请指定配置项名称和值\n";
                exit(1);
            }
            setConfigValue($argv[2], $argv[3]);
            break;
            
        case '--interval':
        case '-i':
            if (!isset($argv[2]) || !is_numeric($argv[2]) || (int)$argv[2] < 1) {
                echo "错误: 请指定有效的采集间隔（分钟）\n";
                exit(1);
            }
            setConfigValue('collection_interval', (int)$argv[2]);
            echo "提示: 请确保cron任务也相应更新\n";
            break;
            
        case '--retention':
        case '-r':
            if (!isset($argv[2]) || !is_numeric($argv[2]) || (int)$argv[2] < 1) {
                echo "错误: 请指定有效的数据保留时间（天）\n";
                exit(1);
            }
            setConfigValue('data_retention_days', (int)$argv[2]);
            break;
            
        case '--run':
            runCollector();
            break;
            
        default:
            echo "错误: 未知的参数 '{$action}'\n";
            echo "使用 --help 查看帮助信息\n";
            exit(1);
    }
}

// 运行主函数
main($argv); 