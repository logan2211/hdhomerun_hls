<?php
class HDHRStream {
	public $config = null;
	public $tuner = null;
	public $discovery = null;
	public $lineup = null; //channel lineup, populated by get_channel_lineup()

	function __construct($config_file='config.yml') {
		$path = realpath(dirname(__FILE__));
		$this->load_config($path.'/'.$config_file);
		$this->discover_hdhr();
		$this->config['ffmpeg_base'] = 'nohup '.$this->config['ffmpeg_base'].' -i "udp://@:5000?fifo_size=1000000&overrun_nonfatal=1" ##deinterlace## -y -threads '.$this->config['ffmpeg_threads'].' -f image2 -s 480x270 -r 1/'.$this->config['thumb_update_interval'].' -update 1 '.$this->config['stream']['path'].'/stream.png ##ffmpeg_opts## > '.$this->config['encoder_log'].' 2>&1 & echo $! > '.$this->config['pidf'];
		if (!$this->config['stream']['path'] = realpath($this->config['stream']['path'])) die("Stream file output path {$this->config['stream']['path']} does not exist.\n");
	}

	function load_config($file) {
		$this->config = yaml_parse_file($file);
	}

	function start_stream($channel) {
		if (!is_numeric($channel)) throw new Exception('Channel must be numeric');
		$pid = $this->check_enc_running();
		if ($pid !== false) throw new Exception("Encoder already running in PID $pid");

		/*
			streamopts must be pouplated with the following elements:
			encoder => full command line for the encoder with all profile options and a pid output to pidf
			vbr_playlist => built vbr playlist with paths to all playlists
		*/

		$streamopts = $this->ffmpeg_generate();
		file_put_contents($this->config['stream']['path'].'/'.$this->config['stream']['index'], $streamopts['vbr_playlist']);

		$this->tuner = $this->get_available_tuner();
		if ($this->tuner === false) exit("No available tuners found!\n");

		$hdhr_id = escapeshellarg($this->config['hdhr_id']);
		$lockkey = escapeshellarg($this->config['lockkey']);

		$cmds = array("hdhomerun_config $hdhr_id set /{$this->tuner}/lockkey $lockkey");
		if ($this->config['tuner_type'] == 'new') {
			array_push($cmds, "hdhomerun_config $hdhr_id key $lockkey set /{$this->tuner}/vchannel $channel");
		} else {
			$this->get_channel_lineup();
			foreach($this->lineup as $l) {
				if ($l->GuideNumber == $channel) {
					preg_match('/^.*\/ch([0-9]{9})-([0-9])\s*$/', $l->URL, $matches);
					$channel_freq = $matches[1];
					$channel_program = $matches[2];
				}
			}
			array_push($cmds, "hdhomerun_config $hdhr_id key $lockkey set /{$this->tuner}/channel $channel_freq");
			array_push($cmds, "hdhomerun_config $hdhr_id key $lockkey set /{$this->tuner}/program $channel_program");
		}
		array_push($cmds, $streamopts['encoder']);
		array_push($cmds, 'sleep 0.5'); //sleep for 1/2 second to give encoder a little time to start up before it starts receiving the stream.
		array_push($cmds, "hdhomerun_config $hdhr_id key $lockkey set /{$this->tuner}/target {$this->config['target_ip']}:{$this->config['target_port']}");

		foreach($cmds as $c) {
			$r = exec_command($c);
			if ($r['code'] != 0) echo "Failed to run: $c\n";
		}
	}
	function stop_stream() {
		$pid = $this->check_enc_running();
		if ($pid === false) return true;
		//encoder should be running if we get here, but we may not have permission to kill it.
		//get my tuner so it can be unlocked after the encoder is killed
		$this->tuner = $this->get_my_tuner();
		if (!posix_kill($pid, 15)) {
			$errno = posix_get_last_error();
			throw new Exception("Could not kill encoder: ".posix_strerror($errno)." ($errno)");
		}
		//unlock tuner
		if (!empty($this->tuner)) {
			$hdhr_id = escapeshellarg($this->config['hdhr_id']);
			$lockkey = escapeshellarg($this->config['lockkey']);
			exec_command("hdhomerun_config $hdhr_id key $lockkey set /{$this->tuner}/lockkey none");
		}
		return true;
	}
	function ffmpeg_generate() {
		$return = array(
			'encoder' => $this->config['ffmpeg_base'],
			'vbr_playlist' => "#EXTM3U\n"
		);
		$vbr =& $return['vbr_playlist'];

		//handle deinterlace
		if ($this->config['default_profile']['deinterlace'] === true) {
			$deinterlace = '-vf yadif=0:-1:1'; //https://www.ffmpeg.org/ffmpeg-filters.html#yadif-1
		} else {
			$deinterlace = '';
		}
		$return['encoder'] = str_replace('##deinterlace##', $deinterlace, $return['encoder']);

		$ffmpeg_profile_opts = '';
		foreach ($this->config['encoder_profiles'] as $p) {
			if ($p['enabled'] === false) continue;
			$my_streamindex = $p['video_bitrate'].'-'.$this->config['stream']['index'];
			$ffmpeg_profile_opts .= $this->ffmpeg_generate_profile_options($p).' ';
			$vbr .= '#EXT-X-STREAM-INF:PROGRAM-ID=1,BANDWIDTH='.($p['video_bitrate']*1000)."\n$my_streamindex\n";
		}
		$ffmpeg_profile_opts = trim($ffmpeg_profile_opts);
		$return['encoder'] = str_replace('##ffmpeg_opts##', $ffmpeg_profile_opts, $return['encoder']);
		return $return;
	}
	function ffmpeg_generate_profile_options($p) {
		$p = array_merge($this->config['default_profile'], $p);
		$my_streamindex = $p['video_bitrate'].'-'.$this->config['stream']['index'];
		$keyframes_seconds = $p['keyframes']/$p['fps'];
		$opts = "-c:v libx264 -s {$p['width']}x{$p['height']} -r {$p['fps']} -b:v {$p['video_bitrate']}k -force_key_frames 'expr:gte(t,n_forced*$keyframes_seconds)' -profile:v {$p['profile']} -preset {$p['preset']} -x264opts level={$p['level']} -c:a {$p['acodec']} -b:a {$p['audio_bitrate']}k -ac {$p['audio_channels']} -hls_time {$p['seglen']} -hls_list_size {$p['numsegs']} -hls_wrap {$p['numsegs']} {$this->config['stream']['path']}/{$my_streamindex}";
		return $opts;
	}
	function change_channel($channel) {
		if (!is_numeric($channel)) throw new Exception('Channel must be numeric');
		if (!$this->check_enc_running()) {
			//encoder is not running.
			$this->start_stream($channel);
			return true;
		}
		//encoder is running if we get here, so the channel can just be changed
		$tuner = $this->get_my_tuner();
		$hdhr_id = escapeshellarg($this->config['hdhr_id']);
		$lockkey = escapeshellarg($this->config['lockkey']);
		$command = "hdhomerun_config $hdhr_id key $lockkey set /$tuner/vchannel $channel";
		$r = exec_command($command);
		if ($r['code'] == 0) return true;
		return false;
	}
	function check_enc_running() {
		$pid = trim(@file_get_contents($this->config['pidf']));
		$status = @posix_kill($pid, 0);

		if (empty($pid) || !$status) {
			$errno = posix_get_last_error();
			if (empty($pid)) $errno = 3; //errno 3 is ESRCH
			if ($errno == 3) { //errno 3 is ESRCH
				//no such process--encoder is not running
				@unlink($this->config['pidf']); //we clean up after the encoder if it does not delete its PID file
				//also clean up *.ts and *.m3u8 files in the stream output path since the stream is not running.
				array_map('unlink', array_merge(glob($this->config['stream']['path'].'/*stream*.m3u8'), glob($this->config['stream']['path'].'/*stream*.ts')));
				unlink($this->config['stream']['path'].'/stream.png');
				return false;
			}
		}
		return $pid; //encoder is probably running at this point.
	}
	function get_available_tuner() {
		if ($this->config['tuners_descending'] == true) {
			for ($i=$this->config['num_tuners']; $i>=0; $i--) {
				$return = exec_command('hdhomerun_config '.escapeshellarg($this->config['hdhr_id'])." get /tuner$i/lockkey");
				if ($return['code'] != 0) continue; //some error executing the command.
				if ($return['output'] == 'none') {
					$return = exec_command('hdhomerun_config '.escapeshellarg($this->config['hdhr_id'])." get /tuner$i/target");
					if ($return['code'] != 0 || $return['output'] != 'none') continue; //failed to execute or someone is using this tuner unlocked
					//this tuner is not in use!
					return 'tuner'.$i;
				}
			}
		} else {
			for ($i=0; $i<$this->config['num_tuners']; $i++) {
				$return = exec_command('hdhomerun_config '.escapeshellarg($this->config['hdhr_id'])." get /tuner$i/lockkey");
				if ($return['code'] != 0) continue; //some error executing the command.
				if ($return['output'] == 'none') {
					$return = exec_command('hdhomerun_config '.escapeshellarg($this->config['hdhr_id'])." get /tuner$i/target");
					if ($return['code'] != 0 || $return['output'] != 'none') continue; //failed to execute or someone is using this tuner unlocked
					//this tuner is not in use!
					return 'tuner'.$i;
				}
			}
		}
		return false;
	}
	function get_tuner_target($tuner) {
		//expect tunerX input. $tuner should be 'tuner0' or something
		$arg = escapeshellarg("/$tuner/target");
		$result = exec_command('hdhomerun_config '.escapeshellarg($this->config['hdhr_id']).' get '.$arg);
		if ($result['output'] == 'none') return false;
		return $result['output'];
	}
	function get_my_tuner() {
		//loop through the hdhomerun tuners and figure out which one is set to this computer as target.
		$me = $this->config['target_ip'].':'.$this->config['target_port'];
		for ($i=0; $i<$this->config['num_tuners']; $i++) {
			$target = $this->get_tuner_target('tuner'.$i);
			if (strstr($target, $me)) {
				//this is my tuner.
				return 'tuner'.$i;
			}
		}
		return false; //unable to find my tuner.
	}
	function get_my_channel() {
		$tuner = $this->get_my_tuner();
		if (!$tuner) throw new Exception('Could not get tuner');
		$r = exec_command('hdhomerun_config '.escapeshellarg($this->config['hdhr_id']).' get /'.$tuner.'/vchannel');
		if (!is_numeric($r['output'])) return false;
		return $r['output'];
	}
	function discover_hdhr() {
		$cmd = 'hdhomerun_config discover';
		$r = exec_command($cmd);
		if (preg_match('/hdhomerun device (?P<mac>[^\s]+) found at (?P<ip>[^\s]+)/', $r['output'], $m)) {
			$this->config['hdhr_id'] = $m['ip'];
		}
	}
	function discover_hdhr_info() {
		if (!ip2long($this->config['hdhr_id'])) throw new Exception('HDhomerun ID is not an IP address: '.$this->config['hdhr_id']);
		$url = "http://{$this->config['hdhr_id']}/discover.json";
		$this->discovery = json_decode(file_get_contents($url));
		$this->config['num_tuners'] = $this->discovery->TunerCount;
	}
	function get_channel_lineup() {
		$this->discover_hdhr_info();
		$url = $this->discovery->LineupURL;
		$this->lineup = json_decode(file_get_contents($url));
	}
}

function exec_command($cmd) {
	$return = null;
	$output = '';
	exec($cmd, $output, $return);
	$r = array(
		'code' => $return,
		'output' => trim(implode("\n", $output))
	);
	return $r;
}
?>
