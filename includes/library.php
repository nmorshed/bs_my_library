<?php
defined( 'ABSPATH' ) || exit;

function bsml_has_access( $id ) {
    return get_post_status( $id ) === 'publish' && function_exists( 'hlwpw_has_access' ) && hlwpw_has_access( $id, get_current_user_id() );
}
function bsml_product_clearings( $id ) {
    global $bsml_request_links;
    bsml_load_links( array( $id ) );
    return $bsml_request_links[ absint( $id ) ] ?? array();
}
function bsml_load_links( $ids ) {
    global $wpdb, $bsml_request_links;
    if ( ! is_array( $bsml_request_links ) ) { $bsml_request_links = array(); }
    $ids = array_values( array_diff( bsml_ids( $ids ), array_keys( $bsml_request_links ) ) );
    if ( ! $ids ) { return; }
    foreach ( $ids as $id ) { $bsml_request_links[ $id ] = array(); }
    // Request-local batching only. Nothing is written to a persistent or response cache.
    $in = implode( ',', $ids ); // Integers only, from bsml_ids().
    $rows = $wpdb->get_results( "SELECT m.post_id,m.meta_key,m.meta_value FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID=m.post_id WHERE (m.meta_key='_sa_related_clearing' AND m.post_id IN ($in)) OR (m.meta_key='_sa_related_product' AND m.meta_value IN ($in) AND p.post_type='clearing' AND p.post_status='publish')" );
    foreach ( $rows as $row ) {
        $pid = $row->meta_key === '_sa_related_clearing' ? absint( $row->post_id ) : absint( $row->meta_value );
        $cid = $row->meta_key === '_sa_related_clearing' ? absint( $row->meta_value ) : absint( $row->post_id );
        if ( $cid && isset( $bsml_request_links[ $pid ] ) ) { $bsml_request_links[ $pid ][] = $cid; }
    }
    $clearings = array();
    foreach ( $ids as $id ) { $bsml_request_links[ $id ] = array_values( array_unique( $bsml_request_links[ $id ] ) ); $clearings = array_merge( $clearings, $bsml_request_links[ $id ] ); }
    if ( $clearings ) { update_meta_cache( 'post', array_unique( $clearings ) ); }
}
function bsml_product_access( $id ) {
    foreach ( bsml_product_clearings( $id ) as $clearing ) { if ( bsml_has_access( $clearing ) ) { return true; } }
    return false;
}
function bsml_purchased( $id ) {
    $tags = get_post_meta( $id, 'hlwpw_location_tags', true );
    return is_array( $tags ) && $tags && function_exists( 'hlwpw_contact_has_tag' ) && hlwpw_contact_has_tag( $tags, 'any', get_current_user_id() );
}
function bsml_expand_terms( $ids, $taxonomy, $descendants ) {
    $all = bsml_ids( $ids );
    if ( $descendants ) {
        foreach ( $all as $id ) {
            $children = get_term_children( $id, $taxonomy );
            if ( ! is_wp_error( $children ) ) { $all = array_merge( $all, $children ); }
        }
    }
    return bsml_ids( $all );
}
function bsml_matches_scope( $id, $taxonomy, $scope ) {
    if ( empty( $scope['include'] ) ) { return false; }
    $terms = wp_get_object_terms( $id, $taxonomy, array( 'fields' => 'ids' ) );
    if ( is_wp_error( $terms ) ) { return false; }
    $included = bsml_expand_terms( $scope['include'], $taxonomy, $scope['descendants'] );
    $excluded = bsml_expand_terms( $scope['exclude'], $taxonomy, $scope['exclude_descendants'] );
    return (bool) array_intersect( $terms, $included ) && ! array_intersect( $terms, $excluded );
}
function bsml_tax_query( $taxonomy, $scope ) {
    $query = array( 'relation' => 'AND', array( 'taxonomy' => $taxonomy, 'field' => 'term_id', 'terms' => $scope['include'], 'include_children' => $scope['descendants'] ) );
    if ( $scope['exclude'] ) { $query[] = array( 'taxonomy' => $taxonomy, 'field' => 'term_id', 'terms' => $scope['exclude'], 'include_children' => $scope['exclude_descendants'], 'operator' => 'NOT IN' ); }
    return $query;
}
function bsml_filters( $taxonomy, $scope, $explicit = array() ) {
    $allowed = array_diff( bsml_expand_terms( $scope['include'], $taxonomy, $scope['descendants'] ), bsml_expand_terms( $scope['exclude'], $taxonomy, $scope['exclude_descendants'] ) );
    if ( $explicit ) { $ids = array_values( array_intersect( $explicit, $allowed ) ); }
    else { $ids = array_values( array_diff( $allowed, $scope['include'] ) ); if ( count( $scope['include'] ) > 1 ) { $ids = array_values( $allowed ); } }
    $terms = array();
    foreach ( $ids as $id ) { $term = get_term( $id, $taxonomy ); if ( $term && ! is_wp_error( $term ) ) { $terms[] = array( 'id' => (int) $id, 'label' => $term->name ); } }
    return $terms;
}

