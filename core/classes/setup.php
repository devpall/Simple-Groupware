<?php
/**
 * @package Simple Groupware
 * @link http://www.simple-groupware.de
 * @copyright Simple Groupware Solutions Thomas Bley 2002-2012
 * @license GPLv2
 */

class setup {

static $errors = array();

static function build_customizing($file) {
  if (!file_exists($file)) return;
  self::out("Building customizations:");
  self::out("Execute ".$file);
  require($file);
}

static function customize_replace($file,$code_remove,$code_new) {
  setup::out($file.":<br/>Replace:");
  setup::out(nl2br(q($code_remove))."<br/>");
  setup::out("with:<br/>".nl2br(q($code_new))."<br/>");
  $data = file_get_contents($file);
  if (strpos($data,$code_remove)===false) {
	throw new Exception("code not found in: ".$file." Code: ".$code_remove);
  }
  file_put_contents($file, str_replace($code_remove,$code_new,$data));
}

static function save_config($vars) {
  $out = array();
  $out[] = "<?"."php";
  $out[] = "define('CORE_VERSION','".CORE_VERSION."');";
  $out[] = "define('CORE_VERSION_STRING','".CORE_VERSION_STRING."');";
  $out[] = "define('CORE_SGSML_VERSION','".CORE_SGSML_VERSION."');";
  foreach ($vars as $key=>$var) $out[] = "define('".$key."',".$var.");";

  foreach (self::config_defaults() as $key=>$var) {
	$var = setup_update::get_config_old($key,true,$var);
	$out[] = "define('".$key."',".$var.");";
  }
  $out[] = "if (TIMEZONE!='') date_default_timezone_set(TIMEZONE);\n".
		   "  elseif (!ini_get('date.timezone')) date_default_timezone_set(@date_default_timezone_get());";
  $out[] = "if (!ini_get('display_errors')) @ini_set('display_errors','1');";
  $out[] = "define('NOW',time());";
  $out[] = "define('LANG','".LANG."');";
  $out[] = "define('APC',function_exists('apc_store') and ini_get('apc.enabled'));";

  file_put_contents("simple_store/config.php", implode("\n",$out), LOCK_EX);
  if (!file_exists("simple_store/config.php") or filesize("simple_store/config.php")==0) {
	sys_die("cannot write to: simple_store/config.php");
  }
  chmod("simple_store/config.php", 0600);
  sys_log_message_log("info",sprintf("{t}Setup: setup-data written to %s.{/t}","simple_store/config.php"));
}

static function validate_system() {
  $extensions = array("xml", "gd", "pcre", "session", "zlib", "SimpleXML", "json");
  $db_extensions = array("mysqli"=>array("MySQL", "5.00"), "pgsql"=>array("PostgreSQL", "8.36"), "pdo_sqlite"=>array("SQLite", "3.00"));

  $on = array("1", "on", "On");
  $off = array("0", "off", "Off", "");
  $settings = array(
	"safe_mode" => $off, "file_uploads" => $on, "session.auto_start" => $off, "magic_quotes_runtime" => $off, "display_errors" => $on
  );
  $memorylimit = 24000000;

  if (!empty($_SERVER["SERVER_SOFTWARE"]) and !preg_match("/Apache|nginx|IIS|PHP/", $_SERVER["SERVER_SOFTWARE"])) {
	self::error_add("{t}Please choose Apache as Web-Server.{/t} (".$_SERVER["SERVER_SOFTWARE"].")","2".$_SERVER["SERVER_SOFTWARE"]);
  }

  $memory = ini_get("memory_limit");
  if (!empty($memory)) {
	$memory = (int)str_replace("m","000000",strtolower($memory));
	if ($memory < $memorylimit) self::error_add(sprintf("{t}Please modify your php.ini or add an .htaccess file changing the setting '%s' to '%s' (current value is '%s') !{/t}","memory_limit",str_replace("000000","M",$memorylimit),ini_get("memory_limit")),4);
  }

  $sys_extensions = get_loaded_extensions();
  foreach($extensions as $key) {
	if (!in_array($key, $sys_extensions)) self::error_add(sprintf("{t}Setup needs php-extension with name %s !{/t}",$key),"5".$key);
  }
  $databases = array();
  foreach ($db_extensions as $key => $vals) {
	if (in_array($key, $sys_extensions)) $databases[str_replace("pdo_","",$key)] = $vals;
  }
  if (count($databases)==0) self::error_add(sprintf("{t}Setup needs a database-php-extension ! (%s){/t}",implode(", ",array_keys($db_extensions))),6);

  foreach ($settings as $setting => $values) {
	if (!in_array(ini_get($setting), $values)) self::error_add(sprintf("{t}Please modify your php.ini or add an .htaccess file changing the setting '%s' to '%s' (current value is '%s') !{/t}",$setting,$values[0],ini_get($setting)),"7".$setting);
  }

  clearstatcache();
  if (!is_writable(SIMPLE_CACHE."/") or !is_writable(SIMPLE_STORE."/")) {
	$message = sprintf("[1] {t}Please give write access to %s and %s{/t}",SIMPLE_CACHE."/",SIMPLE_STORE."/");
	$message .= sprintf("\n{t}If file system permissions are ok, please check the configurations of %s if present.{/t}", "SELinux, suPHP, Suhosin");
	self::error_add($message,8);
  }
  if (!is_writable("ext/cache/")) self::error_add(sprintf("[2] {t}Please give write access to %s{/t}","ext/cache/"),9);
  if (!is_readable("lang/")) self::error_add(sprintf("[3] {t}Please give read access to %s{/t}","lang/"),11);
  if (is_dir("import/") and !is_readable("import/")) self::error_add(sprintf("[4] {t}Please give read access to %s{/t}","import/"),111);
  self::errors_show(true);

  return $databases;
}

static function validate_input($databases) {
  if ($validate=validate::username($_REQUEST["admin_user"]) and $validate!="") setup::error_add("{t}Admin Username{/t} - {t}validation failed{/t} ".$validate,30);
  if ($_REQUEST["db_host"]=="") setup::error_add(sprintf("{t}missing field{/t}: %s","{t}Database Hostname / IP{/t}"),31);
  if ($_REQUEST["db_user"]=="") setup::error_add(sprintf("{t}missing field{/t}: %s","{t}Database User{/t}"),32);
  if ($_REQUEST["db_name"]=="") setup::error_add(sprintf("{t}missing field{/t}: %s","{t}Database Name{/t}"),33);
  if ($_REQUEST["admin_pw"]=="") setup::error_add(sprintf("{t}missing field{/t}: %s","{t}Admin Password{/t}"),34);
  if ($_REQUEST["admin_pw"]!="" and strlen($_REQUEST["admin_pw"])<5) setup::error_add("{t}Admin Password{/t}: {t}Password must be not null, min 5 characters.{/t}","34b");

  if (!@sql_connect($_REQUEST["db_host"], $_REQUEST["db_user"], $_REQUEST["db_pw"], $_REQUEST["db_name"])) {
    if (!sql_connect($_REQUEST["db_host"], $_REQUEST["db_user"], $_REQUEST["db_pw"])) setup::error_add("{t}Connection to database failed.{/t}\n".sql_error(),35);
	setup::errors_show();
	if (!sgsml_parser::create_database($_REQUEST["db_name"])) setup::error_add("{t}Creating database failed.{/t}\n".sql_error(),36);
  }
  if (!sql_connect($_REQUEST["db_host"], $_REQUEST["db_user"], $_REQUEST["db_pw"], $_REQUEST["db_name"]) or empty(sys::$db)) {
    setup::error_add("{t}Connection to database failed.{/t}\n".sql_error(),37);
	setup::errors_show();
  }

  if (!$version = sgsml_parser::sql_version()) setup::error_add(sprintf("{t}Could not determine database-version.{/t}"),38);
  $database_min = (int)substr(str_replace(".","",$databases[SETUP_DB_TYPE][1]),0,3);
  if ($version < $database_min) setup::error_add(sprintf("{t}Wrong database-version (%s). Please use at least %s !{/t}",$version,$databases[SETUP_DB_TYPE]),"20".SETUP_DB_TYPE);

  if (SETUP_DB_TYPE=="pgsql") {
  	if (!sql_query("SELECT ''::tsvector;")) {
	  setup::error_add("{t}Please install 'tsearch2' for the PostgreSQL database.{/t}\n(Run <postgresql>/share/contrib/tsearch2.sql)\n".sql_error(),21);
	}
    if (!sql_query(file_get_contents("modules/core/pgsql.sql"))) setup::error_add("pgsql.sql: ".sql_error(),50);
  }
  setup::errors_show();
  return $version;
}

static function config_defaults() {
  $session_name = md5("simple_session_".CORE_VERSION.__DIR__);
  return array(
	"APP_TITLE"=>"'Simple Groupware & CMS'",
	"CMS_TITLE"=>"'PmWiki & Simple Groupware'",
	"SETUP_ADMIN_USER2"=>"''", "SETUP_ADMIN_PW2"=>"''",
	"SETUP_AUTH"=>"'sql'", "SETUP_AUTH_AUTOCREATE"=>"0",
	"SETUP_AUTH_DOMAIN"=>"''", "SETUP_AUTH_DOMAIN_GDATA"=>"''", "SETUP_AUTH_DOMAIN_IMAP"=>"''",
	"SETUP_AUTH_LDAP_USER"=>"''", "SETUP_AUTH_LDAP_PW"=>"''", "SETUP_AUTH_BASE_DN"=>"''", "SETUP_AUTH_LDAP_UID"=>"'uid'",
	"SETUP_AUTH_LDAP_MEMBEROF"=>"'memberOf'", "SETUP_AUTH_LDAP_ROOM"=>"''",	"SETUP_AUTH_LDAP_GROUPS"=>"0",
	"SETUP_AUTH_HOSTNAME_LDAP"=>"''", "SETUP_AUTH_HOSTNAME_IMAP"=>"''",
	"SETUP_AUTH_HOSTNAME_SMTP"=>"''", "SETUP_AUTH_HOSTNAME_NTLM"=>"''", "SETUP_AUTH_NTLM_SHARE"=>"''", "CHECK_DOS"=>"1",
	"ENABLE_ANONYMOUS"=>"1", "ENABLE_ANONYMOUS_CMS"=>"1", "DISABLE_BASIC_AUTH"=>"0", "MOUNTPOINT_REQUIRE_ADMIN"=>"0", 
	"SELF_REGISTRATION"=>"0", "SELF_REGISTRATION_CONFIRM"=>"0", "DISABLED_MODULES"=>"''",
	"ENABLE_EXT_MAILCLIENT"=>"0", "USE_DEBIAN_BINARIES"=>"0", "USE_MAIL_FUNCTION"=>"0", "USE_SYSLOG_FUNCTION"=>"0",
	"DEBUG_SQL"=>"false", "DEBUG_IMAP"=>"false", "DEBUG_POP3"=>"false", "DEBUG_JS"=>"false", "DEBUG_SMTP"=>"false", "DEBUG_JAVA"=>"false",
	"LOCKING"=>"900", "FOLDER_REFRESH"=>"5", "LOGIN_TIMEOUT"=>"7200", "SESSION_NAME"=>"'".$session_name."'", "DEFAULT_STYLE"=>"'core'",
	"WEEKSTART"=>"0", "OUTPUT_CACHE"=>"86400", "CSV_CACHE"=>"300", "LDIF_CACHE"=>"300", "BOOKMARKS_CACHE"=>"300", "ICALENDAR_CACHE"=>"300",
	"RSS_CACHE"=>"600", "VCARD_CACHE"=>"300", "XML_CACHE"=>"300",
	"IMAP_CACHE"=>"300", "IMAP_LIST_CACHE"=>"30", "IMAP_MAIL_CACHE"=>"15552000",
	"POP3_LIST_CACHE"=>"30", "POP3_MAIL_CACHE"=>"15552000",
	"GDOCS_CACHE"=>"300", "GDOCS_LIST_CACHE"=>"30", "GDOCS_PREVIEW_LIMIT"=>"5242880", "CIFS_PREVIEW_LIMIT"=>"10485760",
	"FILE_TEXT_LIMIT"=>"2000", "FILE_TEXT_CACHE"=>"15552000", "CMS_CACHE"=>"86400", "LDAP_LIST_CACHE"=>"120", "INDEX_LIMIT"=>"16384",
	"VIRUS_SCANNER"=>"''", "VIRUS_SCANNER_PARAMS"=>"''", "VIRUS_SCANNER_DISPLAY"=>"''",
	"SYNC4J_REMOTE_DELETE"=>"0", "SYNC4J"=>"0", "ARCHIVE_DELETED_FILES"=>"1",
	"SMTP_FOOTER"=>"'Sent with Simple Groupware http://www.simple-groupware.de/'",
	"SMTP_REMINDER"=>"'Simple Groupware {t}Reminder{/t}'",
	"SMTP_NOTIFICATION"=>"'Simple Groupware {t}Notification{/t}'", "CORE_COMPRESS_OUTPUT"=>"true",
	"APC_SESSION"=>"false","MENU_AUTOHIDE"=>"false","TREE_AUTOHIDE"=>"false","FDESC_IN_CONTENT"=>"false",
	"CMS_HOMEPAGE"=>"'HomePage'", "CMS_REAL_URL"=>"''", "DEBUG"=>"false",
	"SIMPLE_CACHE"=>"'".SIMPLE_CACHE."'", "SIMPLE_STORE"=>"'".SIMPLE_STORE."'", "SIMPLE_CUSTOM"=>"'".SIMPLE_CUSTOM."'",
	"SIMPLE_IMPORT"=>"'import/'", "SIMPLE_EXT"=>"'ext/'", "TIMEZONE"=>"''", "ASSET_PAGE_LIMIT"=>"100",
	"SYSTEM_SLOW"=>"2", "DB_SLOW"=>"0.5", "CMS_SLOW"=>"2", "CHMOD_DIR"=>"777", "CHMOD_FILE"=>"666",
	"INVALID_EXTENSIONS"=>"'386,adb,ade,asd,asf,asp,asx,bas,bat,bin,cab,ceo,cgi,chm,cmd,com,cpl,crt,csc,dat,dbx,dll,drv,".
		"ema,eml,exe,fon,hlp,hta,hto,htt,img,inf,isp,jse,jsp,ins,lnk,mbx,mda,mdt,mdx,mdw,mdz,mht,".
		"msc,msg,msi,mso,mst,msp,obj,ocx,oft,ole,ovl,ovr,php,pif,pl,prf,pst,reg,rm,rtf,scr,scs,sct,shb,".
		"shm,shs,sht,sys,tbb,tbi,uin,vb,vbe,vbs,vbx,vsw,vxd,wab,wsc,wsf,wsh,xl,xla,xsd'",
  );
}

static function dirs_create_default_folders() {
  self::dirs_create_htaccess(SIMPLE_STORE."/");
  self::dirs_create_dir(SIMPLE_EXT);
  self::dirs_create_dir(SIMPLE_STORE."/home");
  self::dirs_create_dir(SIMPLE_STORE."/backup");
  self::dirs_create_dir(SIMPLE_STORE."/syncml");
  self::dirs_create_dir(SIMPLE_STORE."/trash");
  self::dirs_create_dir(SIMPLE_STORE."/cron");
  self::dirs_create_dir(SIMPLE_STORE."/old");

  $empty_dir = array(
    SIMPLE_STORE."/locking",
	SIMPLE_CACHE, SIMPLE_CACHE."/debug", SIMPLE_CACHE."/imap", SIMPLE_CACHE."/pop3",
	SIMPLE_CACHE."/ip", SIMPLE_CACHE."/artichow", SIMPLE_CACHE."/output",
	SIMPLE_CACHE."/schema", SIMPLE_CACHE."/schema_data", SIMPLE_CACHE."/smarty",
	SIMPLE_CACHE."/thumbs", SIMPLE_CACHE."/upload", SIMPLE_CACHE."/backup",
	SIMPLE_CACHE."/preview", SIMPLE_CACHE."/cifs", SIMPLE_CACHE."/gdocs", SIMPLE_CACHE."/cms",
	SIMPLE_CACHE."/lang", "/ext/cache",
  );
  foreach ($empty_dir as $dir) dirs_create_empty_dir($dir);
  self::dirs_create_htaccess(SIMPLE_CACHE."/");
  if (APC) apc_clear_cache("user");
}

static function out($str="",$nl=true,$exit=false) {
  echo $str;
  if ($nl) echo "<br>\n";
  if ($exit) exit;
}

static function out_exit($str) {
  self::out($str,false,true);
}

static function dirs_create_htaccess($dirname) {
  if (!file_exists($dirname.".htaccess")) {
    if (!@file_put_contents($dirname.".htaccess", "Order deny,allow\nDeny from all\n", LOCK_EX)) {
	  self::error_add(sprintf("{t}Please give write access to %s{/t}",$dirname),25);
    }
  }
  dirs_create_index_htm($dirname);
}

static function dirs_create_dir($dirname) {
  if (!is_dir($dirname)) sys_mkdir($dirname);
  dirs_create_index_htm($dirname."/");
}

static function show_lang() {
  setup::out('
    <html>
    <head>
	<title>Simple Groupware & CMS</title>
	<style>
	  body { width:526px; margin:10px auto; }
	  body, a { color: #666666; font-size: 13px; font-family: Arial, Helvetica, Verdana, sans-serif; }
	  a { color: #0000FF; }
	  #logo_table {
		color:#FFFFFF; background-image:url(ext/images/sgs_logo_bg.jpg); width:512px; height:208px; border-radius:4px;
		-moz-transition:opacity 3s; -webkit-transition:opacity 3s; opacity:0;
	  }
	  .logo { border-radius:8px; border:1px solid #AAAAAA; width:526px; height:222px; margin-bottom:10px; }
	  .tab { width:84%; margin:auto; }
	  .font { text-shadow: -1px -1px 0px #101010, 1px 1px 0px #505050; font-family: Coustard, serif; }
	  @font-face { font-family:"Coustard"; src:local("Coustard"), url("ext/images/coustard.woff") format("woff"); }
	</style>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    </head>
    <body onload="document.getElementById(\'logo_table\').style.opacity=1;">

	<table class="logo">
	<tr><td align="center" valign="middle">
	  <table id="logo_table">
		<tr style="height:45px;"><td align="center" valign="top" class="font" style="font-size:80%"><b>Simple Groupware Solutions</b></td></tr>
		<tr><td align="center" class="font" style="font-size:170%;"><b>Simple Groupware<br>'.CORE_VERSION_STRING.'</b></td></tr>
		<tr style="height:50px;"><td valign="bottom" style="font-size:70%">Photo from<br>Axel Kristinsson</td></tr>
	  </table>
	</td></tr>
	</table>
  ',false);
  self::out("<table class='tab'><tr><td>",false);
  $i=0;
  $langs = select::languages();
  foreach ($langs as $lang=>$lang_str) {
	$i++;
	self::out("<a href='index.php?lang=".$lang."'>".$lang_str."</a><br>");
	if ($i == ceil(count($langs)/2)) self::out("</td><td valign='top' align='right'>",false);
  }
  self::out("</td></tr></table>",false);
  self::out('<div style="border-top: 1px solid #666666;">Powered by Simple Groupware, Copyright (C) 2002-2012 by Thomas Bley.</div></body></html>',true, true);
}

static function install_footer() {
  self::out('<br><a href="index.php">{t}C O N T I N U E{/t}</a><br><finished>');
  if (function_exists("memory_get_usage") and function_exists("memory_get_peak_usage")) {
	self::out("<!-- ".modify::filesize(memory_get_usage())." - ".modify::filesize(memory_get_peak_usage())." -->",false);
  }
  self::out('<div style="border-top: 1px solid #666666;">Powered by Simple Groupware, Copyright (C) 2002-2012 by Thomas Bley.</div></div></body></html>',false);
}

static function show_form($databases, $install, $accept_gpl) {
  $globals = ini_get("register_globals");
  $mb_string = !in_array("mbstring",get_loaded_extensions());
  
  echo '
    <html><head>
	<title>Simple Groupware & CMS</title>
	<style>
		body { width:700px; margin:10px auto; }
		body, h2, table.data, a {
		  background-color: #FFFFFF; color: #666666; font-size: 13px; font-family: Arial, Helvetica, Verdana, sans-serif;
		}
		a, input, select { color: #0000FF; }
		input {
		  font-size: 11px; background-color: #F5F5F5; border: 1px solid #AAAAAA; height: 18px;
		  vertical-align: middle; padding-left: 5px; padding-right: 5px; border-radius: 10px;
		}
		select { font-size: 11px; background-color: #F5F5F5; border: 1px solid #AAAAAA;	}
		input:focus { border: 1px solid #FF0000; }
		.checkbox,.radio { border: 0px; background-color: transparent; }
		.submit { color: #0000FF; background-color: #FFFFFF; width: 230px; font-weight: bold; }
		table.data td,table.data td.data { padding-left: 5px; padding-right: 5px; }
	</style>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
	<script>
	function getObj(id) {
	  return document.getElementById(id);
	}
	function change_input_type(id,checked) {
	  var obj = getObj(id);
	  obj.type = checked ? "text" : "password";
	}
	function change_db_type(obj) {
	  var val = obj.options ? (obj.options[obj.selectedIndex].value) : obj.value;
	  var ids = ["db_host_row", "db_user_row", "db_pw_row"];
	  for (var i=0; i<ids.length; i++) {
		getObj(ids[i]).style.display = (val == "sqlite") ? "none" : "";
	  }
	}
	</script>
    </head>
    <body>
    <div style="border-bottom: 1px solid #666666; letter-spacing: 2px; font-size: 18px; font-weight: bold;">Simple Groupware '.CORE_VERSION_STRING.'</div>
    <br>
	<div style="color:#ff0000; margin-left:6px;"><b>
	'.($globals?sprintf("{t}Warning{/t}: {t}Please modify your php.ini or add an .htaccess file changing the setting '%s' to '%s' (current value is '%s') !{/t}<br><br>","register_globals","0",$globals):"").'
	'.($mb_string?sprintf("{t}Warning{/t}: {t}Please install the php-extension with name '%s'.{/t}<br><br>","mbstring"):"").'
	'.(($install and !$accept_gpl)?"&nbsp;=&gt; {t}To continue installing Simple Groupware you must check the box under the license{/t}<br><br>":"").'
	</b></div>
	<form action="index.php" method="post">
	<input type="hidden" name="lang" value="'.LANG.'">
	<table class="data">
	<tr id="db_host_row">
	  <td><label for="db_host">{t}Database Hostname / IP{/t}</label></td>
	  <td><input type="Text" value="localhost" size="30" maxlength="50" name="db_host" id="db_host"></td>
	</tr>
	<tr id="db_user_row">
	  <td><label for="db_user">{t}Database User{/t}</label></td>
	  <td><input type="Text" value="root" size="30" maxlength="50" name="db_user" id="db_user"></td>
	</tr>
	<tr id="db_pw_row">
	  <td><label for="db_pw">{t}Database Password{/t}</label></td>
	  <td><input type="text" value="" size="30" maxlength="50" name="db_pw" id="db_pw"></td>
	</tr>
	<tr>
	  <td><label for="db_name">{t}Database Name{/t}</label></td>
	  <td><input type="Text" value="sgs_'.CORE_VERSION.'" size="30" maxlength="50" name="db_name" id="db_name" required="true"></td>
	</tr>
	<tr>
	  <td><label for="db_type">{t}Database{/t}</label></td>
	  <td>
  ';
  if (count($databases)>1) {
    echo '<select name="db_type" id="db_type" onchange="change_db_type(this);">';
    foreach ($databases as $key=>$val) echo '<option value="'.$key.'"> '.$val[0];
    echo '</select>';
  }	else {
    foreach ($databases as $key=>$val) echo '<input type="hidden" name="db_type" id="db_type" value="'.$key.'"> '.$val[0];
  }
  echo '
	  <script>change_db_type(getObj("db_type"));</script>
	  </td>
	</tr>
	<tr>
	  <td><label for="admin_user">{t}Admin Username{/t}</label></td>
	  <td><input type="text" value="admin" size="30" maxlength="50" name="admin_user" id="admin_user" required="true"></td>
	</tr>
	<tr>
	  <td><label for="admin_pw">{t}Admin Password{/t}</label></td>
	  <td><input type="text" value="" size="30" maxlength="50" name="admin_pw" id="admin_pw" required="true"></td>
	</tr>
	<tr>
	  <td><label for="folders">{t}Folder structure{/t}</label></td>
	  <td>
		<select name="folders" id="folders">
		  '.(is_dir("import/")?'<option value="modules/core/folders.xml">{t}Install demo folders{/t}':'').'
		  <option value="modules/core/folders_small.xml">{t}Install default folder structure{/t}
		  <option value="modules/core/folders_minimal.xml">{t}Install minimal folder structure{/t}
		</select>
	  </td>
	</tr>
	</table>
    <div style="border-bottom: 1px solid #666666;">&nbsp;</div>
	<h2>GNU GPL {t}License{/t} Version 2</h2>
	<h4>
	<a href="http://www.gnu.org/copyleft/gpl.html" target="_blank">{t}More information about the GNU GPL{/t}</a><br>
	<a href="http://www.gnu.org/licenses/translations.html" target="_blank">{t}Translations of the GNU GPL{/t}</a><br> 
	<a href="http://www.gnu.org/licenses/gpl-faq.html" target="_blank">{t}GNU GPL Frequently Asked Questions{/t}</a>
	<br>
	</h4>
	<font color="#ff0000">*** {t}To continue installing Simple Groupware you must check the box under the license{/t} ***</font><br><br>
	{t}Please read the following license agreement. Use the scroll bar to view the rest of this agreement.{/t}<br>
    <div style="border-bottom: 1px solid #666666;">&nbsp;</div>
	<pre>'.trim(file_get_contents("License.txt")).'</pre>
    <div style="border-bottom: 1px solid #666666;">&nbsp;</div>
	<br>
	<div style="border:2px solid #FF0000; width:450px; margin-bottom:20px;">&nbsp; 
		<input onclick="if (this.checked) this.parentNode.style.border=\'2px solid #00A000\'; else this.parentNode.style.border=\'2px solid #FF0000\';" type="Checkbox" class="checkbox" name="accept_gpl" id="accept_gpl" value="yes" style="margin: 0px;" accesskey="a" required="true">
		<label for="accept_gpl">{t}I Accept the GNU GENERAL PUBLIC LICENSE VERSION 2{/t}</label>
	</div>
	<input type="submit" name="install" value="{t}I n s t a l l{/t}" class="submit" style="width:450px; margin-bottom:10px;">
	</form>
    <div style="border-top: 1px solid #666666;">Powered by Simple Groupware, Copyright (C) 2002-2012 by Thomas Bley.</div>
	</body></html>
  ';
}

static function error_add($msg,$id=0) {
  self::$errors[] = array($msg,$id);
}

static function errors_show($phpinfo=false) {
  if (count(self::$errors)==0) return;
  $msg = "";
  foreach (self::$errors as $message) {
    $msg .= str_replace("\n","<br>",q($message[0]))."<br>";
  }
  echo '
	<center>
	<div style="border-bottom: 1px solid #666666; letter-spacing: 2px; font-size: 18px; font-weight: bold;">Simple Groupware '.CORE_VERSION_STRING.' - Setup</div>
	<br>{t}Error{/t}:<br>
	<error>'.$msg.'</error><br>
	<a href="index.php">{t}Relaunch Setup{/t}</a><br>
	<hr>
	<a href="http://www.simple-groupware.de/cms/Main/Installation" target="_blank">Installation manual</a> / 
	<a href="http://www.simple-groupware.de/cms/Main/Update" target="_blank">Update manual</a> /
	<a href="http://www.simple-groupware.de/cms/Main/Documentation" target="_blank">Documentation</a> / 
	<a href="http://www.simple-groupware.de/cms/Main/FAQ" target="_blank">FAQ</a><hr>
	<br>
	</center>
  ';
  if ($phpinfo) phpinfo();
  exit();
}
}