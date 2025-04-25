<?php

/**
 * Script to check edited / deleted / new IP addresses
 * If all is ok write to database
 *************************************************/

# include required scripts
require_once( dirname(__FILE__) . '/../../../functions/functions.php' );

# initialize required objects
$Database 	= new Database_PDO;
$Result		= new Result;
$User		= new User ($Database);
$Subnets	= new Subnets ($Database);
$Tools	    = new Tools ($Database);
$Addresses	= new Addresses ($Database);
$Log 		= new Logging ($Database, $User->settings);
$Zones 		= new FirewallZones($Database);
$Ping		= new Scan ($Database);

# verify that user is logged in
$User->check_user_session();
# check maintaneance mode
$User->check_maintaneance_mode ();

# validate csrf cookie
if($POST->action=="add") {
	$User->Crypto->csrf_cookie ("validate", "address_add", $POST->csrf_cookie) === false ? $Result->show("danger", _("Invalid CSRF cookie"), true) : "";
}
else {
	$User->Crypto->csrf_cookie ("validate", "address_".$POST->id, $POST->csrf_cookie) === false ? $Result->show("danger", _("Invalid CSRF cookie"), true) : "";
}

# validate action
$Tools->validate_action(false);
$action = $POST->action;
//reset delete action form visual visual
if(isset($POST->{'action-visual'})) {
	if($POST->{'action-visual'} == "delete") { $action = "delete"; }
}

// set selected address and required addresses fields array
$selected_ip_fields = $Tools->explode_filtered(";", $User->settings->IPfilter);
$required_ip_fields = $Tools->explode_filtered(";", $User->settings->IPrequired);
// append one missing from selected
$selected_ip_fields[] = "description";
$selected_ip_fields[] = "hostname";
// if field is present in required fields but not in selected remove it !
foreach ($required_ip_fields as $k=>$f) {
	if (!in_array($f, $selected_ip_fields)) {
		unset ($required_ip_fields[$k]);
	}
}
// checks
if(is_array($required_ip_fields) && $action!="delete") {
	// remove modules not enabled from required fields
	if($User->settings->enableLocations=="0") { unset($required_ip_fields['location']); }

	// set default array
	$required_field_errors = array();
	// Check that all required fields are present
	foreach ($required_ip_fields as $required_field) {
		if (!isset($POST->{$required_field}) || is_blank($POST->{$required_field})) {
			// Map English field names to Chinese field names
			$field_name_map = [
				'hostname' => '申请人姓名',
				'description' => '工号',
				'mac' => '院系/部门',
				'customer_id' => '存放地点',
			];
			
			$display_name = isset($field_name_map[$required_field]) ? $field_name_map[$required_field] : ucwords($required_field);
			$required_field_errors[] = $display_name." "._("is required");
		}
	}
	// check
	if(sizeof($required_field_errors)>0) {
		array_unshift($required_field_errors, _("Please fix following errors:"));
		$Result->show("danger", implode("<br> - ", $required_field_errors), true);
	}
}


# remove all spaces in hostname
if (!is_blank($POST->hostname)) { $POST->hostname = str_replace(" ", "", $POST->hostname); }

# required fields
isset($POST->action) ?:		$Result->show("danger", _("Missing required fields"). " action", true);
isset($POST->subnet) ?:		$Result->show("danger", _("Missing required fields"). " subnet", true);
isset($POST->subnetId) ?:		$Result->show("danger", _("Missing required fields"). " subnetId", true);
isset($POST->id) ?:			$Result->show("danger", _("Missing required fields"). " id", true);

# ptr
if(!isset($POST->PTRignore))	$POST->PTRignore=0;

# generate firewall address object name
$firewallZoneSettings = db_json_decode($User->settings->firewallZoneSettings, true);
if (isset($firewallZoneSettings['autogen']) && $firewallZoneSettings['autogen'] == 'on') {
	if ($POST->action == 'add' ) {
		$POST->firewallAddressObject = $Zones->generate_address_object($POST->subnetId,$POST->hostname);
	} else {
		if ($POST->firewallAddressObject) {
			$POST->firewallAddressObject = $POST->firewallAddressObject;
		} else {
			$POST->firewallAddressObject = NULL ;
		}
	}
}

# set and check permissions
$subnet_permission = $Subnets->check_permission($User->user, $POST->subnetId);
$subnet_permission > 1 ?:		$Result->show("danger", _('Cannot edit IP address'), true);

# fetch subnet
$subnet = (array) $Subnets->fetch_subnet(null, $POST->subnetId);
if ($POST->verifydatabase!=="yes")
sizeof($subnet)>0 ?:			$Result->show("danger", _("Invalid subnet"), true);

foreach ($POST as $k => $v) {
	if (is_array($v))
		continue;

	if (is_null($v) || is_blank($v)) {
		$POST->{$k} = null;
	}
}

