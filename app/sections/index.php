<!--[if lt IE 9]>
<style type="text/css">
.tooltipBottom,
.tooltipLeft,
.tooltipTop,
.tooltipTopDonate,
.tooltip,
.tooltipRightSubnets {
	filter: progid:DXImageTransform.Microsoft.gradient( startColorstr='#e61d2429', endColorstr='#b3293339',GradientType=0 );
}
.tooltipBottom {
	filter: progid:DXImageTransform.Microsoft.gradient( startColorstr='#e61d2429', endColorstr='#b3293339',GradientType=0 );
}
</style>
<![endif]-->

<!-- 引入Element UI样式 -->
<link rel="stylesheet" href="/css/elementui/index.css">

<!-- 自定义字体路径覆盖 -->
<style>
@font-face {
  font-family: 'element-icons';
  src: url('/css/elementui/fonts/element-icons.ttf') format('truetype');
  font-weight: normal;
  font-style: normal;
}
</style>

<!-- 自定义菜单样式 -->
<style type="text/css">
/* 基础样式覆盖 */
.navbar-default {
    background-color: #409EFF;
    border-color: #337ab7;
    border-radius: 0;
    margin-bottom: 0;
    box-shadow: 0 2px 12px 0 rgba(0, 0, 0, 0.1);
}
.navbar-default .navbar-nav > li > a,
.navbar-default .navbar-brand,
.home-button {
    color: #fff;
}
.navbar-default .navbar-nav > li > a:hover,
.navbar-default .navbar-nav > li > a:focus,
.home-button:hover {
    color: #fff;
    background-color: rgba(255, 255, 255, 0.1);
}
.navbar-default .navbar-nav > .active > a, 
.navbar-default .navbar-nav > .active > a:hover, 
.navbar-default .navbar-nav > .active > a:focus {
    color: #fff;
    background-color: rgba(255, 255, 255, 0.2);
}
.navbar-default .navbar-nav > .open > a, 
.navbar-default .navbar-nav > .open > a:hover, 
.navbar-default .navbar-nav > .open > a:focus {
    color: #fff;
    background-color: rgba(255, 255, 255, 0.2);
}
.dropdown-menu {
    border-radius: 4px;
    box-shadow: 0 2px 12px 0 rgba(0, 0, 0, 0.1);
    border: 1px solid #ebeef5;
    padding: 6px 0;
}
.dropdown-menu > li > a {
    padding: 8px 20px;
    font-size: 14px;
    color: #606266;
}
.dropdown-menu > li > a:hover,
.dropdown-menu > li > a:focus {
    background-color: #ecf5ff;
    color: #409EFF;
}
.navbar-default .navbar-toggle {
    border-color: rgba(255, 255, 255, 0.5);
}
.navbar-default .navbar-toggle .icon-bar {
    background-color: #fff;
}
.navbar-default .navbar-toggle:hover, 
.navbar-default .navbar-toggle:focus {
    background-color: rgba(255, 255, 255, 0.1);
}
.navbar-default .navbar-collapse, 
.navbar-default .navbar-form {
    border-color: rgba(255, 255, 255, 0.1);
}
.nav-divider {
    height: 1px;
    margin: 6px 0;
    overflow: hidden;
    background-color: #e5e5e5;
}
.dropdown-menu .nav-header {
    display: block;
    padding: 8px 20px;
    font-size: 12px;
    line-height: 1.42857143;
    color: #909399;
    white-space: nowrap;
    margin-top: 4px;
    font-weight: bold;
}
.dropdown-menu > li.active > a,
.dropdown-menu > li.active > a:focus {
    color: #409EFF;
    background-color: #ecf5ff;
}

/* 用户菜单样式 */
.padding-left-15 {
    display: block;
    padding: 8px 20px;
    clear: both;
    font-weight: normal;
    color: #606266;
    white-space: nowrap;
}
.dropdown-menu > li > span {
    padding: 8px 20px;
    display: block;
    font-size: 12px;
    color: #909399;
}
.navbar .dropdown-menu {
    max-height: 80vh;
    overflow-y: auto;
}
.home-button {
    padding: 15px 15px;
    display: inline-block;
    line-height: 20px;
    text-decoration: none;
}
.home-button:hover {
    text-decoration: none;
}
.navbar-nav > li > a {
    padding-top: 15px;
    padding-bottom: 15px;
}
.navbar-nav > li > a i.fa {
    margin-right: 5px;
}
.divider {
    height: 1px;
    margin: 6px 0;
    overflow: hidden;
    background-color: #e5e5e5;
}

/* 小屏幕适配 */
@media (max-width: 768px) {
    .navbar-nav .open .dropdown-menu {
        position: static;
        float: none;
        width: auto;
        margin-top: 0;
        background-color: transparent;
        border: 0;
        box-shadow: none;
    }
    .navbar-nav .open .dropdown-menu > li > a,
    .navbar-nav .open .dropdown-menu > li > span {
        padding: 10px 15px 10px 25px;
        color: #fff;
    }
    .navbar-nav .open .dropdown-menu > li > a:hover,
    .navbar-nav .open .dropdown-menu > li > a:focus {
        background-color: rgba(255, 255, 255, 0.1);
        color: #fff;
    }
    .home-button {
        padding: 10px 15px;
        display: inline-block;
    }
    .navbar-default .navbar-nav .open .dropdown-menu > li > a {
        color: #fff;
    }
    .navbar-default .navbar-nav .open .dropdown-menu > li > a:hover,
    .navbar-default .navbar-nav .open .dropdown-menu > li > a:focus {
        color: #fff;
        background-color: rgba(255, 255, 255, 0.1);
    }
    .dropdown-menu .divider {
        background-color: rgba(255, 255, 255, 0.2);
    }
    .dropdown-menu .nav-header {
        color: rgba(255, 255, 255, 0.8);
    }
}
</style>


