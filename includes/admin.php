<?php
defined( 'ABSPATH' ) || exit;
add_action( 'admin_menu', function () { add_options_page( 'My Library', 'My Library', 'manage_options', 'bsml', 'bsml_admin' ); } );
add_action( 'admin_init', function () { register_setting( 'bsml', 'bsml_settings', array( 'sanitize_callback' => 'bsml_sanitize_settings', 'type' => 'array' ) ); } );
add_action( 'admin_enqueue_scripts', function ( $hook ) {
    if ( $hook !== 'settings_page_bsml' ) { return; }
    wp_enqueue_style( 'bsml-admin', BSML_URL . 'assets/admin.css', array(), BSML_VERSION );
    wp_enqueue_script( 'bsml-admin', BSML_URL . 'assets/admin.js', array(), BSML_VERSION, true );
} );

function bsml_field( $name, $label, $value, $type = 'text' ) {
    echo '<label class="bsml-field"><span>' . esc_html( $label ) . '</span><input type="' . esc_attr( $type ) . '" name="bsml_settings[' . esc_attr( $name ) . ']" value="' . esc_attr( is_array( $value ) ? implode( ',', $value ) : $value ) . '"' . ( $type === 'number' ? ' min="0" max="48"' : '' ) . '></label>';
}
function bsml_check( $name, $label, $value ) {
    echo '<label class="bsml-check"><input type="checkbox" name="bsml_settings[' . esc_attr( $name ) . ']" value="1" ' . checked( $value, true, false ) . '> ' . esc_html( $label ) . '</label>';
}
function bsml_terms_field( $name, $label, $selected, $taxonomy ) {
    $terms = taxonomy_exists( $taxonomy ) ? get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) ) : array();
    echo '<label class="bsml-field bsml-term-field"><span>' . esc_html( $label ) . '</span><input type="search" class="bsml-term-search" placeholder="Find category…" aria-label="Find category"><select multiple size="6" name="bsml_settings[' . esc_attr( $name ) . '][]">';
    foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
        echo '<option value="' . (int) $term->term_id . '" ' . selected( in_array( (int) $term->term_id, $selected, true ), true, false ) . '>' . esc_html( $term->name . ' (#' . $term->term_id . ')' ) . '</option>';
    }
    echo '</select><small>Use Command/Ctrl to select several. Empty inclusion lists show no items.</small></label>';
}
function bsml_scope_fields( $prefix, $scope, $taxonomy ) {
    bsml_terms_field( $prefix . '][include', 'Included categories', $scope['include'], $taxonomy );
    bsml_terms_field( $prefix . '][exclude', 'Excluded categories', $scope['exclude'], $taxonomy );
    bsml_check( $prefix . '][descendants', 'Include descendants of included categories', $scope['descendants'] );
    bsml_check( $prefix . '][exclude_descendants', 'Exclude descendants of excluded categories', $scope['exclude_descendants'] );
}
function bsml_admin_tab( $index, $tab ) {
    $prefix = 'tabs][' . $index;
    echo '<details class="bsml-tab-config"><summary>' . esc_html( $tab['label'] ) . '</summary><div class="bsml-admin-grid">';
    bsml_field( $prefix . '][id', 'Stable section ID (unique)', $tab['id'] );
    bsml_field( $prefix . '][label', 'Menu label', $tab['label'] );
    echo '<label class="bsml-field"><span>Section type</span><select name="bsml_settings[' . esc_attr( $prefix ) . '][type]">';
    foreach ( array( 'standard' => 'Standard clearing library', 'membership' => 'VIP Membership', 'wishlist' => 'WebToffee Wishlist' ) as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '" ' . selected( $tab['type'], $key, false ) . '>' . esc_html( $label ) . '</option>'; }
    echo '</select></label>';
    bsml_check( $prefix . '][enabled', 'Enabled', $tab['enabled'] );
    echo '<label class="bsml-field"><span>Clearing taxonomy (save to reload its categories)</span><select name="bsml_settings[' . esc_attr( $prefix ) . '][taxonomy]">';
    foreach ( get_object_taxonomies( 'clearing', 'objects' ) as $taxonomy ) { echo '<option value="' . esc_attr( $taxonomy->name ) . '" ' . selected( $tab['taxonomy'], $taxonomy->name, false ) . '>' . esc_html( $taxonomy->label ) . '</option>'; }
    echo '</select></label>';
    bsml_scope_fields( $prefix, $tab, $tab['taxonomy'] );
    bsml_field( $prefix . '][filters', 'Filter term IDs in display order (blank = automatic)', $tab['filters'] );
    bsml_terms_field( $prefix . '][related', 'Related product categories', $tab['related'], 'product_cat' );
    bsml_terms_field( $prefix . '][related_exclude', 'Excluded product categories', $tab['related_exclude'], 'product_cat' );
    bsml_check( $prefix . '][related_descendants', 'Include related-category descendants', $tab['related_descendants'] );
    bsml_check( $prefix . '][related_exclude_descendants', 'Exclude related-category descendants', $tab['related_exclude_descendants'] );
    bsml_field( $prefix . '][filter_map', 'Optional filter → product category map, JSON: {"12":[34,56]}', wp_json_encode( $tab['filter_map'], JSON_FORCE_OBJECT ) );
    echo '<label class="bsml-field"><span>Default sort</span><select name="bsml_settings[' . esc_attr( $prefix ) . '][sort]">';
    foreach ( array( 'newest' => 'Newest', 'oldest' => 'Oldest', 'az' => 'Title A–Z', 'za' => 'Title Z–A', 'event_asc' => 'Event date: next first', 'event_desc' => 'Event date: latest first' ) as $value => $label ) { echo '<option value="' . esc_attr( $value ) . '" ' . selected( $tab['sort'], $value, false ) . '>' . esc_html( $label ) . '</option>'; }
    echo '</select></label></div><p><button type="button" class="button" data-bsml-move="up">Move up</button> <button type="button" class="button" data-bsml-move="down">Move down</button> <button type="button" class="button" data-bsml-remove>Remove section</button></p></details>';
}
function bsml_admin() {
    if ( ! current_user_can( 'manage_options' ) ) { return; }
    $settings = bsml_settings();
    echo '<div class="wrap bsml-admin"><h1>My Library</h1><p>Place <code>[bs_my_library]</code> on your library page. Configure category mappings before launch. Purchased means <strong>any product access tag</strong> matches the member.</p>';
    if ( ! function_exists( 'hlwpw_has_access' ) ) { echo '<div class="notice notice-error"><p>Connector Wizard is required.</p></div>'; }
    echo '<div class="notice notice-warning inline"><p>Exclude every library page from your page cache/CDN. Add page IDs below for builder-based pages. This plugin sends no-store headers, but cannot override a cache that serves a page before WordPress runs.</p></div>';
    settings_errors();
    echo '<form method="post" action="options.php" id="bsml-settings">'; settings_fields( 'bsml' );
    echo '<h2>General and appearance</h2><div class="bsml-admin-grid">';
    bsml_field( 'default_tab', 'Default section ID', $settings['default_tab'] );
    bsml_field( 'pages', 'Library page IDs, comma-separated', $settings['pages'] );
    bsml_field( 'page_size', 'Items per page (6–48)', $settings['page_size'], 'number' );
    foreach ( $settings['colors'] as $key => $color ) { bsml_field( 'colors][' . $key, ucfirst( $key ) . ' color', $color, 'color' ); }
    echo '</div><h2>Sidebar and standard sections</h2><p>Standard sections show accessible clearing posts. Related categories are configured independently. Exclusions take precedence.</p><div id="bsml-tabs">';
    foreach ( $settings['tabs'] as $i => $tab ) { bsml_admin_tab( $i, $tab ); }
    echo '</div><p><button type="button" class="button" id="bsml-add-tab">Add section</button></p><template id="bsml-tab-template">';
    $blank = bsml_defaults()['tabs'][0]; $blank['id'] = ''; $blank['label'] = 'New section'; bsml_admin_tab( 'NEW', $blank );
    echo '</template><h2>Membership tiers</h2><p>Highest matching tier wins. Allowances are capped at four because four usage-count tags are configured.</p>';
    foreach ( $settings['tiers'] as $i => $tier ) {
        echo '<fieldset><legend>' . esc_html( $tier['label'] ) . '</legend><div class="bsml-admin-grid">';
        foreach ( array( 'label' => 'Tier label', 'tag' => 'GHL tier tag', 'live' => 'Live GEC allowance', 'replay' => 'Replay allowance' ) as $key => $label ) { bsml_field( 'tiers][' . $i . '][' . $key, $label, $tier[ $key ], in_array( $key, array( 'live', 'replay' ), true ) ? 'number' : 'text' ); }
        bsml_check( 'tiers][' . $i . '][appointment', 'Eligible for accelerator appointment', $tier['appointment'] );
        foreach ( array( 'live' => 'Live GEC', 'replay' => 'Replay' ) as $key => $label ) {
            bsml_check( 'tiers][' . $i . '][custom_' . $key, 'Use custom ' . $label . ' categories for this tier', ! empty( $tier[ 'custom_' . $key ] ) );
            bsml_scope_fields( 'tiers][' . $i . '][' . $key . '_scope', bsml_scope( $tier[ $key . '_scope' ] ?? array() ), 'product_cat' );
        }
        echo '</div></fieldset>';
    }
    foreach ( $settings['benefits'] as $key => $benefit ) {
        echo '<h2>' . esc_html( $benefit['label'] ) . '</h2><div class="bsml-admin-grid">';
        bsml_field( 'benefits][' . $key . '][label', 'Benefit label', $benefit['label'] );
        bsml_scope_fields( 'benefits][' . $key, $benefit, 'product_cat' );
        foreach ( $benefit['tags'] as $n => $tag ) { bsml_field( 'benefits][' . $key . '][tags][' . $n, 'Tag for ' . $n . ' used', $tag ); }
        echo '</div>';
    }
    echo '<h2>Accelerator appointment</h2><p>GHL owns booking and the appointment tag. These fields support formatted content and trusted installed shortcodes. Only administrators can edit them.</p>';
    bsml_field( 'appointment_tag', 'Appointment booked tag', $settings['appointment_tag'] );
    foreach ( array( 'appointment_available' => 'Content when appointment is available', 'appointment_booked' => 'Content when appointment is booked' ) as $key => $label ) {
        echo '<h3>' . esc_html( $label ) . '</h3>';
        wp_editor( $settings[ $key ], $key, array( 'textarea_name' => 'bsml_settings[' . $key . ']', 'textarea_rows' => 6, 'media_buttons' => true ) );
    }
    submit_button(); echo '</form>';
    bsml_admin_pending(); echo '</div>';
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
