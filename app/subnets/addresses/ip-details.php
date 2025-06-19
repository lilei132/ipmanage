<?php

/**
 * 显示IP地址详细信息的弹窗
 */

# include required scripts
require_once( dirname(__FILE__) . '/../../../functions/functions.php' );

# initialize required objects
$Database 	= new Database_PDO;
$Result		= new Result;
$User		= new User ($Database);
$Subnets	= new Subnets ($Database);
$Tools	    = new Tools ($Database);
$Addresses	= new Addresses ($Database);

# verify that user is logged in
$User->check_user_session();
# check maintaneance mode
$User->check_maintaneance_mode (true);

# 调试：显示接收到的数据
# error_log("IP Details Debug - POST data: " . print_r($_POST, true));
# error_log("IP Details Debug - POST object: subnetid=" . (isset($POST->subnetid) ? $POST->subnetid : 'undefined') . ", id=" . (isset($POST->id) ? $POST->id : 'undefined'));
# error_log("IP Details Debug - All available vars: " . print_r(get_defined_vars(), true));

# validate input
if(!isset($POST->subnetid) || !is_numeric($POST->subnetid)) {
    $Result->show("danger", _("Invalid subnet ID"), true, true, false, true);
}

if(!isset($POST->id) || !is_numeric($POST->id)) {
    $Result->show("danger", _("Invalid IP address ID"), true, true, false, true);
}

# fetch address
$address = (array) $Addresses->fetch_address(null, $POST->id);
if(empty($address) || !$address) {
    $Result->show("danger", _("IP address not found"), true, true, false, true);
}

# fetch subnet details
$subnet = (array) $Subnets->fetch_subnet(null, $POST->subnetid);
if(empty($subnet) || !$subnet) {
    $Result->show("danger", _("Subnet not found"), true, true, false, true);
}

# set and check permissions
$subnet_permission = $Subnets->check_permission($User->user, $POST->subnetid);
if($subnet_permission <= 0) {
    $Result->show("danger", _('You do not have permission to access this network'), true, true, false, true);
}

# 计算子网信息
$subnet_calculation = $Tools->calculate_ip_calc_results($subnet['ip']."/".$subnet['mask']);

# fetch VLAN details if exists
$vlan = null;
if(!empty($subnet['vlanId'])) {
    $vlan = (array) $Tools->fetch_object("vlans", "vlanId", $subnet['vlanId']);
}

# 查找网关
$gateway = $Subnets->find_gateway($subnet['id']);

# 查找DNS服务器
$nameserver = null;
if(!empty($subnet['nameserverId'])) {
    $nameserver = $Tools->fetch_object("nameservers", "id", $subnet['nameserverId']);
}

# header
print "<div class='pHeader'>" . _('IP地址详细信息') . "
    <button class='btn btn-sm btn-default pull-right' id='copyIpDetails' style='margin-top:-3px;' title='复制IP详细信息'>
        <i class='fa fa-copy'></i> 复制
    </button>
</div>";

# content
print "<div class='pContent'>";

# 构建复制内容
$ip_text = $Subnets->transform_address($address['ip_addr'], 'dotted');
$mask_text = $subnet_calculation['Subnet netmask'];
$gateway_text = ($gateway !== false) ? $Subnets->transform_to_dotted($gateway->ip_addr) : '';
$dns_text = !empty($nameserver) ? $nameserver->name : '202.195.224.100';

$copy_content = "IP: " . $ip_text . "\n";
$copy_content .= "掩码: " . $mask_text . "\n";
if($gateway_text) {
    $copy_content .= "网关: " . $gateway_text . "\n";
}
$copy_content .= "DNS: " . $dns_text;

# IP地址
print "<table class='table table-condensed table-hover' id='ipDetailsTable'>";
print "<tr><th style='width:120px;'>IP</th><td>" . $ip_text . "</td></tr>";

# 掩码
print "<tr><th>掩码</th><td>" . $mask_text . "</td></tr>";

# 网关
if($gateway !== false) {
    print "<tr><th>网关</th><td>" . $gateway_text . "</td></tr>";
}

# DNS
print "<tr><th>DNS</th><td>" . $dns_text . "</td></tr>";

# 子网信息
print "<tr><th>子网</th><td>" . $Subnets->transform_address($subnet['ip'], 'dotted') . "/" . $subnet['mask'];
if($subnet['description']) { 
    print " (" . $subnet['description'] . ")"; 
}
print "</td></tr>";

# VLAN信息
if(!empty($subnet['vlanId']) && !empty($vlan)) {
    print "<tr><th>VLAN</th><td>" . $vlan['number'] . " - " . $vlan['name'] . "</td></tr>";
}

# 主机名
if(!empty($address['hostname'])) {
    print "<tr><th>主机名</th><td>" . $address['hostname'] . "</td></tr>";
}

# 描述
if(!empty($address['description'])) {
    print "<tr><th>描述</th><td>" . $address['description'] . "</td></tr>";
}

print "</table>";

# 添加隐藏的复制内容
print "<textarea id='copyContent' style='position:absolute;left:-9999px;'>" . htmlspecialchars($copy_content) . "</textarea>";

print "</div>";

# footer
print "<div class='pFooter'>";
print "<div class='btn-group'>";
print "<button class='btn btn-sm btn-default hidePopups'>" . _('关闭') . "</button>";
print "</div>";
print "</div>";

# 添加复制功能的JavaScript
print "<script>
$(document).ready(function() {
    $('#copyIpDetails').click(function() {
        var copyText = document.getElementById('copyContent');
        copyText.select();
        copyText.setSelectionRange(0, 99999); // For mobile devices
        
        try {
            var successful = document.execCommand('copy');
            if (successful) {
                // 显示成功提示
                $(this).html('<i class=\"fa fa-check\"></i> 已复制');
                $(this).addClass('btn-success').removeClass('btn-default');
                
                // 2秒后恢复原状
                setTimeout(function() {
                    $('#copyIpDetails').html('<i class=\"fa fa-copy\"></i> 复制');
                    $('#copyIpDetails').removeClass('btn-success').addClass('btn-default');
                }, 2000);
            } else {
                // 失败提示
                $(this).html('<i class=\"fa fa-exclamation\"></i> 复制失败');
                $(this).addClass('btn-danger').removeClass('btn-default');
                setTimeout(function() {
                    $('#copyIpDetails').html('<i class=\"fa fa-copy\"></i> 复制');
                    $('#copyIpDetails').removeClass('btn-danger').addClass('btn-default');
                }, 2000);
            }
        } catch (err) {
            // 备用方案：选中文本让用户手动复制
            copyText.focus();
            copyText.select();
            alert('请手动复制选中的文本 (Ctrl+C)');
        }
    });
});
</script>"; 
?> 