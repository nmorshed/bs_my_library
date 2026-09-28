<?php
// Local CLI integration check. Does not activate the plugin or call GHL.
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
require dirname( __DIR__, 4 ) . '/wp-load.php';
if ( ! defined( 'BSML_VERSION' ) ) { require dirname( __DIR__ ) . '/bs-my-library.php'; }
$failures = 0;
function check( $condition, $label ) {
    global $failures;
    if ( ! $condition ) { $failures++; }
    echo ( $condition ? 'PASS ' : 'FAIL ' ) . $label . PHP_EOL;
}
$defaults = bsml_defaults();
check( bsml_used( array( '1 membership_replay_added', '3 membership_replay_added' ), $defaults['benefits']['replay']['tags'] ) === 3, 'Cumulative tags use highest count' );
check( bsml_used( array(), $defaults['benefits']['live']['tags'] ) === 0, 'An authoritative empty tag set resets usage' );
check( bsml_tier( array( 'level-1', 'level-3' ), $defaults['tiers'] )['label'] === 'Level 3', 'Highest matching tier wins' );
check( bsml_tier( array( 'unrelated' ), $defaults['tiers'] ) === null, 'No tier does not receive baseline allowance' );
$input = $defaults; $input['tabs'] = array_slice( $defaults['tabs'], 0, 2 );
$clean = bsml_sanitize_settings( $input );
check( count( $clean['tabs'] ) === 2, 'Removed menu items remain removed' );
check( $clean['benefits']['live']['tags'][4] === '4 membership_live_gc_added', 'Legacy fourth count tag retained' );
$tier = $defaults['tiers'][0]; $tier['custom_live'] = true; $tier['live_scope'] = array( 'include' => array( 99 ), 'exclude' => array( 408 ), 'descendants' => false, 'exclude_descendants' => true );
check( bsml_benefit_config( 'live', $tier, $defaults )['include'] === array( 99 ), 'Tier-specific categories override default categories' );
check( bsml_benefit_config( 'replay', $tier, $defaults )['include'] === array(), 'Unconfigured replay category is not guessed' );
$r = new WP_REST_Request( 'GET', '/bsml/v1/list' );
$r->set_param( 'sort', 'event_desc' );
$page = bsml_page( array( array( 'id' => 1, 'title' => 'No date', 'date' => '', 'event' => '' ), array( 'id' => 2, 'title' => 'Dated', 'date' => '', 'event' => '2026-10-01' ) ), $r );
check( $page['items'][0]['id'] === 2, 'Undated items follow dated items in descending event sort' );
$r->set_param( 'page', 1000 );
check( bsml_page( array(), $r )['page'] === 1, 'Out-of-range page is clamped' );
wp_set_current_user( 0 );
check( is_wp_error( bsml_permission() ), 'Anonymous library requests are rejected' );
// Register routes without changing plugin activation or settings.
$server = rest_get_server();
check( isset( $server->get_routes()['/bsml/v1/list'] ), 'Library REST route registered' );
$response = $server->dispatch( new WP_REST_Request( 'GET', '/bsml/v1/list' ) );
check( $response->get_status() === 401, 'Anonymous REST request returns 401' );
$response = apply_filters( 'rest_post_dispatch', $response, $server, new WP_REST_Request( 'GET', '/bsml/v1/list' ) );
check( strpos( $response->get_headers()['Cache-Control'], 'no-store' ) !== false, 'REST responses prohibit caching' );
ob_start(); bsml_admin_tab( 0, $defaults['tabs'][0] ); $html = ob_get_clean();
check( strpos( $html, 'bsml_settings[tabs][0][include][]' ) !== false, 'Admin category fields have correct option names' );
echo 'Environment: WordPress ' . get_bloginfo( 'version' ) . '; Connector Wizard ' . ( function_exists( 'hlwpw_has_access' ) ? 'active' : 'inactive' ) . '; WooCommerce ' . ( function_exists( 'wc_get_product' ) ? 'active' : 'inactive' ) . '; Wishlist ' . ( defined( 'WEBTOFFEE_WISHLIST_BASEURL' ) ? 'active' : 'inactive' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
