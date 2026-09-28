<?php
/** CLI fixture test. Creates and deletes its own user/posts/terms. All outbound HTTP is mocked. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$_SERVER['HTTP_HOST'] = 'localhost'; $_SERVER['REQUEST_METHOD'] = 'GET';
require dirname( __DIR__, 4 ) . '/wp-load.php';
if ( ! defined( 'BSML_VERSION' ) ) { require dirname( __DIR__ ) . '/bs-my-library.php'; }
require_once ABSPATH . 'wp-admin/includes/user.php';
$failures = 0; $posts = array(); $terms = array(); $uid = 0; $remote_calls = 0; $remote_fail = false;
function verify( $value, $message ) { global $failures; if ( ! $value ) { $failures++; } echo ( $value ? 'PASS ' : 'FAIL ' ) . $message . PHP_EOL; }
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
    global $remote_calls, $remote_fail;
    if ( strpos( $url, '/contacts/bsml-fixture-' ) !== false && substr( $url, -5 ) === '/tags' ) {
        $remote_calls++;
        if ( $remote_fail ) { return new WP_Error( 'fixture_timeout', 'Simulated uncertain network result' ); }
        return array( 'headers' => array(), 'body' => '{"tags":[]}', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array() );
    }
    return new WP_Error( 'fixture_blocked', 'External HTTP blocked during integration test' );
}, PHP_INT_MAX, 3 );
add_filter( 'pre_wp_mail', '__return_true' );
remove_action( 'user_register', 'hlwpw_user_on_register_and_update', 10 );
$fixture_settings = bsml_defaults();
add_filter( 'pre_option_bsml_settings', function () { global $fixture_settings; return $fixture_settings; } );
try {
    bsml_install();
    $suffix = strtolower( wp_generate_password( 8, false, false ) );
    $uid = wp_insert_user( array( 'user_login' => 'bsml_test_' . $suffix, 'user_pass' => wp_generate_password( 32 ), 'user_email' => 'bsml-' . $suffix . '@example.invalid', 'role' => 'subscriber' ) );
    if ( is_wp_error( $uid ) ) { throw new RuntimeException( $uid->get_error_message() ); }
    wp_set_current_user( $uid );
    global $wpdb;
    $wpdb->insert( $wpdb->prefix . 'lcw_contacts', array( 'user_id' => $uid, 'contact_id' => 'bsml-fixture-' . $suffix, 'contact_email' => 'bsml-' . $suffix . '@example.invalid', 'tags' => serialize( array( 'level-3', 'bsml-owned', 'bsml-product-only' ) ), 'need_to_sync' => 0 ) );
    foreach ( array( 'topic', 'product_cat' ) as $taxonomy ) {
        $term = wp_insert_term( 'BSML Test ' . $suffix, $taxonomy );
        if ( is_wp_error( $term ) ) { throw new RuntimeException( $term->get_error_message() ); }
        $terms[ $taxonomy ] = $term['term_id'];
    }
    $fixture_settings['tabs'][0]['include'] = array( $terms['topic'] );
    $fixture_settings['tabs'][0]['related'] = array( $terms['product_cat'] );
    $fixture_settings['benefits']['live']['include'] = array( $terms['product_cat'] );
    $fixture_settings['benefits']['replay']['include'] = array( $terms['product_cat'] );
    $claim_ids = array();
    for ( $i = 1; $i <= 19; $i++ ) {
        $p = new WC_Product_Simple(); $p->set_name( 'BSML Fixture ' . sprintf( '%02d', $i ) ); $p->set_status( 'publish' ); $p->set_regular_price( (string) ( 10 + $i ) ); $p->set_category_ids( array( $terms['product_cat'] ) );
        $pid = $p->save(); $posts[] = $pid;
        $cid = wp_insert_post( array( 'post_type' => 'clearing', 'post_status' => 'publish', 'post_title' => 'BSML Fixture ' . sprintf( '%02d', $i ) ) ); $posts[] = $cid;
        wp_set_object_terms( $cid, array( $terms['topic'] ), 'topic' );
        $tag = $i <= 14 ? 'bsml-owned' : 'bsml-item-' . $i;
        update_post_meta( $cid, 'hlwpw_required_tags', array( $tag ) );
        update_post_meta( $pid, 'hlwpw_location_tags', array( $i === 15 ? 'bsml-product-only' : $tag ) );
        update_post_meta( $pid, '_sa_related_clearing', $cid ); update_post_meta( $cid, '_sa_related_product', $pid );
        if ( $i >= 16 ) { $claim_ids[] = $pid; }
    }
    $request = new WP_REST_Request( 'GET', '/bsml/v1/list' ); $request->set_param( 'tab', 'books-audios' ); $request->set_param( 'kind', 'library' );
    $list = bsml_list( $request );
    verify( ! is_wp_error( $list ) && $list['total'] === 14 && count( $list['items'] ) === 12, 'Access filtering happens before pagination' );
    $request->set_param( 'page', 2 ); verify( count( bsml_list( $request )['items'] ) === 2, 'Second page contains only remaining accessible clearings' );
    $request->set_param( 'search', 'Fixture 14' ); verify( bsml_list( $request )['total'] === 1, 'Search includes items beyond the first page' );
    $request->set_param( 'search', '' ); $request->set_param( 'page', 1 ); $request->set_param( 'kind', 'related' );
    $list = bsml_list( $request ); verify( $list['total'] === 4, 'Recommendations exclude accessible clearings and ANY purchased tag' );
    $request->set_param( 'term', 99999999 ); verify( is_wp_error( bsml_list( $request ) ), 'Forged category filters rejected' );
    $claim = new WP_REST_Request( 'POST', '/bsml/v1/claim' ); $claim->set_param( 'benefit', 'live' ); $claim->set_param( 'request_key', 'test-' . $suffix . '-claim-01' );
    $claim->set_param( 'ids', array( $claim_ids[0], $claim_ids[0] ) ); verify( is_wp_error( bsml_claim( $claim ) ), 'Duplicate product IDs rejected' );
    $claim->set_param( 'ids', array_slice( $claim_ids, 0, 3 ) ); verify( is_wp_error( bsml_claim( $claim ) ), 'Over-allowance claim rejected' );
    $claim->set_param( 'ids', array( $claim_ids[0] ) ); $result = bsml_claim( $claim );
    verify( ! is_wp_error( $result ) && $result['confirmed'], 'Valid claim confirmed through mocked Connector Wizard API' );
    $contact = bsml_contact(); verify( in_array( '1 membership_live_gc_added', $contact['tags'], true ) && in_array( 'bsml-item-16', $contact['tags'], true ), 'Access tag and count tag both synchronized' );
    $again = bsml_claim( $claim ); verify( ! is_wp_error( $again ) && $remote_calls === 1, 'Idempotent replay does not send tags twice' );
    $claim->set_param( 'request_key', 'test-' . $suffix . '-claim-02' ); $claim->set_param( 'ids', array( $claim_ids[1] ) );
    verify( ! is_wp_error( bsml_claim( $claim ) ), 'Member can claim remaining allowance later' );
    $state = bsml_membership_state(); verify( $state['benefits']['live']['remaining'] === 0, 'Highest cumulative count exhausts live allowance' );
    $contact = bsml_contact(); verify( in_array( '1 membership_live_gc_added', $contact['tags'], true ) && in_array( '2 membership_live_gc_added', $contact['tags'], true ), 'Previous count tags are preserved' );
    $remote_fail = true;
    $claim->set_param( 'benefit', 'replay' ); $claim->set_param( 'request_key', 'test-' . $suffix . '-claim-03' ); $claim->set_param( 'ids', array( $claim_ids[2] ) );
    verify( is_wp_error( bsml_claim( $claim ) ), 'Uncertain API result is not reported as success' );
    $state = bsml_membership_state(); verify( $state['pending'], 'Uncertain claim blocks new claims pending reconciliation' );
    $claim->set_param( 'request_key', 'test-' . $suffix . '-claim-04' ); $claim->set_param( 'ids', array( $claim_ids[3] ) );
    $before = $remote_calls; verify( is_wp_error( bsml_claim( $claim ) ) && $remote_calls === $before, 'Pending claim prevents another API write' );
    // Simulate Connector Wizard receiving a complete payment reset from GHL.
    $wpdb->update( $wpdb->prefix . 'lcw_contacts', array( 'tags' => serialize( array( 'level-3', 'bsml-owned', 'bsml-item-16', 'bsml-item-17' ) ) ), array( 'user_id' => $uid ) );
    $state = bsml_membership_state(); verify( $state['benefits']['live']['remaining'] === 2, 'GHL count-tag removal renews allowance without a payment identifier' );
    $history = bsml_history( new WP_REST_Request( 'GET', '/bsml/v1/history' ) ); verify( $history['total'] === 3, 'Reset preserves confirmed and pending selection history' );
    $wpdb->update( $wpdb->prefix . 'lcw_contacts', array( 'need_to_sync' => 1 ), array( 'user_id' => $uid ) );
    verify( is_wp_error( bsml_membership_state() ), 'Incomplete synchronization never resets usage' );
} catch ( Throwable $error ) {
    $failures++; echo 'FAIL ' . $error->getMessage() . PHP_EOL;
} finally {
    foreach ( $posts as $post_id ) { wp_delete_post( $post_id, true ); }
    foreach ( $terms as $taxonomy => $term_id ) { wp_delete_term( $term_id, $taxonomy ); }
    if ( $uid && ! is_wp_error( $uid ) ) {
        $wpdb->delete( $wpdb->prefix . 'bsml_claims', array( 'user_id' => $uid ) );
        $wpdb->delete( $wpdb->prefix . 'lcw_contacts', array( 'user_id' => $uid ) );
        wp_delete_user( $uid );
    }
}
exit( $failures ? 1 : 0 );