function bsml_list( $request ) {
    if ( ! function_exists( 'hlwpw_has_access' ) ) { return new WP_Error( 'bsml_connector', 'Connector Wizard is required to load your library.', array( 'status' => 503 ) ); }
    $settings = bsml_settings();
    $kind = sanitize_key( $request->get_param( 'kind' ) ?? 'library' );
    if ( ! in_array( $kind, array( 'library', 'related', 'benefit', 'wishlist' ), true ) ) { return new WP_Error( 'bsml_kind', 'Unknown list.', array( 'status' => 400 ) ); }
    $tab = null;
    foreach ( $settings['tabs'] as $candidate ) { if ( $candidate['id'] === $request->get_param( 'tab' ) && $candidate['enabled'] ) { $tab = $candidate; break; } }
    if ( ! $tab ) { return new WP_Error( 'bsml_tab', 'This section is unavailable.', array( 'status' => 404 ) ); }
    if ( ( in_array( $kind, array( 'library', 'related' ), true ) && $tab['type'] !== 'standard' ) || ( $kind === 'benefit' && $tab['type'] !== 'membership' ) || ( $kind === 'wishlist' && $tab['type'] !== 'wishlist' ) ) { return new WP_Error( 'bsml_kind', 'This list is not part of the selected section.', array( 'status' => 400 ) ); }
    $is_product = $kind !== 'library';
    if ( $is_product && ! function_exists( 'wc_get_product' ) ) { return new WP_Error( 'bsml_woo', 'WooCommerce is required for this section.', array( 'status' => 503 ) ); }
    $taxonomy = $is_product ? 'product_cat' : $tab['taxonomy'];
    $scope = bsml_scope( $tab );
    $filters = bsml_filters( $tab['taxonomy'], $scope, $tab['filters'] );
    $term = absint( $request->get_param( 'term' ) );
    $wishlist = bsml_wishlist_rows();
    $wishlist_ids = array_map( 'intval', wp_list_pluck( $wishlist, 'product_id' ) );
    if ( $kind === 'benefit' ) {
        $key = sanitize_key( $request->get_param( 'benefit' ) );
        $state = bsml_membership_state();
        if ( is_wp_error( $state ) ) { return $state; }
        if ( ! $state['tier'] || ! isset( $settings['benefits'][ $key ] ) || $state['benefits'][ $key ]['limit'] < 1 ) { return new WP_Error( 'bsml_benefit', 'This benefit is not available.', array( 'status' => 403 ) ); }
        $contact = bsml_contact();
        if ( is_wp_error( $contact ) ) { return $contact; }
        $scope = bsml_scope( bsml_benefit_config( $key, bsml_tier( $contact['tags'], $settings['tiers'] ), $settings ) );
        $filters = bsml_filters( 'product_cat', $scope );
    }
    if ( $term && ! in_array( $term, wp_list_pluck( $filters, 'id' ), true ) ) { return new WP_Error( 'bsml_term', 'This category filter is unavailable.', array( 'status' => 400 ) ); }
    if ( $kind === 'related' ) {
        $related = $term && isset( $tab['filter_map'][ $term ] ) ? $tab['filter_map'][ $term ] : $tab['related'];
        $scope = array( 'include' => $related, 'exclude' => $tab['related_exclude'], 'descendants' => $tab['related_descendants'], 'exclude_descendants' => $tab['related_exclude_descendants'] );
    }
    $args = array( 'post_type' => $is_product ? 'product' : 'clearing', 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => 200, 'paged' => 1, 'orderby' => 'ID', 'order' => 'ASC', 'cache_results' => false, 'update_post_meta_cache' => false, 'update_post_term_cache' => false, 'no_found_rows' => true, 's' => mb_substr( sanitize_text_field( $request->get_param( 'search' ) ?? '' ), 0, 150 ) );
    if ( $kind === 'wishlist' ) {
        if ( ! defined( 'WEBTOFFEE_WISHLIST_BASEURL' ) ) { return new WP_Error( 'bsml_wishlist', 'WebToffee Wishlist is not active.', array( 'status' => 503 ) ); }
        $args['post__in'] = $wishlist_ids ?: array( 0 );
        $filters = array();
    } else {
        if ( empty( $scope['include'] ) ) { return array( 'items' => array(), 'total' => 0, 'pages' => 1, 'page' => 1, 'filters' => array(), 'configured' => false ); }
        $args['tax_query'] = bsml_tax_query( $taxonomy, $scope );
        if ( $term && $kind !== 'related' ) { $args['tax_query'][] = array( 'taxonomy' => $taxonomy, 'field' => 'term_id', 'terms' => array( $term ), 'include_children' => $scope['descendants'] ); }
    }
    $items = array();
    // Scan candidates in bounded batches. Access filtering precedes global sorting and pagination.
    do {
        $query = new WP_Query( $args );
        if ( $query->posts ) { update_meta_cache( 'post', $query->posts ); }
        if ( $is_product ) { bsml_load_links( $query->posts ); }
        foreach ( $query->posts as $id ) {
            if ( ! $is_product && ! bsml_has_access( $id ) ) { continue; }
            $product = $is_product ? wc_get_product( $id ) : false;
            if ( $is_product && ( ! $product || ! $product->is_visible() ) ) { continue; }
            $accessible = $is_product && bsml_product_access( $id );
            if ( $kind === 'related' && ( $accessible || bsml_purchased( $id ) ) ) { continue; }
            if ( $kind === 'benefit' && ( $accessible || bsml_purchased( $id ) ) ) { continue; }
            if ( $kind === 'benefit' && ( ! bsml_product_clearings( $id ) || ! get_post_meta( $id, 'hlwpw_location_tags', true ) ) ) { continue; }
            $event_product = $is_product ? $id : absint( get_post_meta( $id, '_sa_related_product', true ) );
            $item = array( 'id' => (int) $id, 'title' => html_entity_decode( get_the_title( $id ), ENT_QUOTES, get_bloginfo( 'charset' ) ), 'date' => get_post_field( 'post_date_gmt', $id ), 'event' => get_post_meta( $event_product, '_sa_event_date', true ), 'image' => get_the_post_thumbnail_url( $id, 'medium_large' ) ?: '', 'url' => get_permalink( $id ), 'clearing' => $is_product ? 0 : (int) $id, 'price' => $product ? (float) $product->get_price() : 0, 'priceHtml' => $product ? wp_kses_post( $product->get_price_html() ) : '', 'wishlisted' => in_array( (int) $id, $wishlist_ids, true ) );
            if ( $accessible ) { foreach ( bsml_product_clearings( $id ) as $cid ) { if ( bsml_has_access( $cid ) ) { $item['clearing'] = $cid; break; } } }
            $items[] = $item;
        }
        $args['paged']++;
    } while ( count( $query->posts ) === 200 );
    return array_merge( bsml_page( $items, $request ), array( 'filters' => $filters, 'configured' => true ) );
}

