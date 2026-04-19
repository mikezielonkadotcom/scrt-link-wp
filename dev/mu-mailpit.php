<?php
/**
 * Dev-only MU plugin: route wp_mail() through Mailpit (SMTP on port 1025).
 * Drop into wp-content/mu-plugins/ of a Docker dev instance that has a
 * sibling `mailpit` container on the same Docker network. View inbox at
 * http://localhost:8025.
 *
 * @package ScrtLinkWP\Dev
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'phpmailer_init',
	function ( $phpmailer ) {
		$phpmailer->isSMTP();
		$phpmailer->Host       = 'mailpit';
		$phpmailer->Port       = 1025;
		$phpmailer->SMTPAuth   = false;
		$phpmailer->SMTPAutoTLS = false;
		$phpmailer->SMTPSecure  = '';
		$phpmailer->From       = 'wordpress@localhost.test';
		$phpmailer->FromName   = 'WordPress Dev';
	}
);

add_filter( 'wp_mail_from', function () { return 'wordpress@localhost.test'; } );
add_filter( 'wp_mail_from_name', function () { return 'WordPress Dev'; } );
