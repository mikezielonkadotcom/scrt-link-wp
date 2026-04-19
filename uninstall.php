<?php
/**
 * Uninstall handler — removes plugin options. Only runs on explicit uninstall.
 *
 * @package ScrtLinkWP
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'scrt_link_wp_options' );
delete_site_option( 'scrt_link_wp_options' );
