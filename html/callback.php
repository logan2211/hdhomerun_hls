<?php
require('/opt/hdhrstream/hdhrstream.class.php');
$h = new HDHRStream;

switch ($_REQUEST['type']) {
	case 'status':
		if ($h->check_vlc_running()) {
			$channel = $h->get_my_channel();
			returnJSON(array(
				'vlcstatus' => true,
				'channel' => $channel
			));
		} else returnJSON(array('vlcstatus' => false));
	break;
	case 'lineup':
		$h->get_channel_lineup();
		echo $h->lineup->asXML();
	break;
	case 'set_channel':
		$channel = $_REQUEST['channel'];
		if ($_REQUEST['deinterlace'] == "true") $h->default_profile['deinterlace'] = true;
		else $h->default_profile['deinterlace'] = false;
		
		foreach(json_decode($_REQUEST['profiles']) as $name => $enabled) {
			if ($enabled == true) $h->profiles[$name]['enabled'] = true;
			else $h->profiles[$name]['enabled'] = false;
		}

		if (!is_numeric($channel)) exit();
		$h->change_channel($channel);
	break;
	case 'stop_stream':
		$h->stop_stream();
	break;
	case 'get_profiles':
		$return = array();
		foreach ($h->profiles as $p => $profile) {
			$profile = array_merge($h->default_profile, $profile);
			$return[] = array('name' => $p, 'settings' => $profile);
		}
		echo returnJSON($return);
	break;
}

function returnJSON($data) {
	die(json_encode($data));
}
?>