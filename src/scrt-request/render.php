<?php
/**
 * Dynamic render — emits form markup bound to the Interactivity API store in view.js.
 *
 * @package ScrtLinkWP
 *
 * @var array    $attributes
 * @var string   $content
 * @var WP_Block $block
 */

defined( 'ABSPATH' ) || exit;

$heading          = isset( $attributes['heading'] ) ? (string) $attributes['heading'] : '';
$description      = isset( $attributes['description'] ) ? (string) $attributes['description'] : '';
$submit_label     = isset( $attributes['submitLabel'] ) ? (string) $attributes['submitLabel'] : __( 'Encrypt & send', 'scrt-link-wp' );
$success_message  = isset( $attributes['successMessage'] ) ? (string) $attributes['successMessage'] : '';
$placeholder      = isset( $attributes['placeholder'] ) ? (string) $attributes['placeholder'] : '';
$allow_note       = ! empty( $attributes['allowPublicNote'] );
$allow_password   = ! empty( $attributes['allowPassword'] );
$expires_in       = isset( $attributes['expiresIn'] ) ? (int) $attributes['expiresIn'] : 0;

$configured = (bool) \ScrtLinkWP\Plugin::get_option( 'api_key' );

if ( ! $configured ) {
	if ( current_user_can( 'manage_options' ) ) {
		printf(
			'<div %1$s><p>%2$s <a href="%3$s">%4$s</a></p></div>',
			get_block_wrapper_attributes( [ 'class' => 'scrt-link-wp-request scrt-link-wp-request--unconfigured' ] ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html__( 'scrt.link for WordPress is not configured.', 'scrt-link-wp' ),
			esc_url( admin_url( 'options-general.php?page=scrt-link-wp' ) ),
			esc_html__( 'Add your API key.', 'scrt-link-wp' )
		);
	}
	return;
}

$context = [
	'status'         => 'idle',
	'secret'         => '',
	'note'           => '',
	'password'       => '',
	'errorMessage'   => '',
	'expiresIn'      => $expires_in,
	'nonce'          => wp_create_nonce( 'wp_rest' ),
	'restSubmitUrl'  => esc_url_raw( rest_url( 'scrt-link/v1/submit' ) ),
	'restConfigUrl'  => esc_url_raw( rest_url( 'scrt-link/v1/config' ) ),
	'labelRequired'  => __( 'Please enter a message before sending.', 'scrt-link-wp' ),
	'labelUnknownError' => __( 'Something went wrong. Please try again.', 'scrt-link-wp' ),
];

$wrapper = get_block_wrapper_attributes(
	[
		'class'                => 'scrt-link-wp-request',
		'data-wp-interactive'  => 'scrt-link-wp/scrt-request',
		'data-wp-context'      => wp_json_encode( $context ),
	]
);

?>
<div <?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- safe, built via WP core helpers. ?>>
	<?php if ( $heading ) : ?>
		<h2 class="scrt-link-wp-request__heading"><?php echo wp_kses_post( $heading ); ?></h2>
	<?php endif; ?>

	<?php if ( $description ) : ?>
		<p class="scrt-link-wp-request__description"><?php echo wp_kses_post( $description ); ?></p>
	<?php endif; ?>

	<form
		class="scrt-link-wp-request__form"
		data-wp-class--is-busy="state.isBusy"
		data-wp-bind--hidden="!state.showForm"
		data-wp-on--submit="actions.submit"
	>
		<label class="scrt-link-wp-request__field">
			<span class="screen-reader-text"><?php esc_html_e( 'Your secret message', 'scrt-link-wp' ); ?></span>
			<textarea
				class="scrt-link-wp-request__textarea"
				rows="6"
				required
				placeholder="<?php echo esc_attr( $placeholder ); ?>"
				data-wp-on--input="actions.setSecret"
				data-wp-bind--disabled="state.isBusy"
			></textarea>
		</label>

		<?php if ( $allow_note ) : ?>
			<label class="scrt-link-wp-request__field">
				<span class="screen-reader-text"><?php esc_html_e( 'Optional unencrypted note', 'scrt-link-wp' ); ?></span>
				<input
					class="scrt-link-wp-request__note"
					type="text"
					maxlength="140"
					placeholder="<?php esc_attr_e( 'From (optional, sent in plain text)', 'scrt-link-wp' ); ?>"
					data-wp-on--input="actions.setNote"
					data-wp-bind--disabled="state.isBusy"
				/>
			</label>
		<?php endif; ?>

		<?php if ( $allow_password ) : ?>
			<label class="scrt-link-wp-request__field">
				<span class="screen-reader-text"><?php esc_html_e( 'Optional password', 'scrt-link-wp' ); ?></span>
				<input
					class="scrt-link-wp-request__password"
					type="password"
					autocomplete="new-password"
					placeholder="<?php esc_attr_e( 'Optional password', 'scrt-link-wp' ); ?>"
					data-wp-on--input="actions.setPassword"
					data-wp-bind--disabled="state.isBusy"
				/>
			</label>
		<?php endif; ?>

		<button
			type="submit"
			class="scrt-link-wp-request__submit wp-block-button__link wp-element-button"
			data-wp-bind--disabled="state.isBusy"
		>
			<span data-wp-bind--hidden="state.isBusy"><?php echo esc_html( $submit_label ); ?></span>
			<span data-wp-bind--hidden="!state.isBusy"><?php esc_html_e( 'Encrypting…', 'scrt-link-wp' ); ?></span>
		</button>

		<p
			class="scrt-link-wp-request__error"
			role="alert"
			data-wp-bind--hidden="!state.isError"
			data-wp-text="context.errorMessage"
		></p>
	</form>

	<p
		class="scrt-link-wp-request__success"
		role="status"
		data-wp-bind--hidden="!state.isSuccess"
	>
		<?php echo wp_kses_post( $success_message ); ?>
	</p>
</div>
<?php
