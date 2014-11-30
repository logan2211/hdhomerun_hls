<?php
class HDHRStream {
	public $target_ip = '192.168.1.10'; //this is the IP for the hdhr to send the stream to. (ie. ip of this computer.)
	public $target_port = '5000'; //target port for the hdhr to send the stream to

	public $pidf = '/tmp/hdhrstream.pid';
	public $enc_log = '/tmp/hdhrstream.log'; //truncated every time the stream restarts
	public $enc_type = 'ffmpeg';
	
	public $ffmpeg_base = '/usr/bin/ffmpeg'; //path to ffmpeg binary. not needed if using vlc
	public $ffmpeg_threads = 10;
	public $ffmpeg_acodec = 'libfdk_aac';
	
	public $vlc_base = "/usr/bin/vlc"; //path to VLC binary. not needed if using ffmpeg mode
	public $vlc_acodec = 'mp4a';
	
	public $stream = array(
		'path' => '/set/to/html/dir', //path to webserver where we store the stream files
		'files' => 'stream-######.ts', //ffmpeg does not use this
		'index' => 'stream.m3u8'
	);
	
	public $hdhr_id = ''; //leave blank for autodiscovery. can be either mac address (hdhomerun_config discover), or the ip address of hdhr. FFFFFFFF is first discovered.
	public $num_tuners = 3; //hdhomerun prime has 3 tuners
	public $tuners_descending = false; //find available tuners starting from highest # first. Used to try to avoid conflicts with apps like mythtv which do not handle tuner sharing.
	public $lockkey = '11111';
	public $tuner = null;

	public $lineup = null; //channel lineup, populated by get_channel_lineup()

	//apple recommended profiles at http://developer.apple.com/library/ios/#technotes/tn2224/_index.html#//apple_ref/doc/uid/DTS40009745
	public $profiles = array(
		'200k' => array( //200k
			'width' => '416',
			'height' => '234',
			'vb' => '200',
			'ab' => '32',
			'preset' => 'medium', //ultrafast,superfast,veryfast,faster,fast,medium,slow,slower,veryslow,placebo
			'profile' => 'baseline', //baseline,main,high,high10,high422,high444
			'enabled' => true
		),
		'400k' => array( //400k
			'width' => '416',
			'height' => '234',
			'vb' => '400',
			'ab' => '48',
			'preset' => 'medium',
			'profile' => 'main',
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
			'profile' => 'high',
			'enabled' => true
		),
		'1800k' => array( //1800k lots of CPU
			'width' => '960',
			'height' => '540',
			'vb' => '1800',
			'ab' => '64',
			'preset' => 'medium',
			'profile' => 'high',
			'enabled' => false
		),
		'2500k' => array( //2500k 720p high cpu+bw
			'width' => '1280',
			'height' => '720',
			'vb' => '2500',
			'ab' => '96',
			'preset' => 'medium',
			'profile' => 'high',
			'enabled' => false
		),
		'4500k' => array( //4500k 720p high cpu+bw
			'width' => '1280',
			'height' => '720',
			'vb' => '4500',
			'ab' => '96',
			'preset' => 'medium',
			'profile' => 'high',
			'enabled' => false
		)
	);
		
	public $default_profile = array(
		'vb' => '600',
		'ab' => '64',
		'deinterlace' => true,
		'preset' => 'medium',
		'profile' => 'high',
		'level' => '41', //https://trac.ffmpeg.org/wiki/Encode/H.264#Alldevices
		'fps' => '30',
		'seglen' => '10',
		'numsegs' => '8',
		'keyframes' => '60' //typically 1-3 seconds between keyframes. so fps*3 is a good starting point.
		//(seglen/(keyframs/fps)) should be integer so each segment starts with keyframe.
		//info about keyframe rates: http://www.streamingmedia.com/Articles/ReadArticle.aspx?ArticleID=73017&PageNum=2
	);
	
