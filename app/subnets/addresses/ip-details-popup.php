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

# validate post
is_numeric($_POST['subnetId']) ?:							$Result->show("danger", _("Invalid ID"), true, true, false, true);
if(is_numeric($_POST['id'])) {
	!is_blank($_POST['id']) ?:								$Result->show("danger", _("Invalid ID"), true, true, false, true);
	# fetch address
	$address = (array) $Addresses->fetch_address(null, $_POST['id']);
} else {
	$Result->show("danger", _("Invalid ID"), true, true, false, true);
}

# fetch subnet details
$subnet = (array) $Subnets->fetch_subnet(null, $_POST['subnetId']);
$subnet_calculation = $Tools->calculate_ip_calc_results($subnet['ip']."/".$subnet['mask']);

# fetch VLAN details
$vlan = (array) $Tools->fetch_object("vlans", "vlanId", $subnet['vlanId']);

# set and check permissions
$subnet_permission = $Subnets->check_permission($User->user, $_POST['subnetId']);
$subnet_permission > 0 ?:								$Result->show("danger", _('You do not have permission to access this network'), true, true, false, true);

# 准备详情内容用于复制
$details = array();
$details[] = "IP 地址: " . $Subnets->transform_address($address['ip_addr'], 'dotted') . "/" . $subnet['mask'];
$details[] = "子网: " . $Subnets->transform_address($subnet['ip'], 'dotted') . "/" . $subnet['mask'] . ($subnet['description'] ? " (" . $subnet['description'] . ")" : "");
$details[] = "网络掩码: " . $subnet_calculation['Subnet netmask'];

# 网关
$gateway = $Subnets->find_gateway($subnet['id']);
if($gateway !== false) {
    $details[] = "网关: " . $Subnets->transform_to_dotted($gateway->ip_addr);
}

# VLAN
if(!empty($subnet['vlanId'])) {
    $details[] = "VLAN: " . $vlan['number'] . " - " . $vlan['name'];
}

# MAC
if(!empty($address['mac'])) {
    $details[] = "院系/部门: " . $address['mac'];
}

# customer_id (存放地点)
if(!empty($address['customer_id'])) {
    $details[] = "存放地点: " . $address['customer_id'];
}

$details_text = implode("\n", $details);

# header
print "<div class='pHeader'>" . _('IP地址详细信息') . 
      "<div style='position:absolute; right:15px; top:15px;'>" .
      "<button class='btn btn-xs btn-default copy-details'><i class='fa fa-copy'></i> 复制</button>" .
      "</div></div>";

# content
print "<div class='pContent'>";

# 使用隐藏的区域存储完整内容用于复制
print "<div id='ip-details-content' style='display:none;'>" . $details_text . "</div>";

# IP地址
print "<p><strong>IP 地址:</strong> " . $Subnets->transform_address($address['ip_addr'], 'dotted') . "/" . $subnet['mask'] . "</p>";

# 子网
print "<p><strong>子网:</strong> " . $Subnets->transform_address($subnet['ip'], 'dotted') . "/" . $subnet['mask'];
if($subnet['description']) { print " (" . $subnet['description'] . ")"; }
print "</p>";

# 网络掩码
print "<p><strong>网络掩码:</strong> " . $subnet_calculation['Subnet netmask'] . "</p>";

# 网关
if($gateway !== false) {
    print "<p><strong>网关:</strong> " . $Subnets->transform_to_dotted($gateway->ip_addr) . "</p>";
}

# VLAN
if(!empty($subnet['vlanId'])) {
    print "<p><strong>VLAN:</strong> " . $vlan['number'] . " - " . $vlan['name'] . "</p>";
}

# MAC
if(!empty($address['mac'])) {
    print "<p><strong>院系/部门:</strong> " . $address['mac'] . "</p>";
}

# customer_id (存放地点)
if(!empty($address['customer_id'])) {
    print "<p><strong>存放地点:</strong> " . $address['customer_id'] . "</p>";
}

print "</div>";

# footer
print "<div class='pFooter'>";
print "<div class='btn-group'>";
print "<button class='btn btn-sm btn-default hidePopup2'>" . _('关闭') . "</button>";
print "</div>";
print "</div>"; 