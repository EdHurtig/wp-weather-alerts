<?php

/**
 * Constantly pulls weather alerts from NOAA and will publish an Alert to the entire town site if there is a Severe weather warning for the Town
 *
 * @author  Eddie Hurtig <hurtige@sudbury.ma.us>
 * @since   2013-08-14
 */
class Weather_Alerts_Core {
	private $alerts;

	/**
	 * Constructor
	 */
	function __construct() {
		add_action( 'init', array( &$this, 'init' ) );
	}

	/**
	 * The init function, will load data from cache or from NOAA if not cached
	 */
	function init() {
		add_action( 'wp_ajax_reload_weather_alerts', array( &$this, 'ajax' ) );
		add_action( 'wp_ajax_nopriv_reload_weather_alerts', array( &$this, 'ajax' ) );
		add_filter( 'nonce_user_logged_out', array( &$this, 'nonce_user_logged_out' ) );

		// Load the alerts unless this is an AJAX request b/c we don't want to slow those down
		if ( ! defined( 'DOING_AJAX' ) ) {
			$this->load_alerts();
		}

		if ( $this->has_alerts() ) {
			// Disable W3TC caching when in a weather alert situation
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			add_filter( 'sudbury_alerts', array( &$this, 'parse_alerts' ) );
		}

		// If we should display weather alerts in the admin, then enqueue the notices
		if ( get_site_option( 'weather_alerts_in_admin', true ) ) {
			add_action( 'admin_notices', array( &$this, 'admin_notices' ) );
			add_action( 'network_admin_notices', array( &$this, 'admin_notices' ) );
		}
	}

	/**
	 * Talks to the NOAA Alerts System and gets a list of weather alerts for sudbury then parses them into a format recognized by the sudbury alert system
	 */
	function load_alerts() {

		if ( false !== ( $alerts = get_site_transient( 'weather_alerts' ) ) ) {

			$this->alerts = $alerts;
		} elseif ( false !== ( $stale_alerts = get_site_transient( 'stale_weather_alerts' ) ) ) {
			$this->alerts = $stale_alerts;
			// Spawn Async Reload
			$this->spawn_reload();

		} else {
			$this->spawn_reload();

			// We are really out of date must reload during this request
			_sudbury_log( '[error] [weather-alerts] System is running a manual reload' );
		}

	}

	/**
	 * Triggers the reload event
	 * @return bool Whether the reload was spawned or not
	 */
	function spawn_reload() {
		if ( ! get_site_transient( 'weather_alerts_reloading' ) ) {

			$nonce = wp_create_nonce( 'sudbury-weather-alerts' );

			$url = admin_url( 'admin-ajax.php?action=reload_weather_alerts&_wpnonce=' . $nonce . '&owner=' . get_current_user_id() );
			$url = str_replace( 'https://', 'http://', $url );

			// See this archiac issue that seems to be getting very little attention: https://core.trac.wordpress.org/ticket/18738
			$this->no_block_request( $url );


			return true;
		}

		return false;
	}