<?php

/**
 * Script to print sections and admin link on top of page
 ********************************************************/

# verify that user is logged in
$User->check_user_session();

# fetch all sections
$sections = $Sections->fetch_all_sections ();

# check for requests
if ($User->settings->enableIPrequests==1) {
	# count requests
	$requests = $Tools->requests_fetch(true);
	# remove
	if ($requests==0) { unset($requests); }
	# parse
	if ($User->is_admin(false)==false && isset($requests)) {
		# fetch all Active requests
		$requests   = $Tools->fetch_multiple_objects ("requests", "processed", 0, "id", false);
		foreach ($requests as $k=>$r) {
			// check permissions
			if($Subnets->check_permission($User->user, $r->subnetId) != 3) {
				unset($requests[$k]);
			}
		}
		# null
		if (sizeof($requests)==0) {
			unset($requests);
		} else {
			$requests = sizeof($requests);
		}
	}
}

# get admin and tools menu items
require( dirname(__FILE__) . '/../tools/tools-menu-config.php' );
require( dirname(__FILE__) . '/../admin/admin-menu-config.php' );

?>

<!-- Section navigation -->
<div class="navbar" id="menu">
<nav class="navbar navbar-default" id="menu-navbar" role="navigation">

	<!-- Collapsed display for mobile -->
	<div class="navbar-header">
		<button type="button" class="navbar-toggle" data-toggle="collapse" data-target="#menu-collapse">
			<span class="icon-bar"></span>
			<span class="icon-bar"></span>
			<span class="icon-bar"></span>
		</button>
		<a href="<?php print create_link(); ?>" class="home-button visible-xs">
            <i class="fa fa-home"></i> <?php print _("首页"); ?>
        </a>
		<span class="navbar-brand visible-xs"><?php print _("Menu"); ?></span>
	</div>

	<!-- menu -->
	<div class="collapse navbar-collapse" id="menu-collapse">
        <!-- 添加首页链接 -->
        <ul class="nav navbar-nav">
            <li>
                <a href="<?php print create_link(); ?>">
                    <i class="fa fa-home"></i> <?php print _("首页"); ?>
                </a>
            </li>
            <li>
                <a href="<?php print create_link("tools", "traffic-monitor"); ?>">
                    <i class="fa fa-chart-line"></i> <?php print _("流量监控"); ?>
                </a>
            </li>
            <li>
                <a href="<?php print create_link("subnets"); ?>">
                    <i class="fa fa-sitemap"></i> <?php print _("子网管理"); ?>
                </a>
            </li>
        </ul>
        
        <?php
        # static?
        if($User->user->menuType=="Static") {
            # static menu
            include("menu/menu-static.php");
        }
        else {
            # dashboard, tools menu
            if (!isset($GET->page) || $GET->page=="dashboard" || $GET->page=="tools") {
                include("menu/menu-tools.php");
            }
            # admin menu
            elseif ($GET->page=="administration") {
                include("menu/menu-administration.php");
            }
            else {
                include("menu/menu-sections.php");
            }

            # tools and admin menu
            include("menu/menu-tools-admin.php");
        }
        ?>
        
        <!-- 添加用户信息和登出功能到折叠菜单 -->
        <ul class="nav navbar-nav navbar-right">
            <li class="dropdown">
                <a href="#" class="dropdown-toggle" data-toggle="dropdown">
                    <i class="fa fa-user"></i> 
                    <?php print $User->user->real_name; ?> 
                    <b class="caret"></b>
                </a>
                <ul class="dropdown-menu">
                    <li><a href="<?php print create_link("tools","user-menu"); ?>"><i class="fa fa-user"></i> <?php print _('User profile'); ?></a></li>
                    <li class="divider"></li>
                    <li><span class="padding-left-15"><?php print _('Logged in as'); ?> <?php print "&nbsp;"._($User->user->role); ?></span></li>
                    <?php if(isset($_SESSION['realipamusername'])) { 
                        $realuser = $Tools->fetch_object("users", "username", $_SESSION['realipamusername']);
                    ?>
                    <li><span class="padding-left-15"><?php print _('Switched to'); ?> <?php print $User->user->real_name; ?></span></li>
                    <li><a href="<?php print create_link(null)."?switch=back"; ?>"><i class="fa fa-undo"></i> <?php print _('Switch back user'); ?></a></li>
                    <?php } else { ?>
                    <li><a href="<?php print create_link("login"); ?>"><i class="fa fa-sign-out"></i> <?php print _('Logout'); ?></a></li>
                    <?php } ?>
                </ul>
            </li>
        </ul>
	</div>	 <!-- end menu div -->
</nav>
</div>

<!-- Element UI JS (可选，如果需要一些交互组件) -->
<script src="/js/elementui/vue.min.js"></script>
<script src="/js/elementui/index.js"></script>
<script>
    // 初始化Vue和Element UI组件
    document.addEventListener('DOMContentLoaded', function() {
        // 测试Element UI是否成功加载
        if (typeof ELEMENT !== 'undefined') {
            console.log('Element UI 成功加载!');
            
            // 创建Vue实例
            new Vue({
                el: '#app'
            });
        }
        
        // 增强菜单交互体验
        const menuItems = document.querySelectorAll('.dropdown-toggle');
        menuItems.forEach(item => {
            item.addEventListener('mouseenter', function() {
                if(window.innerWidth >= 768) {
                    this.click();
                }
            });
            
            const parentLi = item.parentElement;
            parentLi.addEventListener('mouseleave', function() {
                if(window.innerWidth >= 768 && this.classList.contains('open')) {
                    item.click();
                }
            });
        });
    });
</script>
