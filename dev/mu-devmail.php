<?php
/**
 * Dev-only MU plugin: capture wp_mail() calls to the debug log instead of sending.
 * Useful for testing the scrt-link-wp email delivery flow inside wp-env.
 *
 * @package ScrtLinkWP\Dev
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'pre_wp_mail',
	function ( $null, $atts ) {
		$atts['to']      = (array) ( $atts['to'] ?? [] );
		$atts['subject'] = (string) ( $atts['subject'] ?? '' );
		$atts['message'] = (string) ( $atts['message'] ?? '' );

		$lines = [
			'================ scrt-link-wp dev mail ================',
			'TO:      ' . implode( ', ', $atts['to'] ),
			'SUBJECT: ' . $atts['subject'],
			'---',
			$atts['message'],
			'=======================================================',
		];
		error_log( "\n" . implode( "\n", $lines ) );
		return true; // short-circuit — don't actually send.
	},
	10,
	2
);
