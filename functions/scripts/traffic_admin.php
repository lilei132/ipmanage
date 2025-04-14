<?php
/**
 * phpIPAM 流量采集管理脚本
 * 
 * 功能：
 * - 查看当前流量采集配置
 * - 修改采集时间间隔
 * - 调整历史数据保留时间
 * - 立即执行采集测试
 */

// 设置错误报告
error_reporting(E_ALL & ~E_NOTICE);
ini_set('display_errors', 1);

// 设置时区
date_default_timezone_set('Asia/Shanghai');

// 引入phpIPAM核心函数
require_once dirname(__FILE__) . '/../functions.php';

// 加载配置文件
require_once dirname(__FILE__) . '/traffic_config.php';

// 初始化数据库连接
$database = new Database_PDO;

// 处理POST请求 - 更新配置
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_settings') {
        // 修改采集间隔
        if (isset($_POST['collection_interval']) && is_numeric($_POST['collection_interval'])) {
            $interval = intval($_POST['collection_interval']);
            if ($interval >= 1) {
                set_traffic_config('collection_interval', $interval);
                $message = "采集间隔已更新为 {$interval} 分钟";
            }
        }
        
        // 修改数据保留时间
        if (isset($_POST['data_retention_days']) && is_numeric($_POST['data_retention_days'])) {
            $days = intval($_POST['data_retention_days']);
            if ($days >= 1) {
                set_traffic_config('data_retention_days', $days);
                $message .= "，数据保留时间已更新为 {$days} 天";
            }
        }
        
        // 保存配置到数据库
        if (save_traffic_config_to_db($database)) {
            $success = true;
            $message .= "。设置已成功保存到数据库。";
        } else {
            $success = false;
            $message = "保存配置到数据库失败，请检查数据库连接。";
        }
    } elseif ($action === 'run_collector') {
        // 立即执行采集测试
        $output = [];
        $return_var = 0;
        exec('php ' . dirname(__FILE__) . '/traffic_collector.php 2>&1', $output, $return_var);
        
        if ($return_var === 0) {
            $success = true;
            $message = "流量采集脚本已成功执行。";
            $collector_output = implode("\n", $output);
        } else {
            $success = false;
            $message = "执行流量采集脚本失败，错误代码: {$return_var}";
            $collector_output = implode("\n", $output);
        }
    }
}

// 获取当前配置
$collection_interval = get_traffic_config('collection_interval', 5);
$data_retention_days = get_traffic_config('data_retention_days', 30);
$verbose_logging = get_traffic_config('verbose_logging', true);
$log_level = get_traffic_config('log_level', 3);

// 获取cron设置
$cron_output = [];
exec('crontab -l | grep traffic_collector.php', $cron_output);
$cron_setting = !empty($cron_output) ? implode("\n", $cron_output) : "未找到cron设置，请手动添加: */5 * * * * php /var/www/html/functions/scripts/traffic_collector.php";

// 获取最近采集记录
$query1 = "SELECT device_id, timestamp, COUNT(*) as count 
     FROM port_traffic_history 
     GROUP BY device_id, timestamp 
     ORDER BY timestamp DESC LIMIT 5";

if (!empty($query1)) {
    $latest_records = $database->getObjectsQuery($query1);
} else {
    $latest_records = [];
}

// 获取存储统计信息
$query2 = "SELECT COUNT(*) as total_records, 
        MIN(timestamp) as oldest_record, 
        MAX(timestamp) as newest_record,
        COUNT(DISTINCT device_id) as device_count
 FROM port_traffic_history";

if (!empty($query2)) {
    $stats = $database->getObjectsQuery($query2);
    $storage_stats = !empty($stats) ? $stats[0] : null;
} else {
    $storage_stats = null;
}

