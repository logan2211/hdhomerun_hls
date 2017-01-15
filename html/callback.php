<?php
require('/opt/hdhrstream/hdhrstream.class.php');
$h = new HDHRStream;

switch ($_REQUEST['type']) {
	case 'status':
		if ($h->check_enc_running()) {
			$channel = $h->get_my_channel();
			returnJSON(array(
				'vlcstatus' => true,
				'channel' => $channel
			));
		} else returnJSON(array('vlcstatus' => false));
	break;
	case 'lineup':
		$h->get_channel_lineup();
		echo json_encode($h->lineup);
	break;
	case 'set_channel':
		$channel = $_REQUEST['channel'];
		if ($_REQUEST['deinterlace'] == "true") $h->config['default_profile']['deinterlace'] = true;
		else $h->config['default_profile']['deinterlace'] = false;

		foreach(json_decode($_REQUEST['profiles']) as $name => $enabled) {
			if ($enabled == true) $h->config['encoder_profiles'][$name]['enabled'] = true;
			else $h->config['encoder_profiles'][$name]['enabled'] = false;
		}

		if (!is_numeric($channel)) exit();
		$h->change_channel($channel);
	break;
	case 'stop_stream':
		$h->stop_stream();
	break;
	case 'get_profiles':
		$return = array();
		foreach ($h->config['encoder_profiles'] as $p => $profile) {
			$profile = array_merge($h->config['default_profile'], $profile);
			$return[] = array('name' => $p, 'settings' => $profile);
		}
		echo returnJSON($return);
	break;
}

function returnJSON($data) {
	die(json_encode($data));
}
?>
