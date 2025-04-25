<?php
/**
 * 流量数据清理脚本
 * 
 * 用于删除错误的零值流量数据和今天下午修改前的无效数据
 */

// 设置时区和脚本执行时间限制
date_default_timezone_set('Asia/Shanghai');
set_time_limit(0);

// 错误报告设置
error_reporting(E_ALL & ~E_NOTICE);
ini_set('display_errors', 1);

// 引入phpIPAM核心函数
require_once dirname(__FILE__) . '/../functions.php';

// 初始化数据库连接
$Database = new Database_PDO;

// 记录开始时间
$start_time = microtime(true);
echo "开始清理流量数据...\n";

// 获取总记录数
$total_records_query = "SELECT COUNT(*) as total FROM port_traffic_history";
try {
    $total_result = $Database->getObjectsQuery("port_traffic_history", $total_records_query);
    $total_records = $total_result[0]->total;
    echo "总记录数: {$total_records}\n";
} catch (Exception $e) {
    echo "获取总记录数时出错: " . $e->getMessage() . "\n";
    $total_records = 0;
}

// 第1步：删除入站和出站流量都为0的记录
$zero_traffic_query = "DELETE FROM port_traffic_history WHERE in_octets = 0 AND out_octets = 0";
try {
    // 先计算满足条件的记录数
    $count_query = "SELECT COUNT(*) as total FROM port_traffic_history WHERE in_octets = 0 AND out_octets = 0";
    $count_result = $Database->getObjectsQuery("port_traffic_history", $count_query);
    $zero_deleted = $count_result[0]->total;
    
    // 然后删除
    $Database->runQuery($zero_traffic_query);
    echo "删除零流量记录: {$zero_deleted} 条记录\n";
} catch (Exception $e) {
    echo "删除零流量记录时出错: " . $e->getMessage() . "\n";
}

// 第2步：删除重复记录，保留每个设备-接口-时间戳组合中流量最高的记录
echo "开始删除重复记录...\n";

try {
    // 先计算重复记录数
    $count_duplicates_query = "
        SELECT COUNT(*) as total
        FROM (
            SELECT COUNT(*) as cnt
            FROM port_traffic_history
            GROUP BY device_id, if_index, timestamp
            HAVING COUNT(*) > 1
        ) as t";
    $count_dupes_result = $Database->getObjectsQuery("port_traffic_history", $count_duplicates_query);
    $dupes_count = $count_dupes_result[0]->total;
    echo "发现重复记录组: {$dupes_count} 组\n";
    
    // 找出重复记录中要保留的ID
    $find_duplicates_query = "
        CREATE TEMPORARY TABLE IF NOT EXISTS tmp_traffic_duplicates AS
        SELECT 
            (SELECT id FROM port_traffic_history th2 
             WHERE th2.device_id = th1.device_id 
             AND th2.if_index = th1.if_index 
             AND th2.timestamp = th1.timestamp 
             ORDER BY (th2.in_octets + th2.out_octets) DESC, id DESC 
             LIMIT 1) as keep_id
        FROM (
            SELECT DISTINCT device_id, if_index, timestamp
            FROM port_traffic_history
            WHERE (device_id, if_index, timestamp) IN (
                SELECT device_id, if_index, timestamp
                FROM port_traffic_history
                GROUP BY device_id, if_index, timestamp
                HAVING COUNT(*) > 1
            )
        ) as th1
    ";
    
    $Database->runQuery($find_duplicates_query);
    
    // 统计要删除的记录数
    $count_to_delete_query = "
        SELECT COUNT(*) as total
        FROM port_traffic_history th
        JOIN (
            SELECT device_id, if_index, timestamp
            FROM port_traffic_history
            GROUP BY device_id, if_index, timestamp
            HAVING COUNT(*) > 1
        ) as dupes
        ON th.device_id = dupes.device_id 
        AND th.if_index = dupes.if_index 
        AND th.timestamp = dupes.timestamp
        LEFT JOIN tmp_traffic_duplicates td
        ON th.id = td.keep_id
        WHERE td.keep_id IS NULL
    ";
    
    $count_delete_result = $Database->getObjectsQuery("port_traffic_history", $count_to_delete_query);
    $dupes_deleted = $count_delete_result[0]->total;
    
    // 删除重复记录，保留选定的ID
    $delete_duplicates_query = "
        DELETE p 
        FROM port_traffic_history p
        JOIN (
            SELECT th.id
            FROM port_traffic_history th
            JOIN (
                SELECT device_id, if_index, timestamp
                FROM port_traffic_history
                GROUP BY device_id, if_index, timestamp
                HAVING COUNT(*) > 1
            ) as dupes
            ON th.device_id = dupes.device_id 
            AND th.if_index = dupes.if_index 
            AND th.timestamp = dupes.timestamp
            LEFT JOIN tmp_traffic_duplicates td
            ON th.id = td.keep_id
            WHERE td.keep_id IS NULL
        ) as to_delete
        ON p.id = to_delete.id
    ";
    
    $Database->runQuery($delete_duplicates_query);
    echo "删除重复记录: {$dupes_deleted} 条记录\n";
    
    // 删除临时表
    $Database->runQuery("DROP TEMPORARY TABLE IF EXISTS tmp_traffic_duplicates");
} catch (Exception $e) {
    echo "删除重复记录时出错: " . $e->getMessage() . "\n";
}

// 第3步：删除明显异常的数据（如极大值）
try {
    // 先计算满足条件的记录数
    $count_abnormal_query = "SELECT COUNT(*) as total FROM port_traffic_history WHERE in_octets > 1000000000000000 OR out_octets > 1000000000000000";
    $count_abnormal_result = $Database->getObjectsQuery("port_traffic_history", $count_abnormal_query);
    $abnormal_deleted = $count_abnormal_result[0]->total;
    
    // 然后删除
    $abnormal_query = "DELETE FROM port_traffic_history WHERE in_octets > 1000000000000000 OR out_octets > 1000000000000000";
    $Database->runQuery($abnormal_query);
    echo "删除异常大值记录: {$abnormal_deleted} 条记录\n";
} catch (Exception $e) {
    echo "删除异常大值记录时出错: " . $e->getMessage() . "\n";
}

// 第4步：获取清理后的总记录数
$remaining_records_query = "SELECT COUNT(*) as total FROM port_traffic_history";
try {
    $remaining_result = $Database->getObjectsQuery("port_traffic_history", $remaining_records_query);
    $remaining_records = $remaining_result[0]->total;
    $total_deleted = $total_records - $remaining_records;

    echo "\n清理完成!\n";
    echo "总删除记录数: {$total_deleted}\n";
    echo "剩余记录数: {$remaining_records}\n";
    echo "执行时间: " . round(microtime(true) - $start_time, 2) . " 秒\n";
} catch (Exception $e) {
    echo "获取剩余记录数时出错: " . $e->getMessage() . "\n";
}

// 第5步：优化表
echo "正在优化数据表...\n";
try {
    $Database->runQuery("OPTIMIZE TABLE port_traffic_history");
    echo "表优化完成!\n";
} catch (Exception $e) {
    echo "表优化失败: " . $e->getMessage() . "\n";
}

exit(0); 