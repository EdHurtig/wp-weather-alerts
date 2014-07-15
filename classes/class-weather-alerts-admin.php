<?php

/**
 * Handles the admin UI for the Weather Alerts System
 *
 * Class Weather_Alerts_Admin
 */
class Weather_Alerts_Admin {
	function __construct() {
		add_action( 'init', array( &$this, 'init' ) );
	}

	function init() {
		if ( ! is_admin() && ! is_network_admin() ) {
			return;
		}
		if ( is_multisite() ) {
			add_action( 'network_admin_menu', array( &$this, 'admin_menu' ) );
		} else {
			add_action( 'admin_menu', array( &$this, 'admin_menu' ) );
		}
		add_action( 'admin_post_weather_alerts_save', array( &$this, 'save' ) );
	}

	function admin_menu() {
		add_menu_page( 'Weather Alerts System', 'Weather Alerts', $this->required_cap(), 'weather-alerts-admin', array(
			&$this,
			'admin_page'
		), 'dashicons-cloud' );
	}

	function admin_page() {

		$weather_alerts_url          = get_site_option( 'weather_alerts_url', '' );
		$weather_alerts_search_terms = get_site_option( 'weather_alerts_search_terms', array() );
		$weather_alerts_in_admin     = get_site_option( 'weather_alerts_in_admin', true );

		?>
		<h2>Weather Alerts Settings</h2>

		<?php if ( isset( $_REQUEST['weather_alerts_msg'] ) ) : ?>
			<div class="<?php echo esc_attr( $_REQUEST['weather_alerts_msg_class'] ); ?>">
				<p><?php _e( $_REQUEST['weather_alerts_msg'], 'weather_alerts' ); ?></p>
			</div>
		<?php endif; ?>

		<form action="<?php echo admin_url( 'admin-post.php' ); ?>" method="post">
			<?php wp_nonce_field( 'save_weather_alerts_option', 'weather_alerts_nonce_form' ); ?>
			<?php wp_referer_field(); ?>
			<input type="hidden" name="action" value="weather_alerts_save">
			<table class="form-table">
				<tbody>
				<tr>
					<th scope="row"><label for="weather_alerts_url">Weather Alerts Url (NWS Atom Feed)</label></th>
					<td>
						<input name="weather_alerts_url" type="text" id="weather_alerts_url" class="regular-text" value="<?php echo esc_attr( $weather_alerts_url ); ?>" style="width:35em;" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="weather_alerts_url">Alerts Filter</label><br>
						<i>(1 term per line)</i>
					</th>
					<td>
						<textarea name="weather_alerts_search_terms" id="weather_alerts_search_terms" cols="45" rows="8"><?php echo esc_textarea( implode( "\n", $weather_alerts_search_terms ) ); ?></textarea><br>
						<i>We will show the alert if the title contains any of the terms (case-insensitive) </i>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="weather_alerts_url">Show Alerts in Admin</label></th>
					<td>
						<input type="checkbox" name="weather_alerts_in_admin" id="weather_alerts_in_admin" <?php checked( $weather_alerts_in_admin ); ?>/>
					</td>
				</tr>

				</tbody>
			</table>


			<?php submit_button( "Save Changes" ); ?>
		</form>

	<?php
	}

	function save() {
		check_admin_referer( 'save_weather_alerts_option', 'weather_alerts_nonce_form' );

		if ( is_multisite() ) {
			if ( ! current_user_can( 'manage_network' ) ) {
				wp_redirect( add_query_arg( array(
					'weather_alerts_msg'       => urlencode( 'Access Denied you need to have the <b>manage_network</b> Capability to update options on a multisite network' ),
					'weather_alerts_msg_class' => 'error'
				), wp_get_referer() ), 302 );

				return;
			}
		}

		if ( isset( $_POST['weather_alerts_url'] ) ) {
			$weather_alerts_url = sanitize_text_field( $_POST['weather_alerts_url'] );

			update_site_option( 'weather_alerts_url', $weather_alerts_url );
		}

		if ( isset( $_POST['weather_alerts_search_terms'] ) ) {
			$weather_alerts_search_terms = trim( strtolower( $this->sanitize_textarea( $_POST['weather_alerts_search_terms'] ) ) );
			$weather_alerts_search_terms = explode( "\n", $weather_alerts_search_terms );
			update_site_option( 'weather_alerts_search_terms', $weather_alerts_search_terms );
		}

		if ( isset( $_POST['weather_alerts_in_admin'] ) && $_POST['weather_alerts_in_admin'] == 'on' ) {
			update_site_option( 'weather_alerts_in_admin', true );
		} else {
			update_site_option( 'weather_alerts_in_admin', false );
		}

		wp_redirect( add_query_arg( array(
			'weather_alerts_msg'       => urlencode( 'Settings Saved' ),
			'weather_alerts_msg_class' => 'updated'
		), wp_get_referer() ), 302 );
	}

	function required_cap() {
		return ( is_multisite() ? 'manage_network' : 'manage_options' );
	}

	function sanitize_textarea( $dirty ) {
		$newline = '=+=NEWLINE=+=';
		$dirty   = str_replace( "\n", $newline, $dirty );
		$clean   = sanitize_text_field( $dirty );
		$clean   = str_replace( $newline, "\n", $clean );

		return $clean;
	}
}

$weather_alerts_admin = new Weather_Alerts_Admin();