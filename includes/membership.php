<?php
defined( 'ABSPATH' ) || exit;

function bsml_install() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $table = $wpdb->prefix . 'bsml_claims';
    dbDelta( "CREATE TABLE $table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        user_id bigint(20) unsigned NOT NULL,
        request_key varchar(64) NOT NULL,
        benefit varchar(24) NOT NULL,
        product_ids longtext NOT NULL,
        tags longtext NOT NULL,
        used_count smallint unsigned NOT NULL DEFAULT 0,
        status varchar(20) NOT NULL DEFAULT 'pending',
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY user_request (user_id,request_key),
        KEY user_status (user_id,status)
    ) " . $wpdb->get_charset_collate() . ';' );
    if ( ! get_option( 'bsml_settings' ) ) { add_option( 'bsml_settings', bsml_defaults(), '', false ); }
    update_option( 'bsml_schema', BSML_VERSION, false );
}

/** Read the synchronized record directly: no response/object cache and no missing-contact reset. */
function bsml_contact() {
    if ( ! function_exists( 'hlwpw_has_access' ) || ! function_exists( 'lcw_get_user_data' ) ) { return new WP_Error( 'bsml_connector', 'Connector Wizard must be active to load your benefits.', array( 'status' => 503 ) ); }
    global $wpdb;
    $row = $wpdb->get_row( $wpdb->prepare( "SELECT contact_id,tags,need_to_sync FROM {$wpdb->prefix}lcw_contacts WHERE user_id=%d", get_current_user_id() ) );
    if ( ! $row || empty( $row->contact_id ) ) { return new WP_Error( 'bsml_contact', 'Your membership contact is not available yet. Please contact support.', array( 'status' => 503 ) ); }
    if ( ! empty( $row->need_to_sync ) ) { return new WP_Error( 'bsml_sync', 'Your membership is synchronizing. Please try again shortly.', array( 'status' => 503 ) ); }
    $tags = maybe_unserialize( $row->tags );
    if ( ! is_array( $tags ) ) { return new WP_Error( 'bsml_tags', 'Membership tags are unavailable. Your allowances have not been reset.', array( 'status' => 503 ) ); }
    return array( 'id' => $row->contact_id, 'tags' => array_values( $tags ) );
}

function bsml_membership_state( $request = null ) {
    $contact = bsml_contact();
    if ( is_wp_error( $contact ) ) { return $contact; }
    $settings = bsml_settings(); $tier = bsml_tier( $contact['tags'], $settings['tiers'] );
    $benefits = array();
    foreach ( $settings['benefits'] as $key => $benefit ) {
        $benefit = bsml_benefit_config( $key, $tier, $settings );
        $used = bsml_used( $contact['tags'], $benefit['tags'] );
        $limit = $tier ? $tier[ $key ] : 0;
        $benefits[ $key ] = array( 'label' => $benefit['label'], 'used' => $used, 'limit' => $limit, 'remaining' => max( 0, $limit - $used ), 'configured' => ! empty( $benefit['include'] ) );
    }
    global $wpdb;
    // Recover a response lost after GHL accepted the complete set of tags. Never blindly resend.
    $pending = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bsml_claims WHERE user_id=%d AND status='pending'", get_current_user_id() ) );
    foreach ( $pending as $row ) {
        $expected = json_decode( $row->tags, true );
        if ( is_array( $expected ) && $expected && ! array_diff( $expected, $contact['tags'] ) ) {
            $wpdb->update( $wpdb->prefix . 'bsml_claims', array( 'status' => 'confirmed' ), array( 'id' => $row->id, 'status' => 'pending' ) );
        }
    }
    $has_pending = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}bsml_claims WHERE user_id=%d AND status='pending' LIMIT 1", get_current_user_id() ) );
    return array( 'tier' => $tier ? $tier['label'] : null, 'nonMemberContent' => $tier ? '' : wpautop( wp_kses_post( $settings['membership_guest_content'] ) ), 'benefits' => $benefits, 'pending' => $has_pending,
        'appointment' => array( 'eligible' => $tier && $tier['appointment'], 'booked' => in_array( $settings['appointment_tag'], $contact['tags'], true ) ) );
}

