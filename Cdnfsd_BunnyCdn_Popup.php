<?php
/**
 * File: Cdnfsd_BunnyCdn_Popup.php
 *
 * @since X.X.X
 *
 * @package W3TC
 */

namespace W3TC;

/**
 * Class: Cdnfsd_BunnyCdn_Popup
 *
 * @since X.X.X
 */
class Cdnfsd_BunnyCdn_Popup {
	/**
	 * W3TC AJAX: Popup.
	 *
	 * @since  X.X.X
	 * @static
	 */
	public static function w3tc_ajax() {
		$o = new Cdnfsd_BunnyCdn_Popup();

		add_action(
			'w3tc_ajax_cdn_bunnycdn_fsd_intro',
			array( $o, 'w3tc_ajax_cdn_bunnycdn_fsd_intro' )
		);

		add_action(
			'w3tc_ajax_cdn_bunnycdn_fsd_list_pull_zones',
			array( $o, 'w3tc_ajax_cdn_bunnycdn_fsd_list_pull_zones' )
		);

		add_action(
			'w3tc_ajax_cdn_bunnycdn_fsd_configure_pull_zone',
			array( $o, 'w3tc_ajax_cdn_bunnycdn_fsd_configure_pull_zone' )
		);

		add_action(
			'w3tc_ajax_cdn_bunnycdn_fsd_deauthorization',
			array( $o, 'w3tc_ajax_cdn_bunnycdn_fsd_deauthorization' )
		);

		add_action(
			'w3tc_ajax_cdn_bunnycdn_fsd_deauthorize',
			array( $o, 'w3tc_ajax_cdn_bunnycdn_fsd_deauthorize' )
		);
	}

	/**
	 * W3TC AJAX: Intro -- authorization.
	 *
	 * @since X.X.X
	 */
	public function w3tc_ajax_cdn_bunnycdn_fsd_intro() {
		$config          = Dispatcher::config();
		$account_api_key = $config->get_string( 'cdn.bunnycdn.account_api_key' );

		// Ask for an account API key.
		$this->render_intro(
			array(
				'account_api_key' => empty( $account_api_key ) ? null : $account_api_key,
			)
		);
	}

	/**
	 * W3TC AJAX: List pull zones.
	 *
	 * @since X.X.X
	 */
	public function w3tc_ajax_cdn_bunnycdn_fsd_list_pull_zones() {
		$account_api_key = Util_Request::get_string( 'account_api_key' );
		$api             = new Cdn_BunnyCdn_Api( array( 'account_api_key' => $account_api_key ) );

		// Try to retrieve pull zones.
		try {
			$pull_zones = $api->list_pull_zones();
		} catch ( \Exception $ex ) {
			// Reauthorize: Ask for a new account API key.
			$this->render_intro(
				array(
					'account_api_key' => empty( $account_api_key ) ? null : $account_api_key,
					'error_message'   => esc_html( __( 'Cannot list pull zones', 'w3-total-cache' ) . '; ' . $ex->getMessage() ),
				)
			);
		}

		// Save the account API key, if added or changed.
		$config = Dispatcher::config();

		if ( $config->get_string( 'cdn.bunnycdn.account_api_key' ) !== $account_api_key ) {
			$config->set( 'cdn.bunnycdn.account_api_key', $account_api_key );
			$config->save();
		}

		// Print the view.
		$server_ip            = ! empty( $_SERVER['SERVER_ADDR'] ) && \filter_var( \wp_unslash( $_SERVER['SERVER_ADDR'] ), FILTER_VALIDATE_IP ) ?
			\filter_var( \wp_unslash( $_SERVER['SERVER_ADDR'] ), FILTER_SANITIZE_URL ) : null;
		$suggested_origin_url = 'http' . ( is_ssl() ? 's' : '' ) . '://' .
			( empty( $server_ip ) ? \parse_url( \home_url(), PHP_URL_HOST ) : $server_ip );

		$details = array(
			'pull_zones'           => $pull_zones,
			'suggested_origin_url' => $suggested_origin_url, // Suggested origin URL or IP.
			'suggested_zone_name'  => \substr( \str_replace( '.', '-', \parse_url( \home_url(), PHP_URL_HOST ) ), 0, 60 ), // Suggested pull zone name.
			'pull_zone_id'         => $config->get_integer( 'cdnfsd.bunnycdn.pull_zone_id' ),
		);

		include W3TC_DIR . '/Cdnfsd_BunnyCdn_Popup_View_Pull_Zones.php';
		\wp_die();
	}

