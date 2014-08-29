<?php
/*
Plugin Name: Weather Alerts
Plugin URI: http://sudbury.ma.us/
Description: Adds automated weather alerts functionality to the town website
Version: 1.0
Author: Eddie Hurtig
Author URI: http://hurtigtechnologies.com
Network: True
*/

require_once 'classes/class-weather-alerts-core.php';
require_once 'classes/class-point-in-polygon.php';

if ( is_admin() ) {
	require_once 'classes/class-weather-alerts-admin.php';
}