	function __construct() {
		$this->discover_hdhr();
		$this->vlc_base =  $this->vlc_base." -d --ignore-config --file-logging --logfile {$this->enc_log} --pidfile {$this->pidf} udp://@:{$this->target_port} --sout-avcodec-strict=-2";
		$this->ffmpeg_base = 'nohup '.$this->ffmpeg_base.' -i "udp://@:5000" ##deinterlace## -y -analyzeduration 2000000 -threads '.$this->ffmpeg_threads.' ##ffmpeg_opts## > '.$this->enc_log.' 2>&1 & echo $! > '.$this->pidf;
		if (!$this->stream['path'] = realpath($this->stream['path'])) die("Stream file output path {$this->stream['path']} does not exist.\n");
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
		
		switch($this->enc_type) {
			case 'vlc':
				$streamopts = $this->vlc_generate();
			break;
			case 'ffmpeg':
				$streamopts = $this->ffmpeg_generate();
			break;
			default:
				die("Invalid encoder selection: {$this->enc_type}\n");
			break;
		}
		if (empty($streamopts)) throw new Exception("No encoder options were generated. Check enc_type config option.\n");
		
		file_put_contents($this->stream['path'].'/'.$this->stream['index'], $streamopts['vbr_playlist']);
		
		$this->tuner = $this->get_available_tuner();
		if ($this->tuner === false) exit("No available tuners found!\n");
		
		$hdhr_id = escapeshellarg($this->hdhr_id);
		$lockkey = escapeshellarg($this->lockkey);
		$cmds = array(
			"hdhomerun_config $hdhr_id set /{$this->tuner}/lockkey $lockkey",
			"hdhomerun_config $hdhr_id key $lockkey set /{$this->tuner}/vchannel $channel",
			$streamopts['encoder'],
			'sleep 0.5', //sleep for 1/2 second to give encoder a little time to start up before it starts receiving the stream.
			"hdhomerun_config $hdhr_id key $lockkey set /{$this->tuner}/target {$this->target_ip}:{$this->target_port}"
		);
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
			$hdhr_id = escapeshellarg($this->hdhr_id);
			$lockkey = escapeshellarg($this->lockkey);
			exec_command("hdhomerun_config $hdhr_id key $lockkey set /{$this->tuner}/lockkey none");
		}
		return true;
	}
	function vlc_generate() {
		$return = array(
			'encoder' => $this->vlc_base.' --sout=\'#duplicate{',
			'vbr_playlist' => "#EXTM3U\n"
		);
		$vbr =& $return['vbr_playlist'];
		$vlc =& $return['encoder'];
		
		$first_enc = true;
		foreach ($this->profiles as $p) {
			if ($p['enabled'] === false) continue;
			$v = $this->vlc_generate_profile_options($p);
			if ($first_enc === false) $vlc .= ',';
			else $first_enc = false;
			$vlc .= "dst=\"transcode{{$v['size']},{$v['video']},{$v['audio']}}:std{{$v['std']}}\"";
			$vbr .= '#EXT-X-STREAM-INF:PROGRAM-ID=1,BANDWIDTH='.($p['vb']*1000)."\n{$v['streamindex']}\n";
		}
		$vlc .= '}\'';
		return $return;
	}
	function vlc_generate_profile_options($p) {
		$p = array_merge($this->default_profile, $p);
		
		$my_streamindex = $p['vb'].'-'.$this->stream['index'];
		$my_streamfiles = $p['vb'].'-'.$this->stream['files'];
		$v = array(
			'streamindex' => $my_streamindex,
			'size' => "width={$p['width']},height={$p['height']}",
			'x264_opts' => "preset={$p['preset']},profile={$p['profile']},keyint={$p['keyframes']}",
			'audio' => "ab={$p['ab']},channels=2,audio-sync,acodec={$this->vlc_acodec}",
			'livehttp_opts' => "seglen={$p['seglen']},numsegs={$p['numsegs']},delsegs=true,index={$this->stream['path']}/$my_streamindex,index-url=$my_streamfiles"
		);
		$v['video'] = ($p['deinterlace'] == true ? 'deinterlace,' : '')."vcodec=h264,vb={$p['vb']},fps={$p['fps']},venc=x264{{$v['x264_opts']}}";
		$v['std'] = "access=livehttp{{$v['livehttp_opts']}},mux=ts{use-key-frames},dst={$this->stream['path']}/$my_streamfiles";
		return $v;
	}
	function ffmpeg_generate() {
		$return = array(
			'encoder' => $this->ffmpeg_base,
			'vbr_playlist' => "#EXTM3U\n"
		);
		$vbr =& $return['vbr_playlist'];
		
		//handle deinterlace
		if ($this->default_profile['deinterlace'] === true) {
			$deinterlace = '-vf yadif=0:-1:1'; //https://www.ffmpeg.org/ffmpeg-filters.html#yadif-1
		} else {
			$deinterlace = '';
		}
		$return['encoder'] = str_replace('##deinterlace##', $deinterlace, $return['encoder']);
		
		$ffmpeg_profile_opts = '';
		foreach ($this->profiles as $p) {
			if ($p['enabled'] === false) continue;
			$my_streamindex = $p['vb'].'-'.$this->stream['index'];
			$ffmpeg_profile_opts .= $this->ffmpeg_generate_profile_options($p).' ';
			$vbr .= '#EXT-X-STREAM-INF:PROGRAM-ID=1,BANDWIDTH='.($p['vb']*1000)."\n$my_streamindex\n";
		}
		$ffmpeg_profile_opts = trim($ffmpeg_profile_opts);
		$return['encoder'] = str_replace('##ffmpeg_opts##', $ffmpeg_profile_opts, $return['encoder']);
		return $return;
	}
	function ffmpeg_generate_profile_options($p) {
		$p = array_merge($this->default_profile, $p);
		$my_streamindex = $p['vb'].'-'.$this->stream['index'];
		$keyframes_seconds = $p['keyframes']/$p['fps'];
		$opts = "-c:v libx264 -s {$p['width']}x{$p['height']} -r {$p['fps']} -b:v {$p['vb']}k -force_key_frames 'expr:gte(t,n_forced*$keyframes_seconds)' -profile:v {$p['profile']} -preset {$p['preset']} -x264opts level={$p['level']} -c:a {$this->ffmpeg_acodec} -b:a {$p['ab']}k -ac 2 -hls_time {$p['seglen']} -hls_list_size {$p['numsegs']} -hls_wrap {$p['numsegs']} {$this->stream['path']}/{$my_streamindex}";
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
		$hdhr_id = escapeshellarg($this->hdhr_id);
		$lockkey = escapeshellarg($this->lockkey);
		$command = "hdhomerun_config $hdhr_id key $lockkey set /$tuner/vchannel $channel";
		$r = exec_command($command);
		if ($r['code'] == 0) return true;
		return false;
	}
	function check_enc_running() {
		$pid = trim(@file_get_contents($this->pidf));
		$status = @posix_kill($pid, 0);

		if (empty($pid) || !$status) {
			$errno = posix_get_last_error();
			if (empty($pid)) $errno = 3; //errno 3 is ESRCH
			if ($errno == 3) { //errno 3 is ESRCH
				//no such process--encoder is not running
				@unlink($this->pidf); //we clean up after the encoder if it does not delete its PID file
				//also clean up *.ts and *.m3u8 files in the stream output path since the stream is not running.
				array_map('unlink', array_merge(glob($this->stream['path'].'/*stream*.m3u8'), glob($this->stream['path'].'/*stream*.ts')));
				return false;
			}
		}
		return $pid; //encoder is probably running at this point.
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
