<?php
/**
 * 流量监控页面 - 看板系统
 * 
 * 注意：这个文件被主phpIPAM系统包含，无需重新初始化对象
 */

# 验证用户权限
$User->check_user_session();
if ($User->get_module_permissions("devices") < 1) {
    $Result->show("danger", _("无权访问此页面"), true);
    die();
}

# 获取参数
$subpage = isset($GET->subPage) ? $GET->subPage : '';
$dashboard_id = isset($GET->dashboard_id) ? intval($GET->dashboard_id) : 0;
$action = isset($GET->action) ? $GET->action : '';

# API请求处理
if ($action == 'api') {
    # 设置JSON输出头
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, must-revalidate');
    
    include dirname(__FILE__) . '/dashboard_api.php';
    exit;
}

# 路由处理
if ($subpage == 'dashboards' || empty($subpage)) {
    # 显示看板列表页面（第一级）
    
    # 获取看板数据
    $dashboards = array();
    $dashboard_cards_count = array();
    
    try {
        # 使用 phpIPAM 的正确方法获取看板数据
        $dashboards = $Database->getObjectsQuery("traffic_dashboards", "SELECT * FROM traffic_dashboards WHERE is_active = 1 ORDER BY sort_order ASC, name ASC");
        
        if ($dashboards && is_array($dashboards)) {
            foreach ($dashboards as $dashboard) {
                # 获取每个看板的卡片数量
                $card_count_result = $Database->getObjectQuery("traffic_dashboard_cards", "SELECT COUNT(*) as count FROM traffic_dashboard_cards WHERE dashboard_id = ? AND is_active = 1", array($dashboard->id));
                $dashboard_cards_count[$dashboard->id] = $card_count_result ? $card_count_result->count : 0;
            }
        } else {
            $dashboards = array();
        }
    } catch (Exception $e) {
        # 如果查询失败，使用默认数据
        $dashboards = array();
        
        $default_dashboard = new stdClass();
        $default_dashboard->id = 1;
        $default_dashboard->name = "核心出口端口";
        $default_dashboard->description = "监控校园网出口端口流量，包括运营商线路和专线";
        $default_dashboard->updated_at = date('Y-m-d H:i:s');
        $dashboards[] = $default_dashboard;
        
        $default_dashboard2 = new stdClass();
        $default_dashboard2->id = 2;
        $default_dashboard2->name = "区域核心端口";
        $default_dashboard2->description = "监控各区域核心交换机上行端口流量";
        $default_dashboard2->updated_at = date('Y-m-d H:i:s');
        $dashboards[] = $default_dashboard2;
        
        $dashboard_cards_count[1] = 0;
        $dashboard_cards_count[2] = 0;
    }
    
    # 包含看板列表页面
    include dirname(__FILE__) . '/dashboards.php';
    
} elseif ($subpage == 'dashboard-view' && $dashboard_id > 0) {
    # 显示看板详情页面（第二级）
    
    # 获取看板信息
    try {
        $dashboard = $Database->getObjectQuery("traffic_dashboards", "SELECT * FROM traffic_dashboards WHERE id = ? AND is_active = 1", array($dashboard_id));
        if ($dashboard) {
            # 获取看板的卡片
            $cards = $Database->getObjectsQuery("traffic_dashboard_cards", "SELECT * FROM traffic_dashboard_cards WHERE dashboard_id = ? AND is_active = 1 ORDER BY position_y ASC, position_x ASC", array($dashboard_id));
            if (!$cards) {
                $cards = array();
            }
            
            # 包含看板详情页面
            include dirname(__FILE__) . '/dashboard-view.php';
        } else {
            $Result->show("danger", _("看板不存在"), true);
        }
    } catch (Exception $e) {
        $Result->show("danger", _("加载看板失败: ") . $e->getMessage(), true);
    }
    
} elseif ($subpage == 'debug') {
    # 显示调试页面
    include dirname(__FILE__) . '/debug-test.php';
    
} else {
    # 默认显示看板列表
    header("Location: " . create_link('tools', 'traffic-monitor') . "&subPage=dashboards");
    exit;
}
?> 