// HTML输出
?>
<!DOCTYPE html>
<html>
<head>
    <title>phpIPAM 流量采集管理</title>
    <meta charset="utf-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; margin: 0; padding: 20px; color: #333; }
        .container { max-width: 1200px; margin: 0 auto; }
        h1 { color: #2c3e50; border-bottom: 1px solid #eee; padding-bottom: 10px; }
        h2 { color: #3498db; margin-top: 30px; }
        .card { background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px; padding: 15px; margin-bottom: 20px; }
        .success { background-color: #dff0d8; border-color: #d6e9c6; color: #3c763d; }
        .error { background-color: #f2dede; border-color: #ebccd1; color: #a94442; }
        label { display: block; margin: 10px 0 5px; font-weight: bold; }
        input[type="text"], input[type="number"] { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
        button { background: #3498db; color: white; border: none; padding: 10px 15px; border-radius: 4px; cursor: pointer; margin-top: 10px; }
        button:hover { background: #2980b9; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        table, th, td { border: 1px solid #ddd; }
        th, td { padding: 10px; text-align: left; }
        th { background-color: #f2f2f2; }
        pre { background: #f5f5f5; padding: 10px; border-radius: 4px; overflow: auto; }
    </style>
</head>
<body>
    <div class="container">
        <h1>phpIPAM 流量采集管理</h1>
        
        <?php if (isset($message)): ?>
            <div class="card <?php echo isset($success) && $success ? 'success' : 'error'; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        
        <div class="card">
            <h2>当前配置</h2>
            <form method="post" action="">
                <input type="hidden" name="action" value="update_settings">
                
                <label for="collection_interval">采集时间间隔 (分钟):</label>
                <input type="number" id="collection_interval" name="collection_interval" value="<?php echo $collection_interval; ?>" min="1">
                
                <label for="data_retention_days">数据保留时间 (天):</label>
                <input type="number" id="data_retention_days" name="data_retention_days" value="<?php echo $data_retention_days; ?>" min="1">
                
                <button type="submit">保存设置</button>
            </form>
        </div>
        
        <div class="card">
            <h2>Cron 任务设置</h2>
            <pre><?php echo htmlspecialchars($cron_setting); ?></pre>
            <p>注意: 修改采集间隔后，需要同时更新cron任务中的时间间隔。</p>
        </div>
        
        <div class="card">
            <h2>存储统计</h2>
            <?php if ($storage_stats): ?>
            <table>
                <tr>
                    <th>总记录数</th>
                    <th>设备数量</th>
                    <th>最早记录</th>
                    <th>最新记录</th>
                </tr>
                <tr>
                    <td><?php echo number_format($storage_stats->total_records); ?></td>
                    <td><?php echo $storage_stats->device_count; ?></td>
                    <td><?php echo $storage_stats->oldest_record; ?></td>
                    <td><?php echo $storage_stats->newest_record; ?></td>
                </tr>
            </table>
            <?php else: ?>
            <p>未找到任何流量记录。</p>
            <?php endif; ?>
        </div>
        
        <div class="card">
            <h2>最近采集记录</h2>
            <?php if (!empty($latest_records)): ?>
            <table>
                <tr>
                    <th>设备ID</th>
                    <th>采集时间</th>
                    <th>接口数量</th>
                </tr>
                <?php foreach ($latest_records as $record): ?>
                <tr>
                    <td><?php echo $record->device_id; ?></td>
                    <td><?php echo $record->timestamp; ?></td>
                    <td><?php echo $record->count; ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php else: ?>
            <p>未找到任何采集记录。</p>
            <?php endif; ?>
        </div>
        
        <div class="card">
            <h2>手动执行采集</h2>
            <form method="post" action="">
                <input type="hidden" name="action" value="run_collector">
                <p>点击下面的按钮立即运行流量采集脚本：</p>
                <button type="submit">立即执行采集</button>
            </form>
            
            <?php if (isset($collector_output)): ?>
            <h3>执行结果:</h3>
            <pre><?php echo htmlspecialchars($collector_output); ?></pre>
            <?php endif; ?>
        </div>
    </div>
</body>
</html> 