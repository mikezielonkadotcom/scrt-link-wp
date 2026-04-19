<?php
/**
 * REST proxy — receives ciphertext from the block, forwards to scrt.link with Bearer token,
 * emails the resulting self-destructing URL to the site's notification address.
 *
 * @package ScrtLinkWP
 */

namespace ScrtLinkWP;

defined( 'ABSPATH' ) || exit;

final class Rest {

	private const NAMESPACE = 'scrt-link/v1';

	private static ?Rest $instance = null;

	public static function instance(): Rest {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/submit',
			[
				'methods'             => 'POST',
				'permission_callback' => [ $this, 'permission_check' ],
				'callback'            => [ $this, 'handle_submit' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/config',
			[
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => [ $this, 'handle_config' ],
			]
		);
	}

	/**
	 * Permission: anonymous visitors must present a valid REST nonce (enqueued with the
	 * block's view module). The nonce ties the request to the current session.
	 */
	public function permission_check( \WP_REST_Request $request ) {
		$nonce = $request->get_header( 'x_wp_nonce' );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new \WP_Error( 'rest_forbidden', __( 'Invalid or missing nonce.', 'scrt-link-wp' ), [ 'status' => 403 ] );
		}

		if ( ! Plugin::get_option( 'api_key' ) ) {
			return new \WP_Error( 'scrt_link_not_configured', __( 'scrt.link plugin has not been configured.', 'scrt-link-wp' ), [ 'status' => 503 ] );
		}

		return true;
	}

	/**
	 * Public config for the block frontend — base URL only. Never exposes the API key.
	 */
	public function handle_config(): \WP_REST_Response {
		return new \WP_REST_Response(
			[
				'baseUrl'   => untrailingslashit( (string) Plugin::get_option( 'base_url' ) ),
				'expiresIn' => (int) Plugin::get_option( 'default_expiry' ),
			],
			200
		);
	}

	public function handle_submit( \WP_REST_Request $request ) {
		if ( ! $this->check_rate_limit() ) {
			return new \WP_Error( 'rate_limited', __( 'Too many submissions. Try again later.', 'scrt-link-wp' ), [ 'status' => 429 ] );
		}

		$body_json  = (string) $request->get_body();
		$checksum   = (string) $request->get_header( 'x_scrt_checksum' );
		$secret_id  = (string) $request->get_header( 'x_scrt_secret_id' );

		if ( '' === $body_json || '' === $checksum || '' === $secret_id ) {
			return new \WP_Error( 'scrt_link_bad_request', __( 'Missing ciphertext, checksum, or secret id.', 'scrt-link-wp' ), [ 'status' => 400 ] );
		}

		if ( ! preg_match( '/^[A-Za-z0-9$~\-_.]{20,64}$/', $secret_id ) ) {
			return new \WP_Error( 'scrt_link_bad_request', __( 'Invalid secret id.', 'scrt-link-wp' ), [ 'status' => 400 ] );
		}

		$decoded = json_decode( $body_json, true );
		if ( ! is_array( $decoded ) || empty( $decoded['secretIdHash'] ) || empty( $decoded['content'] ) ) {
			return new \WP_Error( 'scrt_link_bad_request', __( 'Malformed payload.', 'scrt-link-wp' ), [ 'status' => 400 ] );
		}

		$resp_body = Plugin::post_to_upstream( $body_json, $checksum );

		if ( is_wp_error( $resp_body ) ) {
			return $resp_body;
		}

		$base_url    = untrailingslashit( (string) Plugin::get_option( 'base_url' ) );
		$secret_link = $base_url . '/s#' . $secret_id;
		$public_note = isset( $decoded['publicNote'] ) ? sanitize_textarea_field( (string) $decoded['publicNote'] ) : '';
		$expires_at  = ! empty( $resp_body['expiresAt'] ) ? (string) $resp_body['expiresAt'] : '';

		$this->deliver_to_owner( $secret_link, $public_note, $expires_at );

		/**
		 * Fires after a secret has been successfully created on scrt.link and
		 * the owner notification has been dispatched.
		 *
		 * Integration point for: Slack delivery, CRM logging, custom CPTs, webhooks.
		 *
		 * @param string $secret_link Full self-destructing URL (e.g. https://scrt.link/s#abc).
		 * @param string $public_note Visitor-supplied unencrypted "from" note, may be empty.
		 * @param string $expires_at  ISO-8601 timestamp of upstream expiry, may be empty.
		 * @param array  $upstream    Full decoded response from scrt.link.
		 */
		do_action( 'scrt_link_wp_secret_created', $secret_link, $public_note, $expires_at, $resp_body );

		return new \WP_REST_Response(
			[
				'ok'        => true,
				'expiresAt' => $expires_at,
			],
			200
		);
	}

