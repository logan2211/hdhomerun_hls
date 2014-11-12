<?php
class HDHRStream {
	public $target_ip = '192.168.1.10'; //this is the IP for the hdhr to send the stream to. (ie. ip of this computer.)
	public $target_port = '5000'; //target port for the hdhr to send the stream to

	public $pidf = '/tmp/hdhrstream_vlc.pid';
	public $vlc_log = '/tmp/hdhrstream_vlc.log'; //truncated every time the stream restarts
	public $vlc_base = "/usr/bin/vlc"; //path to VLC binary
	public $stream = array(
		'path' => '/set/to/html/dir', //path to webserver where we store the stream files
		'files' => 'stream-######.ts',
		'index' => 'stream.m3u8'
	);
	
	public $hdhr_id = ''; //leave blank for autodiscovery. can be either mac address (hdhomerun_config discover), or the ip address of hdhr. FFFFFFFF is first discovered.
	public $num_tuners = 3; //hdhomerun prime has 3 tuners
	public $tuners_descending = false; //find available tuners starting from highest # first. Used to try to avoid conflicts with apps like mythtv which do not handle tuner sharing.
	public $lockkey = '11111';
	public $tuner = null;

	public $lineup = null; //channel lineup, populated by get_channel_lineup()
	
	public $acodec = 'mp4a'; //audio codec

	//apple recommended profiles at http://developer.apple.com/library/ios/#technotes/tn2224/_index.html#//apple_ref/doc/uid/DTS40009745
	public $profiles = array(
		'200k' => array( //200k
			'width' => '416',
			'height' => '234',
			'vb' => '200',
			'ab' => '32',
			'preset' => 'faster', //ultrafast,superfast,veryfast,faster,fast,medium,slow,slower,veryslow,placebo
			'profile' => 'baseline', //baseline,main,high,high10,high422,high444
			'enabled' => true
		),
		'400k' => array( //400k
			'width' => '416',
			'height' => '234',
			'vb' => '400',
			'ab' => '48',
			'preset' => 'fast',
			'profile' => 'baseline',
			'enabled' => true
		),
		'600k' => array( //600k
			'width' => '640',
			'height' => '360',
			'vb' => '600',
			'ab' => '64',
			'preset' => 'medium',
			'profile' => 'main',
			'enabled' => true
		),
		'1200k' => array( //1200k
			'width' => '640',
			'height' => '360',
			'vb' => '1200',
			'ab' => '64',
			'preset' => 'medium',
			'profile' => 'main',
			'enabled' => true
		),
		'1800k' => array( //1800k lots of CPU
			'width' => '960',
			'height' => '540',
			'vb' => '1800',
			'ab' => '64',
			'preset' => 'medium',
			'profile' => 'main',
			'enabled' => false
		),
		'2500k' => array( //2500k 720p high cpu+bw
			'width' => '1280',
			'height' => '720',
			'vb' => '2500',
			'ab' => '96',
			'preset' => 'medium',
			'profile' => 'main',
			'enabled' => false
		),
		'4500k' => array( //4500k 720p high cpu+bw
			'width' => '1280',
			'height' => '720',
			'vb' => '4500',
			'ab' => '96',
			'preset' => 'medium',
			'profile' => 'main',
			'enabled' => false
		)
	);
		
	public $default_profile = array(
		'vb' => '600',
		'ab' => '64',
		'deinterlace' => true,
		'preset' => 'medium',
		'profile' => 'baseline',
		'fps' => '30',
		'seglen' => '10',
		'numsegs' => '8',
		'keyframes' => '60' //typically 1-3 seconds between keyframes. so fps*3 is a good starting point.
		//(seglen/(keyframs/fps)) should be integer so each segment starts with keyframe.
		//info about keyframe rates: http://www.streamingmedia.com/Articles/ReadArticle.aspx?ArticleID=73017&PageNum=2
	);
	
	function __construct() {
		$this->discover_hdhr();
		$this->vlc_base =  $this->vlc_base." -d --ignore-config --file-logging --logfile {$this->vlc_log} --pidfile {$this->pidf} udp://@:{$this->target_port} --sout-avcodec-strict=-2";
	}
	
