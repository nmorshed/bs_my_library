<?php
defined( 'ABSPATH' ) || exit;
add_action( 'admin_menu', function () { add_options_page( 'My Library', 'My Library', 'manage_options', 'bsml', 'bsml_admin' ); } );
add_action( 'admin_init', function () { register_setting( 'bsml', 'bsml_settings', array( 'sanitize_callback' => 'bsml_sanitize_settings', 'type' => 'array' ) ); } );
add_action( 'admin_enqueue_scripts', function ( $hook ) {
    if ( $hook !== 'settings_page_bsml' ) { return; }
    wp_enqueue_editor();
    wp_enqueue_style( 'bsml-admin', BSML_URL . 'assets/admin.css', array(), BSML_VERSION );
    wp_enqueue_script( 'bsml-admin', BSML_URL . 'assets/admin.js', array( 'editor' ), BSML_VERSION, true );
    wp_localize_script( 'bsml-admin', 'BSMLAdmin', array( 'nonce' => wp_create_nonce( 'bsml_terms' ) ) );
} );

function bsml_field( $name, $label, $value, $type = 'text' ) {
    echo '<label class="bsml-field"><span>' . esc_html( $label ) . '</span><input type="' . esc_attr( $type ) . '" name="bsml_settings[' . esc_attr( $name ) . ']" value="' . esc_attr( is_array( $value ) ? implode( ',', $value ) : $value ) . '"' . ( $type === 'number' ? ' min="0" max="48"' : '' ) . '></label>';
}
function bsml_check( $name, $label, $value ) {
    echo '<input type="hidden" name="bsml_settings[' . esc_attr( $name ) . ']" value="0"><label class="bsml-check"><input type="checkbox" name="bsml_settings[' . esc_attr( $name ) . ']" value="1" ' . checked( $value, true, false ) . '> ' . esc_html( $label ) . '</label>';
}
function bsml_terms_field( $name, $label, $selected, $taxonomy ) {
    $terms = taxonomy_exists( $taxonomy ) ? get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) ) : array();
    echo '<label class="bsml-field bsml-term-field"><span>' . esc_html( $label ) . '</span><input type="search" class="bsml-term-search" placeholder="Find category…" aria-label="Find category"><select multiple size="6" name="bsml_settings[' . esc_attr( $name ) . '][]">';
    if ( ! is_wp_error( $terms ) && substr( $name, -9 ) === '][filters' ) {
        usort( $terms, function ( $a, $b ) use ( $selected ) { $ai = array_search( (int) $a->term_id, $selected, true ); $bi = array_search( (int) $b->term_id, $selected, true ); return ( $ai === false ? PHP_INT_MAX : $ai ) <=> ( $bi === false ? PHP_INT_MAX : $bi ); } );
    }
    foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
        echo '<option value="' . (int) $term->term_id . '" ' . selected( in_array( (int) $term->term_id, $selected, true ), true, false ) . '>' . esc_html( $term->name . ' (#' . $term->term_id . ')' ) . '</option>';
    }
    echo '</select><small>Use Command/Ctrl to select several. Leave included terms empty to show no items; empty exclusions exclude nothing.</small></label>';
}
function bsml_scope_fields( $prefix, $scope, $taxonomy ) {
    bsml_terms_field( $prefix . '][include', 'Included categories', $scope['include'], $taxonomy );
    bsml_terms_field( $prefix . '][exclude', 'Excluded categories', $scope['exclude'], $taxonomy );
    bsml_check( $prefix . '][descendants', 'Include descendants of included categories', $scope['descendants'] );
    bsml_check( $prefix . '][exclude_descendants', 'Exclude descendants of excluded categories', $scope['exclude_descendants'] );
}
add_action( 'wp_ajax_bsml_terms', function () {
    check_ajax_referer( 'bsml_terms', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Not allowed', 403 ); }
    $taxonomy = sanitize_key( $_GET['taxonomy'] ?? '' );
    $object = get_taxonomy( $taxonomy );
    if ( ! $object ) { wp_send_json_error( 'Unknown taxonomy', 400 ); }
    $terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
    if ( is_wp_error( $terms ) ) { wp_send_json_error( 'Could not load terms', 500 ); }
    wp_send_json_success( array( 'terms' => array_map( function ( $term ) { return array( 'id' => $term->term_id, 'label' => $term->name . ' (#' . $term->term_id . ')' ); }, $terms ), 'attached' => in_array( bsml_library_post_type( $taxonomy ), $object->object_type, true ) ) );
} );
function bsml_content_admin( $prefix, $section ) {
    echo '<div data-section-types="page" class="bsml-content-fields">';
    bsml_terms_page_field( $prefix . '][page_id', $section['page_id'] ?? 0 );
    bsml_check( $prefix . '][page_content_only', 'Load page content directly (no iframe)', ! isset( $section['page_content_only'] ) || ! empty( $section['page_content_only'] ) );
    bsml_check( $prefix . '][page_new_tab', 'Open page in a new tab', ! empty( $section['page_new_tab'] ) );
    echo '<label class="bsml-field"><span>Required GHL tags (any match)</span><textarea rows="3" name="bsml_settings[' . esc_attr( $prefix ) . '][page_tags]">' . esc_textarea( implode( "\n", bsml_page_tags( $section['page_tags'] ?? array() ) ) ) . '</textarea><small>One tag per line or comma-separated. Leave blank for no extra menu restriction. A member needs any matching tag. If a parent is hidden, its submenus are hidden too.</small></label>';

    echo '<p>Checked: render content directly in the panel. Unchecked: use the iframe viewer. Neither mode includes the site header or footer. Opening in a new tab still shows only page content. Existing page access restrictions still apply.</p></div><div data-section-types="content" class="bsml-content-fields"><label class="bsml-field"><span>Custom content</span><textarea class="bsml-content-editor" rows="10" name="bsml_settings[' . esc_attr( $prefix ) . '][content]">' . esc_textarea( $section['content'] ?? '' ) . '</textarea></label><p>Supports formatted text and installed shortcodes. Visible to logged-in members who open this section.</p></div>';
}
function bsml_terms_page_field( $name, $selected ) {
    echo '<label class="bsml-field"><span>WordPress page</span><input type="search" class="bsml-term-search" placeholder="Find page…"><select name="bsml_settings[' . esc_attr( $name ) . ']">';
    echo '<option value="0">Select a page</option>';
    foreach ( get_posts( array( 'post_type' => 'page', 'post_status' => array( 'publish', 'private', 'draft', 'pending' ), 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ) ) as $page ) {
        echo '<option value="' . (int) $page->ID . '" ' . selected( $selected, $page->ID, false ) . '>' . esc_html( $page->post_title . ' (#' . $page->ID . ', ' . $page->post_status . ')' ) . '</option>';
    }
    echo '</select></label>';
}
function bsml_admin_child( $prefix, $child ) {
    echo '<details class="bsml-child-config" open><summary>' . esc_html( $child['label'] ) . '</summary><div class="bsml-admin-grid">';
    bsml_field( $prefix . '][label', 'Submenu label', $child['label'] );
    echo '<label class="bsml-field"><span>Content type</span><select class="bsml-section-type" name="bsml_settings[' . esc_attr( $prefix ) . '][type]">';
    foreach ( array( 'page' => 'WordPress Page', 'content' => 'Custom Content' ) as $value => $label ) { echo '<option value="' . esc_attr( $value ) . '" ' . selected( $child['type'], $value, false ) . '>' . esc_html( $label ) . '</option>'; }
    echo '</select></label>';
    bsml_check( $prefix . '][enabled', 'Enabled', $child['enabled'] );
    echo '</div>'; bsml_content_admin( $prefix, $child );
    echo '<details><summary>Advanced</summary>'; bsml_field( $prefix . '][id', 'Stable submenu ID (unique within parent)', $child['id'] ); echo '</details>';
    echo '<p><button type="button" class="button" data-bsml-move="up">Move up</button> <button type="button" class="button" data-bsml-move="down">Move down</button> <button type="button" class="button" data-bsml-remove>Remove submenu</button></p></details>';
}
function bsml_admin_tab( $index, $tab ) {
    $tab = array_merge( array( 'show_related' => true, 'show_terms' => true, 'children' => array() ), $tab );
    $prefix = 'tabs][' . $index;
    echo '<details class="bsml-tab-config"><summary>' . esc_html( $tab['label'] ) . '</summary><h3>Basic details</h3><div class="bsml-admin-grid">';
    bsml_field( $prefix . '][label', 'Menu label', $tab['label'] );
    echo '<label class="bsml-field"><span>Section type</span><select class="bsml-section-type" name="bsml_settings[' . esc_attr( $prefix ) . '][type]">';
    foreach ( array( 'standard' => 'Standard library', 'membership' => 'VIP Membership', 'wishlist' => 'WebToffee Wishlist', 'page' => 'WordPress Page', 'content' => 'Custom Content', 'account' => 'WooCommerce My Account' ) as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '" ' . selected( $tab['type'], $key, false ) . '>' . esc_html( $label ) . '</option>'; }
    echo '</select></label>'; bsml_check( $prefix . '][enabled', 'Enabled', $tab['enabled'] ); echo '</div>';
    echo '<div data-section-types="standard"><h3>Library content</h3><div class="bsml-admin-grid"><label class="bsml-field"><span>Taxonomy</span><select class="bsml-taxonomy" name="bsml_settings[' . esc_attr( $prefix ) . '][taxonomy]">';
    foreach ( array( 'topic' => 'Topic', 'ld_course_category' => 'Program Categories' ) as $taxonomy => $label ) { echo '<option value="' . esc_attr( $taxonomy ) . '" ' . selected( $tab['taxonomy'], $taxonomy, false ) . '>' . esc_html( $label ) . '</option>'; }
    echo '</select><small class="bsml-taxonomy-notice" role="status">Topic lists clearing posts; Program Categories lists LearnDash courses. Changing taxonomy clears this section’s term selections.</small></label></div><div class="bsml-admin-grid bsml-library-scope">';
    bsml_scope_fields( $prefix, $tab, $tab['taxonomy'] ); echo '</div><h3>Category menu</h3>';
    bsml_check( $prefix . '][show_terms', 'Show category menu below pagination', $tab['show_terms'] );
    echo '<div data-toggle-field="show_terms">';
    bsml_terms_field( $prefix . '][filters', 'Menu terms (none selected = automatic; reorder selected terms with buttons)', $tab['filters'], $tab['taxonomy'] );
    echo '<p><button type="button" class="button" data-term-move="up">Move selected terms up</button> <button type="button" class="button" data-term-move="down">Move selected terms down</button></p></div><h3>Related products</h3>';
    bsml_check( $prefix . '][show_related', 'Show related products', $tab['show_related'] );
    echo '<div data-toggle-field="show_related"><p>Matches library terms to product categories by name, then adds the categories below.</p><div class="bsml-admin-grid">';
    bsml_terms_field( $prefix . '][related', 'Additional product categories', $tab['related'], 'product_cat' );
    bsml_terms_field( $prefix . '][related_exclude', 'Excluded product categories', $tab['related_exclude'], 'product_cat' );
    bsml_check( $prefix . '][related_descendants', 'Include related-category descendants', $tab['related_descendants'] );
    bsml_check( $prefix . '][related_exclude_descendants', 'Exclude related-category descendants', $tab['related_exclude_descendants'] ); echo '</div></div></div>';
    echo '<div data-section-types="standard membership wishlist"><label class="bsml-field"><span>Default sort</span><select name="bsml_settings[' . esc_attr( $prefix ) . '][sort]">';
    foreach ( array( 'newest' => 'Newest', 'oldest' => 'Oldest', 'az' => 'Title A–Z', 'za' => 'Title Z–A', 'event_asc' => 'Event date: next first', 'event_desc' => 'Event date: latest first' ) as $value => $label ) { echo '<option value="' . esc_attr( $value ) . '" ' . selected( $tab['sort'], $value, false ) . '>' . esc_html( $label ) . '</option>'; }
    echo '</select></label></div>';
    echo '<div data-section-types="account"><h3>Account submenus</h3><p>Choose which submenus to exclude from this library section. WooCommerce still manages the account and its permissions.</p><div class="bsml-admin-grid">';
    foreach ( bsml_account_options() as $endpoint => $label ) {
        echo '<label class="bsml-check"><input type="checkbox" name="bsml_settings[' . esc_attr( $prefix ) . '][account_exclude][]" value="' . esc_attr( $endpoint ) . '" ' . checked( in_array( $endpoint, $tab['account_exclude'] ?? array( 'customer-logout' ), true ), true, false ) . '> Exclude ' . esc_html( $label ) . '</label>';
    }
    echo '</div><p>Orders, addresses and account details open inside the library. Payment setup, payment actions, logout and extension screens use their native WooCommerce flow.</p></div>';
    bsml_content_admin( $prefix, $tab );
    echo '<div data-section-types="page content"><h3>Submenus</h3><p>One level of submenu items. Each can display a page or custom content.</p><div class="bsml-children">';
    foreach ( $tab['children'] as $n => $child ) { bsml_admin_child( $prefix . '][children][' . $n, $child ); }
    echo '</div><button type="button" class="button" data-bsml-add-child>Add submenu</button><template class="bsml-child-template">';
    bsml_admin_child( $prefix . '][children][CHILD', array( 'id' => '', 'label' => 'New submenu', 'type' => 'content', 'enabled' => true ) );
    echo '</template></div><details class="bsml-advanced"><summary>Advanced</summary>';
    bsml_field( $prefix . '][id', 'Stable section ID (unique)', $tab['id'] );
    echo '<div data-section-types="standard">'; bsml_field( $prefix . '][filter_map', 'Category mapping override, JSON: {"12":[34,56]}', wp_json_encode( $tab['filter_map'], JSON_FORCE_OBJECT ) ); echo '</div></details>';
    echo '<p><button type="button" class="button" data-bsml-move="up">Move up</button> <button type="button" class="button" data-bsml-move="down">Move down</button> <button type="button" class="button" data-bsml-remove>Remove section</button></p></details>';
}
function bsml_admin() {
    if ( ! current_user_can( 'manage_options' ) ) { return; }
    $settings = bsml_settings();
    echo '<div class="wrap bsml-admin"><h1>My Library</h1><p>Place <code>[bs_my_library]</code> on your library page. Configure category mappings before launch. Purchased means <strong>any product access tag</strong> matches the member.</p>';
    if ( ! function_exists( 'hlwpw_has_access' ) ) { echo '<div class="notice notice-error"><p>Connector Wizard is required.</p></div>'; }
    echo '<div class="notice notice-warning inline"><p>Exclude every library page from your page cache/CDN. Add page IDs below for builder-based pages. This plugin sends no-store headers, but cannot override a cache that serves a page before WordPress runs.</p></div>';
    // WordPress already renders Settings API notices for options pages.
    echo '<form method="post" action="options.php" id="bsml-settings">'; settings_fields( 'bsml' );
    echo '<nav class="nav-tab-wrapper bsml-admin-nav" aria-label="My Library settings">';
    foreach ( array( 'general' => 'General', 'sections' => 'Library Sections', 'membership' => 'Membership', 'appearance' => 'Appearance', 'claims' => 'Claim Management' ) as $id => $label ) { echo '<button type="button" class="nav-tab" data-panel="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</button>'; }
    echo '</nav><section class="bsml-settings-panel" data-panel-id="general"><h2>General</h2><div class="bsml-admin-grid">';
    bsml_field( 'default_tab', 'Default section ID', $settings['default_tab'] );
    bsml_field( 'pages', 'Library page IDs, comma-separated', $settings['pages'] );
    bsml_field( 'page_size', 'Items per page (6–48)', $settings['page_size'], 'number' );
    echo '</div></section><section class="bsml-settings-panel" data-panel-id="appearance"><h2>Appearance</h2><div class="bsml-admin-grid">';
    foreach ( $settings['colors'] as $key => $color ) { bsml_field( 'colors][' . $key, ucfirst( $key ) . ' color', $color, 'color' ); }
    echo '</div></section><section class="bsml-settings-panel" data-panel-id="sections"><h2>Library Sections</h2><p>Standard sections show accessible clearings or LearnDash courses according to their taxonomy. Related categories are configured independently. Exclusions take precedence.</p><div id="bsml-tabs">';
    foreach ( $settings['tabs'] as $i => $tab ) { bsml_admin_tab( $i, $tab ); }
    echo '</div><p><button type="button" class="button" id="bsml-add-tab">Add section</button></p><template id="bsml-tab-template">';
    $blank = bsml_defaults()['tabs'][0]; $blank['id'] = ''; $blank['label'] = 'New section'; bsml_admin_tab( 'NEW', $blank );
    echo '</template></section><section class="bsml-settings-panel" data-panel-id="membership">';
    bsml_admin_membership( $settings );
    echo '</section>'; submit_button(); echo '</form><section class="bsml-settings-panel" data-panel-id="claims">';
    bsml_admin_pending(); echo '</section></div>';
}
function bsml_admin_membership( $settings ) {
    echo '<div class="bsml-membership-heading"><h2>Membership</h2><p>Set tier allowances, shared claim categories and appointment content. GHL remains the source of truth for usage and renewal.</p></div><h3>1. Tiers and allowances</h3><p class="description">The highest matching tier wins. Expand a tier to edit its allowances or override the shared categories below.</p>';
    foreach ( $settings['tiers'] as $i => $tier ) {
        echo '<details class="bsml-membership-card bsml-tier-card"><summary><strong>' . esc_html( $tier['label'] ) . '</strong><span>' . (int) $tier['live'] . ' live · ' . (int) $tier['replay'] . ' replay' . ( $tier['appointment'] ? ' · Appointment included' : '' ) . '</span></summary><div class="bsml-membership-body"><div class="bsml-admin-grid">';
        foreach ( array( 'label' => 'Tier label', 'tag' => 'GHL tier tag', 'live' => 'Live GEC allowance', 'replay' => 'Replay allowance' ) as $key => $label ) { bsml_field( 'tiers][' . $i . '][' . $key, $label, $tier[$key], in_array( $key, array( 'live', 'replay' ), true ) ? 'number' : 'text' ); }
        echo '</div><p class="description">Allowances support 0–4 items, matching the configured count tags.</p>';
        bsml_check( 'tiers][' . $i . '][appointment', 'Eligible for an accelerator appointment', $tier['appointment'] );
        echo '<h4>Category overrides</h4><p class="description">Use shared categories unless this tier needs a different selection.</p>';
        foreach ( array( 'live' => 'Live GEC', 'replay' => 'Replay' ) as $key => $label ) {
            $control = 'bsml-override-' . $i . '-' . $key;
            echo '<div class="bsml-membership-override"><label class="bsml-check"><input type="checkbox" id="' . esc_attr( $control ) . '" name="bsml_settings[tiers][' . (int) $i . '][custom_' . esc_attr( $key ) . ']" value="1" ' . checked( ! empty( $tier['custom_' . $key] ), true, false ) . '> Use custom ' . esc_html( $label ) . ' categories</label><div class="bsml-admin-grid" data-membership-toggle="' . esc_attr( $control ) . '">';
            bsml_scope_fields( 'tiers][' . $i . '][' . $key . '_scope', bsml_scope( $tier[$key . '_scope'] ?? array() ), 'product_cat' );
            echo '</div></div>';
        }
        echo '</div></details>';
    }
    echo '<h3 class="bsml-membership-group-title">2. Shared benefits</h3><p class="description">These categories apply to every tier unless a tier override is enabled. Existing count tags are retained; the highest matching count is used.</p>';
    foreach ( $settings['benefits'] as $key => $benefit ) {
        echo '<details class="bsml-membership-card"><summary><strong>' . esc_html( $benefit['label'] ) . '</strong><span>Categories and usage tags</span></summary><div class="bsml-membership-body">';
        bsml_field( 'benefits][' . $key . '][label', 'Benefit label', $benefit['label'] );
        echo '<h4>Claimable categories</h4><div class="bsml-admin-grid">';
        bsml_scope_fields( 'benefits][' . $key, $benefit, 'product_cat' );
        echo '</div><h4>GHL count tags</h4><div class="bsml-admin-grid">';
        foreach ( $benefit['tags'] as $n => $tag ) { bsml_field( 'benefits][' . $key . '][tags][' . $n, 'Tag for ' . $n . ' claimed', $tag ); }
        echo '</div></div></details>';
    }
    echo '<h3 class="bsml-membership-group-title">Message for non-members</h3><div class="bsml-membership-card bsml-membership-body"><p>Shown when the account has no eligible VIP membership tier. Edit the heading and message, and add a membership link if you wish. Supports formatted text and HTML.</p><label class="bsml-field"><span>Non-member content</span><textarea id="membership_guest_content" class="bsml-content-editor" rows="8" name="bsml_settings[membership_guest_content]">' . esc_textarea( $settings['membership_guest_content'] ) . '</textarea></label></div>';
    echo '<h3 class="bsml-membership-group-title">3. Accelerator appointment</h3><div class="bsml-membership-card bsml-membership-body"><p>Eligible members see one of the two messages below, directly in the library. GHL handles bookings and the booked tag.</p>';
    bsml_field( 'appointment_tag', 'GHL appointment booked tag', $settings['appointment_tag'] );
    foreach ( array( 'appointment_available' => 'Available — no booked tag', 'appointment_booked' => 'Booked — booked tag present' ) as $key => $label ) {
        echo '<details class="bsml-membership-editor"><summary>' . esc_html( $label ) . '</summary><p>Use Visual mode for formatting or Text mode for HTML. Installed shortcodes are supported.</p><label class="bsml-field"><span>' . esc_html( $label ) . ' content</span><textarea id="' . esc_attr( $key ) . '" class="bsml-content-editor" rows="10" name="bsml_settings[' . esc_attr( $key ) . ']">' . esc_textarea( $settings[$key] ) . '</textarea></label></details>';
    }
    echo '</div>';
}

function bsml_admin_pending() {
    global $wpdb;
    $rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}bsml_claims WHERE status='pending' ORDER BY id DESC LIMIT 50" );
    echo '<h2>Claims awaiting reconciliation</h2><p>A pending claim blocks further claims for that member. Verify the GHL contact and granted content before resolving. Mark “Not applied” only if no access or usage tags were applied, or after correcting them in GHL.</p>';
    if ( ! $rows ) { echo '<p>No pending claims.</p>'; return; }
    foreach ( $rows as $row ) {
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="bsml_resolve"><input type="hidden" name="claim" value="' . (int) $row->id . '">';
        wp_nonce_field( 'bsml_resolve_' . $row->id );
        echo '<p>Claim #' . (int) $row->id . ' · User #' . (int) $row->user_id . ' · ' . esc_html( $row->benefit . ' · ' . $row->product_ids . ' · ' . $row->created_at . ' UTC' ) . ' <button class="button" name="resolution" value="confirmed">Confirmed in GHL</button> <button class="button" name="resolution" value="failed">Not applied</button></p></form>';
    }
}
add_action( 'admin_post_bsml_resolve', function () {
    if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Not allowed', '', array( 'response' => 403 ) ); }
    $id = absint( $_POST['claim'] ?? 0 ); check_admin_referer( 'bsml_resolve_' . $id );
    $status = sanitize_key( $_POST['resolution'] ?? '' );
    if ( in_array( $status, array( 'confirmed', 'failed' ), true ) ) {
        global $wpdb;
        $uid = absint( $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$wpdb->prefix}bsml_claims WHERE id=%d", $id ) ) );
        $lock = 'bsml_' . md5( $wpdb->prefix . ':' . $uid );
        if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) ) { wp_die( 'A claim is still processing. Please try again shortly.' ); }
        try {
            $wpdb->update( $wpdb->prefix . 'bsml_claims', array( 'status' => $status ), array( 'id' => $id, 'status' => 'pending' ) );
            update_user_meta( $uid, 'bsml_claim_resolution_' . $id, array( 'by' => get_current_user_id(), 'at' => current_time( 'mysql', true ), 'status' => $status ) );
        } finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
    }
    wp_safe_redirect( admin_url( 'options-general.php?page=bsml' ) ); exit;
} );
