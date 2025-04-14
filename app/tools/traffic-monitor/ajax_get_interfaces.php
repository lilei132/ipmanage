<?php
// 防止任何错误显示到输出中
ini_set('display_errors', 0);
error_reporting(0);

/**
 * AJAX获取设备接口列表
 */

// 设置响应类型为JSON
header('Content-Type: application/json');

try {
    # 引入页面头部和检查用户权限
    include_once("../../../functions/functions.php");

    # 用户认证
    $User = new User ($Database);
    if (!$User->check_user_session()) {
        die(json_encode(array("success" => false, "message" => _("请先登录"))));
    }

    # 检查访问权限
    if ($User->get_module_permissions ("devices") < User::ACCESS_R) {
        die(json_encode(array("success" => false, "message" => _("权限不足"))));
    }

    # 获取设备ID
    $deviceId = isset($_GET['deviceId']) ? $_GET['deviceId'] : null;

    if (!$deviceId) {
        die(json_encode(array("success" => false, "message" => _("参数不完整"))));
    }

    # 实例化Traffic类
    $Traffic = new Traffic($Database);

    # 获取设备接口列表
    $interfaces = $Traffic->get_device_interfaces($deviceId);

    if ($interfaces === false) {
        die(json_encode(array("success" => false, "message" => _("获取接口数据失败"))));
    }

    # 格式化接口数据
    $formatted_interfaces = array();
    foreach ($interfaces as $interface) {
        $formatted_interfaces[] = array(
            'if_index' => $interface->if_index,
            'if_name' => $interface->if_name,
            'if_description' => $interface->if_description,
            'speed' => $interface->speed
        );
    }

    # 返回格式化的接口数据
    echo json_encode(array(
        "success" => true,
        "interfaces" => $formatted_interfaces
    ));
} catch (Exception $e) {
    // 记录错误到日志而不是显示
    error_log("Interface data error: " . $e->getMessage());
    echo json_encode(array(
        "success" => false,
        "message" => "服务器内部错误"
    ));
} 