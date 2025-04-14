<?php
/**
 * 流量收集设置同步脚本
 * 
 * 从phpIPAM系统设置同步到流量采集cron任务
 */

// 引入配置文件获取phpIPAM设置
require_once(dirname(__FILE__) . '/traffic_config.php');

// 脚本路径
$SCRIPT_PATH = dirname(__FILE__);
$CRON_SCRIPT = $SCRIPT_PATH . '/traffic_cron.php';

// 从phpIPAM获取当前设置
$phpipam_settings = get_phpipam_settings();

if (!$phpipam_settings) {
    error_log("无法读取phpIPAM设置，同步失败");
    exit(1);
}

// 转换秒到分钟
$interval_minutes = intval($phpipam_settings->trafficCollectionInterval / 60);
if ($interval_minutes < 1) $interval_minutes = 5; // 防止除零

// 检查phpIPAM的流量采集是否启用
if ($phpipam_settings->trafficCollection == 1) {
    // 流量采集已启用，更新cron
    echo "phpIPAM流量采集已启用，采集间隔: $interval_minutes 分钟\n";
    
    // 更新收集器cron任务 - 通过命令行
    echo "正在更新流量采集cron任务...\n";
    exec("php $CRON_SCRIPT install-collector $interval_minutes 2>&1", $output, $return_var);
    echo implode("\n", $output) . "\n";
    
    // 确保维护任务也已安装
    echo "正在确保数据维护cron任务已安装...\n";
    exec("php $CRON_SCRIPT install-maintenance 2>&1", $output2, $return_var2);
    echo implode("\n", $output2) . "\n";
    
    echo "流量设置同步完成\n";
} else {
    // 流量采集已禁用，移除cron
    echo "phpIPAM流量采集已禁用，移除相关cron任务\n";
    
    // 移除cron任务 - 通过命令行
    exec("php $CRON_SCRIPT remove 2>&1", $output, $return_var);
    echo implode("\n", $output) . "\n";
    
    echo "流量设置同步完成\n";
}

exit(0); 