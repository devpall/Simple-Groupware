<?php
/**
 * @package Simple Groupware
 * @link http://www.simple-groupware.de
 * @copyright Simple Groupware Solutions Thomas Bley 2002-2012
 * @license GPLv2
 */

class lib_modules extends lib_default {

static function get_filecontent($var,$args,$data) {
  $file = $data["filename"]["data"][0];
  if (!file_exists($file)) return "";
  return modify::displayfile("modules",$file,false,false);;
}

static function count($path,$where,$vars,$mfolder) {
  $files = array();
  foreach (array("modules/schema/","modules/schema_sys/",SIMPLE_CUSTOM."modules/schema/",SIMPLE_CUSTOM."modules/schema_sys/") as $path) {
    if (is_dir($path) and ($handle=@opendir($path))) {
      while (false !== ($file = readdir($handle))) {
        if($file=='.' or $file=='..' or is_dir($path.$file) or !strpos($file,".xml")) continue;
	    $files[$file] = 0;
      }
      closedir($handle);
    }
  }
  return count($files);
}

static function select($path,$fields,$where,$order,$limit,$vars,$mfolder) {
  $rows = array();
  $modules = select::modules();
  asort($modules);
  foreach ($modules as $module=>$name) {
	if ($name[0]==" ") continue;
    $file = sys_find_module($module);
	$row = array(
	  "id"=>$file,
	  "name"=>$name,
	  "modulename"=>$module,
	  "created"=>@filectime($file),
	  "lastmodified"=>@filemtime($file),
	  "lastmodifiedby"=>"",
	  "searchcontent"=>$file,
	  "filename"=>$file,
	  "filemtime"=>@filemtime($file),
	  "filectime"=>@filectime($file),
	  "filesize"=>@filesize($file),
	  "filecontent"=>""
	);
	if (sys_select_where($row,$where,$vars)) $rows[$file] = $row;
  }
  return sys_select($rows,$order,$limit,$fields);
}
}