#!/usr/bin/php
<?php
/**
 * 自动扫描脚本 - 用于cron定时任务
 * 此脚本执行以下操作：
 * 1. 检测所有已添加子网中的IP地址在线状态（不仅限于标记了pingSubnet的子网）
 * 2. 发现所有启用了发现功能的子网中的新IP地址
 * 3. 记录执行日志
 * 
 * 建议的cron设置：每天执行一次
 * 0 0 * * * /usr/bin/php /var/www/html/functions/scripts/discovery_cron_job.php > /dev/null 2>&1
 */

# 包含必要的函数
require_once(dirname(__FILE__) . '/../functions.php');

# 设置时区
date_default_timezone_set('Asia/Shanghai');

# 初始化日志文件
$log_file = dirname(__FILE__) . '/../../logs/discovery_cron_job.log';
$log_dir = dirname($log_file);

# 确保日志目录存在
if (!file_exists($log_dir)) {
    mkdir($log_dir, 0755, true);
}

# 记录日志函数
function write_log($message) {
    global $log_file;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($log_file, "[$timestamp] $message\n", FILE_APPEND);
    echo "[$timestamp] $message\n";
}

# 开始执行
write_log("自动扫描脚本开始执行...");

# 执行ping检查
write_log("执行ping检查...");
$ping_output = [];
$ping_return_var = 0;
exec(PHP_BINARY . ' ' . dirname(__FILE__) . '/pingCheck.php 2>&1', $ping_output, $ping_return_var);

if ($ping_return_var === 0) {
    write_log("ping检查执行成功");
    foreach ($ping_output as $line) {
        write_log("pingCheck: $line");
    }
} else {
    write_log("ping检查执行失败，错误代码: $ping_return_var");
    foreach ($ping_output as $line) {
        write_log("pingCheck error: $line");
    }
}

# 执行发现检查
write_log("执行发现检查...");
$discovery_output = [];
$discovery_return_var = 0;
exec(PHP_BINARY . ' ' . dirname(__FILE__) . '/discoveryCheck.php 2>&1', $discovery_output, $discovery_return_var);

if ($discovery_return_var === 0) {
    write_log("发现检查执行成功");
    foreach ($discovery_output as $line) {
        write_log("discoveryCheck: $line");
    }
} else {
    write_log("发现检查执行失败，错误代码: $discovery_return_var");
    foreach ($discovery_output as $line) {
        write_log("discoveryCheck error: $line");
    }
}

# 清理旧日志文件
write_log("清理旧日志文件...");
$log_files = glob("$log_dir/*.log");
if (count($log_files) > 10) {
    usort($log_files, function($a, $b) {
        return filemtime($a) - filemtime($b);
    });
    
    $files_to_delete = array_slice($log_files, 0, count($log_files) - 10);
    foreach ($files_to_delete as $file) {
        unlink($file);
        write_log("已删除旧日志文件: $file");
    }
}

write_log("自动扫描脚本执行完成");
exit(0); 