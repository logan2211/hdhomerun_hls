# HDHomeRun HLS Streaming
## Intro
This project is a web frontend to interface with the [HDHomeRun](http://www.silicondust.com/) products and [VLC](http://www.videolan.org/vlc/) in order to provide a mobile ready VBR [HLS live stream](http://en.wikipedia.org/wiki/HTTP_Live_Streaming) of a channel. Scroll down for images of this in action.

## Prerequisites
* PHP enabled webserver
* [VLC](http://www.videolan.org/vlc/)
* [hdhomerun_config](http://www.silicondust.com/support/downloads/)

## Installation
* Clone this repo: `git clone https://github.com/Logan2211/hdhomerun_hls.git`
* In `html/callback.php` configure the path to `hdhrstream.class.php`
* Copy `html/*` to a PHP enabled directory on your webserver. Make sure that your web server has write permissions to this directory so VLC can write the stream segments here.
* Configure the settings at the top of `hdhrstream.class.php`. The main options involved in the initial configuration are listed below.
	* `target_ip` should be set to the IP of the system hosting this app
	* `vlc_base` must point to your VLC binary
	* `stream => path` must be set to the html directory where `index.html`, `callback.php`, etc reside. This is the directory where VLC will output the m3u8 HLS playlists and transcoded stream segments.
	* `num_tuners` will typically be 3 on HDHomeRun Prime, may be different on other HDHR models.

## Usage
Once configured navigate to the index.html URL on your device and attempt to start the stream. Check the web server error log and VLC log file (configured in `hdhrstream.class.php`) if the stream does not start.

I have had spotty experience with HLS streaming on Android devices. On iOS, it seems to work best playing the stream in Safari. Other browsers work but sometimes not as reliably or smoothly as Safari.

## Screenshots
![Screenshot 1](http://i.imgur.com/Xz3gt5a.png) ![Screenshot 2](http://i.imgur.com/pTWCmHd.png) ![Screenshot 3](http://i.imgur.com/zWMGDS8.png)

## License

This project is distributed under the GNU GPL v2 license which can be found in LICENSE.