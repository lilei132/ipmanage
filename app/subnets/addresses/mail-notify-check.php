<?php

/**
 * Script to verify posted data for mail notif
 *************************************************/

# include required scripts
require_once( dirname(__FILE__) . '/../../../functions/functions.php' );

# initialize required objects
$Database 	= new Database_PDO;
$Result		= new Result;
$User		= new User ($Database);
$Tools		= new Tools ($Database);

$User->Crypto->csrf_cookie ("validate", "mail_notify", $POST->csrf_cookie) === false ? $Result->show("danger", _("Invalid CSRF cookie"), true) : "";

# verify that user is logged in
$User->check_user_session();

# verify each recipient
foreach (pf_explode(",", $POST->recipients) as $rec) {
	if(!filter_var(trim($rec), FILTER_VALIDATE_EMAIL)) {
		$Result->show("danger", _("邮箱地址无效")." - ".$rec, true);
	}
}

# try to send
try {
	# fetch mailer settings
	$mail_settings = $Tools->fetch_object("settingsMail", "id", 1);

	# initialize mailer
	$phpipam_mail = new phpipam_mail($User->settings, $mail_settings);

	// set subject
	$subject	= $POST->subject;

	// set html content
	$content[] = "<table style='margin-left:10px;margin-top:5px;width:auto;padding:0px;border-collapse:collapse;'>";
	$content[] = "<tr><td style='padding:5px;margin:0px;border-bottom:1px solid #eeeeee;'>$User->mail_font_style<strong>$subject</strong></font></td></tr>";
	foreach(pf_explode("\r\n", $POST->content) as $c) {
	$content[] = "<tr><td style='padding-left:15px;margin:0px;'>$User->mail_font_style $c</font></td></tr>";
	}
	$content[] = "<tr><td style='padding-left:15px;padding-top:20px;margin:0px;font-style:italic;'>$User->mail_font_style_light Sent by user ".$User->user->real_name." at ".date('Y/m/d H:i')."</font></td></tr>";
	//set al content
	$content_plain[] = "$subject"."\r\n------------------------------\r\n";
	$content_plain[] = str_replace("&middot;", "\t - ", $POST->content);
	$content_plain[] = "\r\n\r\n"._("Sent by user")." ".$User->user->real_name." at ".date('Y/m/d H:i');
	$content[] = "</table>";

	// set alt content
	$content 		= $phpipam_mail->generate_message (implode("\r\n", $content));
	$content_plain 	= implode("\r\n",$content_plain);

	$phpipam_mail->Php_mailer->setFrom($mail_settings->mAdminMail, $mail_settings->mAdminName);
	foreach(pf_explode(",", $POST->recipients) as $r) {
	$phpipam_mail->Php_mailer->addAddress(addslashes(trim($r)));
	}
	$phpipam_mail->Php_mailer->Subject = $subject;
	$phpipam_mail->Php_mailer->msgHTML($content);
	$phpipam_mail->Php_mailer->AltBody = $content_plain;
	//send
	$phpipam_mail->Php_mailer->send();
} catch (PHPMailer\PHPMailer\Exception $e) {
	$Result->show("danger", "Mailer Error: ".$e->errorMessage(), true);
} catch (Exception $e) {
	$Result->show("danger", "Mailer Error: ".$e->getMessage(), true);
}

# all good
$Result->show("success", _('邮件发送成功')."!" , true);
