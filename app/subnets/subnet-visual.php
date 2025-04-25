<?php

# show squares to display free/used subnet

print "<br><h4>"._('Visual subnet display')." <i class='icon-gray icon-info-sign' rel='tooltip' data-html='true' title='"._('Click on IP address box<br>to manage IP address')."!'></i></h4><hr>";

# 添加图例说明 - 放在上方
print "<div class='ip_vis_legend'>";
print "<h5>"._('标记说明')."</h5>";
print "<ul class='list-inline'>";
print "<li><span class='status' style='background-color:".$Subnets->address_types[5]['bgcolor']."'></span> "._('最近30天内使用')."</li>";
print "<li><span class='status' style='background-color:".$Subnets->address_types[6]['bgcolor']."'></span> "._('超过30天未使用')."</li>";
print "<li><span class='status' style='background-color:".$Subnets->address_types[1]['bgcolor']."'></span> "._('离线')."</li>";
print "<li><span class='status' style='background-color:".$Subnets->address_types[2]['bgcolor']."'></span> "._('使用中')."</li>";
print "<li><span class='status' style='background-color:".$Subnets->address_types[3]['bgcolor']."'></span> "._('预留')."</li>";
print "<li><span class='status' style='background-color:#ffffff'></span> "._('未使用')."</li>";
print "</ul>";
print "</div>";

print "<div class='ip_vis'>";

# we need to reindex addresses to have ip address in decimal as key!
$visual_addresses = array();
if($addresses_visual) {
	foreach($addresses_visual as $a) {
		$visual_addresses[$a->ip_addr] = (array) $a;
	}
}

$alpha = ($User->user->theme == "dark") ? "cc" : "";

# 设置30天时间阈值
$thirtyDaysAgo = date("Y-m-d H:i:s", strtotime("-30 days"));

# print
foreach ($Subnets->get_all_possible_subnet_addresses($subnet) as $m) {
	$ip_addr = $Subnets->transform_to_dotted($m);
	$title = $ip_addr;

	# already exists
	if (array_key_exists($m, $visual_addresses)) {

		# fix for empty states - if state is disabled, set to active
		if(is_blank($visual_addresses[$m]['state'])) { $visual_addresses[$m]['state'] = 1; }
		
		# 检查最后响应时间，设置对应标签
		$lastSeen = $visual_addresses[$m]['lastSeen'];
		$original_state = $visual_addresses[$m]['state'];
		
		# 根据lastSeen时间判断使用状态
		if (!empty($lastSeen) && $lastSeen != "1970-01-01 00:00:01" && $lastSeen != "0000-00-00 00:00:00") {
			# 最近30天内响应过
			if ($lastSeen >= $thirtyDaysAgo) {
				$visual_addresses[$m]['state'] = 5; // 最近使用
			} 
			# 超过30天未响应
			else {
				$visual_addresses[$m]['state'] = 6; // 久未使用
			}
		}

		# to edit
		$class = $visual_addresses[$m]['state'];
		$action = 'all-edit';
		$id = (int) $visual_addresses[$m]['id'];

		# tooltip 增加显示最后响应时间
		if(!is_blank($visual_addresses[$m]['hostname']))		{ $title .= "<br>".$visual_addresses[$m]['hostname']; }
		if(!is_blank($visual_addresses[$m]['description']))	{ $title .= "<br>".$visual_addresses[$m]['description']; }
		if(!empty($lastSeen) && $lastSeen != "1970-01-01 00:00:01" && $lastSeen != "0000-00-00 00:00:00") {
		    $title .= "<br>最后响应: ".$lastSeen;
		}

		# set colors
		$background = $Subnets->address_types[$visual_addresses[$m]['state']]['bgcolor'].$alpha." !important";
		$foreground = $Subnets->address_types[$visual_addresses[$m]['state']]['fgcolor'];
	}
	else {
		# print add new
		$class = "unused";
		$id = $m;
		$action = 'all-add';

		# set colors
		$background = "#ffffff";
		$foreground = "#333333";
	}

	# print box
	$shortname = ($Subnets->identify_address($m) == "IPv6") ? substr(strrchr($ip_addr,':'), 1) : '.'.substr(strrchr($ip_addr,'.'), 1);

	if($subnet_permission > 1) 	{
		print "<span class='ip-$class modIPaddr' 	style='background:$background;color:$foreground' data-action='$action' rel='tooltip' title='$title' data-position='top' data-html='true' data-subnetId='".$subnet['id']."' data-id='$id'>".$shortname."</span>";
	} else {
		print "<span class='ip-$class '  			style='background:$background;color:$foreground' data-action='$action' data-subnetId='".$subnet['id']."' data-id='$id'>".$shortname."</span>";
	}
	print "\n";
}
print "</div>";
print "<div class='clearfix' style='padding-bottom:20px;'></div>";	# clear float
