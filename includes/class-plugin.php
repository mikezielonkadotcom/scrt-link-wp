<?php
/**
 * Plugin bootstrap.
 *
 * @package ScrtLinkWP
 */

namespace ScrtLinkWP;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?Plugin $instance = null;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot(): void {
		add_action( 'init', [ $this, 'register_block' ] );
		add_action( 'enqueue_block_editor_assets', [ $this, 'localize_editor' ] );

		Settings::instance()->boot();
		Rest::instance()->boot();

		if ( defined( 'WP_CLI' ) && \WP_CLI ) {
			require_once SCRT_LINK_WP_PATH . 'includes/class-cli.php';
			CLI::register();
		}
	}

	public function register_block(): void {
		$block_dir = SCRT_LINK_WP_PATH . 'build/scrt-request';

		if ( ! file_exists( $block_dir . '/block.json' ) ) {
			return;
		}

		register_block_type( $block_dir );
	}

	public function localize_editor(): void {
		wp_add_inline_script(
			'scrt-link-wp-scrt-request-editor-script',
			sprintf(
				'window.scrtLinkWp = %s;',
				wp_json_encode(
					[
						'configured' => (bool) self::get_option( 'api_key' ),
						'settingsUrl' => esc_url_raw( admin_url( 'options-general.php?page=scrt-link-wp' ) ),
					]
				)
			),
			'before'
		);
	}

	/**
	 * Read a single plugin option with sensible defaults.
	 */
	public static function get_option( string $key, $default = '' ) {
		$opts = get_option( SCRT_LINK_WP_OPTION, [] );
		if ( ! is_array( $opts ) ) {
			$opts = [];
		}

		$defaults = [
			'api_key'          => '',
			'base_url'         => 'https://scrt.link',
			'notify_email'     => get_option( 'admin_email' ),
			'default_expiry'   => (string) ( 24 * 60 * 60 * 1000 ), // 24h in ms
			'rate_limit'       => '5',                              // submissions per hour per IP
		];

		$opts = array_merge( $defaults, $opts );

		if ( array_key_exists( $key, $opts ) ) {
			return $opts[ $key ];
		}

		return $default;
	}

	/**
	 * Forward a pre-built, pre-checksummed ciphertext payload to scrt.link.
	 * Shared by the REST proxy and the WP-CLI `test` command.
	 *
	 * @param string $payload_json The exact JSON string the checksum was computed over.
	 * @param string $checksum     SHA-256 hex of $payload_json.
	 * @return array|\WP_Error     scrt.link response decoded as assoc array, or WP_Error.
	 */
	public static function post_to_upstream( string $payload_json, string $checksum ) {
		$base_url = untrailingslashit( (string) self::get_option( 'base_url' ) );
		$host     = wp_parse_url( $base_url, PHP_URL_HOST );
		$endpoint = $base_url . '/api/v1/secrets';

		$args = [
			'timeout' => 15,
			'headers' => array_filter(
				[
					'Authorization' => 'Bearer ' . self::get_option( 'api_key' ),
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
					'X-Checksum'    => $checksum,
					'X-Host'        => $host ?: null,
					'User-Agent'    => 'scrt-link-wp/' . SCRT_LINK_WP_VERSION . '; ' . home_url(),
				]
			),
			'body'    => $payload_json,
		];

		/**
		 * Filter the HTTP args sent to scrt.link before the request goes out.
		 *
		 * @param array  $args         wp_remote_post args (timeout, headers, body).
		 * @param string $payload_json The raw ciphertext payload.
		 * @param string $endpoint     Full endpoint URL.
		 */
		$args = apply_filters( 'scrt_link_wp_request_args', $args, $payload_json, $endpoint );

		$response = wp_remote_post( $endpoint, $args );

		if ( is_wp_error( $response ) ) {
			/**
			 * Fires when the request to scrt.link could not be delivered.
			 *
			 * @param \WP_Error $error    WP_Error from wp_remote_post.
			 * @param int       $status   0 for transport failure.
			 * @param mixed     $body     Always null for transport failure.
			 */
			do_action( 'scrt_link_wp_upstream_failed', $response, 0, null );
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			/**
			 * Fires when scrt.link returns a non-2xx status.
			 *
			 * @param \WP_Error|null $error    Null on HTTP-level response.
			 * @param int            $status   HTTP status code from scrt.link.
			 * @param mixed          $body     Decoded response body.
			 */
			do_action( 'scrt_link_wp_upstream_failed', null, $code, $body );

			return new \WP_Error(
				'scrt_link_upstream_error',
				is_array( $body ) && ! empty( $body['message'] )
					? (string) $body['message']
					: sprintf( /* translators: %d: HTTP status */ __( 'scrt.link returned HTTP %d.', 'scrt-link-wp' ), $code ),
				[ 'status' => 502, 'upstream' => $code, 'body' => $body ]
			);
		}

		return is_array( $body ) ? $body : [];
	}
}
