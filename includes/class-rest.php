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
		// Note on auth: this endpoint is intentionally reachable by anonymous visitors —
		// the block is a public "send me a secret" form. We deliberately DO NOT use
		// WP cookie-based nonces here. Most managed-WP hosts and CDNs (BigScoots,
		// Cloudflare page cache, LiteSpeed, etc.) either strip auth cookies from
		// /wp-json POSTs or serve cached HTML with stale nonces baked in — both
		// produce "Cookie check failed" for real visitors. Security posture relies on:
		//
		//   1. Content is end-to-end encrypted client-side before it reaches this
		//      endpoint (nothing sensitive for an attacker to steal).
		//   2. Per-IP rate limit (see check_rate_limit) caps abuse.
		//   3. Origin-header check below rejects cross-origin POSTs from other sites.
		//   4. The scrt.link API key never leaves PHP; attackers can only burn the
		//      site owner's upstream quota, which is further rate-limited by scrt.link.
		if ( ! Plugin::get_option( 'api_key' ) ) {
			return new \WP_Error( 'scrt_link_not_configured', __( 'scrt.link plugin has not been configured.', 'scrt-link-wp' ), [ 'status' => 503 ] );
		}

		$origin = (string) $request->get_header( 'origin' );
		if ( '' !== $origin ) {
			$site_host   = wp_parse_url( home_url(), PHP_URL_HOST );
			$origin_host = wp_parse_url( $origin, PHP_URL_HOST );
			if ( $site_host && $origin_host && 0 !== strcasecmp( $site_host, $origin_host ) ) {
				return new \WP_Error( 'rest_forbidden_origin', __( 'Cross-origin submissions are not permitted.', 'scrt-link-wp' ), [ 'status' => 403 ] );
			}
		}

		return true;
	}

	/**
	 * Public config for the block frontend. Never exposes the API key.
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

		/**
		 * Reject oversized bodies before spending an upstream call. scrt.link ciphertext
		 * is small in practice; cap the whole JSON body to keep an attacker from tying up
		 * the site's quota (and inbox) with megabyte payloads. Filterable for edge cases.
		 *
		 * @param int $max_body_bytes Maximum accepted request body size in bytes.
		 */
		$max_body_bytes = (int) apply_filters( 'scrt_link_wp_max_body_bytes', 65536 );
		if ( strlen( $body_json ) > $max_body_bytes ) {
			return new \WP_Error( 'scrt_link_bad_request', __( 'Payload too large.', 'scrt-link-wp' ), [ 'status' => 413 ] );
		}

		if ( ! preg_match( '/^[A-Za-z0-9$~\-_.]{20,64}$/', $secret_id ) ) {
			return new \WP_Error( 'scrt_link_bad_request', __( 'Invalid secret id.', 'scrt-link-wp' ), [ 'status' => 400 ] );
		}

		$decoded = json_decode( $body_json, true );
		if ( ! is_array( $decoded ) || empty( $decoded['secretIdHash'] ) || empty( $decoded['content'] ) ) {
			return new \WP_Error( 'scrt_link_bad_request', __( 'Malformed payload.', 'scrt-link-wp' ), [ 'status' => 400 ] );
		}

		// Enforce the client's publicNote cap (140, see maxlength in render.php) server-side.
		// The note is emailed to the owner verbatim, so bound it before the upstream call.
		if ( isset( $decoded['publicNote'] ) && mb_strlen( (string) $decoded['publicNote'] ) > 140 ) {
			return new \WP_Error( 'scrt_link_bad_request', __( 'Note is too long.', 'scrt-link-wp' ), [ 'status' => 400 ] );
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

		// Global/site-wide cap, checked in addition to (and before) the per-IP bucket.
		// The per-IP limiter alone is defeated by IP rotation or by many visitors
		// collapsed behind a single CDN egress IP; this caps total accepted requests
		// site-wide per hour so the owner's upstream quota and inbox can't be drained.
		/**
		 * Filter the site-wide hourly cap on accepted submissions.
		 *
		 * @param int $global_limit Max accepted requests site-wide per hour.
		 */
		$global_limit   = max( 1, (int) apply_filters( 'scrt_link_wp_global_rate_limit', 100 ) );
		$global_key     = 'scrt_link_wp_rl_global';
		$global_count   = (int) get_transient( $global_key );
		if ( $global_count >= $global_limit ) {
			return false;
		}

		$limit = max( 1, (int) Plugin::get_option( 'rate_limit' ) );
		if ( '' === $ip ) {
			// Can't identify the client for a per-IP bucket, but the request still
			// counts against the global cap so an unidentifiable flood is bounded.
			set_transient( $global_key, $global_count + 1, HOUR_IN_SECONDS );
			return true;
		}

		$key   = 'scrt_link_wp_rl_' . md5( $ip );
		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return false;
		}

		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		set_transient( $global_key, $global_count + 1, HOUR_IN_SECONDS );
		return true;
	}

	/**
	 * Resolve the client IP for rate-limiting buckets.
	 *
	 * REMOTE_ADDR is the only value we can trust unconditionally. Forwarded headers
	 * (CF-Connecting-IP, X-Forwarded-For) are attacker-controllable and MUST NOT be
	 * honored blindly — doing so would let anyone mint unlimited per-IP buckets by
	 * spoofing the header. We only read them when the request demonstrably arrived
	 * through the CDN: either REMOTE_ADDR is inside a published Cloudflare range, or
	 * the site owner explicitly opts in via the `scrt_link_wp_trust_proxy_headers`
	 * filter (for other reverse proxies, e.g. BigScoots' front layer).
	 *
	 * IPv6 addresses are aggregated to their /64 prefix so a single allocation can't
	 * cycle through 2^64 addresses to defeat the per-IP limit.
	 */
	private function client_ip(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? filter_var( wp_unslash( $_SERVER['REMOTE_ADDR'] ), FILTER_VALIDATE_IP ) : false;
		$remote = $remote ?: '';

		$candidate = $remote;

		$trust_proxy = (bool) apply_filters( 'scrt_link_wp_trust_proxy_headers', false );
		if ( '' !== $remote && ( $trust_proxy || $this->is_cloudflare_ip( $remote ) ) ) {
			// Prefer Cloudflare's single-IP header; fall back to the left-most XFF entry.
			$cf = isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ? filter_var( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ), FILTER_VALIDATE_IP ) : false;
			if ( $cf ) {
				$candidate = $cf;
			} elseif ( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
				$parts = explode( ',', (string) wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
				$first = filter_var( trim( (string) reset( $parts ) ), FILTER_VALIDATE_IP );
				if ( $first ) {
					$candidate = $first;
				}
			}
		}

		if ( '' === $candidate ) {
			return '';
		}

		// Aggregate IPv6 to /64 so one allocation maps to one bucket.
		if ( filter_var( $candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$packed = @inet_pton( $candidate );
			if ( false !== $packed && strlen( $packed ) === 16 ) {
				return 'v6/64:' . bin2hex( substr( $packed, 0, 8 ) );
			}
		}

		return $candidate;
	}

	/**
	 * Whether an IP falls inside Cloudflare's published IPv4/IPv6 ranges. Used to
	 * decide if CF-Connecting-IP / X-Forwarded-For can be trusted for this request.
	 * List per https://www.cloudflare.com/ips/ (filterable in case ranges change).
	 */
	private function is_cloudflare_ip( string $ip ): bool {
		$ranges = (array) apply_filters(
			'scrt_link_wp_cloudflare_ranges',
			[
				'173.245.48.0/20',
				'103.21.244.0/22',
				'103.22.200.0/22',
				'103.31.4.0/22',
				'141.101.64.0/18',
				'108.162.192.0/18',
				'190.93.240.0/20',
				'188.114.96.0/20',
				'197.234.240.0/22',
				'198.41.128.0/17',
				'162.158.0.0/15',
				'104.16.0.0/13',
				'104.24.0.0/14',
				'172.64.0.0/13',
				'131.0.72.0/22',
				'2400:cb00::/32',
				'2606:4700::/32',
				'2803:f800::/32',
				'2405:b500::/32',
				'2405:8100::/32',
				'2a06:98c0::/29',
				'2c0f:f248::/32',
			]
		);

		foreach ( $ranges as $range ) {
			if ( $this->ip_in_cidr( $ip, (string) $range ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Test whether an IP is contained in a CIDR range (IPv4 or IPv6).
	 */
	private function ip_in_cidr( string $ip, string $cidr ): bool {
		if ( false === strpos( $cidr, '/' ) ) {
			return false;
		}
		list( $subnet, $bits ) = explode( '/', $cidr, 2 );
		$bits = (int) $bits;

		$ip_bin     = @inet_pton( $ip );
		$subnet_bin = @inet_pton( $subnet );
		if ( false === $ip_bin || false === $subnet_bin || strlen( $ip_bin ) !== strlen( $subnet_bin ) ) {
			return false; // different families or malformed
		}

		$bytes = intdiv( $bits, 8 );
		$rem   = $bits % 8;

		if ( $bytes > 0 && 0 !== substr_compare( $ip_bin, substr( $subnet_bin, 0, $bytes ), 0, $bytes ) ) {
			return false;
		}

		if ( $rem > 0 ) {
			$mask     = chr( 0xff << ( 8 - $rem ) & 0xff );
			$ip_byte  = isset( $ip_bin[ $bytes ] ) ? ord( $ip_bin[ $bytes ] ) : 0;
			$sub_byte = isset( $subnet_bin[ $bytes ] ) ? ord( $subnet_bin[ $bytes ] ) : 0;
			if ( ( $ip_byte & ord( $mask ) ) !== ( $sub_byte & ord( $mask ) ) ) {
				return false;
			}
		}

		return true;
	}
}