function bsml_claim( $request ) {
    global $wpdb;
    $uid = get_current_user_id();
    $key = sanitize_text_field( $request->get_param( 'request_key' ) );
    $benefit_key = sanitize_key( $request->get_param( 'benefit' ) );
    $raw_ids = $request->get_param( 'ids' );
    if ( ! preg_match( '/^[a-zA-Z0-9-]{16,64}$/', $key ) || ! is_array( $raw_ids ) || ! $raw_ids || count( $raw_ids ) > 4 ) { return new WP_Error( 'bsml_request', 'Please select between one and four eligible items.', array( 'status' => 400 ) ); }
    $ids = bsml_ids( $raw_ids );
    if ( count( $ids ) !== count( $raw_ids ) ) { return new WP_Error( 'bsml_duplicates', 'The selection contains invalid or duplicate items.', array( 'status' => 400 ) ); }
    // Connection-owned lock is released by MySQL even if PHP exits unexpectedly.
    $lock = 'bsml_' . md5( $wpdb->prefix . ':' . $uid );
    if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) ) { return new WP_Error( 'bsml_busy', 'Another claim is processing. Please wait before trying again.', array( 'status' => 409 ) ); }
    try {
        $table = $wpdb->prefix . 'bsml_claims';
        $existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE user_id=%d AND request_key=%s", $uid, $key ) );
        if ( $existing ) {
            if ( $existing->benefit !== $benefit_key || bsml_ids( json_decode( $existing->product_ids, true ) ) !== $ids ) { return new WP_Error( 'bsml_key', 'This request reference has already been used.', array( 'status' => 409 ) ); }
            if ( $existing->status === 'confirmed' ) { return array( 'confirmed' => true ); }
            return new WP_Error( 'bsml_pending', 'This claim needs reconciliation. Refresh your benefits or contact support; do not submit it again.', array( 'status' => 409 ) );
        }
        $contact = bsml_contact();
        if ( is_wp_error( $contact ) ) { return $contact; }
        $state = bsml_membership_state();
        if ( is_wp_error( $state ) ) { return $state; }
        if ( $state['pending'] ) { return new WP_Error( 'bsml_pending', 'A previous claim is awaiting confirmation. Please refresh or contact support.', array( 'status' => 409 ) ); }
        $settings = bsml_settings();
        if ( ! isset( $settings['benefits'][ $benefit_key ] ) || ! $state['tier'] || count( $ids ) > $state['benefits'][ $benefit_key ]['remaining'] ) { return new WP_Error( 'bsml_allowance', 'Your selection exceeds your current membership allowance.', array( 'status' => 403 ) ); }
        $benefit = bsml_benefit_config( $benefit_key, bsml_tier( $contact['tags'], $settings['tiers'] ), $settings );
        $tags = array();
        foreach ( $ids as $id ) {
            $product = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : false;
            if ( ! $product || get_post_type( $id ) !== 'product' || $product->get_status() !== 'publish' || ! $product->is_visible() || ! bsml_matches_scope( $id, 'product_cat', $benefit ) || bsml_product_access( $id ) || bsml_purchased( $id ) ) {
                return new WP_Error( 'bsml_item', 'An item is no longer eligible or is already in your library. Refresh the list.', array( 'status' => 403 ) );
            }
            $item_tags = get_post_meta( $id, 'hlwpw_location_tags', true );
            if ( ! is_array( $item_tags ) || ! array_filter( $item_tags, 'is_string' ) || ! bsml_product_clearings( $id ) ) { return new WP_Error( 'bsml_mapping', 'An item is missing its access tags or clearing link. Please contact support.', array( 'status' => 422 ) ); }
            $tags = array_merge( $tags, array_filter( $item_tags, 'is_string' ) );
        }
        $used = $state['benefits'][ $benefit_key ]['used'] + count( $ids );
        $count_tag = $benefit['tags'][ $used ] ?? '';
        if ( ! $count_tag || ! function_exists( 'hlwpw_loation_add_contact_tags' ) ) { return new WP_Error( 'bsml_config', 'The claim integration is not configured.', array( 'status' => 503 ) ); }
        $tags[] = $count_tag; $tags = array_values( array_unique( array_filter( $tags ) ) );
        if ( ! $wpdb->insert( $table, array( 'user_id' => $uid, 'request_key' => $key, 'benefit' => $benefit_key, 'product_ids' => wp_json_encode( $ids ), 'tags' => wp_json_encode( $tags ), 'used_count' => $used, 'status' => 'pending', 'created_at' => current_time( 'mysql', true ) ) ) ) { return new WP_Error( 'bsml_store', 'The claim could not be safely recorded. Please try again.', array( 'status' => 500 ) ); }
        $claim_id = $wpdb->insert_id;
        $result = hlwpw_loation_add_contact_tags( $contact['id'], array( 'tags' => $tags ), $uid );
        if ( ! $result || is_wp_error( $result ) ) { return new WP_Error( 'bsml_uncertain', 'Confirmation is delayed. Your selection is saved as pending; refresh your benefits or contact support before claiming again.', array( 'status' => 503 ) ); }
        if ( false === $wpdb->update( $table, array( 'status' => 'confirmed' ), array( 'id' => $claim_id ) ) ) { return new WP_Error( 'bsml_store', 'Your claim was sent and is awaiting local confirmation. Refresh your benefits.', array( 'status' => 503 ) ); }
        if ( function_exists( 'lcw_turn_on_post_access_update' ) ) { lcw_turn_on_post_access_update( $uid ); }
        do_action( 'bsml_claim_confirmed', $uid, $ids, $benefit_key, $claim_id );
        return array( 'confirmed' => true );
    } finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); }
}

