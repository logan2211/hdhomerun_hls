# HDHomeRun HLS Streaming
## Intro
This project is a web frontend to interface with the [HDHomeRun](http://www.silicondust.com/) products and [FFmpeg](https://www.ffmpeg.org/) in order to provide a mobile ready VBR [HLS live stream](http://en.wikipedia.org/wiki/HTTP_Live_Streaming) of a channel. Scroll down for images of this in action.

###Update - Chromecast support
This app is now updated to support casting to Chromecast devices using desktop Chrome. Start the stream in a Chrome browser with the cast extension installed and the icon will appear next to the on/off slider when cast devices are discovered.

## Prerequisites
* PHP enabled webserver
* [FFmpeg](https://www.ffmpeg.org/) with libx264 support
* [hdhomerun_config](http://www.silicondust.com/support/downloads/)

## Installation
* Clone this repo: `git clone https://github.com/Logan2211/hdhomerun_hls.git`
* In `html/callback.php` configure the path to `hdhrstream.class.php`
* Copy `html/*` to a PHP enabled directory on your webserver. Make sure that your web server has write permissions to this directory so ffmpeg can write the stream segments here.
* Copy `config.example.yml` to `config.yml` and perform the initial configuration as follows.
	* `target_ip` should be set to the IP of the system hosting this app
	* `ffmpeg_base` must point to your FFmpeg binary
	* `stream => path` must be set to the html directory where `index.html`, `callback.php`, etc reside. This is the directory where ffmpeg will output the m3u8 HLS playlists and transcoded stream segments.
	* `num_tuners` will typically be 3 on HDHomeRun Prime, may be different on other HDHR models.

### Advanced Settings
* In `html/index.html` there are several `channel_filter_*` settings at the top of the file which enable display filtering of channels. It is disabled by default but I use this to display only HD channels.

## Usage
Once configured navigate to the index.html URL on your device and attempt to start the stream. Check the web server error log and hdhrstream log file (configured in `hdhrstream.class.php`) if the stream does not start.

I have had spotty experience with HLS streaming on Android devices. On iOS, it seems to work best playing the stream in Safari. Other browsers work but sometimes not as reliably or smoothly as Safari.

## Screenshots
![Screenshot 1](http://i.imgur.com/Xz3gt5a.png) ![Screenshot 2](http://i.imgur.com/pTWCmHd.png) ![Screenshot 3](http://i.imgur.com/zWMGDS8.png)

## License

This project is distributed under the GNU GPL v2 license which can be found in LICENSE.