	/**
	 * Public entry point for the same owner-notification email the REST handler fires.
	 * Used by the WP-CLI `test` command so it goes through every filter (`scrt_link_wp_email_*`).
	 */
	public function deliver_to_owner_public( string $link, string $note, string $expiry ): void {
		$this->deliver_to_owner( $link, $note, $expiry );
	}

	private function deliver_to_owner( string $link, string $note, string $expiry ): void {
		/**
		 * Filter the notification recipient.
		 *
		 * @param string $to     Current recipient (site notify_email option).
		 * @param string $link   Self-destructing secret URL.
		 * @param string $note   Visitor's plain-text "from" note.
		 * @param string $expiry ISO-8601 expiry timestamp.
		 */
		$to = (string) apply_filters(
			'scrt_link_wp_email_to',
			(string) Plugin::get_option( 'notify_email' ),
			$link,
			$note,
			$expiry
		);

		if ( ! is_email( $to ) ) {
			return;
		}

		/**
		 * Filter the notification subject line.
		 *
		 * @param string $subject Default subject.
		 * @param string $link    Self-destructing secret URL.
		 * @param string $note    Visitor's plain-text "from" note.
		 */
		$subject = (string) apply_filters(
			'scrt_link_wp_email_subject',
			sprintf(
				/* translators: %s: site name */
				__( '[%s] You received a new encrypted secret', 'scrt-link-wp' ),
				wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
			),
			$link,
			$note
		);

		$lines = [
			__( 'A visitor submitted a secret through your site.', 'scrt-link-wp' ),
			'',
			__( 'Open the self-destructing link to read it. It can only be opened once.', 'scrt-link-wp' ),
			'',
			$link,
			'',
		];

		if ( '' !== $note ) {
			$lines[] = __( 'Visitor note:', 'scrt-link-wp' );
			$lines[] = $note;
			$lines[] = '';
		}

		if ( '' !== $expiry ) {
			$lines[] = sprintf( /* translators: %s: ISO timestamp */ __( 'Expires: %s', 'scrt-link-wp' ), $expiry );
		}

		$default_body = implode( "\n", $lines );

		/**
		 * Filter the notification email body. Return HTML to switch to an HTML email
		 * (together with a `text/html` content-type via the standard `wp_mail_content_type` filter).
		 *
		 * @param string $body   Default plain-text body.
		 * @param string $link   Self-destructing secret URL.
		 * @param string $note   Visitor's plain-text "from" note.
		 * @param string $expiry ISO-8601 expiry timestamp.
		 */
		$body = (string) apply_filters( 'scrt_link_wp_email_body', $default_body, $link, $note, $expiry );

		wp_mail( $to, $subject, $body );
	}

	private function check_rate_limit(): bool {
		$ip = $this->client_ip();

		/**
		 * Filter whether to skip rate limiting for the current request. Return true
		 * to bypass (e.g. allowlist an office IP, skip during load tests).
		 *
		 * @param bool   $skip False by default.
		 * @param string $ip   Client IP (may be empty if not resolvable).
		 */
		if ( apply_filters( 'scrt_link_wp_rate_limit_skip', false, $ip ) ) {
			return true;
		}

		$limit = max( 1, (int) Plugin::get_option( 'rate_limit' ) );
		if ( '' === $ip ) {
			return true; // fail open — don't block if we can't identify
		}

		$key   = 'scrt_link_wp_rl_' . md5( $ip );
		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return false;
		}

		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		return true;
	}

	private function client_ip(): string {
		$raw = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
		$ip  = filter_var( $raw, FILTER_VALIDATE_IP );
		return $ip ?: '';
	}
}