function bsml_history( $request ) {
    global $wpdb;
    $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bsml_claims WHERE user_id=%d AND status IN ('confirmed','pending') ORDER BY created_at DESC,id DESC", get_current_user_id() ) );
    $items = array();
    foreach ( $rows as $row ) {
        foreach ( (array) json_decode( $row->product_ids, true ) as $id ) {
            $items[] = bsml_history_item( $id, $row->created_at, $row->benefit, $row->status );
        }
    }
    // Legacy data remains intact and is read once per request, not copied destructively.
    $legacy = get_user_meta( get_current_user_id(), 'bs_membership_claimed_items', true );
    foreach ( is_array( $legacy ) ? $legacy : array() as $id => $date ) {
        $utc = get_gmt_from_date( $date );
        $items[] = bsml_history_item( $id, $utc, 'Earlier selection', 'confirmed' );
    }
    $search = sanitize_text_field( $request->get_param( 'search' ) ?? '' );
    if ( $search !== '' ) { $items = array_values( array_filter( $items, function ( $item ) use ( $search ) { return stripos( $item['title'], $search ) !== false; } ) ); }
    return bsml_page( $items, $request );
}
function bsml_history_item( $id, $date, $benefit, $status ) {
    $clearing_ids = bsml_product_clearings( $id );
    $open = 0;
    foreach ( $clearing_ids as $cid ) { if ( bsml_has_access( $cid ) ) { $open = $cid; break; } }
    return array( 'id' => (int) $id, 'title' => get_the_title( $id ) ?: 'Previously claimed item', 'date' => $date, 'displayDate' => wp_date( get_option( 'date_format' ), strtotime( $date . ' UTC' ) ), 'benefit' => $benefit, 'status' => $status, 'clearing' => $open );
}
