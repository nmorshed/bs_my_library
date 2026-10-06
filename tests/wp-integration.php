<?php
/** CLI fixture test. Creates and deletes its own user/posts/terms. All outbound HTTP is mocked. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$_SERVER['HTTP_HOST'] = 'localhost'; $_SERVER['REQUEST_METHOD'] = 'GET';
require dirname( __DIR__, 4 ) . '/wp-load.php';
if ( ! defined( 'BSML_VERSION' ) ) { require dirname( __DIR__ ) . '/bs-my-library.php'; }
require_once ABSPATH . 'wp-admin/includes/user.php';
$failures = 0; $posts = array(); $terms = array(); $extra_terms = array(); $uid = 0; $remote_calls = 0; $remote_fail = false;
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
    $cart = $list['items'][0]['cartHtml'];
    verify( strpos( $cart, 'ajax_add_to_cart' ) !== false && strpos( $cart, 'add-to-cart=' ) !== false && strpos( $cart, '/wp-json/' ) === false, 'Simple recommendation uses the native WooCommerce button template' );
    $variable = new WC_Product_Variable(); $variable->set_regular_price( '20' ); $variable->set_price( '20' );
    verify( strpos( bsml_cart_html( $variable ), 'ajax_add_to_cart' ) === false, 'Variable products require their options instead of direct AJAX addition' );
    $unavailable = wc_get_product( $claim_ids[0] ); $unavailable->set_stock_status( 'outofstock' );
    verify( strpos( bsml_cart_html( $unavailable ), 'ajax_add_to_cart' ) === false, 'Out-of-stock products are not advertised as directly addable' );
    $bundle = new class( $claim_ids[0] ) extends WC_Product_Simple {
        public function get_type() { return 'bsml_bundle_fixture'; }
        public function add_to_cart_text() { return 'Configure bundle'; }
        public function add_to_cart_url() { return add_query_arg( array( 'add-to-cart' => $this->get_id(), 'bundle-selection' => 'required' ) ); }
        public function supports( $feature ) { return $feature === 'ajax_add_to_cart' ? false : parent::supports( $feature ); }
    };
    $args_filter = function ( $args ) { $args['attributes']['data-extension-test'] = 'preserved'; return $args; };
    $link_filter = function ( $html ) { return '<div class="extension-native-wrapper">' . $html . '</div>'; };
    add_filter( 'woocommerce_loop_add_to_cart_args', $args_filter );
    add_filter( 'woocommerce_loop_add_to_cart_link', $link_filter, 99 );
    $old_uri = $_SERVER['REQUEST_URI'] ?? null;
    $_SERVER['REQUEST_URI'] = '/wp_test/wp-json/bsml/v1/list?kind=related';
    $original_post = $GLOBALS['post'] ?? null; $original_product = $GLOBALS['product'] ?? null;
    try {
        $bundle_html = bsml_cart_html( $bundle );
        verify( strpos( $bundle_html, 'Configure bundle' ) !== false && strpos( $bundle_html, 'bundle-selection=required' ) !== false && strpos( $bundle_html, 'ajax_add_to_cart' ) === false, 'Custom product type retains its native label, URL parameters, and AJAX policy' );
        verify( strpos( $bundle_html, 'data-extension-test="preserved"' ) !== false && strpos( $bundle_html, 'extension-native-wrapper' ) !== false, 'Native argument and HTML filters preserve extension markup' );
        verify( strpos( $bundle_html, '/wp-json/' ) === false, 'Bundle cart URL never targets the library REST endpoint' );
        verify( $_SERVER['REQUEST_URI'] === '/wp_test/wp-json/bsml/v1/list?kind=related' && ( $GLOBALS['post'] ?? null ) === $original_post && ( $GLOBALS['product'] ?? null ) === $original_product, 'Native rendering restores the caller request and post/product context' );
    } finally {
        remove_filter( 'woocommerce_loop_add_to_cart_args', $args_filter );
        remove_filter( 'woocommerce_loop_add_to_cart_link', $link_filter, 99 );
        if ( $old_uri === null ) { unset( $_SERVER['REQUEST_URI'] ); } else { $_SERVER['REQUEST_URI'] = $old_uri; }
    }
    $make_term = function ( $name, $taxonomy, $parent = 0 ) use ( &$extra_terms ) {
        $created = wp_insert_term( $name, $taxonomy, array( 'parent' => $parent ) );
        if ( is_wp_error( $created ) ) { throw new RuntimeException( $created->get_error_message() ); }
        $extra_terms[] = array( $created['term_id'], $taxonomy );
        return $created['term_id'];
    };
    $additional = $make_term( 'Additional ' . $suffix, 'product_cat' );
    $tab = $fixture_settings['tabs'][0]; $tab['related'] = array( $additional );
    $resolved = bsml_related_categories( $tab, $terms['topic'] );
    verify( count( $resolved ) === 2 && in_array( $additional, $resolved, true ) && in_array( $terms['product_cat'], $resolved, true ), 'Automatic category match is combined with manual categories' );
    verify( bsml_related_categories( $tab ) === $resolved, 'All includes matched configured topics plus manual categories' );
    $tab['filter_map'][ $terms['topic'] ] = array( $additional );
    verify( bsml_related_categories( $tab, $terms['topic'] ) === array( $additional ), 'Explicit mapping overrides automatic matching and deduplicates additions' );
    $unmatched = $make_term( 'Unmatched ' . $suffix, 'topic' );
    verify( bsml_related_categories( $tab, $unmatched ) === array( $additional ), 'Unmatched topic retains manual categories' );
    verify( bsml_term_name( '  RELATIONSHIPS  ' ) === bsml_term_name( 'Relationships' ), 'Name matching ignores case and surrounding whitespace' );
    $parent_topic = $make_term( 'Branch ' . $suffix, 'topic' );
    $child_topic = $make_term( 'Duplicate ' . $suffix, 'topic', $parent_topic );
    $parent_product = $make_term( 'Branch ' . $suffix, 'product_cat' );
    $other_parent = $make_term( 'Other branch ' . $suffix, 'product_cat' );
    $matched_child = $make_term( 'Duplicate ' . $suffix, 'product_cat', $parent_product );
    $make_term( 'Duplicate ' . $suffix, 'product_cat', $other_parent );
    verify( in_array( $matched_child, bsml_related_categories( $tab, $child_topic ), true ), 'Duplicate names resolved using parent hierarchy' );
    $ambiguous = $make_term( 'Duplicate ' . $suffix, 'topic' );
    verify( bsml_related_categories( $tab, $ambiguous ) === array( $additional ), 'Ambiguous category names do not select an arbitrary branch' );
    wp_set_object_terms( $claim_ids[3], array( $additional ), 'product_cat' );
    $fixture_settings['tabs'][0]['related'] = array( $additional );
    verify( bsml_list( $request )['total'] === 4, 'Product query includes both automatic and additional categories' );
    $fixture_settings['tabs'][0]['related_exclude'] = array( $additional );
    verify( bsml_list( $request )['total'] === 3, 'Product exclusions take priority over combined categories' );
    wp_set_object_terms( $claim_ids[3], array( $terms['product_cat'] ), 'product_cat' );
    $fixture_settings['tabs'][0]['related'] = array( $terms['product_cat'] );
    $fixture_settings['tabs'][0]['related_exclude'] = array();
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
    $calendar_html = do_shortcode( '[bsml_appointment_calendars]' );
    verify( substr_count( $calendar_html, '<details ' ) === 6 && strpos( $calendar_html, '<iframe' ) === false && strpos( $calendar_html, '<script' ) === false, 'Calendar shortcode renders six closed lazy panels and enqueues its assets' );
    verify( substr_count( $calendar_html, 'data-calendar-payment="1"' ) === 1 && strpos( $calendar_html, 'appointmentType=79844709' ) !== false, 'Calendar configuration preserves payment permission' );
    file_put_contents( '/private/tmp/bsml-calendar-fixture.html', $calendar_html );
    $appointment_tags = bsml_contact()['tags'];
    $fixture_settings['appointment_available'] = '<p>Available [bsml_appointment_test]</p>';
    $fixture_settings['appointment_booked'] = '<p>Booked [bsml_appointment_test]</p>';
    add_shortcode( 'bsml_appointment_test', function() { return '<strong>Appointment shortcode</strong>'; } );
    $appointment = bsml_appointment_content();
    verify( strpos( $appointment['html'], 'Available <strong>Appointment shortcode</strong>' ) !== false && strpos( $appointment['html'], '<iframe' ) === false, 'Available appointment renders HTML and shortcode directly' );
    $wpdb->update( $wpdb->prefix . 'lcw_contacts', array( 'tags' => serialize( array_merge( $appointment_tags, array( $fixture_settings['appointment_tag'] ) ) ) ), array( 'user_id' => $uid ) );
    verify( strpos( bsml_appointment_content()['html'], 'Booked <strong>Appointment shortcode</strong>' ) !== false, 'Fresh GHL booked tag selects booked content' );
    $wpdb->update( $wpdb->prefix . 'lcw_contacts', array( 'tags' => serialize( array( 'level-1' ) ) ), array( 'user_id' => $uid ) );
    verify( is_wp_error( bsml_appointment_content() ), 'Ineligible tier cannot request appointment content directly' );
    $wpdb->update( $wpdb->prefix . 'lcw_contacts', array( 'tags' => serialize( $appointment_tags ) ), array( 'user_id' => $uid ) );
    verify( strpos( bsml_appointment_content()['html'], 'Available' ) !== false, 'Removing booked tag restores available content' );
    remove_shortcode( 'bsml_appointment_test' );
    ob_start(); bsml_admin_membership( bsml_settings() ); $membership_admin = ob_get_clean();
    file_put_contents( '/private/tmp/bsml-membership-admin-fixture.html', '<div class="bsml-admin"><form id="bsml-settings">' . $membership_admin . '</form></div>' );
    $wpdb->update( $wpdb->prefix . 'lcw_contacts', array( 'need_to_sync' => 1 ), array( 'user_id' => $uid ) );
    verify( is_wp_error( bsml_membership_state() ), 'Incomplete synchronization never resets usage' );
    // New section settings and server-side content protection.
    $legacy = $fixture_settings['tabs'][0]; unset( $fixture_settings['tabs'][0]['show_related'], $fixture_settings['tabs'][0]['show_terms'] );
    verify( bsml_settings()['tabs'][0]['show_related'] && bsml_settings()['tabs'][0]['show_terms'], 'Existing sections retain both display options' );
    $fixture_settings['tabs'][0] = $legacy;
    $fixture_settings['tabs'][0]['show_related'] = false;
    $request = new WP_REST_Request( 'GET', '/bsml/v1/list' ); $request->set_param( 'tab', $legacy['id'] ); $request->set_param( 'kind', 'related' );
    verify( is_wp_error( bsml_list( $request ) ), 'Disabled recommendations are rejected server-side' );
    $fixture_settings['tabs'][0]['show_terms'] = false; $request->set_param( 'kind', 'library' ); $request->set_param( 'term', 99999999 );
    $result = bsml_list( $request ); verify( ! is_wp_error( $result ) && $result['filters'] === array(), 'Hidden menu ignores stale category filters while retaining section scope' );
    $page_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'BSML content fixture', 'post_content' => '<p>Protected fixture content</p>' ) ); $posts[] = $page_id;
    $section = array_merge( $legacy, array( 'id' => 'content-test', 'type' => 'content', 'content' => '<p>Welcome [fixture]</p>', 'children' => array( array( 'id' => 'page-test', 'label' => 'Page child', 'enabled' => true, 'type' => 'page', 'page_id' => $page_id, 'content' => '' ) ) ) );
    $fixture_settings['tabs'][] = $section;
    verify( ! is_wp_error( bsml_content_section( 'content-test', 'page-test' ) ), 'Published accessible page can be embedded through its configured submenu' );
    $page_section_index = count( $fixture_settings['tabs'] ) - 1;
    $fixture_settings['tabs'][$page_section_index]['children'][0]['page_tags'] = array( 'missing-tag', 'level-3' );
    $fixture_settings['tabs'][$page_section_index]['children'][0]['page_new_tab'] = true;
    $public = bsml_public_tabs(); $menu = end( $public );
    verify( count( $menu['children'] ) === 1 && $menu['children'][0]['newTab'] && strpos( $menu['children'][0]['url'], 'bsml_section=content-test' ) !== false && strpos( $menu['children'][0]['url'], 'bsml_embed=section' ) !== false, 'Any matching GHL tag reveals the submenu with a guarded content-only new-tab URL' );
    verify( ! isset( $menu['children'][0]['page_tags'] ), 'Required menu tags are not exposed in public navigation' );
    verify( ! is_wp_error( bsml_content_section( 'content-test', 'page-test' ) ), 'Matching submenu tag permits server-side page loading' );
    $fixture_settings['tabs'][$page_section_index]['children'][0]['page_tags'] = array( 'missing-tag' );
    $public = bsml_public_tabs(); $menu = end( $public );
    verify( empty( $menu['children'] ) && is_wp_error( bsml_content_section( 'content-test', 'page-test' ) ), 'Unmatched tags hide the submenu and reject direct viewer access' );
    $fixture_settings['tabs'][$page_section_index]['children'][0]['page_tags'] = array();
    $fixture_settings['tabs'][$page_section_index]['type'] = 'page';
    $fixture_settings['tabs'][$page_section_index]['page_id'] = $page_id;
    $fixture_settings['tabs'][$page_section_index]['page_tags'] = array( 'missing-parent-tag' );
    verify( ! in_array( 'content-test', wp_list_pluck( bsml_public_tabs(), 'id' ), true ) && is_wp_error( bsml_content_section( 'content-test', 'page-test' ) ), 'Hidden parent menu also hides and protects its descendants' );
    $fixture_settings['tabs'][$page_section_index]['page_tags'] = " level-3, level-3\n another tag ";
    $fixture_settings['tabs'][$page_section_index]['page_new_tab'] = true;
    $clean = bsml_sanitize_settings( $fixture_settings ); $clean_page = end( $clean['tabs'] );
    verify( $clean_page['page_tags'] === array( 'level-3', 'another tag' ) && $clean_page['page_new_tab'] && $clean_page['children'][0]['page_new_tab'], 'Page and submenu options save with trimmed, deduplicated comma/newline tags' );
    $fixture_settings['tabs'][$page_section_index] = $section;
    update_post_meta( $page_id, 'hlwpw_required_tags', array( 'bsml-missing-access-tag' ) );
    verify( is_wp_error( bsml_content_section( 'content-test', 'page-test' ) ), 'Page embeds enforce Connector Wizard access tags' );
    delete_post_meta( $page_id, 'hlwpw_required_tags' );
    wp_update_post( array( 'ID' => $page_id, 'post_password' => 'fixture-secret' ) );
    verify( is_wp_error( bsml_content_section( 'content-test', 'page-test' ) ), 'Password protected pages are not exposed' );
    wp_update_post( array( 'ID' => $page_id, 'post_password' => '', 'post_status' => 'draft' ) );
    verify( is_wp_error( bsml_content_section( 'content-test', 'page-test' ) ), 'Draft pages are not exposed' );
    verify( is_wp_error( bsml_content_section( 'content-test', 'not-configured' ) ), 'Arbitrary submenu identifiers cannot load content' );
    $public = bsml_public_tabs(); $last = end( $public );
    verify( ! isset( $last['content'] ) && ! isset( $last['children'][0]['content'] ), 'Public navigation contains no saved content' );
    $clean = bsml_sanitize_settings( $fixture_settings ); $last = end( $clean['tabs'] );
    verify( $last['children'][0]['page_id'] === $page_id && $last['content'] === $section['content'], 'New content types and shortcode text survive saving' );
    ob_start(); bsml_admin_tab( 0, $section ); $admin_html = ob_get_clean();
    preg_match( '/<select class="bsml-taxonomy"[^>]*>(.*?)<\/select>/s', $admin_html, $taxonomy_select );
    preg_match_all( '/<option value="([^"]+)"/', $taxonomy_select[1], $taxonomy_options );
    verify( $taxonomy_options[1] === array( 'topic', 'ld_course_category' ), 'Taxonomy dropdown offers only Topic and Program Categories' );
    file_put_contents( '/private/tmp/bsml-admin-fixture.html', '<form id="bsml-settings"><div id="bsml-tabs">' . $admin_html . '</div><button type="submit">Save</button></form>' );
    add_shortcode( 'bsml_inline_fixture', function () {
        wp_register_script( 'bsml-inline-fixture', false, array(), false, true ); wp_enqueue_script( 'bsml-inline-fixture' );
        wp_add_inline_script( 'bsml-inline-fixture', 'window.bsmlInlineFixture=true;' );
        return '<strong>Inline shortcode result</strong>';
    } );
    $content_index = count( $fixture_settings['tabs'] ) - 1;
    $fixture_settings['tabs'][$content_index]['content'] = 'Welcome [bsml_inline_fixture] [bs_my_library]';
    $content_request = new WP_REST_Request( 'GET', '/bsml/v1/content' ); $content_request->set_param( 'section', 'content-test' );
    $inline = bsml_custom_content( $content_request );
    verify( strpos( $inline['html'], '<strong>Inline shortcode result</strong>' ) !== false && strpos( $inline['html'], '<iframe' ) === false && strpos( $inline['html'], 'bsml-root' ) === false, 'Custom endpoint returns rendered shortcode content without a viewer or nested library' );
    verify( strpos( $inline['assets'], 'window.bsmlInlineFixture=true;' ) !== false, 'Custom endpoint returns enqueued shortcode initialization assets' );
    $content_request->set_param( 'child', 'page-test' );
    verify( is_wp_error( bsml_custom_content( $content_request ) ), 'Custom endpoint cannot bypass page access restrictions' );
    $content_request->set_param( 'child', '' ); $fixture_settings['tabs'][$content_index]['enabled'] = false;
    verify( is_wp_error( bsml_custom_content( $content_request ) ), 'Disabled custom sections cannot be retrieved' );
    $fixture_settings['tabs'][$content_index]['enabled'] = true;
    remove_shortcode( 'bsml_inline_fixture' );
    // Exercise course taxonomy/post-type pairing even without LearnDash installed locally.
    if ( ! post_type_exists( 'sfwd-courses' ) ) { register_post_type( 'sfwd-courses', array( 'public' => true ) ); }
    if ( ! taxonomy_exists( 'ld_course_category' ) ) { register_taxonomy( 'ld_course_category', 'sfwd-courses', array( 'public' => true, 'hierarchical' => true ) ); }
    $program_term = wp_insert_term( 'BSML Program ' . $suffix, 'ld_course_category' );
    if ( is_wp_error( $program_term ) ) { throw new RuntimeException( $program_term->get_error_message() ); }
    $program_term = $program_term['term_id']; $extra_terms[] = array( $program_term, 'ld_course_category' );
    $course_ids = array();
    foreach ( array( 'Beta', 'Alpha', 'Restricted' ) as $title ) {
        $course = wp_insert_post( array( 'post_type' => 'sfwd-courses', 'post_status' => 'publish', 'post_title' => 'BSML Program ' . $title ) );
        $posts[] = $course; $course_ids[] = $course;
        wp_set_object_terms( $course, array( (int) $program_term ), 'ld_course_category' );
        update_post_meta( $course, 'hlwpw_required_tags', array( $title === 'Restricted' ? 'bsml-denied-course' : 'level-3' ) );
    }
    $fixture_settings['tabs'][0] = array_merge( $legacy, array( 'taxonomy' => 'ld_course_category', 'include' => array( $program_term ), 'exclude' => array(), 'filters' => array( $program_term ) ) );
    $request->set_param( 'term', $program_term ); $request->set_param( 'sort', 'az' );
    $courses = bsml_list( $request );
    if ( is_wp_error( $courses ) ) { throw new RuntimeException( $courses->get_error_code() . ': ' . $courses->get_error_message() ); }
    verify( ! is_wp_error( $courses ) && $courses['total'] === 2 && $courses['items'][0]['id'] === $course_ids[1], 'Program Categories queries accessible LearnDash courses and sorts them' );
    verify( $courses['items'][0]['postType'] === 'sfwd-courses' && $courses['items'][0]['clearing'] === 0 && $courses['items'][0]['url'] === get_permalink( $course_ids[1] ), 'Course cards use their native LearnDash link instead of the clearing viewer' );
    verify( in_array( (int) $program_term, wp_list_pluck( $courses['filters'], 'id' ), true ), 'Program category menu includes populated course categories' );
    $request->set_param( 'search', 'Beta' ); $courses = bsml_list( $request );
    verify( $courses['total'] === 1 && $courses['items'][0]['id'] === $course_ids[0], 'Program search returns matching accessible courses' );
    wp_set_current_user( 0 ); verify( is_wp_error( bsml_content_section( 'content-test' ) ), 'Anonymous content requests are rejected' );
} catch ( Throwable $error ) {
    $failures++; echo 'FAIL ' . $error->getMessage() . PHP_EOL;
} finally {
    foreach ( $posts as $post_id ) { wp_delete_post( $post_id, true ); }
    foreach ( array_reverse( $extra_terms ) as $term ) { wp_delete_term( $term[0], $term[1] ); }
    foreach ( $terms as $taxonomy => $term_id ) { wp_delete_term( $term_id, $taxonomy ); }
    if ( $uid && ! is_wp_error( $uid ) ) {
        $wpdb->delete( $wpdb->prefix . 'bsml_claims', array( 'user_id' => $uid ) );
        $wpdb->delete( $wpdb->prefix . 'lcw_contacts', array( 'user_id' => $uid ) );
        wp_delete_user( $uid );
    }
}
exit( $failures ? 1 : 0 );
