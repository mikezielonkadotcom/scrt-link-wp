<?php
/**
 * WP-CLI commands for scrt-link-wp.
 *
 * @package ScrtLinkWP
 */

namespace ScrtLinkWP;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! \WP_CLI ) {
	return;
}

/**
 * Manage the scrt-link-wp plugin from the command line.
 */
final class CLI {

	private const SECRET_ID_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789$~-_.';

	public static function register(): void {
		\WP_CLI::add_command( 'scrt-link', self::class );
	}

	/**
	 * Show current scrt-link-wp configuration.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. Default: table.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp scrt-link status
	 *     wp scrt-link status --format=json
	 *
	 * @when after_wp_load
	 */
	public function status( $args, $assoc_args ): void {
		$key = (string) Plugin::get_option( 'api_key' );

		$rows = [
			[ 'key' => 'api_key_set',    'value' => '' !== $key ? 'yes' : 'no' ],
			[ 'key' => 'api_key_prefix', 'value' => '' !== $key ? substr( $key, 0, 8 ) . '…' : '(unset)' ],
			[ 'key' => 'base_url',       'value' => (string) Plugin::get_option( 'base_url' ) ],
			[ 'key' => 'notify_email',   'value' => (string) Plugin::get_option( 'notify_email' ) ],
			[ 'key' => 'default_expiry_ms', 'value' => (string) Plugin::get_option( 'default_expiry' ) ],
			[ 'key' => 'rate_limit_per_hour', 'value' => (string) Plugin::get_option( 'rate_limit' ) ],
			[ 'key' => 'version',        'value' => SCRT_LINK_WP_VERSION ],
		];

		\WP_CLI\Utils\format_items(
			(string) ( $assoc_args['format'] ?? 'table' ),
			$rows,
			[ 'key', 'value' ]
		);
	}

	/**
	 * Set the scrt.link API key.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : The API key to store. Will be saved as-is.
	 *
	 * ## EXAMPLES
	 *
	 *     wp scrt-link set-key ak_abc123...
	 *
	 * @when after_wp_load
	 *
	 * @subcommand set-key
	 */
	public function set_key( $args, $assoc_args ): void {
		$key = trim( (string) ( $args[0] ?? '' ) );
		if ( '' === $key ) {
			\WP_CLI::error( 'Missing API key argument.' );
		}

		$opts = get_option( SCRT_LINK_WP_OPTION, [] );
		if ( ! is_array( $opts ) ) {
			$opts = [];
		}
		$opts['api_key'] = sanitize_text_field( $key );
		update_option( SCRT_LINK_WP_OPTION, $opts );

		\WP_CLI::success( sprintf( 'Stored API key (prefix: %s…).', substr( $key, 0, 8 ) ) );
	}

	/**
	 * Send a test secret through the live scrt.link API using the configured key.
	 * Prints the resulting self-destructing URL on success.
	 *
	 * ## OPTIONS
	 *
	 * [--message=<text>]
	 * : Plaintext message to encrypt. Default: "scrt-link-wp CLI test at <timestamp>".
	 *
	 * [--expires-in=<ms>]
	 * : Expiration in milliseconds. Default: 1 hour (3600000).
	 *
	 * [--note=<text>]
	 * : Unencrypted "from" note. Default: "wp-cli test".
	 *
	 * [--skip-mail]
	 * : Don't trigger the owner notification email.
	 *
	 * [--dump-payload]
	 * : Print the JSON payload and checksum for debugging. No upstream call is skipped.
	 *
	 * ## EXAMPLES
	 *
	 *     wp scrt-link test
	 *     wp scrt-link test --message="hello from CLI" --expires-in=86400000
	 *
	 * @when after_wp_load
	 */
	public function test( $args, $assoc_args ): void {
		if ( '' === (string) Plugin::get_option( 'api_key' ) ) {
			\WP_CLI::error( 'No API key configured. Run `wp scrt-link set-key <key>` first.' );
		}

		$message    = (string) ( $assoc_args['message'] ?? 'scrt-link-wp CLI test at ' . gmdate( 'c' ) );
		$note       = (string) ( $assoc_args['note'] ?? 'wp-cli test' );
		$expires_in = (int) ( $assoc_args['expires-in'] ?? 3600000 );

		\WP_CLI::log( 'Encrypting locally…' );

		$built = self::build_secret( $message, $note, $expires_in );
		if ( is_wp_error( $built ) ) {
			\WP_CLI::error( $built->get_error_message() );
		}

		if ( ! empty( $assoc_args['dump-payload'] ) ) {
			\WP_CLI::log( '-- DUMP: payload_json --' );
			\WP_CLI::log( $built['payload_json'] );
			\WP_CLI::log( '-- DUMP: checksum = ' . $built['checksum'] . ' --' );
		}

		\WP_CLI::log( 'Posting to scrt.link…' );

		$resp = Plugin::post_to_upstream( $built['payload_json'], $built['checksum'] );
		if ( is_wp_error( $resp ) ) {
			\WP_CLI::error( sprintf( '%s (code: %s)', $resp->get_error_message(), $resp->get_error_code() ) );
		}

		$base_url    = untrailingslashit( (string) Plugin::get_option( 'base_url' ) );
		$secret_link = $base_url . '/s#' . $built['secret_id'];
		$expires_at  = (string) ( $resp['expiresAt'] ?? '' );

		\WP_CLI::success( 'Secret created.' );
		\WP_CLI::log( '' );
		\WP_CLI::log( 'Secret link (opens once, then self-destructs):' );
		\WP_CLI::log( '  ' . $secret_link );
		if ( '' !== $expires_at ) {
			\WP_CLI::log( '  Expires: ' . $expires_at );
		}

		if ( empty( $assoc_args['skip-mail'] ) ) {
			Rest::instance()->deliver_to_owner_public( $secret_link, $note, $expires_at );
			\WP_CLI::log( '  Notification emailed to ' . (string) Plugin::get_option( 'notify_email' ) );
		}

		/** This action is documented in includes/class-rest.php */
		do_action( 'scrt_link_wp_secret_created', $secret_link, $note, $expires_at, $resp );
	}