function bsml_page( $items, $request ) {
    $sort = bsml_sort_key( $request->get_param( 'sort' ) ?? 'newest' );
    usort( $items, function ( $a, $b ) use ( $sort ) {
        if ( $sort === 'az' || $sort === 'za' ) { $comparison = strnatcasecmp( $a['title'], $b['title'] ); }
        elseif ( strpos( $sort, 'price_' ) === 0 ) { $comparison = ( $a['price'] ?? 0 ) <=> ( $b['price'] ?? 0 ); }
        elseif ( strpos( $sort, 'event_' ) === 0 ) {
            // Undated items always follow dated items, in either direction.
            if ( empty( $a['event'] ) !== empty( $b['event'] ) ) { return empty( $a['event'] ) ? 1 : -1; }
            $comparison = strcmp( $a['event'] ?? '', $b['event'] ?? '' );
        } else { $comparison = strcmp( $a['date'], $b['date'] ); }
        if ( in_array( $sort, array( 'newest', 'za', 'price_desc', 'event_desc' ), true ) ) { $comparison *= -1; }
        return $comparison ?: ( $a['id'] <=> $b['id'] );
    } );
    $size = bsml_settings()['page_size']; $total = count( $items ); $pages = max( 1, (int) ceil( $total / $size ) );
    $page = min( $pages, max( 1, absint( $request->get_param( 'page' ) ?? 1 ) ) );
    return array( 'items' => array_slice( $items, ( $page - 1 ) * $size, $size ), 'total' => $total, 'page' => $page, 'pages' => $pages );
}
function bsml_wishlist_rows() {
    if ( ! defined( 'WEBTOFFEE_WISHLIST_BASEURL' ) ) { return array(); }
    global $wpdb;
    // WebToffee has no public uncached getter in this version. Use its table for reads only.
    return $wpdb->get_results( $wpdb->prepare( "SELECT product_id,variation_id FROM {$wpdb->prefix}wt_wishlists WHERE user_id=%d", get_current_user_id() ), ARRAY_A ) ?: array();
}
