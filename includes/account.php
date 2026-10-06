<?php
defined( 'ABSPATH' ) || exit;

function bsml_account_options() {
    $items = array( 'dashboard' => 'Dashboard', 'orders' => 'Orders', 'downloads' => 'Downloads', 'edit-address' => 'Addresses', 'payment-methods' => 'Payment methods', 'edit-account' => 'Account details', 'customer-logout' => 'Log out' );
    return function_exists( 'wc_get_account_menu_items' ) ? array_replace( $items, wc_get_account_menu_items() ) : $items;
}
function bsml_account_menu( $tab ) {
    return array_diff_key( bsml_account_options(), array_flip( $tab['account_exclude'] ?? array( 'customer-logout' ) ) );
}
function bsml_account_inline_endpoints() {
    return array( 'dashboard', 'orders', 'view-order', 'downloads', 'edit-address', 'payment-methods', 'edit-account' );
}
function bsml_account_parent( $endpoint ) { return $endpoint === 'view-order' ? 'orders' : $endpoint; }
function bsml_account_tab( $id ) {
    foreach ( bsml_settings()['tabs'] as $tab ) {
        if ( $tab['id'] === $id && $tab['type'] === 'account' && $tab['enabled'] ) { return $tab; }
    }
    return null;
}
function bsml_account_endpoint_url( $endpoint, $value = '' ) {
    return $endpoint === 'dashboard' ? wc_get_page_permalink( 'myaccount' ) : wc_get_endpoint_url( $endpoint, $value, wc_get_page_permalink( 'myaccount' ) );
}
function bsml_account_check( $tab, $endpoint, $value = '' ) {
    if ( ! is_user_logged_in() ) { return new WP_Error( 'bsml_login', 'Please log in again to view your account.', array( 'status' => 401 ) ); }
    if ( ! $tab || ! in_array( $endpoint, bsml_account_inline_endpoints(), true ) || ! isset( bsml_account_menu( $tab )[ bsml_account_parent( $endpoint ) ] ) ) {
        return new WP_Error( 'bsml_account_section', 'This account section is unavailable in the library.', array( 'status' => 403 ) );
    }
    if ( $endpoint === 'view-order' ) {
        $order = wc_get_order( absint( $value ) );
        if ( ! $order || (int) $order->get_customer_id() !== get_current_user_id() ) {
            return new WP_Error( 'bsml_account_order', 'This order is not available to your account.', array( 'status' => 403 ) );
        }
    }
    return true;
}
function bsml_account_payload( $tab, $endpoint, $value ) {
    $allowed = bsml_account_check( $tab, $endpoint, $value );
    if ( is_wp_error( $allowed ) ) { return $allowed; }
    if ( null === WC()->cart ) { wc_load_cart(); }
    WC_Frontend_Scripts::load_scripts();
    // Native templates, notices, hooks, and nonce-protected forms; no second account navigation.
    ob_start();
    wc_print_notices();
    woocommerce_account_content();
    $html = ob_get_clean();
    if ( $endpoint === 'edit-address' ) { wp_enqueue_script( 'wc-country-select' ); wp_enqueue_script( 'wc-address-i18n' ); }
    WC_Frontend_Scripts::localize_printed_scripts();
    ob_start(); wp_styles()->do_items(); wp_scripts()->do_items( false, 1 ); $assets = ob_get_clean();
    $routes = array();
    foreach ( bsml_account_inline_endpoints() as $key ) {
        if ( isset( bsml_account_menu( $tab )[ bsml_account_parent( $key ) ] ) ) { $routes[$key] = bsml_account_endpoint_url( $key ); }
    }
    return array( 'html' => $html, 'assets' => $assets, 'endpoint' => $endpoint, 'value' => (string) $value, 'url' => bsml_account_endpoint_url( $endpoint, $value ), 'routes' => $routes, 'nonce' => wp_create_nonce( 'wp_rest' ) );
}

// The native account URL is used so WooCommerce processes forms, custom endpoint
// slugs, customer sessions and validation in its normal front-end lifecycle.
add_action( 'template_redirect', function () {
    if ( ! isset( $_GET['bsml_account'] ) ) { return; }
    bsml_no_cache();
    if ( ! function_exists( 'WC' ) || ! is_account_page() ) { wp_send_json( array( 'message' => 'Configure a WooCommerce My Account page first.' ), 400 ); }
    $tab = bsml_account_tab( sanitize_key( wp_unslash( $_GET['bsml_account'] ) ) );
    $endpoint = WC()->query->get_current_endpoint() ?: 'dashboard';
    global $wp;
    $value = $wp->query_vars[$endpoint] ?? '';
    $allowed = bsml_account_check( $tab, $endpoint, $value );
    if ( is_wp_error( $allowed ) ) { wp_send_json( array( 'message' => $allowed->get_error_message() ), $allowed->get_error_data()['status'] ); }
    if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
        $action = sanitize_key( wp_unslash( $_POST['action'] ?? '' ) );
        $valid = ( $endpoint === 'edit-account' && $action === 'save_account_details' && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['save-account-details-nonce'] ?? '' ) ), 'save_account_details' ) ) ||
            ( $endpoint === 'edit-address' && $action === 'edit_address' && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['woocommerce-edit-address-nonce'] ?? '' ) ), 'woocommerce-edit_address' ) );
        if ( ! $valid ) { wp_send_json( array( 'message' => 'Your form has expired. Refresh the account panel and try again.' ), 403 ); }
    } elseif ( $_SERVER['REQUEST_METHOD'] !== 'GET' ) { wp_send_json( array( 'message' => 'Unsupported request.' ), 405 ); }
    $GLOBALS['bsml_account_request'] = array( $tab, $endpoint, $value );
}, 0 );

// Keep WooCommerce's successful form redirect within the fragment request.
add_filter( 'wp_redirect', function ( $url ) {
    if ( empty( $GLOBALS['bsml_account_request'] ) ) { return $url; }
    $base = wp_parse_url( wc_get_page_permalink( 'myaccount' ) );
    $target = wp_parse_url( $url );
    $path = trailingslashit( $base['path'] ?? '/' );
    if ( ( $target['host'] ?? '' ) === ( $base['host'] ?? '' ) && strpos( trailingslashit( $target['path'] ?? '/' ), $path ) === 0 ) {
        $url = add_query_arg( 'bsml_account', $GLOBALS['bsml_account_request'][0]['id'], $url );
    }
    return $url;
} );
add_action( 'template_redirect', function () {
    if ( empty( $GLOBALS['bsml_account_request'] ) ) { return; }
    // Runs after WooCommerce's native account form handlers (priority 10).
    $result = bsml_account_payload( ...$GLOBALS['bsml_account_request'] );
    if ( is_wp_error( $result ) ) { wp_send_json( array( 'message' => $result->get_error_message() ), 403 ); }
    wp_send_json( $result );
}, 20 );
