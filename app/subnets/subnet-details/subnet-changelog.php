<?php

/**
 * Script to display subnet changelog
 ***********************************************/

# verify that user is logged in
$User->check_user_session();
# get clog entries for current subnet
$clogs = $Log->fetch_changlog_entries("subnet", $GET->subnetId, true);
# subnet changelog for all slave subnets
$clogsSlaves = $Log->fetch_subnet_slaves_changlog_entries_recursive($GET->subnetId);
# changelog for each IP address, also in slave subnets
$clogsAddresses = $Log->fetch_subnet_addresses_changelog_recursive($GET->subnetId);  //se ne dela !

# get subnet details
$subnet = (array) $Subnets-> fetch_subnet("id",$GET->subnetId);


# permissions
$permission = $Subnets->check_permission ($User->user, $GET->subnetId);
if($permission == 0)	{ $Result->show("danger", _('You do not have permission to access this network'), true); }

# header
print "<h4>"._('Subnet')." - "._('Changelog')."</h4><hr>";

# back
if($subnet['isFolder']==1) {
	print "<a class='btn btn-sm btn-default' href='".create_link("folder",$GET->section,$GET->subnetId)."'><i class='fa fa-gray fa-chevron-left'></i>  "._('Back to subnet')."</a>";
} else {
	print "<a class='btn btn-sm btn-default' href='".create_link("subnets",$GET->section,$GET->subnetId)."'><i class='fa fa-gray fa-chevron-left'></i> "._('Back to subnet')."</a>";
}


/* current subnet changelog */

# empty
if(sizeof($clogs)==0) {
	print "<blockquote style='margin-top:20px;margin-left:20px;'>";
	print "<p>"._("No changelogs available")."</p>";
	print "<small>"._("No changelog entries are available for this subnet")."</small>";
	print "</blockquote>";
}
# result
else {
	# printout
	print "<table class='table table-striped table-top table-condensed' style='margin-top:30px;'>";

	# headers
	print "<tr>";
	print "	<th>"._('User')."</th>";
	print "	<th>"._('Subnet')."</th>";
	print "	<th>"._('Action')."</th>";
	print "	<th>"._('Result')."</th>";
	print "	<th>"._('Date')."</th>";
	print "	<th>"._('Change')."</th>";
	print "</tr>";

	# logs
	foreach($clogs as $l) {
		$l = (array) $l;
		# format diff
		$l['cdiff'] = str_replace("\n", "<br>", $l['cdiff']);

		# set class for badge
		if($l['cresult']=="success") { $bclass='alert-success'; }
		else 						 { $bclass='alert-danger'; }

		print "<tr>";
		print "	<td>$l[real_name]</td>";
		# folder?
		if($subnet['isFolder']==1)			{ print "	<td><a href='".create_link("subnets",$GET->section,$GET->subnetId)."'>$subnet[description]</a></td>"; }
		else 								{ print "	<td><a href='".create_link("subnets",$GET->section,$GET->subnetId)."'>$subnet[ip]/$subnet[mask]</a></td>"; }
		print "	<td><span class='badge badge1 badge5'>"._(ucwords($l['caction']))."</span></td>";
		print "	<td><span class='badge badge1 badge5 $bclass'>"._(ucwords("$l[cresult]"))."</span></td>";
		print "	<td class='text-muted'>$l[cdate]</td>";
		print "	<td><p style='background: rgba(0,0,0,0.1); padding:10px 20px;border-radius:4px;margin-bottom:0px;'>$l[cdiff]</p></td>";
		print "</tr>";

	}

	print "</table>";
}


/* Subnet slaves changelog */

# empty
if($clogsSlaves) {
	# header
	print "<h4 style='margin-top:30px;'>"._('Slave subnets')." "._('Changelog')."</h4><hr>";

	# printout
	print "<table class='table table-striped table-top table-condensed'>";

	# headers
	print "<tr>";
	print "	<th>"._('User')."</th>";
	print "	<th>"._('Subnet')."</th>";
	print "	<th>"._('Description')."</th>";
	print "	<th>"._('Action')."</th>";
	print "	<th>"._('Result')."</th>";
	print "	<th>"._('Date')."</th>";
	print "	<th>"._('Change')."</th>";
	print "</tr>";

	# logs
	foreach($clogsSlaves as $l) {
		$l = (array) $l;
		# format diff
		$l['cdiff'] = str_replace("\n", "<br>", $l['cdiff']);

		print "<tr>";
		print "	<td>$l[real_name]</td>";
		# folder?
		if($l['isFolder']==1)				{ print "	<td><a href='".create_link("subnets",$l['sectionId'],$l['id'])."'>$l[description]</a></td>"; }
		else 								{ print "	<td><a href='".create_link("subnets",$l['sectionId'],$l['id'])."'>".$Subnets->transform_to_dotted($l['subnet'])."/$l[mask]</a></td>"; }
		print "	<td>$l[description]</td>";
		print "	<td>"._("$l[caction]")."</td>";
		print "	<td>"._("$l[cresult]")."</td>";
		print "	<td>$l[cdate]</td>";
		print "	<td>$l[cdiff]</td>";
		print "</tr>";

	}

	print "</table>";
}


/* IP changelog */

if($clogsAddresses) {
	# header
	print "<h4 style='margin-top:30px;'>"._('Underlying hosts')." "._('Changelog')."</h4><hr>";

	# printout
	print "<table class='table table-striped table-top table-condensed'>";

	# headers
	print "<tr>";
	print "	<th>"._('User')."</th>";
	print "	<th>"._('IP')."</th>";
	print "	<th>"._('Action')."</th>";
	print "	<th>"._('Result')."</th>";
	print "	<th>"._('Date')."</th>";
	print "	<th>"._('Change')."</th>";
	print "</tr>";

	# logs
	foreach($clogsAddresses as $l) {
		$l = (array) $l;
		# format diff
		if(!is_null($l['cdiff']))
		$l['cdiff'] = str_replace("\n", "<br>", $l['cdiff']);

		print "<tr>";
		print "	<td>$l[real_name]</td>";
		print "	<td><a href='".create_link("subnets",$GET->section,$GET->subnetId,"address-details",$l['id'])."'>".$Subnets->transform_to_dotted($l['ip_addr'])."</a></td>";
		print "	<td>"._("$l[caction]")."</td>";
		print "	<td>"._("$l[cresult]")."</td>";
		print "	<td>$l[cdate]</td>";
		print "	<td>$l[cdiff]</td>";
		print "</tr>";

	}

	print "</table>";
}
