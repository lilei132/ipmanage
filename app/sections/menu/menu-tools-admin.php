<?php
# verify that user is logged in
$User->check_user_session();

# print admin menu for admin users
if($User->is_admin(false)) {
	# if section is not set
	if(!isset($GET->section)) { $GET->section = ""; }

	print "<ul class='nav navbar-nav navbar-right'>";
	print "	<li class='dropdown administration'>";
	# title
	print "	<a class='dropdown-toggle' data-toggle='dropdown' href='".create_link("administration")."' id='admin' rel='tooltip' data-placement='bottom' title='"._('Show Administration menu')."'><i class='fa fa-cog'></i> "._('Administration')." <b class='caret'></b></a>";
	# dropdown
	print "		<ul class='dropdown-menu'>";

	# all items
	print "		<li class='nav-header'>"._('Available IPAM tools')."</li>";
	print "		<li><a href='".create_link("administration")."'><i class='fa fa-wrench'></i> "._('Show all settings')."</a></li>";
	print "		<li class='divider'></li>";
	# print admin items
	foreach($admin_menu as $k=>$item) {
		# header
		print "<li class='nav-header'>".$k."</li>";
		# items
		foreach($item as $i) {
			# only selected
			if($i['show']) {
				# active?
				if($GET->page=="administration") {
					$active = $GET->section==$i['href'] ? "active" : "";
				} else {
					$active = "";
				}
				print "<li class='$active'><a href='".create_link("administration",$i['href'])."'><i class='fa fa-angle-right'></i> ".$i['name']."</a></li>";
			}
		}
	}

	print "		</ul>";
	print "	</li>";
	print "</ul>";
}
?>


<!-- Tools (for small menu) -->
<ul class="nav navbar-nav visible-xs visible-sm navbar-right">
	<li class="dropdown">
		<a href="#" class="dropdown-toggle" data-toggle="dropdown"><i class='fa fa-wrench'></i> <?php print _('Tools'); ?> <b class="caret"></b></a>
		<ul class="dropdown-menu">
			<?php
    		# all tools
	    	print "	<li><a href='".create_link("tools")."'><i class='fa fa-list'></i> "._('Show all tools')."</a></li>";
	    	print "	<li class='divider'></li>";

			# print tools items
			$m=0;
			foreach($tools_menu as $k=>$item) {
				# header
				print "<li class='nav-header'>".$k."</li>";
				# items
				foreach($item as $i) {
					# only active
					if($i['show']) {
						# active?
						if($GET->page=="tools") {
							$active = $GET->section==$i['href'] ? "active" : "";
						} else {
							$active = "";
						}
						print "<li class='$active'><a href='".create_link("tools",$i['href'])."'><i class='fa fa-angle-right'></i> ".$i['name']."</a></li>";
					}
				}
			}
			?>
		</ul>
	</li>
</ul>