	/**
	 * The Endpoint for admin-ajax.php?action=reload_weather_alerts
	 * Will change this endpoint to a json rest endpoint later
	 */
	function ajax() {
		// Verify Request
		if ( ! isset( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( $_REQUEST['_wpnonce'], 'sudbury-weather-alerts' ) ) {
			wp_send_json_error( array( 'code' => '401 Unauthorized', 'message' => __( 'Nonce Verification Failed' ) ) );
		} else {

			// Do the Reload
			$result = $this->reload();

			// Return Status
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message()
				) );

			} else {
				wp_send_json_success( $this->get_alerts() );
			}
		}
	}

	/**
	 * Loads up Alerts Asyncronously for performance improvements.  Cannot use cron because cron does not run fast enough
	 * for us when we are in an emergency situation... when refresh is required every 10 seconds
	 */
	function reload() {
		// Check Lock
		if ( get_site_transient( 'weather_alerts_reloading' ) ) {
			// Someone Else is reloading weather alerts... quit
			return new WP_Error( 'weather_alerts_reloading', 'Weather Alerts are already Reloading' );
		}

		// Grab the Lock
		set_site_transient( 'weather_alerts_reloading', true, 20 );


		$alert_types_to_show = apply_filters( 'weather_alerts_search_terms', get_site_option( 'weather_alerts_search_terms', array(
			'tornado warning',
			'severe thunderstorm warning'
		) ) );

		$coordinates = apply_filters( 'weather_alerts_coordinates', get_site_option( 'weather_alerts_coordinates', array(
			array( "42.437255", "-71.430091" ),
			array( "42.412456", "-71.367254" ),
			array( "42.402443", "-71.469564" ),
			array( "42.352733", "-71.484842" ),
			array( "42.341442", "-71.389913" ),
		) ) );

		$weather_url = $this->get_alerts_endpoint();

		$feed = $this->get_xml_data( $weather_url );

		if ( is_wp_error( $feed ) ) {
			_sudbury_log( '[Error] Could not Retrieve weather data from NOAA: WP_Error' );
			_sudbury_log( $feed );

			// Immediately release lock to allow someone else to try again right away
			delete_site_transient( 'weather_alerts_reloading' );

			return $feed;
		}

		$this->alerts = array();

		// Filter the alerts because we don't want small alerts like flood watches, just the big exciting ones like tornado warnings
		foreach ( $feed->entry as $alert ) {

			$title_lc = strtolower( $alert->title );

			$show_alert = true;

			// We can filter alerts
			if ( ! empty( $alert_types_to_show ) ) {
				$show_alert = false;

				foreach ( $alert_types_to_show as $alert_title_frag ) {
					$search = trim( strtolower( $alert_title_frag ) );
					if ( '' == $search ) {
						continue;
					}
					if ( false !== strpos( $title_lc, $search ) ) {
						$show_alert = true;
					}
				}
			}

			if ( ! empty( $coordinates ) && $show_alert ) {
				$pointfinder = new pointLocation();

				$points = $alert->children( 'cap', true )->polygon;

				// Split it into an array of arrays containing x-y pairs
				$alert_polygon = array_map( function ( $point ) {
					return explode( ',', $point );
				}, explode( ' ', $points ) );

				$inside = false;

				// If any part of town is within the alert zone include it
				foreach ( $coordinates as $coordinate ) {
					if ( 'outside' !== $pointfinder->pointInPolygon( $coordinate, $alert_polygon ) ) {
						$inside = true;

						break;
					}
				}

				if ( ! $inside ) {
					$show_alert = true;
					_sudbury_log( 'Polygon Search: [Fail] ' . $alert->title );

				} else {
					_sudbury_log( 'Polygon Search: [Pass] ' . $alert->title );
				}

			}

			if ( $show_alert ) {
				$structured_alert = array(
					'title'         => (string) $alert->title,
					'url'           => (string) $alert->link['href'][0],
					'readmore-text' => 'View Alert',
					'alert-class'   => 'alert-red',
				);

				$this->alerts[] = $structured_alert;
			}
		}


		$cache_time = 60; // Default to checking NOAA every minute
		if ( ! empty( $this->alerts ) ) {
			set_site_transient( 'weather_alerts_recently', true, 3600 );
		}
		if ( defined( 'SUDBURY_WEATHER_CACHE_TIME' ) ) {
			$cache_time = SUDBURY_WEATHER_CACHE_TIME; // If there is an override in effect respect the override
		} else if ( get_site_transient( 'weather_alerts_recently' ) ) { // This speeds up the cache time when weather phenomena has been detected recently for faster and more up-to-date reporting
			_sudbury_log( '[Alerts] [Weather Alerts] Weather Alerts System Actively Checking Alerts at Increased Speed' );
			$cache_time = 10; // If there were recently weather alerts check NOAA every 10 seconds instead (6x faster than default)
		}


		/**
		 * This filter allows you to override the time that alerts will be cached in a transient for.
		 * The cache time varies based on the constant 'SUDBURY_WEATHER_CACHE_TIME' and whether there are currently
		 * any active weather alerts
		 */
		$cache_time = apply_filters( 'weather_alerts_cache_time', $cache_time, $this->alerts );

		// Fix for empty array bug with W3TC and wp_cache_set/get()
		if ( empty( $this->alerts ) ) {
			$this->alerts = 'none';
		}

		// This is the primary one... it contains the recent alerts... when it expires we need to spawn_reload()
		// When we spawn_reload we will use 'stale_weather_alerts' defined below
		set_site_transient( 'weather_alerts', $this->alerts, $cache_time );
		// Need a stale transient for the event that the primary transient expires... then we will return the stale one
		// while we are refreshing.
		set_site_transient( 'stale_weather_alerts', $this->alerts, $cache_time + 3600 );

		// Holding the lock for a couple more seconds to prevent any race conditions
		sleep( 2 );

		// Release the Reload Lock
		delete_site_transient( 'weather_alerts_reloading' );

		// success
		return true;

	}

	/**
	 * Returns the Weather Alerts URL and Prompts an admin if the url is not found
	 * @return string The Alerts Endpoint URL
	 */
	function get_alerts_endpoint() {
		$weather_url = get_site_option( 'weather_alerts_url', false );

		if ( ! $weather_url ) {
			add_action( 'admin_notices', function () {
				if ( current_user_can( $this->required_cap ) ) :
					if ( is_multisite() ) {
						$settings_url = network_admin_url( 'admin.php?page=weather-alerts-admin' );
					} else {
						$settings_url = admin_url( 'admin.php?page=weather-alerts-admin' );
					}
					?>
					<p><b>Weather Alerts System Error: </b> Please Set the NWS alerts feed for your area
						<a href="<?php echo esc_url( $settings_url ); ?>">here</a>
					</p>
				<?php endif;
			} );
			$weather_url = 'http://alerts.weather.gov/cap/wwaatmget.php?x=MAC017&y=0';
		}

		return $weather_url;
	}

	/**
	 * Gets the XML data from the specified $url and parses it into a SimpleXMLElement
	 *
	 * @param string $url The URL where the XML is located
	 *
	 * @return SimpleXMLElement
	 */
	function get_xml_data( $url ) {
		$start    = microtime( true );
		$response = wp_remote_get( $url, array( 'timeout' => 1.5 ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$feed = new SimpleXMLElement( $response['body'] );

		_sudbury_log( 'Finished talking with NOAA, total time was ' . ( microtime( true ) - $start ) . ' seconds', array( 'echo' => false ) );

		return $feed;
	}

	/**
	 * Meant to be used with the sudbury_alerts filter to add any weather alerts to the list of alerts to be displayed for this request
	 *
	 * @param array $existing_alerts
	 *
	 * @return array
	 */
	function parse_alerts( $existing_alerts ) {

		$alerts                          = apply_filters( 'weather_alerts', $this->get_alerts() );
		$existing_alerts['network-wide'] = array_merge( $alerts, $existing_alerts['network-wide'] );
		$existing_alerts['all']          = array_merge( $alerts, $existing_alerts['all'] );

		return $existing_alerts;

	}


	/**
	 * Pushes out admin notices for each weather alert so that you can get alerts when you are in wp-admin
	 */
	function admin_notices() {
		if ( $this->has_alerts() ) : ?>
			<?php foreach ( $this->get_alerts() as $alert ) : ?>
				<div class="error">
					<p><b>Weather Alert: </b> <?php _e( $alert['title'], 'weather_alerts' ); ?>
						<a href="<?php echo esc_url( $alert['url'] ); ?>"><?php _e( $alert['readmore-text'], 'weather_alerts' ); ?> </a>
					</p>
				</div>
			<?php endforeach; ?>
		<?php endif; ?>
	<?php
	}

	/**
	 * wp_verify_nonce is run as an anonymous user on wp_ajax_nopriv_reload_weather_alerts but the nonce was generated by
	 * an authenticated request... therefore the uids are different and nonces will break
	 *
	 * @param int $uid The UID of the not-logged-in user
	 *
	 * @return int the correct UID
	 */
	function nonce_user_logged_out( $uid ) {
		if ( doing_action( 'wp_ajax_nopriv_reload_weather_alerts' ) && isset( $_REQUEST['owner'] ) ) {
			return intval( $_REQUEST['owner'] );
		}

		return $uid;
	}

	/**
	 * Sends a truly non-blocking HTTP request to the $url because WordPress HTTP API isn't capable of doing what it
	 * claims with 'blocking' set to false because it still blocks... quite badly too
	 *
	 * @param string $url The URL
	 */
	function no_block_request( $url ) {

		$parts = parse_url( $url );

		$fp = fsockopen( $parts['host'],
			isset( $parts['port'] ) ? $parts['port'] : 80,
			$errno, $errstr, 1 );

		$out = "GET " . $parts['path'] . '?' . $parts['query'] . " HTTP/1.1\r\n";
		$out .= "Host: " . $parts['host'] . "\r\n";
		$out .= "Content-Type: application/x-www-form-urlencoded\r\n";
		$out .= "Content-Length: 0\r\n";
		$out .= "Connection: Close\r\n\r\n";

		fwrite( $fp, $out );
		fclose( $fp );
	}

	/**
	 * Retrieves the array of alerts
	 * @return array
	 */
	function get_alerts() {
		if ( $this->alerts == null ) {
			$this->load_alerts();
		}
		// W3TC and wp_cache_set/get bug for empty array()
		if ( 'none' == $this->alerts ) {
			return array();
		}

		return $this->alerts;
	}

	/**
	 * @return bool Whether there are alerts or not
	 */
	function has_alerts() {
		return ( 'none' != $this->alerts );
	}
}

// KickStart the Weather Alerts Class
$sudbury_weather_alerts = new Weather_Alerts_Core();

/**
 * This function will add scaffolds for certain global functions that are part of the non-open source sudbury plugins
 * so that you can use this plugin seamlessly, and Sudbury can use the same version without having to change it with every release
 */
function weather_alerts_sudbury_functions() {
	if ( ! function_exists( 'sudbury_log' ) ) {
		/**
		 * Logs and echos the given message
		 *
		 * @param       $message
		 * @param array $args
		 */
		function sudbury_log( $message, $args = array() ) {
			_sudbury_log( $message, $args );
			echo $message;
		}
	}
	if ( ! function_exists( '_sudbury_log' ) ) {
		/**
		 * Only Logs the given message (silent to the end user)
		 *
		 * @param       $message
		 * @param array $args
		 */
		function _sudbury_log( $message, $args = array() ) {
			error_log( $message );
		}
	}
}

add_action( 'init', 'weather_alerts_sudbury_functions', 1 );
