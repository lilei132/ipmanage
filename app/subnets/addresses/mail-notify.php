<?php

/**
 * Script to print mail notification form
 ********************************************/

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

# id must be numeric
is_numeric($POST->id) || is_blank($POST->id) ?:	$Result->show("danger", _("Invalid ID"), true);

$csrf = $User->Crypto->csrf_cookie ("create", "mail_notify");

# get IP address id
$id = $POST->id;

# fetch address, subnet, vlan and nameservers
$address = (array) $Addresses->fetch_address (null, $id);
$subnet  = (array) $Subnets->fetch_subnet (null, $address['subnetId']);
$vlan    = (array) $Tools->fetch_object ("vlans", "vlanId", $subnet['vlanId']);
$nameservers    = (array) $Tools->fetch_object("nameservers", "id", $subnet['nameserverId']);

# get all custom fields
$custom_fields = $Tools->fetch_custom_fields ('ipaddresses');

# get subnet calculation
$subnet_calculation = $Tools->calculate_ip_calc_results ($subnet['ip']."/".$subnet['mask']);


# checks
sizeof($address)>0 ?:	$Result->show("danger", _("Invalid ID"), true);
sizeof($subnet)>0 ?:	$Result->show("danger", _("Invalid subnet"), true);


# set title
$title = _('IP address details').' :: ' . $address['ip'];


# address
										$content[] = "&bull; "._('IP address').": \t\t $address[ip]/$subnet[mask]";
# description
empty($address['description']) ? : 		$content[] = "&bull; "._('工号').": \t\t $address[description]";
# hostname
empty($address['hostname']) ? : 		$content[] = "&bull; "._('申请人姓名').": \t\t $address[hostname]";
# subnet desc
$s_descrip = empty($subnet['description']) ? "" : 	 " (" . $subnet['description']. ")";
# subnet
						$content[] = "&bull; "._('Subnet').": \t\t $subnet[ip]/$subnet[mask] $s_descrip";
						$content[] = "&bull; "._('Netmask').": \t\t ".$subnet_calculation['Subnet netmask'];
# gateway
$gateway = $Subnets->find_gateway($subnet['id']);
if($gateway !==false)
 						$content[] = "&bull; "._('Gateway').": \t\t". $Subnets->transform_to_dotted($gateway->ip_addr);


# VLAN
empty($subnet['vlanId']) ? : 			$content[] = "&bull; "._('VLAN ID').": \t\t $vlan[number] ($vlan[name])";

# Nameserver sets
if ( !empty( $subnet['nameserverId'] ) ) {
	$nslist = str_replace(";", ", ", $nameservers['namesrv1']);

						$content[] = "&bull; "._('Nameservers').": \t $nslist ({$nameservers['name']})";
}

# Switch
if(!empty($address['switch'])) {
	# get device by id
	$device = (array) $Tools->fetch_object("devices", "id", $address['switch']);
	!sizeof($device)>1 ? : 				$content[] = "&bull; "._('Device').": \t\t\t $device[hostname]";
}
# port
empty($address['port']) ? : 			$content[] = "&bull; "._('Port').": \t\t $address[port]";
# mac
empty($address['mac']) ? : 			$content[] = "&bull; "._('院系/部门').": \t\t $address[mac]";
# owner
empty($address['owner']) ? : 			$content[] = "&bull; "._('Owners').": \t\t $address[owner]";
# customer_id
empty($address['customer_id']) ? : 		$content[] = "&bull; "._('存放地点').": \t\t $address[customer_id]";

# custom
if(sizeof($custom_fields) > 0) {
	foreach($custom_fields as $custom_field) {
		if(!empty($address[$custom_field['name']])) {
						$content[] =  "&bull; ". _($custom_field['name']).":\t".$address[$custom_field['name']];
		}
	}
}
?>



<!-- header -->
<div class="pHeader"><?php print _('发送电子邮件通知'); ?></div>

<!-- content -->
<div class="pContent mailIPAddress">

	<!-- sendmail form -->
	<form name="mailNotify" id="mailNotify">
	<table id="mailNotify" class="table table-noborder table-condensed">

	<!-- recipient -->
	<tr>
		<th><?php print _('收件人'); ?></th>
		<td>
			<?php 
			// 默认填入工号@nuist.edu.cn，如果工号为空则为空
			$default_recipient = !empty($address['description']) ? $address['description'].'@nuist.edu.cn' : '';
			?>
			<input type="text" class='form-control input-sm pull-left' name="recipients" style="width:400px;margin-right:5px;" value="<?php echo $default_recipient; ?>">
			<i class="fa fa-info input-append" rel="tooltip" data-placement="bottom" title="<?php print _('多个收件人请使用逗号分隔'); ?>"></i>
		</td>
	</tr>

	<!-- title -->
	<tr>
		<th><?php print _('标题'); ?></th>
		<td>
			<input type="text" class='form-control input-sm' name="subject" style="width:400px;" value="<?php print $title; ?>">
		</td>
	</tr>

	<!-- content -->
	<tr>
		<th><?php print _('内容'); ?></th>
		<td style="padding-right:20px;">
			<textarea name="content" class='form-control input-sm' rows="10" style="width:100%;"><?php print implode("\n", $content); ?></textarea>
		</td>
	</tr>

	</table>
	<input type="hidden" name='csrf_cookie' value='<?php print $csrf; ?>'>
	</form>
</div>

<!-- footer -->
<div class="pFooter">
	<div class="btn-group">
		<button class="btn btn-sm btn-default hidePopups"><?php print _('取消'); ?></button>
		<button class="btn btn-sm btn-default btn-success" id="mailIPAddressSubmit"><i class="fa fa-envelope-o"></i> <?php print _('发送邮件'); ?></button>
	</div>

	<!-- holder for result -->
	<div class="sendmail_check"></div>
</div>
