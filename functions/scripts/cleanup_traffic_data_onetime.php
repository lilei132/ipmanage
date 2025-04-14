#!/usr/bin/php
<?php

/* 
 * 一次性脚本：删除4月8日前的所有端口流量数据
 *
 * 执行方法：php cleanup_traffic_data_onetime.php
 */

// 脚本可以直接从命令行运行
if(!isset($_SERVER['HTTP_HOST'])) { $_SERVER['HTTP_HOST'] = "localhost"; }

// 引入必要的类和函数
require_once(dirname(__FILE__) . '/../../functions/functions.php');

// 初始化类
$Database = new Database_PDO;
$Result = new Result;

// 设置删除日期边界：2023年4月8日
$delete_before_date = "2023-04-08 00:00:00";

// 先计算将要删除的记录数
$count_query = "SELECT COUNT(*) as count FROM `port_traffic_history` WHERE `timestamp` < ?";
$count = $Database->getObjectQuery($count_query, array($delete_before_date));

if ($count === false) {
    print_r("Error: Unable to count records.\n");
    exit(1);
}

$total_records = $count->count;
print_r("将删除 $total_records 条4月8日前的流量记录。\n");

// 执行删除操作
$delete_query = "DELETE FROM `port_traffic_history` WHERE `timestamp` < ?";
try {
    $Database->runQuery($delete_query, array($delete_before_date));
    print_r("成功清理了 $total_records 条历史流量数据。\n");
} 
catch (Exception $e) {
    print_r("删除操作失败: " . $e->getMessage() . "\n");
    exit(1);
}

print_r("清理操作完成。\n");
exit(0); 