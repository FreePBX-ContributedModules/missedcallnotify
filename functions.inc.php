<?php
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }

function missedcallnotify_hookGet_config($engine) {
  global $ext;
  global $version;
  $newsplice=1;
  switch($engine) {
  case "asterisk":
    if($newsplice){ # Method fpr splicing using modified splice code yet not implemented in 2.10.0.2
      $ext->splice('macro-hangupcall', 's', 'theend', new ext_gosub(1,'s','sub-missedcallnotify'),'theend',false,true);
    }else{ # Custom method to splice in correct code prior to hangup
      $spliceext=array(
          'basetag'=>'n',
          'tag'=>'theend',
          'addpri'=>'',
          'cmd'=>new ext_gosub(1,'s','sub-missedcallnotify')
        );
      foreach($ext->_exts['macro-hangupcall']['s'] as $_ext_k=>&$_ext_v){
        if($_ext_v['tag']!='theend'){continue;}
        $_ext_v['tag']='';
        array_splice($ext->_exts['macro-hangupcall']['s'],$_ext_k,0, array($spliceext) );
        break;
      }
    }
  break;
  }
}
function missedcallnotify_get_config($engine) {
  $modulename = 'missedcallnotify';

  // This generates the dialplan
  global $ext;
  global $amp_conf;
  $mcontext = 'sub-missedcallnotify';
  $exten = 's';

  $ext->add($mcontext,$exten,'', new ext_noop_trace('UserEmail: ${DB(AMPUSER/${EXTTOCALL}/email)}'));
  $ext->add($mcontext,$exten,'', new ext_noop_trace('GroupEmail: ${DB(AMPGROUP/${NODEST}/email)}'));
  $ext->add($mcontext,$exten,'', new ext_noop_trace('DialStatus: ${DIALSTATUS}'));
  $ext->add($mcontext,$exten,'', new ext_noop_trace('MEXTEN: ${MEXTEN}'));
  $ext->add($mcontext,$exten,'', new ext_noop_trace('VMSTATUS: ${VMSTATUS}'));
  $ext->add($mcontext,$exten,'', new ext_noop_trace('OutboundGrp: ${OUTBOUND_GROUP}'));
  $ext->add($mcontext,$exten,'', new ext_noop_trace('DialTrunk: ${DIAL_TRUNK}'));
  $ext->add($mcontext,$exten,'', new ext_noop_trace('RingGroupMethod: ${RingGroupMethod}'));

#ext_execif($expr, $app_true, $data_true='', $app_false = '', $data_false = '') {
  $ext->add($mcontext,$exten,'', new ext_execif('$["${DB(AMPUSER/${EXTTOCALL}/missedcallnotify/status)}"=="enabled" & ["${DIALSTATUS}" == "CANCEL" | "${DIALSTATUS}" == "BUSY" | "${DIALSTATUS}" == "NOANSWER"] & "${OUTBOUND_GROUP}" == "" & "${DIAL_TRUNK}" == "" & "${RingGroupMethod}" == "none"]','System','echo "" | mail -s "MissedCall (${EXTTOCALL}) - ${CALLERID(name)} <${CALLERID(num)}>" ${DB(AMPUSER/${EXTTOCALL}/missedcallnotify/email)}'));
  $ext->add($mcontext,$exten,'', new ext_execif('$["${DB_EXISTS(AMPGROUP/${NODEST}/email)}"=="1" & "${DIALSTATUS}" == "CANCEL" & "${OUTBOUND_GROUP}" == "" & "${DIAL_TRUNK}" == "" & "${RingGroupMethod}" != "none"]','System','echo "" | mail -s "MissedCallGroup (${NODEST}) - ${CALLERID(name)} <${CALLERID(num)}>" ${DB(AMPGROUP/${NODEST}/email)}'));

}


function missedcallnotify_configpageinit($pagename) {
        global $currentcomponent;

        $action = isset($_REQUEST['action'])?$_REQUEST['action']:null;
        $extdisplay = isset($_REQUEST['extdisplay'])?$_REQUEST['extdisplay']:null;
        $extension = isset($_REQUEST['extension'])?$_REQUEST['extension']:null;
        $tech_hardware = isset($_REQUEST['tech_hardware'])?$_REQUEST['tech_hardware']:null;

        // We only want to hook 'users' or 'extensions' pages.
        if ($pagename != 'extensions')
                return true;

	// On a 'new' user, 'tech_hardware' is set, and there's no extension. Hook into the page.
        if ($tech_hardware != null) {
                missedcallnotify_applyhooks();
		$currentcomponent->addprocessfunc('missedcallnotify_configprocess', 8);
        } elseif ($action=="add") {
                // We don't need to display anything on an 'add', but we do need to handle returned data.
                $currentcomponent->addprocessfunc('missedcallnotify_configprocess', 8);
        } elseif ($extdisplay != '') {
                // We're now viewing an extension, so we need to display _and_ process.
                missedcallnotify_applyhooks();
                $currentcomponent->addprocessfunc('missedcallnotify_configprocess', 8);
        }

}