	// ------------------------------------------------------------------
	// PHP crypto port (mirrors src/scrt-request/view.js).
	// ------------------------------------------------------------------

	/**
	 * Build the full encrypted payload server-side using openssl + sodium.
	 *
	 * @return array{secret_id:string,payload_json:string,checksum:string}|\WP_Error
	 */
	private static function build_secret( string $content, string $public_note, int $expires_in ) {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return new \WP_Error( 'scrt_link_no_openssl', 'OpenSSL extension is required for wp scrt-link test.' );
		}
		if ( ! in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true ) ) {
			return new \WP_Error( 'scrt_link_no_gcm', 'OpenSSL AES-256-GCM unavailable. Use PHP 7.1+.' );
		}

		$secret_id       = self::random_id( 36 );
		$secret_id_hash  = hash( 'sha256', substr( $secret_id, 28 ) );

		$public_key_pem = self::generate_public_key_pem();
		if ( is_wp_error( $public_key_pem ) ) {
			return $public_key_pem;
		}

		$meta    = wp_json_encode( [ 'secretType' => 'text' ] );
		$meta    = self::encrypt_with_secret( $meta, $secret_id );
		$content = self::encrypt_with_secret( $content, $secret_id );

		// PHP < 8 preserves insertion order on associative arrays, but the JSON
		// encoder is explicit regardless; this ordering must match the checksum.
		$payload = [
			'secretIdHash' => $secret_id_hash,
			'meta'         => $meta,
			'content'      => $content,
			'publicKey'    => $public_key_pem,
			'publicNote'   => '' !== $public_note ? $public_note : null,
			'expiresIn'    => $expires_in,
			'password'     => null,
		];

		// Match JS's JSON.stringify: omit keys where value is undefined. PHP's
		// wp_json_encode emits null as "null", so strip null entries explicitly.
		$payload = array_filter( $payload, static fn( $v ) => null !== $v );

		$payload_json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES );
		$checksum     = hash( 'sha256', $payload_json );

		return [
			'secret_id'    => $secret_id,
			'payload_json' => $payload_json,
			'checksum'     => $checksum,
		];
	}

	private static function random_id( int $n ): string {
		$bytes = random_bytes( $n );
		$chars = self::SECRET_ID_CHARS;
		$len   = strlen( $chars );
		$out   = '';
		for ( $i = 0; $i < $n; $i++ ) {
			$out .= $chars[ ord( $bytes[ $i ] ) % $len ];
		}
		return $out;
	}

	private static function encrypt_with_secret( string $plaintext, string $secret ): string {
		$salt        = random_bytes( 16 );
		$derived_key = hash_pbkdf2( 'sha256', $secret, $salt, 100000, 32, true );
		$iv          = random_bytes( 16 );
		$tag         = '';
		$ciphertext  = openssl_encrypt(
			$plaintext,
			'aes-256-gcm',
			$derived_key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);

		// WebCrypto's AES-GCM emits ciphertext with the 16-byte tag appended. The
		// JS blob layout is salt(16) + iv(16) + ciphertext_with_tag. Match it.
		$blob = $salt . $iv . $ciphertext . $tag;

		return 'data:application/octet-stream;base64,' . base64_encode( $blob );
	}

	private static function generate_public_key_pem() {
		$res = openssl_pkey_new(
			[
				'private_key_type' => OPENSSL_KEYTYPE_EC,
				'curve_name'       => 'secp384r1',
			]
		);

		if ( ! $res ) {
			return new \WP_Error( 'scrt_link_keygen_failed', 'Failed to generate EC keypair (openssl).' );
		}

		$details = openssl_pkey_get_details( $res );
		if ( ! $details || empty( $details['key'] ) ) {
			return new \WP_Error( 'scrt_link_keyexport_failed', 'Failed to export public key.' );
		}

		// openssl's PEM wraps the base64 body at 64 chars with \n. scrt.link's JS client
		// emits a single-line body. Normalize to match so the payload validates upstream.
		$pem  = (string) $details['key'];
		$pem  = preg_replace( '/-----BEGIN PUBLIC KEY-----\s*/', '', $pem );
		$pem  = preg_replace( '/\s*-----END PUBLIC KEY-----\s*$/', '', $pem );
		$body = preg_replace( '/\s+/', '', $pem );

		return "-----BEGIN PUBLIC KEY-----\n{$body}\n-----END PUBLIC KEY-----";
	}
}
