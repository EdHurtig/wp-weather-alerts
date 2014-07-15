<?php
/*
Plugin Name: Weather Alerts
Plugin URI: http://sudbury.ma.us/kb/plugin/weather-alerts/
Description: Adds automated weather alerts functionality to your WordPress Website.  Developed at the Town of Sudbury
Version: 1.0
Author: Eddie Hurtig
Author URI: http://hurtigtechnologies.com
Network: True
*/

require_once 'classes/class-weather-alerts-core.php';

if ( is_admin() ) {
	require_once 'classes/class-weather-alerts-admin.php';
}