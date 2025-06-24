<?php
/**
 * 流量监控看板列表页面
 * 注意：这个文件被 index.php 包含，不需要重复引入函数文件和检查权限
 */

# 看板数据已经在index.php中准备好了
echo "<!-- DEBUG: dashboards.php 开始渲染，看板数量: " . count($dashboards) . " -->";
?>

<!-- 页面标题 -->
<div class="dashboard-header">
    <h4><i class="fa fa-dashboard"></i> <?php print _("流量监控看板"); ?></h4>
    <p class="text-muted"><?php print _("选择一个看板查看和管理流量监控卡片"); ?></p>
</div>

<hr>

<!-- 操作按钮 -->
<div class="dashboard-actions" style="margin-bottom: 20px;">
    <button class="btn btn-success" data-toggle="modal" data-target="#addDashboardModal">
        <i class="fa fa-plus"></i> <?php print _("创建新看板"); ?>
    </button>
</div>

<!-- 看板网格 -->
<div class="row dashboard-grid">
    <?php if ($dashboards && count($dashboards) > 0): ?>
        <?php foreach ($dashboards as $dashboard): ?>
            <?php $card_count = isset($dashboard_cards_count[$dashboard->id]) ? $dashboard_cards_count[$dashboard->id] : 0; ?>
            <div class="col-lg-4 col-md-6 col-sm-12 dashboard-item">
                <div class="panel panel-default dashboard-card <?php echo $card_count == 0 ? 'empty-dashboard' : ''; ?>">
                    <div class="panel-body">
                        <div class="dashboard-header-row">
                            <div class="dashboard-title-area">
                                <h5 class="dashboard-name">
                                    <i class="fa fa-dashboard text-primary"></i>
                                    <?php echo htmlspecialchars($dashboard->name); ?>
                                </h5>
                            </div>
                            <div class="card-count-badge">
                                <span class="count-number <?php echo $card_count == 0 ? 'zero' : ''; ?>"><?php echo $card_count; ?></span>
                                <span class="count-label">个监控卡片</span>
                            </div>
                        </div>
                        
                        <div class="dashboard-content">
                            <p class="dashboard-description text-muted">
                                <?php echo htmlspecialchars($dashboard->description ?: '暂无描述'); ?>
                            </p>
                        </div>
                        
                        <div class="dashboard-actions">
                            <a href="<?php print create_link('tools', 'traffic-monitor') . '&subPage=dashboard-view&dashboard_id=' . $dashboard->id; ?>" 
                               class="btn btn-primary btn-sm">
                                <i class="fa fa-eye"></i> <?php print _("查看看板"); ?>
                            </a>
                            <button class="btn btn-default btn-sm edit-dashboard" 
                                    data-dashboard-id="<?php echo $dashboard->id; ?>"
                                    data-dashboard-name="<?php echo htmlspecialchars($dashboard->name); ?>"
                                    data-dashboard-description="<?php echo htmlspecialchars($dashboard->description); ?>">
                                <i class="fa fa-edit"></i> <?php print _("编辑"); ?>
                            </button>
                            <?php if ((isset($dashboard_cards_count[$dashboard->id]) ? $dashboard_cards_count[$dashboard->id] : 0) == 0): ?>
                                <button class="btn btn-danger btn-sm delete-dashboard" 
                                        data-dashboard-id="<?php echo $dashboard->id; ?>"
                                        data-dashboard-name="<?php echo htmlspecialchars($dashboard->name); ?>">
                                    <i class="fa fa-trash"></i> <?php print _("删除"); ?>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="col-12">
            <div class="alert alert-info text-center">
                <i class="fa fa-info-circle fa-3x" style="margin-bottom: 10px;"></i>
                <h4><?php print _("暂无监控看板"); ?></h4>
                <p><?php print _("点击上方按钮创建您的第一个流量监控看板"); ?></p>
                <button class="btn btn-success" data-toggle="modal" data-target="#addDashboardModal">
                    <i class="fa fa-plus"></i> <?php print _("立即创建"); ?>
                </button>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- 添加看板模态框 -->
<div class="modal fade" id="addDashboardModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
                <h4 class="modal-title"><?php print _("创建新看板"); ?></h4>
            </div>
            <form id="addDashboardForm">
                <div class="modal-body">
                    <div class="form-group">
                        <label for="dashboard_name"><?php print _("看板名称"); ?> *</label>
                        <input type="text" class="form-control" id="dashboard_name" name="name" required 
                               placeholder="<?php print _("例如：核心出口端口"); ?>">
                    </div>
                    <div class="form-group">
                        <label for="dashboard_description"><?php print _("看板描述"); ?></label>
                        <textarea class="form-control" id="dashboard_description" name="description" rows="3" 
                                  placeholder="<?php print _("简要描述这个看板的用途..."); ?>"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal"><?php print _("取消"); ?></button>
                    <button type="submit" class="btn btn-success"><?php print _("创建看板"); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 编辑看板模态框 -->
<div class="modal fade" id="editDashboardModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
                <h4 class="modal-title"><?php print _("编辑看板"); ?></h4>
            </div>
            <form id="editDashboardForm">
                <input type="hidden" id="edit_dashboard_id" name="dashboard_id">
                <div class="modal-body">
                    <div class="form-group">
                        <label for="edit_dashboard_name"><?php print _("看板名称"); ?> *</label>
                        <input type="text" class="form-control" id="edit_dashboard_name" name="name" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_dashboard_description"><?php print _("看板描述"); ?></label>
                        <textarea class="form-control" id="edit_dashboard_description" name="description" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal"><?php print _("取消"); ?></button>
                    <button type="submit" class="btn btn-primary"><?php print _("保存更改"); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 样式 - 最后更新: <?php echo date('Y-m-d H:i:s'); ?> -->
