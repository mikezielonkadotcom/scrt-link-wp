<?php
/**
 * Settings page — stores scrt.link API key, base URL, notify email.
 *
 * @package ScrtLinkWP
 */

namespace ScrtLinkWP;

defined( 'ABSPATH' ) || exit;

final class Settings {

	private static ?Settings $instance = null;

	public static function instance(): Settings {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot(): void {
		add_action( 'admin_menu', [ $this, 'register_page' ] );
		add_action( 'admin_init', [ $this, 'register_fields' ] );
	}

	public function register_page(): void {
		add_options_page(
			__( 'scrt.link', 'scrt-link-wp' ),
			__( 'scrt.link', 'scrt-link-wp' ),
			'manage_options',
			'scrt-link-wp',
			[ $this, 'render_page' ]
		);
	}

	public function register_fields(): void {
		register_setting(
			'scrt_link_wp',
			SCRT_LINK_WP_OPTION,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize' ],
				'default'           => [],
			]
		);

		add_settings_section(
			'scrt_link_wp_main',
			__( 'scrt.link connection', 'scrt-link-wp' ),
			function () {
				echo '<p>' . esc_html__( 'Paste your scrt.link API key. Submissions from the block will be encrypted in the visitor\'s browser, then forwarded to scrt.link using this key. The resulting self-destructing URL is emailed to your notification address.', 'scrt-link-wp' ) . '</p>';
			},
			'scrt-link-wp'
		);

		add_settings_field( 'api_key',        __( 'API key',                    'scrt-link-wp' ), [ $this, 'field_api_key' ],        'scrt-link-wp', 'scrt_link_wp_main' );
		add_settings_field( 'base_url',       __( 'Base URL (white-label)',     'scrt-link-wp' ), [ $this, 'field_base_url' ],       'scrt-link-wp', 'scrt_link_wp_main' );
		add_settings_field( 'notify_email',   __( 'Notification email',         'scrt-link-wp' ), [ $this, 'field_notify_email' ],   'scrt-link-wp', 'scrt_link_wp_main' );
		add_settings_field( 'default_expiry', __( 'Default expiration (ms)',    'scrt-link-wp' ), [ $this, 'field_default_expiry' ], 'scrt-link-wp', 'scrt_link_wp_main' );
		add_settings_field( 'rate_limit',     __( 'Rate limit (per IP / hour)', 'scrt-link-wp' ), [ $this, 'field_rate_limit' ],     'scrt-link-wp', 'scrt_link_wp_main' );
	}

	public function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : [];
		$existing = get_option( SCRT_LINK_WP_OPTION, [] );
		if ( ! is_array( $existing ) ) {
			$existing = [];
		}

		$out = $existing;

		if ( isset( $input['api_key'] ) ) {
			$raw = trim( (string) $input['api_key'] );
			// Preserve the stored key when the field is submitted empty (common when the
			// password field is left untouched). Otherwise take the new value as-is.
			if ( '' !== $raw ) {
				$out['api_key'] = sanitize_text_field( $raw );
			}
		}

		if ( isset( $input['base_url'] ) ) {
			$url = esc_url_raw( trim( (string) $input['base_url'] ) );
			$out['base_url'] = $url ?: 'https://scrt.link';
		}

		if ( isset( $input['notify_email'] ) ) {
			$email = sanitize_email( (string) $input['notify_email'] );
			$out['notify_email'] = is_email( $email ) ? $email : get_option( 'admin_email' );
		}

		if ( isset( $input['default_expiry'] ) ) {
			$ms = absint( $input['default_expiry'] );
			$out['default_expiry'] = (string) max( 60 * 1000, $ms ); // min 1 minute
		}

		if ( isset( $input['rate_limit'] ) ) {
			$out['rate_limit'] = (string) max( 1, absint( $input['rate_limit'] ) );
		}

		return $out;
	}

	public function field_api_key(): void {
		$stored = Plugin::get_option( 'api_key' );
		printf(
			'<input type="password" autocomplete="new-password" class="regular-text" name="%1$s[api_key]" value="" placeholder="%2$s" /> <p class="description">%3$s</p>',
			esc_attr( SCRT_LINK_WP_OPTION ),
			esc_attr( $stored ? __( '••••••••  (leave blank to keep current)', 'scrt-link-wp' ) : __( 'sk_live_…', 'scrt-link-wp' ) ),
			esc_html__( 'Generate a key in your scrt.link account settings.', 'scrt-link-wp' )
		);
	}

	public function field_base_url(): void {
		printf(
			'<input type="url" class="regular-text" name="%1$s[base_url]" value="%2$s" /> <p class="description">%3$s</p>',
			esc_attr( SCRT_LINK_WP_OPTION ),
			esc_attr( Plugin::get_option( 'base_url' ) ),
			esc_html__( 'Defaults to https://scrt.link. Change this to point at your self-hosted deployment.', 'scrt-link-wp' )
		);
	}

	public function field_notify_email(): void {
		printf(
			'<input type="email" class="regular-text" name="%1$s[notify_email]" value="%2$s" /> <p class="description">%3$s</p>',
			esc_attr( SCRT_LINK_WP_OPTION ),
			esc_attr( Plugin::get_option( 'notify_email' ) ),
			esc_html__( 'Where self-destructing secret links are delivered. Defaults to the site admin email.', 'scrt-link-wp' )
		);
	}

	public function field_default_expiry(): void {
		printf(
			'<input type="number" min="60000" step="60000" class="regular-text" name="%1$s[default_expiry]" value="%2$s" /> <p class="description">%3$s</p>',
			esc_attr( SCRT_LINK_WP_OPTION ),
			esc_attr( Plugin::get_option( 'default_expiry' ) ),
			esc_html__( 'Default TTL for submissions, in milliseconds. Individual blocks may override.', 'scrt-link-wp' )
		);
	}

	public function field_rate_limit(): void {
		printf(
			'<input type="number" min="1" step="1" class="regular-text" name="%1$s[rate_limit]" value="%2$s" /> <p class="description">%3$s</p>',
			esc_attr( SCRT_LINK_WP_OPTION ),
			esc_attr( Plugin::get_option( 'rate_limit' ) ),
			esc_html__( 'Max submissions per client IP per hour. Excess requests return HTTP 429.', 'scrt-link-wp' )
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'scrt.link for WordPress', 'scrt-link-wp' ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( 'scrt_link_wp' );
				do_settings_sections( 'scrt-link-wp' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}
