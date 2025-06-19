-- 流量监控看板系统 - 数据库优化脚本
-- 目标：大幅提升查询性能，减少页面加载时间

-- 1. 设备表优化
-- 确保设备查询快速
ALTER TABLE devices 
ADD INDEX idx_snmp_hostname (snmp_version, hostname),
ADD INDEX idx_snmp_version (snmp_version);

-- 2. 设备接口表优化  
-- 优化接口查询性能
ALTER TABLE device_interfaces 
ADD INDEX idx_device_if_name (device_id, if_name, if_index),
ADD INDEX idx_device_if_index (device_id, if_index),
ADD INDEX idx_if_name_not_null (device_id, if_name(50)) USING BTREE;

-- 3. 流量数据表优化
-- 这是最关键的优化，大幅提升流量数据查询速度
ALTER TABLE device_interface_traffic 
ADD INDEX idx_device_if_timestamp (device_id, if_index, timestamp),
ADD INDEX idx_timestamp_recent (timestamp) USING BTREE,
ADD INDEX idx_device_timestamp (device_id, timestamp);

-- 4. 创建复合索引优化常见查询
-- 针对最近1小时的数据查询优化
ALTER TABLE device_interface_traffic 
ADD INDEX idx_recent_traffic (device_id, if_index, timestamp DESC, in_octets, out_octets);

-- 5. 如果表很大，考虑分区优化（可选）
-- 按月份分区流量数据表（如果数据量大于1000万条记录）
-- ALTER TABLE device_interface_traffic 
-- PARTITION BY RANGE (YEAR(timestamp)*100 + MONTH(timestamp)) (
--     PARTITION p202401 VALUES LESS THAN (202402),
--     PARTITION p202402 VALUES LESS THAN (202403),
--     -- 继续添加分区...
--     PARTITION p_future VALUES LESS THAN MAXVALUE
-- );

-- 6. 优化配置建议
-- 在MySQL配置文件中添加以下优化参数：
-- 
-- [mysqld]
-- # 查询缓存
-- query_cache_size = 128M
-- query_cache_type = 1
-- 
-- # InnoDB优化
-- innodb_buffer_pool_size = 2G  # 根据服务器内存调整
-- innodb_log_file_size = 256M
-- innodb_flush_log_at_trx_commit = 2
-- 
-- # 连接优化
-- max_connections = 200
-- wait_timeout = 300
-- interactive_timeout = 300

-- 7. 清理优化
-- 删除过旧的流量数据（保留最近3个月）
-- DELETE FROM device_interface_traffic 
-- WHERE timestamp < DATE_SUB(NOW(), INTERVAL 3 MONTH);

-- 8. 创建性能监控视图
CREATE OR REPLACE VIEW v_traffic_monitor_stats AS
SELECT 
    COUNT(DISTINCT d.id) as total_devices,
    COUNT(DISTINCT CASE WHEN d.snmp_version > 0 THEN d.id END) as snmp_devices,
    COUNT(DISTINCT di.id) as total_interfaces,
    COUNT(DISTINCT dit.device_id) as devices_with_data,
    MIN(dit.timestamp) as earliest_data,
    MAX(dit.timestamp) as latest_data,
    COUNT(*) as total_traffic_records
FROM devices d
LEFT JOIN device_interfaces di ON d.id = di.device_id
LEFT JOIN device_interface_traffic dit ON d.id = dit.device_id;

-- 9. 创建最近活跃设备视图
CREATE OR REPLACE VIEW v_active_devices AS
SELECT 
    d.id,
    d.hostname,
    d.ip_addr,
    d.description,
    COUNT(DISTINCT di.if_index) as interface_count,
    MAX(dit.timestamp) as last_data_time,
    COUNT(dit.id) as traffic_records_count
FROM devices d
JOIN device_interfaces di ON d.id = di.device_id
LEFT JOIN device_interface_traffic dit ON d.id = dit.device_id 
    AND dit.timestamp >= DATE_SUB(NOW(), INTERVAL 1 DAY)
WHERE d.snmp_version > 0
GROUP BY d.id, d.hostname, d.ip_addr, d.description
HAVING interface_count > 0
ORDER BY last_data_time DESC, interface_count DESC;

-- 10. 分析表以更新统计信息
ANALYZE TABLE devices;
ANALYZE TABLE device_interfaces;  
ANALYZE TABLE device_interface_traffic;

-- 查看索引使用情况的查询
-- SELECT 
--     TABLE_NAME,
--     INDEX_NAME,
--     CARDINALITY,
--     NULLABLE,
--     INDEX_TYPE
-- FROM information_schema.STATISTICS 
-- WHERE TABLE_SCHEMA = DATABASE() 
-- AND TABLE_NAME IN ('devices', 'device_interfaces', 'device_interface_traffic')
-- ORDER BY TABLE_NAME, INDEX_NAME; 