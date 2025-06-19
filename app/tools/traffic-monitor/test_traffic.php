<?php
/**
 * 流量数据获取测试脚本
 */

# 引入函数文件
require_once dirname(__FILE__) . "/../../../functions/functions.php";

# 设置错误报告
ini_set('display_errors', 1);
error_reporting(E_ALL);

# 设置响应头
header('Content-Type: application/json');

try {
    echo json_encode([
        'success' => true,
        'message' => '测试脚本运行成功',
        'php_version' => phpversion(),
        'database_exists' => isset($Database) ? 'yes' : 'no',
        'traffic_class_exists' => class_exists('Traffic') ? 'yes' : 'no',
        'user_logged_in' => isset($User) ? 'yes' : 'no'
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}
?> 