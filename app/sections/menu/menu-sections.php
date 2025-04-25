<?php
# verify that user is logged in
$User->check_user_session();
?>

<!-- 区域菜单 -->
<ul class="nav navbar-nav">
	<?php
	# if section is not set
	if(!isset($GET->section)) { $GET->section = ""; }

	# dashboard - 首页已在导航栏添加，此处删除

	# first item - subnets or admin
    print "<li class='first-item'>";
    print " <a href='".create_link("subnets")."'><i class='fa fa-sitemap'></i> "._('Subnets')."</a>";
    print "</li>";

	# printout
	if($sections!==false) {
		# loop
		foreach($sections as $section) {
			# check permissions for user
			$perm = $Sections->check_permission ($User->user, $section->id);
			if($perm > 0 ) {
				# print only masters!
				if($section->masterSection=="0" || empty($section->masterSection)) {

					# check if has slaves
					unset($sves);
					foreach($sections as $s) {
						if($s->masterSection==$section->id) { $sves[$s->id] = $s; }
					}

					# slaves?
					if(isset($sves)) {
						print "<li class='dropdown'>";
						print " <a class='dropdown-toggle' data-toggle='dropdown'>";
                        print "<i class='fa fa-folder-open'></i> $section->name";
                        print "<b class='caret' style='margin-left:5px;'></b></a>";
						print "	<ul class='dropdown-menu'>";

						# section
						if($GET->section==$section->id) { 
                            print "<li class='active'><a href='".create_link("subnets",$section->id)."'>";
                            print "<i class='fa fa-folder-open'></i> $section->name</a></li>"; 
                        }
						else { 
                            print "<li><a href='".create_link("subnets",$section->id)."'>";
                            print "<i class='fa fa-folder'></i> $section->name</a></li>";
                        }

						print "	<li class='divider'></li>";

						# subsections
						foreach($sves as $sl) {
							if($GET->section==$sl->id) {
                                print "<li class='active'><a href='".create_link("subnets",$sl->id)."'>";
                                print "<i class='fa fa-folder-open'></i> $sl->name</a></li>";
                            }
							else {
                                print "<li><a href='".create_link("subnets",$sl->id)."'>";
                                print "<i class='fa fa-folder'></i> $sl->name</a></li>";
                            }
						}

						print "	</ul>";
						print "</li>";
					}
					# no slaves
					else {
						if( ($section->name == $GET->section) || ($section->id == $GET->section) ) { 
                            print "<li class='active'>"; 
                        }
						else { 
                            print "<li>"; 
                        }

						print "	<a href='".create_link("subnets",$section->id)."' rel='tooltip' data-placement='bottom' title='$section->description'>";
                        print "<i class='fa fa-folder'></i> $section->name</a>";
						print "</li>";
					}
				}
			}
		}
	}
	else {
		print "<div class='text-muted'>"._("No sections available!")."</div>";
	}
	?>
</ul>