	/**
	 * W3TC AJAX: Configure pull zone.
	 *
	 * @since X.X.X
	 */
	public function w3tc_ajax_cdn_bunnycdn_fsd_configure_pull_zone() {
		$config          = Dispatcher::config();
		$account_api_key = $config->get_string( 'cdn.bunnycdn.account_api_key' );
		$pull_zone_id    = Util_Request::get_string( 'pull_zone_id' );
		$origin_url      = Util_Request::get_string( 'origin_url' ); // Origin URL or IP.
		$name            = Util_Request::get_string( 'name' ); // Pull zone name.
		$cdn_hostname    = Util_Request::get_string( 'cdn_hostname' ); // Pull zone CDN hostname.

		// If not selecting a pull zone. then create a new one.
		if ( empty( $pull_zone_id ) ) {
			$api = new Cdn_BunnyCdn_Api( array( 'account_api_key' => $account_api_key ) );

			// Try to create a new pull zone.
			try {
				$response = $api->add_pull_zone(
					array(
						'Name'                => $name, // The name/hostname for the pull zone where the files will be accessible; only letters, numbers, and dashes.
						'OriginUrl'           => $origin_url, // Origin URL or IP (with optional port number).
						'EnableTLS1'          => false, // TLS 1.0 was deprecated in 2018.
						'EnableTLS1_1'        => false, // TLS 1.1 was EOL's on March 31,2020.
						'ErrorPageWhitelabel' => true, // Any bunny.net branding will be removed from the error page and replaced with a generic term.
					)
				);

				$pull_zone_id = (int) $response['Id'];
				$name         = $response['Name'];
				$cdn_hostname = $response['Hostnames'][0]['Value'];
			} catch ( \Exception $ex ) {
				// Reauthorize: Ask for a new account API key.
				$this->render_intro(
					array(
						'account_api_key' => empty( $account_api_key ) ? null : $account_api_key,
						'error_message'   => esc_html( __( 'Cannot select or add a pull zone', 'w3-total-cache' ) . '; ' . $ex->getMessage() ),
					)
				);
			}
		}

		// Save configuration.
		$config->set( 'cdnfsd.bunnycdn.pull_zone_id', $pull_zone_id );
		$config->set( 'cdnfsd.bunnycdn.name', $name );
		$config->set( 'cdnfsd.bunnycdn.origin_url', $origin_url );
		$config->set( 'cdnfsd.bunnycdn.cdn_hostname', $cdn_hostname );
		$config->save();

		// Print success view.
		include W3TC_DIR . '/Cdnfsd_BunnyCdn_Popup_View_Configured.php';
		\wp_die();
	}

	/**
	 * W3TC AJAX: Deauthorization form.
	 *
	 * @since X.X.X
	 */
	public function w3tc_ajax_cdn_bunnycdn_fsd_deauthorization() {
		$config          = Dispatcher::config();
		$origin_url      = $config->get_string( 'cdnfsd.bunnycdn.origin_url' ); // Origin URL or IP.
		$name            = $config->get_string( 'cdnfsd.bunnycdn.name' ); // Pull zone name.
		$cdn_hostname    = $config->get_string( 'cdnfsd.bunnycdn.cdn_hostname' ); // Pull zone CDN hostname.

		// Present details and ask to deauthorize and optionally delete the pull zone.
		include W3TC_DIR . '/Cdnfsd_BunnyCdn_Popup_View_Deauthorize.php';
		\wp_die();
	}

	/**
	 * W3TC AJAX: Deauthorize.
	 *
	 * Deauthorize and optionally delete the pull zone.
	 *
	 * @since X.X.X
	 */
	public function w3tc_ajax_cdn_bunnycdn_fsd_deauthorize() {
		$config           = Dispatcher::config();
		$account_api_key  = $config->get_string( 'cdn.bunnycdn.account_api_key' );
		$pull_zone_id     = $config->get_string( 'cdnfsd.bunnycdn.pull_zone_id' );
		$delete_pull_zone = Util_Request::get_string( 'delete_pull_zone' );

		// Delete pull zone, if requested.
		if ( 'yes' === $delete_pull_zone ) {
			$api = new Cdn_BunnyCdn_Api( array( 'account_api_key' => $account_api_key ) );

			// Try to delete pull zone.
			try {
				$result = $api->delete_pull_zone( $pull_zone_id );
			} catch ( \Exception $ex ) {
				$delete_error_message = $ex->getMessage();
			}
		}

		$config->set( 'cdnfsd.bunnycdn.pull_zone_id', null );
		$config->set( 'cdnfsd.bunnycdn.name', null );
		$config->set( 'cdnfsd.bunnycdn.origin_url', null );
		$config->set( 'cdnfsd.bunnycdn.cdn_hostname', null );
		$config->save();

		// Print success view.
		include W3TC_DIR . '/Cdnfsd_BunnyCdn_Popup_View_Deauthorized.php';
		\wp_die();
	}

	/**
	 * Render intro.
	 *
	 * @since  X.X.X
	 * @access private
	 *
	 * @param array $details {
	 *     Details for the modal.
	 *
	 *     @type string $account_api_key Account API key.
	 *     @type string $error_message Error message (optional).
	 * }
	 */
	private function render_intro( array $details ) {
		include W3TC_DIR . '/Cdnfsd_BunnyCdn_Popup_View_Intro.php';
		\wp_die();
	}
}
