<?php
/**
 * 流量监控看板系统 - 高性能版本
 * 优化：数据库获取接口信息，异步加载，避免SNMP扫描
 */

require_once dirname(__FILE__) . "/../../../functions/functions.php";

# 确认用户登录
$User->check_user_session();

# 检查用户权限
if ($User->get_module_permissions("devices") < User::ACCESS_R) {
    $Result->show("danger", _("无权访问此页面"), true);
    die();
}

# 快速获取设备列表 - 只获取基本信息，不进行SNMP操作
$devices_query = "SELECT id, hostname, ip_addr, description, snmp_version FROM devices WHERE snmp_version != 0 ORDER BY hostname";
$devices = $Database->getObjects($devices_query);

# 处理AJAX请求
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, must-revalidate');
    
    switch ($_GET['action']) {
        case 'get_interfaces':
            $deviceId = intval($_GET['deviceId'] ?? 0);
            if (!$deviceId) {
                echo json_encode(['success' => false, 'error' => '设备ID无效']);
                exit;
            }
            
            try {
                // 从数据库获取接口信息，避免SNMP扫描
                $interfaces_query = "SELECT if_index, if_name, if_description, if_alias, speed, if_oper_status 
                                   FROM device_interfaces 
                                   WHERE device_id = ? 
                                   AND if_name IS NOT NULL 
                                   ORDER BY if_index";
                
                $interfaces = $Database->getObjects($interfaces_query, array($deviceId));
                
                if ($interfaces) {
                    $formatted = [];
                    foreach ($interfaces as $interface) {
                        $name = $interface->if_name ?: "Interface " . $interface->if_index;
                        $desc = $interface->if_description ?: $interface->if_alias ?: '';
                        
                        $formatted[] = [
                            'if_index' => $interface->if_index,
                            'if_name' => $name,
                            'if_description' => $desc,
                            'speed' => $interface->speed ?: 0,
                            'status' => $interface->if_oper_status ?: 'unknown'
                        ];
                    }
                    echo json_encode(['success' => true, 'interfaces' => $formatted]);
                } else {
                    echo json_encode(['success' => false, 'error' => '未找到接口数据或设备尚未扫描']);
                }
            } catch (Exception $e) {
                error_log("获取接口数据错误: " . $e->getMessage());
                echo json_encode(['success' => false, 'error' => '数据库查询失败']);
            }
            exit;
            
        case 'get_traffic_data':
            $deviceId = intval($_GET['deviceId'] ?? 0);
            $interfaceId = intval($_GET['interfaceId'] ?? 0);
            $timespan = $_GET['timespan'] ?? '1d';
            
            if (!$deviceId || !$interfaceId) {
                echo json_encode(['success' => false, 'error' => '参数无效']);
                exit;
            }
            
            try {
                // 从流量数据表获取历史数据
                $time_condition = '';
                switch ($timespan) {
                    case '1h':
                        $time_condition = "AND timestamp >= DATE_SUB(NOW(), INTERVAL 1 HOUR)";
                        break;
                    case '6h':
                        $time_condition = "AND timestamp >= DATE_SUB(NOW(), INTERVAL 6 HOUR)";
                        break;
                    case '1d':
                        $time_condition = "AND timestamp >= DATE_SUB(NOW(), INTERVAL 1 DAY)";
                        break;
                    case '7d':
                        $time_condition = "AND timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
                        break;
                    default:
                        $time_condition = "AND timestamp >= DATE_SUB(NOW(), INTERVAL 1 DAY)";
                }
                
                $traffic_query = "SELECT UNIX_TIMESTAMP(timestamp) as timestamp, in_octets, out_octets 
                                FROM device_interface_traffic 
                                WHERE device_id = ? AND if_index = ? 
                                $time_condition 
                                ORDER BY timestamp";
                
                $traffic_data = $Database->getObjects($traffic_query, array($deviceId, $interfaceId));
                
                if ($traffic_data && count($traffic_data) > 0) {
                    $in_data = [];
                    $out_data = [];
                    
                    foreach ($traffic_data as $point) {
                        $timestamp = $point->timestamp * 1000; // JS时间戳
                        $in_data[] = [$timestamp, floatval($point->in_octets) * 8]; // 转换为bits
                        $out_data[] = [$timestamp, floatval($point->out_octets) * 8];
                    }
                    
                    echo json_encode([
                        'success' => true,
                        'in_data' => $in_data,
                        'out_data' => $out_data,
                        'points_count' => count($traffic_data)
                    ]);
                } else {
                    echo json_encode(['success' => false, 'error' => '暂无流量数据']);
                }
            } catch (Exception $e) {
                error_log("获取流量数据错误: " . $e->getMessage());
                echo json_encode(['success' => false, 'error' => '数据查询失败']);
            }
            exit;
    }
    
    echo json_encode(['success' => false, 'error' => '未知操作']);
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>流量监控看板</title>
    <style>
        /* 现代化样式 */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: #333;
        }
        
        .dashboard-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem 1rem;
        }
        
        .header {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            padding: 2rem;
            text-align: center;
            color: white;
            margin-bottom: 2rem;
        }
        
        .header h1 {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .header p {
            font-size: 1.1rem;
            opacity: 0.9;
        }
        
        .main-content {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #f0f2f5;
        }
        
        .section-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: #2c3e50;
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.9rem;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
        }
        
        .btn-outline {
            background: transparent;
            border: 2px solid #667eea;
            color: #667eea;
        }
        
        .btn-outline:hover {
            background: #667eea;
            color: white;
        }
        
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 1.5rem;
        }
        
        .dashboard-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            border: 2px solid transparent;
            transition: all 0.3s ease;
            cursor: pointer;
            position: relative;
        }
        
        .dashboard-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.15);
            border-color: #667eea;
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }
        
        .card-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #2c3e50;
            margin: 0;
        }
        
        .card-description {
            color: #6c757d;
            margin: 0.5rem 0 1.5rem;
            font-size: 0.9rem;
            line-height: 1.5;
        }
        
        .card-stats {
            display: flex;
            justify-content: space-between;
            font-size: 0.875rem;
            color: #6c757d;
        }
        
        .add-dashboard-card {
            border: 2px dashed #667eea;
            background: linear-gradient(135deg, #f8f9ff 0%, #e3e7ff 100%);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            min-height: 200px;
            color: #667eea;
        }
        
        .add-dashboard-card:hover {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-color: transparent;
        }
        
        .add-icon {
            font-size: 2.5rem;
            margin-bottom: 1rem;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            z-index: 1000;
            backdrop-filter: blur(4px);
        }
        
        .modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .modal-content {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #e9ecef;
        }
        
        .modal-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin: 0;
        }
        
        .close-btn {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #6c757d;
            padding: 0;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.3s ease;
        }
        
        .close-btn:hover {
            background: #f8f9fa;
            color: #495057;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #2c3e50;
        }
        
        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 0.9rem;
            transition: border-color 0.3s ease;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .modal-footer {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
            padding-top: 1rem;
            border-top: 1px solid #e9ecef;
        }
        
        .loading {
            text-align: center;
            padding: 2rem;
            color: #6c757d;
        }
        
        .loading i {
            font-size: 2rem;
            margin-bottom: 1rem;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 1rem 1.5rem;
            border-radius: 8px;
            color: white;
            font-weight: 500;
            z-index: 2000;
            animation: slideIn 0.3s ease;
        }
        
        .notification.success {
            background: #28a745;
        }
        
        .notification.error {
            background: #dc3545;
        }
        
        .notification.info {
            background: #17a2b8;
        }
        
        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        @keyframes slideOut {
            from {
                transform: translateX(0);
                opacity: 1;
            }
            to {
                transform: translateX(100%);
                opacity: 0;
            }
        }
        
        /* 响应式设计 */
        @media (max-width: 768px) {
            .dashboard-container {
                padding: 1rem;
            }
            
            .header h1 {
                font-size: 2rem;
            }
            
            .section-header {
                flex-direction: column;
                gap: 1rem;
                align-items: stretch;
            }
            
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- 顶部标题 -->
        <div class="header">
            <h1><i class="fa fa-tachometer-alt"></i> 流量监控看板</h1>
            <p>高性能网络流量监控与分析平台</p>
        </div>

        <!-- 主要内容 -->
        <div class="main-content">
            <div class="section-header">
                <h2 class="section-title">我的监控看板</h2>
                <button class="btn btn-primary" onclick="showCreateModal()">
                    <i class="fa fa-plus"></i>
                    创建看板
                </button>
            </div>

            <div class="dashboard-grid" id="dashboard-grid">
                <div class="loading">
                    <i class="fa fa-spinner"></i>
                    <p>正在加载看板...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- 创建看板模态框 -->
    <div id="create-modal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">创建新看板</h3>
                <button class="close-btn" onclick="hideCreateModal()">&times;</button>
            </div>
            
            <form id="create-form" onsubmit="createDashboard(event)">
                <div class="form-group">
                    <label class="form-label">看板名称 *</label>
                    <input type="text" class="form-control" id="dashboard-name" 
                           placeholder="例如：核心网监控、办公区网络" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">看板描述</label>
                    <textarea class="form-control" id="dashboard-desc" rows="3" 
                              placeholder="简要描述此看板的用途和监控范围"></textarea>
                </div>
            </form>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="hideCreateModal()">取消</button>
                <button type="button" class="btn btn-primary" onclick="createDashboard()">
                    <i class="fa fa-plus"></i> 创建看板
                </button>
            </div>
        </div>
    </div>

    <!-- 添加监控模态框 -->
    <div id="add-monitor-modal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">添加监控项目</h3>
                <button class="close-btn" onclick="hideAddMonitorModal()">&times;</button>
            </div>
            
            <form id="monitor-form">
                <div class="form-group">
                    <label class="form-label">选择设备 *</label>
                    <select class="form-control" id="device-select" onchange="loadInterfaces()" required>
                        <option value="">请选择设备...</option>
                        <?php foreach ($devices as $device): ?>
                        <option value="<?php echo $device->id; ?>">
                            <?php echo htmlspecialchars($device->hostname); ?>
                            (<?php echo htmlspecialchars($device->ip_addr); ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">选择接口 *</label>
                    <select class="form-control" id="interface-select" required>
                        <option value="">请先选择设备...</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">监控名称</label>
                    <input type="text" class="form-control" id="monitor-name" 
                           placeholder="自定义监控项目名称（可选）">
                </div>
                
                <div class="form-group">
                    <label class="form-label">默认时间范围</label>
                    <select class="form-control" id="timespan-select">
                        <option value="1h">1小时</option>
                        <option value="6h">6小时</option>
                        <option value="1d" selected>1天</option>
                        <option value="7d">7天</option>
                    </select>
                </div>
            </form>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="hideAddMonitorModal()">取消</button>
                <button type="button" class="btn btn-primary" onclick="addMonitor()">
                    <i class="fa fa-plus"></i> 添加监控
                </button>
            </div>
        </div>
    </div>

    <script>
        // 全局变量
        let dashboards = [];
        let currentDashboard = null;

        // 页面初始化
        document.addEventListener('DOMContentLoaded', function() {
            loadDashboards();
        });

        // 加载看板数据
        function loadDashboards() {
            const saved = localStorage.getItem('traffic_dashboards');
            if (saved) {
                try {
                    dashboards = JSON.parse(saved);
                } catch (e) {
                    console.error('解析看板数据失败:', e);
                    dashboards = [];
                }
            }
            
            // 如果没有看板，创建默认看板
            if (dashboards.length === 0) {
                dashboards = [{
                    id: 'default-' + Date.now(),
                    name: '默认监控看板',
                    description: '系统默认创建的监控看板',
                    monitors: [],
                    created: new Date().toISOString()
                }];
                saveDashboards();
            }
            
            renderDashboards();
        }

        // 保存看板数据
        function saveDashboards() {
            try {
                localStorage.setItem('traffic_dashboards', JSON.stringify(dashboards));
            } catch (e) {
                console.error('保存看板数据失败:', e);
                showNotification('保存失败：存储空间不足', 'error');
            }
        }

        // 渲染看板列表
        function renderDashboards() {
            const grid = document.getElementById('dashboard-grid');
            grid.innerHTML = '';

            // 渲染现有看板
            dashboards.forEach(dashboard => {
                const card = document.createElement('div');
                card.className = 'dashboard-card';
                card.onclick = () => openDashboard(dashboard.id);
                
                card.innerHTML = `
                    <div class="card-header">
                        <h3 class="card-title">${escapeHtml(dashboard.name)}</h3>
                        <button class="btn btn-outline" onclick="event.stopPropagation(); deleteDashboard('${dashboard.id}')" 
                                style="padding: 0.25rem 0.5rem; font-size: 0.75rem;" title="删除看板">
                            <i class="fa fa-trash"></i>
                        </button>
                    </div>
                    <p class="card-description">${escapeHtml(dashboard.description || '暂无描述')}</p>
                    <div class="card-stats">
                        <span><i class="fa fa-chart-line"></i> ${dashboard.monitors ? dashboard.monitors.length : 0} 个监控</span>
                        <span><i class="fa fa-clock"></i> ${formatDate(dashboard.created)}</span>
                    </div>
                `;
                grid.appendChild(card);
            });

            // 添加创建看板卡片
            const addCard = document.createElement('div');
            addCard.className = 'dashboard-card add-dashboard-card';
            addCard.onclick = showCreateModal;
            addCard.innerHTML = `
                <div class="add-icon">
                    <i class="fa fa-plus"></i>
                </div>
                <h3 style="margin: 0 0 0.5rem 0;">创建新看板</h3>
                <p style="margin: 0; opacity: 0.8;">点击这里创建一个新的监控看板</p>
            `;
            grid.appendChild(addCard);
        }

        // 打开看板详情
        function openDashboard(dashboardId) {
            showNotification('看板详情功能开发中...', 'info');
            // TODO: 实现看板详情页面
        }

        // 格式化日期
        function formatDate(dateString) {
            const date = new Date(dateString);
            const now = new Date();
            const diffMs = now - date;
            const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24));
            
            if (diffDays === 0) return '今天创建';
            if (diffDays === 1) return '昨天创建';
            if (diffDays < 7) return `${diffDays}天前创建`;
            if (diffDays < 30) return `${Math.floor(diffDays / 7)}周前创建`;
            return date.toLocaleDateString('zh-CN');
        }

        // HTML转义
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text || '';
            return div.innerHTML;
        }

        // 显示创建模态框
        function showCreateModal() {
            document.getElementById('create-modal').classList.add('show');
            document.getElementById('dashboard-name').focus();
        }

        // 隐藏创建模态框
        function hideCreateModal() {
            document.getElementById('create-modal').classList.remove('show');
            document.getElementById('dashboard-name').value = '';
            document.getElementById('dashboard-desc').value = '';
        }

        // 创建看板
        function createDashboard(event) {
            if (event) event.preventDefault();
            
            const name = document.getElementById('dashboard-name').value.trim();
            const description = document.getElementById('dashboard-desc').value.trim();

            if (!name) {
                showNotification('请输入看板名称', 'error');
                document.getElementById('dashboard-name').focus();
                return;
            }

            // 检查名称是否重复
            if (dashboards.some(d => d.name === name)) {
                showNotification('看板名称已存在，请使用其他名称', 'error');
                document.getElementById('dashboard-name').focus();
                return;
            }

            const newDashboard = {
                id: 'dashboard-' + Date.now(),
                name: name,
                description: description,
                monitors: [],
                created: new Date().toISOString()
            };

            dashboards.push(newDashboard);
            saveDashboards();
            hideCreateModal();
            renderDashboards();
            
            showNotification('看板创建成功！', 'success');
        }

        // 删除看板
        function deleteDashboard(dashboardId) {
            const dashboard = dashboards.find(d => d.id === dashboardId);
            if (!dashboard) return;

            if (!confirm(`确定要删除看板"${dashboard.name}"吗？\n此操作不可恢复。`)) {
                return;
            }

            dashboards = dashboards.filter(d => d.id !== dashboardId);
            saveDashboards();
            renderDashboards();
            showNotification('看板已删除', 'info');
        }

        // 异步加载接口数据
        function loadInterfaces() {
            const deviceId = document.getElementById('device-select').value;
            const interfaceSelect = document.getElementById('interface-select');
            
            if (!deviceId) {
                interfaceSelect.innerHTML = '<option value="">请先选择设备...</option>';
                return;
            }

            interfaceSelect.innerHTML = '<option value="">加载中...</option>';
            
            fetch(`?action=get_interfaces&deviceId=${deviceId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.interfaces) {
                        interfaceSelect.innerHTML = '<option value="">请选择接口...</option>';
                        
                        data.interfaces.forEach(interface => {
                            const option = document.createElement('option');
                            option.value = interface.if_index;
                            
                            let text = interface.if_name;
                            if (interface.if_description && interface.if_description !== interface.if_name) {
                                text += ` (${interface.if_description})`;
                            }
                            
                            option.textContent = text;
                            interfaceSelect.appendChild(option);
                        });
                        
                        showNotification(`成功加载 ${data.interfaces.length} 个接口`, 'success');
                    } else {
                        interfaceSelect.innerHTML = '<option value="">无可用接口</option>';
                        showNotification(data.error || '获取接口失败', 'error');
                    }
                })
                .catch(error => {
                    console.error('加载接口失败:', error);
                    interfaceSelect.innerHTML = '<option value="">加载失败</option>';
                    showNotification('网络错误，请稍后重试', 'error');
                });
        }

        // 显示通知
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.textContent = message;
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.style.animation = 'slideOut 0.3s ease';
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.parentNode.removeChild(notification);
                    }
                }, 300);
            }, 3000);
        }

        // 点击模态框背景关闭
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('modal')) {
                e.target.classList.remove('show');
            }
        });

        // 键盘快捷键
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal.show').forEach(modal => {
                    modal.classList.remove('show');
                });
            }
        });
    </script>
</body>
</html> 