	function start_stream($channel) {
		if (!is_numeric($channel)) throw new Exception('Channel must be numeric');
		$pid = $this->check_vlc_running();
		if ($pid !== false) throw new Exception("VLC already running in PID $pid");
		
		$vbr = "#EXTM3U\n";
		$vlc = $this->vlc_base.' --sout=\'#duplicate{';
		foreach ($this->profiles as $p) {
		  if ($p['enabled'] === false) continue;
		  $v = $this->vlc_generate_options($p);
		  if ($first_enc === true) {
		  	$vlc .= ',';
		  } else $first_enc = true;
		  $vlc .= "dst=\"transcode{{$v['size']},{$v['video']},{$v['audio']}}:std{{$v['std']}}\"";
		  
		  $vbr .= "#EXT-X-STREAM-INF:PROGRAM-ID=1,BANDWIDTH=".($p['vb']*1000)."\n{$v['streamindex']}\n";
		}
		$vlc .= '}\'';
		file_put_contents($this->stream['path'].'/'.$this->stream['index'], $vbr);
		
		$this->tuner = $this->get_available_tuner();
		if ($this->tuner === false) exit("No available tuners found!\n");
		
		$hdhr_id = escapeshellarg($this->hdhr_id);
		$lockkey = escapeshellarg($this->lockkey);
		$cmds = array(
			"hdhomerun_config $hdhr_id set /{$this->tuner}/lockkey $lockkey",
			"hdhomerun_config $hdhr_id key $lockkey set /{$this->tuner}/vchannel $channel",
			$vlc,
			'sleep 0.5', //sleep for 1/2 second to give VLC a little time to start up.
			"hdhomerun_config $hdhr_id key $lockkey set /{$this->tuner}/target {$this->target_ip}:{$this->target_port}"
		);
		
		foreach($cmds as $c) {
			$r = exec_command($c);
			if ($r['code'] != 0) echo "Failed to run: $c\n";
		}
	}
	function stop_stream() {
		$pid = $this->check_vlc_running();
		if ($pid === false) return true;
		//vlc should be running if we get here, but we may not have permission to kill it.
		if (!posix_kill($pid, 15)) {
			$errno = posix_get_last_error();
			throw new Exception("Could not kill VLC: ".posix_strerror($errno)." ($errno)");
		}
		return true;
	}
	function vlc_generate_options($profile) {
		$p =& $profile;
		$p = array_merge($this->default_profile, $p);
		
		$my_streamindex = $p['vb'].'-'.$this->stream['index'];
		$my_streamfiles = $p['vb'].'-'.$this->stream['files'];
		$v = array(
			'streamindex' => $my_streamindex,
			'size' => "width={$p['width']},height={$p['height']}",
			'x264_opts' => "preset={$p['preset']},profile={$p['profile']},keyint={$p['keyframes']}",
			'audio' => "ab={$p['ab']},channels=2,audio-sync,acodec={$this->acodec}",
			'livehttp_opts' => "seglen={$p['seglen']},numsegs={$p['numsegs']},delsegs=true,index={$this->stream['path']}/$my_streamindex,index-url=$my_streamfiles"
		);
		$v['video'] = ($p['deinterlace'] == true ? 'deinterlace,' : '')."vcodec=h264,vb={$p['vb']},fps={$p['fps']},venc=x264{{$v['x264_opts']}}";
		$v['std'] = "access=livehttp{{$v['livehttp_opts']}},mux=ts{use-key-frames},dst={$this->stream['path']}/$my_streamfiles";
		return $v;
	}
	function change_channel($channel) {
		if (!is_numeric($channel)) throw new Exception('Channel must be numeric');
		if (!$this->check_vlc_running()) {
			//vlc is not running.
			$this->start_stream($channel);
			return true;
		}
		//vlc is running if we get here, so the channel can just be changed
		$tuner = $this->get_my_tuner();
		$hdhr_id = escapeshellarg($this->hdhr_id);
		$lockkey = escapeshellarg($this->lockkey);
		$command = "hdhomerun_config $hdhr_id key $lockkey set /$tuner/vchannel $channel";
		$r = exec_command($command);
		if ($r['code'] == 0) return true;
		return false;
	}
	function check_vlc_running() {
		$pid = @file_get_contents($this->pidf);
		
		if (!posix_kill($pid, 0)) {
			$errno = posix_get_last_error();
			if ($errno == 3) {
				//no such process--vlc is not running
				@unlink($this->pidf);
				return false;
			}
		}
		return $pid; //vlc is probably running at this point.
	}
	function get_available_tuner() {
		if ($this->tuners_descending == true) {
			for ($i=$this->num_tuners; $i>=0; $i--) {
				$return = exec_command('hdhomerun_config '.escapeshellarg($this->hdhr_id)." get /tuner$i/lockkey");
				if ($return['code'] != 0) continue; //some error executing the command.
				if ($return['output'] == 'none') {
					$return = exec_command('hdhomerun_config '.escapeshellarg($this->hdhr_id)." get /tuner$i/target");
					if ($return['code'] != 0 || $return['output'] != 'none') continue; //failed to execute or someone is using this tuner unlocked
					//this tuner is not in use!
					return 'tuner'.$i;
				}
			}
		} else {
			for ($i=0; $i<$this->num_tuners; $i++) {
				$return = exec_command('hdhomerun_config '.escapeshellarg($this->hdhr_id)." get /tuner$i/lockkey");
				if ($return['code'] != 0) continue; //some error executing the command.
				if ($return['output'] == 'none') {
					$return = exec_command('hdhomerun_config '.escapeshellarg($this->hdhr_id)." get /tuner$i/target");
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
		$result = exec_command('hdhomerun_config '.escapeshellarg($this->hdhr_id).' get '.$arg);
		if ($result['output'] == 'none') return false;
		return $result['output'];
	}
	function get_my_tuner() {
		//loop through the hdhomerun tuners and figure out which one is set to this computer as target.
		$me = $this->target_ip.':'.$this->target_port;
		for ($i=0; $i<$this->num_tuners; $i++) {
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
		$r = exec_command('hdhomerun_config '.escapeshellarg($this->hdhr_id).' get /'.$tuner.'/vchannel');
		if (!is_numeric($r['output'])) return false;
		return $r['output'];
	}
	function discover_hdhr() {
		$cmd = 'hdhomerun_config discover';
		$r = exec_command($cmd);
		if (preg_match('/hdhomerun device (?P<mac>[^\s]+) found at (?P<ip>[^\s]+)/', $r['output'], $m)) {
			$this->hdhr_id = $m['ip'];
		}
	}
	function get_channel_lineup() {
		if (!ip2long($this->hdhr_id)) throw new Exception('HDhomerun ID is not an IP address: '.$this->hdhr_id);
		$url = 'http://'.$this->hdhr_id.'/lineup.xml';
		$xml = simplexml_load_file($url);
		$this->lineup = $xml;
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