<!-- 工具按钮菜单 -->
<ul class="nav navbar-nav navbar-right hidden-xs hidden-sm">

	<!-- Dash lock/unlock -->
	<?php if($GET->page=="dashboard" && !($User->is_admin(false)!==true && (is_blank($User->user->groups) || $User->user->groups==="null") ) ) { ?>
		<li class="w-lock">
			<a href="#" rel='tooltip' data-placement='bottom' title="<?php print _('Click to reorder widgets'); ?>"><i class='fa fa-dashboard'></i></a>
		</li>
	<?php } ?>

	<!-- masks -->
	<li>
		<a href="" class="show-masks" rel='tooltip' data-placement='bottom' title="<?php print _('Subnet masks'); ?>" data-closeClass="hidePopups"><i class='fa fa-th-large'></i></a>
	</li>

	<!-- Favourites -->
	<?php
	//check if user has favourite subnets
	if(!is_blank(trim((string) $User->user->favourite_subnets))) {
	?>
	<li class="<?php if($GET->section=="favourites") print " active"; ?>">
		<a href="<?php print create_link("tools","favourites"); ?>" rel='tooltip' data-placement='bottom' title="<?php print _('Favourite networks'); ?>"><i class='fa fa-star'></i></a>
	</li>
	<?php } ?>

	<!-- instructions -->
	<li class="<?php if($GET->section=="instructions") print " active"; ?>">
		<a href="<?php print create_link("tools","instructions"); ?>" rel='tooltip' data-placement='bottom' title="<?php print _('Show IP addressing guide'); ?>"><i class='fa fa-info-circle'></i></a>
	</li>

	<!-- tools -->
	<li class="tools dropdown <?php if($GET->page=="tools") { print " active"; } ?>">
		<a class="dropdown-toggle" data-toggle="dropdown" href="" rel='tooltip' data-placement='bottom' title='<?php print _('Show tools menu'); ?>'><i class="fa fa-wrench"></i></a>
		<ul class="dropdown-menu">
			<!-- public -->
			<li class="nav-header"><?php print _('Available IPAM tools'); ?> </li>
			<!-- private -->
			<?php
    		# all tools
	    	print "	<li><a href='".create_link("tools")."'><i class='fa fa-list'></i> "._('Show all tools')."</a></li>";
	    	print "	<li class='divider'></li>";

			# print tools items
			foreach($tools_menu as $k=>$item) {
				# header
				print "<li class='nav-header'>".$k."</li>";
				# items
				foreach($item as $i) {
					# only selected
					if($i['show']) {
						# active?
						if($GET->page=="tools") {
							$active = $GET->section==$i['href'] ? "active" : "";
						} else {
							$active = "";
						}
						print "<li class='$active'><a href='".create_link("tools",$i['href'])."'><i class='fa fa-angle-right'></i> ".$i['name']."</a></li>";
					}
				}
			}
			?>
		</ul>
	</li>

	<!-- DB verification -->
	<?php
	if($User->is_admin(false) && $User->settings->dbverified==0) {
		//check
		if(sizeof($dberrsize = $Tools->verify_database())>0) {
			$esize =  isset($dberrsize['tableError']) ? sizeof($dberrsize['tableError']) : 0;
			$esize += isset($dberrsize['fieldError']) ? sizeof($dberrsize['fieldError']) : 0;
			print "<li>";
			print "	<a href='".create_link("administration","verify-database")."' class='btn-danger' rel='tooltip' data-placement='bottom' title='"._('Database errors detected')."'><i class='fa fa-exclamation-triangle'></i><sup>$esize</sup></a>";
			print "</li>";
		}
		else {
			print "<li>";
			print "	<a class='btn-success' rel='tooltip' data-placement='bottom' title='"._('Database verified')."'><i class='fa fa-check'></i></a>";
			print "</li>";
		}
	}
	?>

	<?php
	# get all request
	if(isset($requests)) { ?>
	<li>
		<a href="<?php print create_link("tools","requests"); ?>" rel='tooltip' class="btn-info" data-placement='bottom' title="<?php print $requests." "._('requests')." "._('for IP address waiting for your approval'); ?>"><i class='fa fa-envelope'></i><sup><?php print $requests; ?></sup></a>
	</li>

	<?php
	}

	# check for new version periodically, 1x/week
	if( $User->is_admin(false) && (strtotime(date("Y-m-d H:i:s")) - strtotime($User->settings->vcheckDate)) > 604800 ) {
		# check for new version
		if(!$version = $Tools->check_latest_phpipam_version ()) {
			# we failed, so NW is not ok. update time anyway to avoid future failures
			$Tools->update_phpipam_checktime ();
		} else {
			# new version available
			if ($Tools->cmp_version_strings(VERSION_VISIBLE, $version) < 0) {
				print "<li>";
				print "	<a href='".create_link("administration","version-check")."' class='btn-warning' rel='tooltip' data-placement='bottom' title='"._('New version available')."'><i class='fa fa-bullhorn'></i><sup>$version</sup></a>";
				print "</li>";
			} else {
				# version ok
				$Tools->update_phpipam_checktime ();
			}
		}
	}

	if ($User->is_admin(false) && $Tools->cmp_version_strings(VERSION, $User->settings->version) != 0) {
		print "<li>";
		print "	<a href='".create_link("administration","version-check")."' class='btn-danger' rel='tooltip' data-placement='bottom' title='"._("Incompatible php and database schema versions")."'><i class='fa fa-bullhorn'></i><sup>".$User->settings->version."</sup></a>";
		print "</li>";
	}
	?>

</ul>