# custom fields and checks
$Tools->update_POST_custom_fields('ipaddresses', $action, $POST);

# we need old address details for mailing or if we are editing address
if($action=="edit" || $action=="delete" || $action=="move") {
	$address_old = (array) $Addresses->fetch_address(null, $POST->id);
}

# set excludePing value
$POST->excludePing = $POST->excludePing==1 ? 1 : 0;
$POST->is_gateway = $POST->is_gateway==1 ? 1 : 0;

# check if subnet is multicast
$subnet_is_multicast = $Subnets->is_multicast ($subnet['subnet']);

# are we adding/editing range?
if (!is_blank(strstr($POST->ip_addr,"-"))) {

	# set flag for updating
	$POST->type = "series";

	# remove possible spaces
	$POST->ip_addr = str_replace(" ", "", $POST->ip_addr);

	# get start and stop of range
	$range		 = pf_explode("-", $POST->ip_addr);
	$POST->start = $range[0];
	$POST->stop  = $range[1];

	# verify both IP addresses
	if ($subnet['isFolder']=="1") {
    	if($Addresses->validate_ip( $POST->start)===false)     { $Result->show("danger", _("Invalid IP address")."!", true); }
    	if($Addresses->validate_ip( $POST->stop)===false)      { $Result->show("danger", _("Invalid IP address")."!", true); }
	}
	else {
		$Addresses->address_within_subnet($POST->start, $subnet, true);
		$Addresses->address_within_subnet($POST->stop,  $subnet, true);
	}

	# go from start to stop and insert / update / delete IPs
	$start = $Subnets->transform_to_decimal($POST->start);
	$stop  = $Subnets->transform_to_decimal($POST->stop);

	# start cannot be higher than stop!
	if($start>$stop)									{ $Result->show("danger", _("Invalid address range")."!", true); }

	# we can manage only 4096 IP's at once!
	if(gmp_strval(gmp_sub($stop,$start)) > 4096) 		{ $Result->show("danger", _("Only 4096 IP addresses at once")."!", true); }

	# set limits
	$m = gmp_strval($start);
	$n = gmp_strval(gmp_add($stop,1));

    # check if delete is confirmed
    if ($action=="delete" && !isset($POST->deleteconfirm)) {
	    $range = str_replace("-", " - ", $POST->ip_addr);
		# for ajax to prevent reload
		print "<div style='display:none'>alert alert-danger</div>";
		# result
		print "<div class='alert alert-warning'>";
		print "<strong>"._("Warning")."</strong>: "._("Are you sure you want to delete IP address range")."?";
		print "<hr>$range<div style='text-align:right'>";
		print "<div class='btn-group'>";
		print "	<a class='btn btn-sm btn-danger editIPSubmitDelete' id='editIPSubmitDelete'>"._("Confirm")."</a>";
		print "</div>";
		print "</div>";
		print "</div>";
	}
	# ok, edit
	else {
    	$c = 0;
		# for each IP in range modify
		while (gmp_cmp($m, $n) != 0) {

    		# remove gateway if not 0
    		if ($c!=0)  { unset($POST->is_gateway); }
            $c++;

			# reset IP address field
			$POST->ip_addr = $m;

			# set multicast MAC
			if ($User->settings->enableMulticast==1) {
                if ($Subnets->is_multicast ($POST->ip_addr)) {
                    $POST->mac = $Subnets->create_multicast_mac ($Subnets->transform_address($POST->ip_addr,"dotted"));
                }
            }

    	    # multicast check
    	    if ($User->settings->enableMulticast==1 && $subnet_is_multicast) {
    	    // 跳过多播MAC验证，因为我们现在使用MAC字段存储院系/部门
    	    }

        	# validate and normalize MAC address
        	if($action!=="delete") {
            	if(!is_blank($POST->mac)) {
            		$POST->mac = trim($POST->mac);
            		// MAC地址字段现在存储院系/部门，不需要验证MAC格式
            	}
        	}


			# if it already exist for add skip it !
			if($Addresses->address_exists ($m, $POST->subnetId) && $action=="add") {
				# Add Warnings if it exists
				$Result->show("warning", _('IP address')." ".$Addresses->transform_address($m, "dotted")." "._('already existing in selected network').'!', false);
			}
			else {
				# if it fails set error log
				if (!$Addresses->modify_address($POST->as_array(), false)) {
			        $errors[] = _('Cannot').' '. $POST->action. ' '._('IP address').' '. $Addresses->transform_to_dotted($m);
			    }
			}
			# next IP
			$m = gmp_strval(gmp_add($m,1));
		}

		# print errors if they exist
		if(isset($errors)) {
			$log = $Log->array_to_log ($errors);
			$Result->show("danger", $log, false);
			$Log->write( _("IP address modification"), _("Error")." ".$action." "._("range")." ".$POST->start." - ".$POST->stop."<br> $log", 2);
		}
		else {
			# reset IP for mailing
			$POST->ip_addr = $POST->start .' - '. $POST->stop;
			# log and changelog
			$Result->show("success", _("Range") . " " . escape_input($POST->start) . " - " . escape_input($POST->stop) . " " . $User->get_post_action() . " " . _("successful") . "!", false);
			$Log->write( _("IP address modification"), _("Range")." ".$POST->start." - ".$POST->stop." ".$action." "._("successful")."!", 0);

			# send changelog mail
			$Log->object_action = $action;
			$Log->object_type   = _("address range");
			$Log->object_result = _("success");
			$Log->user 			= $User->user;

			$Log->changelog_send_mail ( _("Address range")." ".$POST->start." - ".$POST->stop." ".$action);
		}
	}
}
/* no range, single IP address */
else {

	# unique hostname requested?
	if(isset($POST->unique)) {
		if($POST->unique == 1 && !is_blank($POST->hostname)) {
			# check if unique
			if(!$Addresses->is_hostname_unique($POST->hostname)) 						{ $Result->show("danger", _('Hostname is not unique')."!", true); }
		}
	}

	# validate and normalize MAC address
	if($action!=="delete") {
    	if(!is_blank($POST->mac)) {
    		$POST->mac = trim($POST->mac);
    	}
	}

	# reset subnet if move
	if($action == "move")	{
		$subnet = (array) $Subnets->fetch_subnet(null, $POST->newSubnet);
		$POST->ip_addr = $address_old['ip'];
	}
	# if errors are present print them, else execute query!
	if(0 && $verify) 				{ $Result->show("danger", _('Error').": $verify (".escape_input($POST->ip_addr).")", true); }  // TODO: Set undefined variable $verify
	else {
		# set update type for update to single
		$POST->type = "single";

		# check for duplicate entryon adding new address
	    if ($action == "add") {
	        if ($Addresses->address_exists ($POST->ip_addr, $POST->subnetId)) 	{ $Result->show("danger", _('IP address')." ".escape_input($POST->ip_addr)." "._('already existing in selected network').'!', true); }
	    }

		# check for duplicate entry on edit!
	    if ($action == "edit") {	    	# if IP is the same than it can already exist!
	    	if($Addresses->transform_address($POST->ip_addr,"decimal") != $POST->ip_addr_old) {
	        	if ($Addresses->address_exists ($POST->ip_addr, $POST->subnetId)) { $Result->show("danger", _('IP address')." ".escape_input($POST->ip_addr)." "._('already existing in selected network').'!', true); }
	    	}
	    }
	    # move checks
	    if($action == "move") {
		    # check if not already used in new subnet
	        if ($Addresses->address_exists ($POST->ip_addr, $POST->newSubnet)) 	{ $Result->show("danger", _('IP address')." ".escape_input($POST->ip_addr)." "._('already existing in selected network').'!', true); }
	    }
	    # multicast check
	    if ($User->settings->enableMulticast==1 && $subnet_is_multicast) {
    	    // 跳过多播MAC验证，因为我们现在使用MAC字段存储院系/部门
	    }

	    # for delete actions check if delete was confirmed
	    if ($action=="delete" && !isset($POST->deleteconfirm)) {
			# for ajax to prevent reload
			print "<div style='display:none'>alert alert-danger</div>";
			# result
			print "<div class='alert alert-warning'>";
			print "<strong>"._("Warning")."</strong>: "._("Are you sure you want to delete IP address")."?";
			print "<hr><div style='text-align:right'>";
			print "<div class='btn-group'>";
			print "	<a class='btn btn-sm btn-danger editIPSubmitDelete' id='editIPSubmitDelete'>"._("Confirm")."</a>";
			print "</div>";
			print "</div>";
			print "</div>";
		}
		# ok, execute
		else {
			//fail
		    if (!$Addresses->modify_address($POST->as_array())) {
		        $Result->show("danger", _('Error inserting IP address')."!", false);
		    }
		    //success, save log file and send email
		    else {
		        $Result->show("success", _("IP $action successful"),false);
		        // try to ping
		        if ($subnet['pingSubnet']=="1" && $action=="add") {
    		        $pingRes = $Ping->ping_address($Subnets->transform_address($POST->ip_addr, "dotted"));
    		        // update status
    		        if($pingRes==0) {
        		        // print alive
        		        $Result->show("success", _("IP address")." ".$Subnets->transform_address($POST->ip_addr, "dotted")." "._("is alive"), false);
        		        // update status
        		        @$Ping->ping_update_lastseen($Addresses->lastId);
                    }
		        }
		    }
		}
	}
}
