<?php
/** CLI fixtures for native WooCommerce account panels. External requests are blocked. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$_SERVER['HTTP_HOST'] = 'localhost'; $_SERVER['REQUEST_METHOD'] = 'GET';
require dirname( __DIR__, 4 ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
ob_start();
$failures = 0; $uid = 0; $page_id = 0; $orders = array();
function account_verify( $ok, $label ) { global $failures; if ( ! $ok ) { $failures++; } echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . PHP_EOL; }
add_filter( 'pre_http_request', function () { return new WP_Error( 'fixture_blocked', 'External HTTP disabled in account test' ); } );
add_filter( 'pre_wp_mail', '__return_true' );
remove_action( 'user_register', 'hlwpw_user_on_register_and_update', 10 );
$settings = bsml_defaults();
$tab = array_merge( $settings['tabs'][0], array( 'id' => 'account-fixture', 'label' => 'My Account', 'type' => 'account', 'account_exclude' => array( 'downloads', 'customer-logout' ) ) );
$settings['tabs'] = array( $tab );
add_filter( 'pre_option_bsml_settings', function () use ( &$settings ) { return $settings; } );
add_filter( 'pre_option_woocommerce_myaccount_page_id', function () use ( &$page_id ) { return $page_id; } );
class BSML_Test_Redirect extends RuntimeException {}
try {
    $uid = wp_insert_user( array( 'user_login' => 'bsml_account_' . wp_generate_password( 10, false ), 'user_pass' => wp_generate_password( 32 ), 'user_email' => 'account-' . wp_generate_password( 8, false ) . '@example.invalid', 'role' => 'subscriber' ) );
    if ( is_wp_error( $uid ) ) { throw new RuntimeException( $uid->get_error_message() ); }
    wp_set_current_user( $uid );
    $page_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'BSML account fixture', 'post_content' => '[woocommerce_my_account]' ) );
    $GLOBALS['wp_query'] = new WP_Query( array( 'page_id' => $page_id ) );
    global $wp; $wp->query_vars = array();
    $menu = bsml_account_menu( $tab );
    account_verify( isset( $menu['orders'], $menu['edit-address'], $menu['payment-methods'], $menu['edit-account'] ) && ! isset( $menu['downloads'], $menu['customer-logout'] ), 'Account exclusions retain the requested native submenus' );
    $saved = bsml_sanitize_settings( $settings );
    account_verify( $saved['tabs'][0]['type'] === 'account' && $saved['tabs'][0]['account_exclude'] === $tab['account_exclude'], 'Account type and exclusions survive settings save' );
    $navigation = bsml_public_tabs()[0];
    account_verify( ! in_array( 'downloads', wp_list_pluck( $navigation['children'], 'id' ), true ), 'Excluded submenus are absent from public navigation' );
    account_verify( is_wp_error( bsml_account_check( $tab, 'downloads' ) ), 'Excluded panels cannot be requested through the library bridge' );
    account_verify( is_wp_error( bsml_account_check( null, 'orders' ) ), 'Disabled or missing account section is rejected' );
    foreach ( array( $uid, 0 ) as $customer ) { $order = wc_create_order( array( 'customer_id' => $customer, 'status' => 'pending' ) ); $orders[] = $order; }
    account_verify( bsml_account_check( $tab, 'view-order', $orders[0]->get_id() ) === true && is_wp_error( bsml_account_check( $tab, 'view-order', $orders[1]->get_id() ) ), 'Order details are limited to orders belonging to the member' );
    $wp->query_vars = array( 'orders' => '1' );
    $payload = bsml_account_payload( $tab, 'orders', '1' );
    account_verify( strpos( $payload['html'], 'woocommerce-orders-table' ) !== false && strpos( $payload['html'], 'woocommerce-MyAccount-navigation' ) === false, 'Native orders render without duplicate account navigation' );
    $wp->query_vars = array( 'edit-account' => '' );
    $payload = bsml_account_payload( $tab, 'edit-account', '' );
    account_verify( strpos( $payload['html'], 'save-account-details-nonce' ) !== false && strpos( $payload['html'], 'account_email' ) !== false, 'Account details retain native fields and nonce protection' );
    $wp->query_vars = array( 'edit-address' => 'billing' );
    $payload = bsml_account_payload( $tab, 'edit-address', 'billing' );
    account_verify( strpos( $payload['html'], 'woocommerce-edit-address-nonce' ) !== false && strpos( $payload['html'], 'billing_country' ) !== false, 'Billing address retains native form and country fields' );
    account_verify( strpos( $payload['assets'], 'wc_country_select_params' ) !== false, 'Country selector assets include WooCommerce localization' );
    // Intercept redirects so the native handler can be tested without terminating cleanup.
    $redirect = function ( $url ) { throw new BSML_Test_Redirect( $url ); };
    add_filter( 'wp_redirect', $redirect, 999 );
    $GLOBALS['bsml_account_request'] = array( $tab, 'edit-account', '' );
    $wp->query_vars = array( 'edit-account' => '' );
    $user = get_userdata( $uid );
    $_POST = array( 'action' => 'save_account_details', 'account_first_name' => 'Fixture', 'account_last_name' => 'Member', 'account_display_name' => 'Fixture Member', 'account_email' => $user->user_email );
    $_REQUEST = array( 'save-account-details-nonce' => 'invalid' );
    WC_Form_Handler::save_account_details();
    account_verify( get_userdata( $uid )->first_name !== 'Fixture', 'Invalid native form nonce cannot save account details' );
    $_REQUEST['save-account-details-nonce'] = wp_create_nonce( 'save_account_details' );
    wc_clear_notices();
    try { WC_Form_Handler::save_account_details(); } catch ( BSML_Test_Redirect $redirected ) {
        account_verify( strpos( $redirected->getMessage(), 'bsml_account=account-fixture' ) !== false, 'Native success redirect preserves inline account request' );
    }
    account_verify( get_userdata( $uid )->first_name === 'Fixture', 'WooCommerce native handler saves fixture account details' );
    wc_clear_notices(); $_POST['account_email'] = 'invalid-email';
    WC_Form_Handler::save_account_details();
    account_verify( wc_notice_count( 'error' ) > 0 && get_userdata( $uid )->user_email === $user->user_email, 'Native validation errors preserve existing account data' );
    wc_clear_notices();
    $wp->query_vars = array( 'edit-address' => 'billing' );
    $GLOBALS['bsml_account_request'] = array( $tab, 'edit-address', 'billing' );
    $_POST = array( 'action' => 'edit_address', 'billing_first_name' => 'Fixture', 'billing_last_name' => 'Member', 'billing_country' => 'US', 'billing_address_1' => '100 Test Street', 'billing_city' => 'San Francisco', 'billing_state' => 'CA', 'billing_postcode' => '94105', 'billing_phone' => '4155550100', 'billing_email' => $user->user_email );
    $_REQUEST = array( 'woocommerce-edit-address-nonce' => wp_create_nonce( 'woocommerce-edit_address' ) );
    try { WC_Form_Handler::save_address(); } catch ( BSML_Test_Redirect $redirected ) {
        account_verify( strpos( $redirected->getMessage(), 'bsml_account=account-fixture' ) !== false, 'Native address success redirects back to the inline panel' );
    }
    account_verify( get_user_meta( $uid, 'billing_city', true ) === 'San Francisco', 'WooCommerce native handler saves the fixture billing address' );
    remove_filter( 'wp_redirect', $redirect, 999 );
    unset( $GLOBALS['bsml_account_request'] ); $_POST = array(); $_REQUEST = array();
    wp_set_current_user( 0 );
    account_verify( is_wp_error( bsml_account_check( $tab, 'orders' ) ), 'Anonymous account panel requests are rejected' );
} catch ( Throwable $error ) { $failures++; echo 'FAIL ' . $error->getMessage() . PHP_EOL; }
finally {
    unset( $GLOBALS['bsml_account_request'] );
    foreach ( $orders as $order ) { $order->delete( true ); }
    if ( $page_id ) { wp_delete_post( $page_id, true ); }
    if ( $uid && ! is_wp_error( $uid ) ) { wp_delete_user( $uid ); }
    if ( function_exists( 'WC' ) && WC()->session ) { wc_clear_notices(); }
}
ob_end_flush();
exit( $failures ? 1 : 0 );
