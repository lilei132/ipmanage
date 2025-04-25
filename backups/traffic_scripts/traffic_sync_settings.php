#!/usr/bin/php
<?php
/**
 * 流量收集设置同步脚本
 * 
 * 从IP地址管理系统设置同步到流量采集cron任务
 */

// 引入配置文件获取IP地址管理设置
require_once(dirname(__FILE__) . '/traffic_config.php');

// 脚本路径
$SCRIPT_PATH = dirname(__FILE__);
$CRON_SCRIPT = $SCRIPT_PATH . '/traffic_cron.php';

// 目标crontab文件
$crontab_file = '/etc/cron.d/traffic_collector';

// 从IP地址管理获取当前设置
$phpipam_settings = get_phpipam_settings();

if (!$phpipam_settings) {
    error_log("无法读取IP地址管理设置，同步失败");
    exit(1);
}

// 提取设置
$interval_minutes = intval($phpipam_settings->trafficCollectionInterval / 60);
$is_enabled = $phpipam_settings->trafficCollection == 1;

// 检查IP地址管理的流量采集是否启用
if ($is_enabled) {
    // 准备crontab内容
    $cron_content = "# IP地址管理 流量采集定时任务 - 由系统自动生成\n";
    $cron_content .= "# 每 $interval_minutes 分钟执行一次\n";
    $cron_content .= "*/$interval_minutes * * * * root php " . dirname(__FILE__) . "/traffic_collector.php > /dev/null 2>&1\n";
    
    echo "IP地址管理流量采集已启用，采集间隔: $interval_minutes 分钟\n";
    echo "更新crontab文件: $crontab_file\n";
    
    // 写入crontab文件
    file_put_contents($crontab_file, $cron_content);
    
    // 设置权限
    chmod($crontab_file, 0644);
    
    echo "crontab文件已更新成功\n";
} else {
    // 如果流量采集被禁用，删除crontab文件
    if (file_exists($crontab_file)) {
        unlink($crontab_file);
        echo "IP地址管理流量采集已禁用，移除相关cron任务\n";
    } else {
        echo "IP地址管理流量采集已禁用，无需变更\n";
    }
}

exit(0); 