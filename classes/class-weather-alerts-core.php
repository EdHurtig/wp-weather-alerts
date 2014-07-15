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
	 *
	 */
	function init() {

		if ( ( $this->alerts = get_transient( 'weather_alerts' ) ) === false ) {
			$this->load_alerts();
		}

		if ( ! empty( $this->alerts ) ) {
			// Generic Alerts Filter, You need to hook into this using apply_filters('alerts', array())
			// TODO: Make front-end alerts work out of the box
			add_filter( 'alerts', array( &$this, 'parse_alerts' ) );
		}

		if ( get_site_option( 'weather_alerts_in_admin', true ) ) {
			add_action( 'admin_notices', array( &$this, 'admin_notices' ) );
			add_action( 'network_admin_notices', array( &$this, 'admin_notices' ) );
		}
	}

	/**
	 * Talks to the NOAA Alerts System and gets a list of weather alerts for sudbury then parses them into a format recognized by the sudbury alert system
	 */
	function load_alerts() {
		$weather_url  = get_site_option( 'weather_alerts_url', false );
		$this->alerts = array();
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
		$alert_types_to_show = apply_filters( 'weather_alerts_search_terms', get_site_option( 'weather_alerts_search_terms', array(
			'tornado warning',
			'severe thunderstorm warning'
		) ) );
		$feed                = $this->get_xml_data( $weather_url );

		if ( is_wp_error( $feed ) ) {
			return;
		}


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

		// This speeds up the cache time when weather phenomena has been detected for faster and more up-to-date reporting
		if ( ! empty( $this->alerts ) ) {
			set_transient( 'had_recent_weather_alerts', true, 3600 );
		}

		$cache_time = 60; // Default to checking NOAA every minute

		if ( get_transient( 'had_recent_weather_alerts' ) ) {
			$cache_time = 10; // If there were recently weather alerts check NOAA every 10 seconds instead (6x faster than default)
		}

		if ( defined( 'WEATHER_ALERTS_CACHE_TIME' ) ) {
			$cache_time = WEATHER_ALERTS_CACHE_TIME; // If there is an override in effect respect the override
		}

		set_transient( 'weather_alerts', $this->alerts, $cache_time );

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
		$response = wp_remote_get( $url );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$feed = new SimpleXMLElement( $response['body'] );

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
		$alerts = apply_filters( 'weather_alerts', $this->alerts );

		$existing_alerts['network-wide'] = array_merge( $alerts, $existing_alerts['network-wide'] );
		$existing_alerts['all']          = array_merge( $alerts, $existing_alerts['all'] );

		return $existing_alerts;

	}

	/**
	 * Pushes out admin notices for each weather alert so that you can get alerts when you are in wp-admin
	 */
	function admin_notices() {
		?>
		<?php foreach ( $this->alerts as $alert ) : ?>
			<div class="error">
				<p><b>Weather Alert: </b> <?php _e( $alert['title'], 'weather_alerts' ); ?>
					<a href="<?php echo esc_url( $alert['url'] ); ?>"><?php _e( $alert['readmore-text'], 'weather_alerts' ); ?> </a>
				</p>
			</div>
		<?php endforeach; ?>
	<?php
	}
}

// KickStart the Weather Alerts Class
$weather_alerts = new Weather_Alerts_Core();