<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html><html><head><title>流量监控调试</title></head><body>";
echo "<h1>流量监控调试页面</h1>";

try {
    # 引入函数文件
    require_once dirname(__FILE__) . "/functions/functions.php";
    echo "<p>✓ 函数文件加载成功</p>";
    
    # 检查用户会话
    echo "<p>用户ID: " . ($User->user->id ?? '未登录') . "</p>";
    echo "<p>用户名: " . ($User->user->real_name ?? '未知') . "</p>";
    
    # 模拟$GET对象
    $GET = new stdClass();
    if (isset($_GET['subPage'])) $GET->subPage = $_GET['subPage'];
    if (isset($_GET['dashboard_id'])) $GET->dashboard_id = $_GET['dashboard_id'];
    
    # 检查路由参数
    $subpage = isset($GET->subPage) ? $GET->subPage : '';
    $dashboard_id = isset($GET->dashboard_id) ? intval($GET->dashboard_id) : 0;
    
    echo "<p>subPage: '" . htmlspecialchars($subpage) . "'</p>";
    echo "<p>dashboard_id: " . $dashboard_id . "</p>";
    
    # 检查数据库连接和表
    $sql = "SELECT COUNT(*) as count FROM traffic_dashboards WHERE is_active = 1";
    $result = $Database->getObjectQuery($sql);
    echo "<p>✓ 数据库连接成功，找到 " . $result->count . " 个活跃看板</p>";
    
    # 获取看板列表
    $sql = "SELECT * FROM traffic_dashboards WHERE is_active = 1 ORDER BY sort_order ASC, name ASC";
    $dashboards = $Database->getObjectsQuery($sql);
    
    echo "<h2>看板数据:</h2>";
    if ($dashboards && count($dashboards) > 0) {
        echo "<table border='1' style='border-collapse: collapse;'>";
        echo "<tr><th>ID</th><th>名称</th><th>描述</th><th>创建时间</th></tr>";
        foreach ($dashboards as $dashboard) {
            echo "<tr>";
            echo "<td>" . $dashboard->id . "</td>";
            echo "<td>" . htmlspecialchars($dashboard->name) . "</td>";
            echo "<td>" . htmlspecialchars($dashboard->description) . "</td>";
            echo "<td>" . $dashboard->created_at . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>没有找到看板数据</p>";
    }
    
    # 测试包含看板文件
    echo "<h2>测试包含看板文件:</h2>";
    
    if ($subpage == 'dashboards' || (empty($subpage) && !isset($_GET['getTrafficData']))) {
        echo "<p>应该显示看板列表页面</p>";
        
        if (file_exists(dirname(__FILE__) . '/app/tools/traffic-monitor/dashboards.php')) {
            echo "<p>✓ dashboards.php 文件存在</p>";
            
            # 模拟包含dashboards.php
            ob_start();
            try {
                include dirname(__FILE__) . '/app/tools/traffic-monitor/dashboards.php';
                $output = ob_get_contents();
                echo "<p>✓ dashboards.php 包含成功</p>";
                echo "<h3>包含输出:</h3>";
                echo "<pre>" . htmlspecialchars(substr($output, 0, 500)) . "...</pre>";
            } catch (Exception $e) {
                echo "<p>✗ dashboards.php 包含失败: " . $e->getMessage() . "</p>";
            }
            ob_end_clean();
        } else {
            echo "<p>✗ dashboards.php 文件不存在</p>";
        }
    }
    
    echo "<p>✓ 调试完成</p>";
    
} catch (Exception $e) {
    echo "<p style='color:red'>✗ 错误: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>文件: " . htmlspecialchars($e->getFile()) . "</p>";
    echo "<p>行号: " . $e->getLine() . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

echo "</body></html>";
?> 