<style>
/* Dashboard styles v2.0 - Updated with !important rules */
.dashboard-header {
    text-align: center;
    margin-bottom: 30px;
}

.dashboard-grid .dashboard-item {
    margin-bottom: 20px;
}

.dashboard-card {
    height: 240px !important;
    border: 1px solid #ddd;
    transition: all 0.3s;
    cursor: pointer;
    position: relative;
}

.dashboard-card:hover {
    border-color: #337ab7;
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    transform: translateY(-2px);
}

.dashboard-card .panel-body {
    height: 100% !important;
    display: flex !important;
    flex-direction: column !important;
    position: relative !important;
}

.dashboard-header-row {
    display: flex !important;
    justify-content: space-between !important;
    align-items: flex-start !important;
    margin-bottom: 15px !important;
}

.dashboard-title-area {
    flex: 1 !important;
}

.dashboard-name {
    margin: 0 !important;
    font-weight: 600 !important;
    line-height: 1.2 !important;
}

.card-count-badge {
    position: relative !important;
    top: -2px !important;
    display: flex !important;
    align-items: center !important;
    gap: 5px !important;
}

.count-number {
    display: inline-block !important;
    font-size: 22px !important;
    font-weight: bold !important;
    color: #f0ad4e !important;
    background-color: rgba(240, 173, 78, 0.1) !important;
    border: 2px solid #f0ad4e !important;
    border-radius: 50% !important;
    width: 32px !important;
    height: 32px !important;
    line-height: 28px !important;
    text-align: center !important;
    margin: 0 !important;
    padding: 0 !important;
    box-sizing: border-box !important;
}

.count-number.zero {
    color: #d9534f !important;
    border-color: #d9534f !important;
    background-color: rgba(217, 83, 79, 0.1) !important;
}

.count-label {
    font-size: 12px !important;
    color: #666 !important;
    font-weight: normal !important;
    white-space: nowrap !important;
}

.dashboard-content {
    flex: 1 !important;
    display: flex !important;
    flex-direction: column !important;
}

.dashboard-description {
    font-size: 13px;
    margin-bottom: 15px;
    height: 40px;
    overflow: hidden;
    color: #666;
}

.dashboard-card.empty-dashboard {
    border: 2px dashed #d9534f !important;
    background-color: rgba(217, 83, 79, 0.05) !important;
}

.dashboard-card.empty-dashboard:hover {
    border-color: #c9302c !important;
    background-color: rgba(217, 83, 79, 0.1) !important;
}

.dashboard-actions {
    text-align: center !important;
    margin-top: auto !important;
    padding-top: 10px !important;
}

.dashboard-actions .btn {
    margin: 0 2px;
}
</style>

<!-- JavaScript -->
<script>
$(document).ready(function() {
    // 创建看板
    $('#addDashboardForm').on('submit', function(e) {
        e.preventDefault();
        
        var formData = {
            action: 'create_dashboard',
            name: $('#dashboard_name').val(),
            description: $('#dashboard_description').val()
        };
        
        $.post('<?php echo create_link("tools", "traffic-monitor"); ?>&action=api', formData)
            .done(function(response) {
                if (response && response.success) {
                    location.reload();
                } else {
                    alert('创建失败: ' + (response ? response.error : '未知错误'));
                }
            })
            .fail(function() {
                alert('网络错误，请重试');
            });
    });
    
    // 编辑看板
    $('.edit-dashboard').on('click', function() {
        var dashboardId = $(this).data('dashboard-id');
        var dashboardName = $(this).data('dashboard-name');
        var dashboardDescription = $(this).data('dashboard-description');
        
        $('#edit_dashboard_id').val(dashboardId);
        $('#edit_dashboard_name').val(dashboardName);
        $('#edit_dashboard_description').val(dashboardDescription);
        
        $('#editDashboardModal').modal('show');
    });
    
    $('#editDashboardForm').on('submit', function(e) {
        e.preventDefault();
        
        var formData = {
            action: 'update_dashboard',
            dashboard_id: $('#edit_dashboard_id').val(),
            name: $('#edit_dashboard_name').val(),
            description: $('#edit_dashboard_description').val()
        };
        
        $.post('<?php echo create_link("tools", "traffic-monitor"); ?>&action=api', formData)
            .done(function(response) {
                if (response && response.success) {
                    location.reload();
                } else {
                    alert('更新失败: ' + (response ? response.error : '未知错误'));
                }
            })
            .fail(function() {
                alert('网络错误，请重试');
            });
    });
    
    // 删除看板
    $('.delete-dashboard').on('click', function() {
        var dashboardId = $(this).data('dashboard-id');
        var dashboardName = $(this).data('dashboard-name');
        
        if (confirm('确定要删除看板 "' + dashboardName + '" 吗？此操作不可恢复。')) {
            $.post('<?php echo create_link("tools", "traffic-monitor"); ?>&action=api', {
                action: 'delete_dashboard',
                dashboard_id: dashboardId
            })
            .done(function(response) {
                if (response && response.success) {
                    location.reload();
                } else {
                    alert('删除失败: ' + (response ? response.error : '未知错误'));
                }
            })
            .fail(function() {
                alert('网络错误，请重试');
            });
        }
    });
});
</script> 