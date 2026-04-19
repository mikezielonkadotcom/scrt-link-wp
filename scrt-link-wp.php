<?php
/**
 * Plugin Name:       scrt.link for WordPress
 * Plugin URI:        https://github.com/mikezielonkadotcom/scrt-link-wp
 * Description:       Drop a block onto any page to let visitors send you end-to-end encrypted, self-destructing secrets via scrt.link.
 * Version:           0.1.3
 * Requires at least: 6.6
 * Requires PHP:      7.4
 * Author:            Mike Zielonka
 * Author URI:        https://mikezielonka.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       scrt-link-wp
 * GitHub Plugin URI: mikezielonkadotcom/scrt-link-wp
 * Primary Branch:    main
 * Release Asset:     true
 *
 * @package ScrtLinkWP
 */

defined( 'ABSPATH' ) || exit;

define( 'SCRT_LINK_WP_VERSION', '0.1.3' );
define( 'SCRT_LINK_WP_FILE', __FILE__ );
define( 'SCRT_LINK_WP_PATH', plugin_dir_path( __FILE__ ) );
define( 'SCRT_LINK_WP_URL', plugin_dir_url( __FILE__ ) );
define( 'SCRT_LINK_WP_OPTION', 'scrt_link_wp_options' );

require_once SCRT_LINK_WP_PATH . 'includes/class-plugin.php';
require_once SCRT_LINK_WP_PATH . 'includes/class-settings.php';
require_once SCRT_LINK_WP_PATH . 'includes/class-rest.php';

\ScrtLinkWP\Plugin::instance()->boot();