function missedcallnotify_applyhooks() {
        global $currentcomponent;

        $currentcomponent->addoptlistitem('missedcallnotify_status', 'disabled', _('Disabled'));
        $currentcomponent->addoptlistitem('missedcallnotify_status', 'enabled', _('Enabled'));
        $currentcomponent->setoptlistopts('missedcallnotify_status', 'sort', false);

	$currentcomponent->addguifunc('missedcallnotify_configpageload');
}

function missedcallnotify_configpageload() {
  global $amp_conf;
  global $currentcomponent;

  // Init vars from $_REQUEST[]
  $action = isset($_REQUEST['action'])?$_REQUEST['action']:null;
  $extdisplay = isset($_REQUEST['extdisplay'])?$_REQUEST['extdisplay']:null;

  $mcn = missedcallnotify_getall($extdisplay);
  $section = _('Missed Call Notification');
  $missedcallnotify_label =      _("Notifications");
  $missedcallnotify_email_label =    _("Email Address");
  $missedcallnotify_tt = _("Enable notification of missed calls");

  $currentcomponent->addguielem($section, new gui_selectbox('missedcallnotify_status', $currentcomponent->getoptlist('missedcallnotify_status'), $mcn['missedcallnotify_status'], $missedcallnotify_label, $missedcallnotify_tt, '', false));
  $currentcomponent->addguielem($section, new gui_textbox('missedcallnotify_email', $mcn['missedcallnotify_email'],$missedcallnotify_email_label, '', '' , false));
}

function missedcallnotify_configprocess() {
  global $amp_conf;

  $action = isset($_REQUEST['action'])?$_REQUEST['action']:null;
  $ext = isset($_REQUEST['extdisplay'])?$_REQUEST['extdisplay']:null;
  $extn = isset($_REQUEST['extension'])?$_REQUEST['extension']:null;

  $mcn=array();
  $mcn['status'] =      isset($_REQUEST['missedcallnotify_status']) ? $_REQUEST['missedcallnotify_status'] : 'disabled';
  $mcn['email'] =    isset($_REQUEST['missedcallnotify_email']) ? $_REQUEST['missedcallnotify_email'] : 'enabled';

  if ($ext==='') {
    $extdisplay = $extn;
  } else {
    $extdisplay = $ext;
  }

  if ($action == "add" || $action == "edit" || (isset($mcn['misedcallnotify']) && $mcn['misedcallnotify']=="false")) {
    if (!isset($GLOBALS['abort']) || $GLOBALS['abort'] !== true) {
      missedcallnotify_update($extdisplay, $mcn);
    }
  } elseif ($action == "del") {
    missedcallnotify_del($extdisplay);
  }
}

function missedcallnotify_getall($ext) {
  global $amp_conf;
  global $astman;
  $mcn=array();

  if ($astman) {
    $missedcallnotify_status = missedcallnotify_get($ext,"status");
    $mcn['missedcallnotify_status'] = $missedcallnotify_status ? $missedcallnotify_status : 'disabled';
    $missedcallnotify_email = missedcallnotify_get($ext,"email");
    $mcn['missedcallnotify_email'] = $missedcallnotify_email ?  $missedcallnotify_email : '';
  } else {
    fatal("Cannot connect to Asterisk Manager with ".$amp_conf["AMPMGRUSER"]."/".$amp_conf["AMPMGRPASS"]);
  }
  return $mcn;
}

function missedcallnotify_get($ext, $key, $sub='missedcallnotify', $base='AMPUSER') {
  global $astman;
  global $amp_conf;

  if ($astman) {
    if(!empty($sub) && $sub!=false)$key=$sub.'/'.$key;
    return $astman->database_get($base,$ext.'/'.$key);
  } else {
    fatal("Cannot connect to Asterisk Manager with ".$amp_conf["AMPMGRUSER"]."/".$amp_conf["AMPMGRPASS"]);
  }
}


function missedcallnotify_update($ext, $options, $sub='missedcallnotify', $base='AMPUSER') {
  global $astman;
  global $amp_conf;

  if ($astman) {
    foreach ($options as $key => $value) {
      if(!empty($sub) && $sub!=false)$key=$sub.'/'.$key;
      $astman->database_put($base,$ext."/$key",$value);
    }
  } else {
    fatal("Cannot connect to Asterisk Manager with ".$amp_conf["AMPMGRUSER"]."/".$amp_conf["AMPMGRPASS"]);
  }
}

function missedcallnotify_del($ext, $sub='missedcallnotify', $base='AMPUSER') {
  global $astman;
  global $amp_conf;

  // Clean up the tree when the user is deleted
  if ($astman) {
    $astman->database_deltree("$base/$ext/$sub");
  } else {
    fatal("Cannot connect to Asterisk Manager with ".$amp_conf["AMPMGRUSER"]."/".$amp_conf["AMPMGRPASS"]);
  }
}


?>
