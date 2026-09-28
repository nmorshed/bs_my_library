<?php
/**
 * Plugin Name: BS My Library
 * Description: Searchable member library, inline clearings, and GHL-authoritative membership claims.
 * Version: 1.0.0
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Text Domain: bs-my-library
 */
defined( 'ABSPATH' ) || exit;
define( 'BSML_VERSION', '1.0.0' );
define( 'BSML_DIR', plugin_dir_path( __FILE__ ) );
define( 'BSML_URL', plugin_dir_url( __FILE__ ) );
require_once BSML_DIR . 'includes/settings.php';
require_once BSML_DIR . 'includes/membership.php';
require_once BSML_DIR . 'includes/library.php';
require_once BSML_DIR . 'includes/admin.php';

register_activation_hook( __FILE__, 'bsml_install' );
add_action( 'plugins_loaded', function () {
    if ( get_option( 'bsml_schema' ) !== BSML_VERSION ) { bsml_install(); }
} );

function bsml_no_cache() {
    if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
    if ( ! headers_sent() ) {
        nocache_headers();
        header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0' );
        header( 'Vary: Cookie', false );
    }
}

add_action( 'template_redirect', function () {
    $settings = bsml_settings();
    if ( isset( $_GET['bsml_embed'] ) || ( is_singular() && ( has_shortcode( get_post_field( 'post_content', get_queried_object_id() ), 'bs_my_library' ) || in_array( get_queried_object_id(), $settings['pages'], true ) ) ) ) {
        bsml_no_cache();
    }
    if ( ! isset( $_GET['bsml_embed'] ) ) { return; }
    if ( ! is_user_logged_in() ) { auth_redirect(); exit; }
    $kind = sanitize_key( wp_unslash( $_GET['bsml_embed'] ) );
    if ( $kind === 'clearing' ) {
        $id = absint( $_GET['clearing_id'] ?? 0 );
        if ( get_post_type( $id ) !== 'clearing' || get_post_status( $id ) !== 'publish' || ! bsml_has_access( $id ) ) {
            wp_die( 'This clearing is not available to your account.', 'Access unavailable', array( 'response' => 403 ) );
        }
        $GLOBALS['bsml_embed_id'] = $id;
    } elseif ( $kind === 'appointment' ) {
        $state = bsml_membership_state();
        if ( is_wp_error( $state ) || empty( $state['appointment']['eligible'] ) ) {
            wp_die( 'This benefit is not available to your account.', 'Access unavailable', array( 'response' => 403 ) );
        }
        $GLOBALS['bsml_appointment_state'] = $state;
    } else { wp_die( 'Unknown viewer.', '', array( 'response' => 404 ) ); }
    header( 'X-Frame-Options: SAMEORIGIN' );
    include BSML_DIR . 'templates/embed.php';
    exit;
}, 1 );

add_shortcode( 'bs_my_library', function () {
    bsml_no_cache();
    if ( ! is_user_logged_in() ) {
        return '<p>Please <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">log in</a> to view your library.</p>';
    }
    wp_enqueue_style( 'bsml', BSML_URL . 'assets/library.css', array(), BSML_VERSION );
    wp_enqueue_script( 'bsml', BSML_URL . 'assets/library.js', array( 'wp-element', 'wp-api-fetch' ), BSML_VERSION, true );
    $settings = bsml_settings();
    wp_add_inline_script( 'bsml', 'window.BSML=' . wp_json_encode( array(
        'root' => esc_url_raw( rest_url( 'bsml/v1/' ) ), 'nonce' => wp_create_nonce( 'wp_rest' ),
        'tabs' => array_values( array_map( function ( $tab ) { return array_intersect_key( $tab, array_flip( array( 'id', 'label', 'type', 'sort' ) ) ); }, array_filter( $settings['tabs'], function ( $t ) { return $t['enabled']; } ) ) ),
        'defaultTab' => $settings['default_tab'], 'embed' => home_url( '/' ),
        'ajax' => admin_url( 'admin-ajax.php' ), 'wishlistNonce' => wp_create_nonce( 'add_to_wishlist' ),
        'wishlist' => defined( 'WEBTOFFEE_WISHLIST_BASEURL' ), 'login' => wp_login_url( get_permalink() ),
    ) ) . ';', 'before' );
    $style = '--bsml-primary:' . $settings['colors']['primary'] . ';--bsml-accent:' . $settings['colors']['accent'] . ';--bsml-soft:' . $settings['colors']['soft'] . ';';
    return '<div class="bsml-root" style="' . esc_attr( $style ) . '"><p role="status">Loading your library…</p></div><noscript>Please enable JavaScript to use the interactive library.</noscript>';
} );

add_action( 'rest_api_init', function () {
    foreach ( array( 'list' => 'bsml_list', 'membership' => 'bsml_membership_state', 'history' => 'bsml_history' ) as $route => $callback ) {
        register_rest_route( 'bsml/v1', '/' . $route, array( 'methods' => 'GET', 'callback' => $callback, 'permission_callback' => 'bsml_permission' ) );
    }
    register_rest_route( 'bsml/v1', '/claim', array( 'methods' => 'POST', 'callback' => 'bsml_claim', 'permission_callback' => 'bsml_permission' ) );
} );
function bsml_permission() {
    return is_user_logged_in() ? true : new WP_Error( 'bsml_login', 'Please log in again to continue.', array( 'status' => 401 ) );
}
add_filter( 'rest_post_dispatch', function ( $response, $server, $request ) {
    if ( strpos( $request->get_route(), '/bsml/v1/' ) === 0 ) {
        $response->header( 'Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0' );
        $response->header( 'Vary', 'Cookie' );
    }
    return $response;
}, 10, 3 );
