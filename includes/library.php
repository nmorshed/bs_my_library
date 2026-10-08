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
    if ( ! $ids ) { return array(); }
    $candidates = get_terms( array( 'taxonomy' => $taxonomy, 'include' => array_values( $allowed ), 'hide_empty' => false, 'pad_counts' => false ) );
    if ( is_wp_error( $candidates ) ) { return array(); }
    $by_id = array(); $populated = array();
    foreach ( $candidates as $candidate ) {
        $by_id[ (int) $candidate->term_id ] = $candidate;
        if ( (int) $candidate->count > 0 ) { $populated[] = (int) $candidate->term_id; }
    }
    $terms = array();
    foreach ( $ids as $id ) {
        // A parent is non-empty when an included descendant has items, unless
        // descendants are disabled. Excluded descendants cannot populate it.
        $filter_ids = bsml_expand_terms( array( $id ), $taxonomy, $scope['descendants'] );
        if ( isset( $by_id[ $id ] ) && array_intersect( $filter_ids, $populated ) ) {
            $terms[] = array( 'id' => (int) $id, 'label' => html_entity_decode( $by_id[ $id ]->name, ENT_QUOTES, get_bloginfo( 'charset' ) ) );
        }
    }
    return $terms;
}

function bsml_term_name( $name ) {
    $name = trim( html_entity_decode( $name, ENT_QUOTES, 'UTF-8' ) );
    return function_exists( 'mb_strtolower' ) ? mb_strtolower( $name, 'UTF-8' ) : strtolower( $name );
}
function bsml_term_parent_names( $term ) {
    $names = array();
    foreach ( array_reverse( get_ancestors( $term->term_id, $term->taxonomy, 'taxonomy' ) ) as $id ) {
        $parent = get_term( $id, $term->taxonomy );
        if ( $parent && ! is_wp_error( $parent ) ) { $names[] = bsml_term_name( $parent->name ); }
    }
    return $names;
}
function bsml_related_categories( $tab, $selected = 0 ) {
    $ids = bsml_ids( $tab['related'] );
    $source_ids = $selected ? array( $selected ) : array_diff(
        bsml_expand_terms( $tab['include'], $tab['taxonomy'], $tab['descendants'] ),
        bsml_expand_terms( $tab['exclude'], $tab['taxonomy'], $tab['exclude_descendants'] )
    );
    $products = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
    $by_name = array();
    foreach ( is_wp_error( $products ) ? array() : $products as $category ) { $by_name[ bsml_term_name( $category->name ) ][] = $category; }
    foreach ( $source_ids as $id ) {
        // Explicit mappings replace automatic matching, never the additional categories.
        if ( array_key_exists( $id, $tab['filter_map'] ) ) { $ids = array_merge( $ids, bsml_ids( $tab['filter_map'][ $id ] ) ); continue; }
        $topic = get_term( $id, $tab['taxonomy'] );
        if ( ! $topic || is_wp_error( $topic ) ) { continue; }
        $matches = $by_name[ bsml_term_name( $topic->name ) ] ?? array();
        if ( count( $matches ) > 1 ) {
            $parents = bsml_term_parent_names( $topic );
            $matches = array_values( array_filter( $matches, function ( $match ) use ( $parents ) { return bsml_term_parent_names( $match ) === $parents; } ) );
        }
        // Ambiguous names require an explicit mapping; do not guess a branch.
        if ( count( $matches ) === 1 ) { $ids[] = (int) $matches[0]->term_id; }
    }
    return bsml_ids( $ids );
}

function bsml_cart_html( $product ) {
    // Let WooCommerce, the theme, and product extensions own the entire button.
    if ( null === WC()->cart ) { wc_load_cart(); }
    $keys = array( 'product', 'post', 'id', 'authordata', 'currentday', 'currentmonth', 'page', 'pages', 'multipage', 'more', 'numpages' );
    $previous = array();
    foreach ( $keys as $key ) {
        if ( array_key_exists( $key, $GLOBALS ) ) { $previous[ $key ] = $GLOBALS[ $key ]; }
    }
    $had_uri = isset( $_SERVER['REQUEST_URI'] );
    $previous_uri = $_SERVER['REQUEST_URI'] ?? '';
    $buffer_level = ob_get_level();
    try {
        $GLOBALS['post'] = get_post( $product->get_id() );
        if ( $GLOBALS['post'] ) { setup_postdata( $GLOBALS['post'] ); }
        $GLOBALS['product'] = $product;
        // Native product classes can append cart arguments to the current URL.
        // Give them a real product URL instead of the REST endpoint, preserving
        // their own bundle/variation query parameters and all URL filters.
        $_SERVER['REQUEST_URI'] = wp_make_link_relative( $product->get_permalink() );
        ob_start();
        woocommerce_template_loop_add_to_cart();
        return ob_get_clean();
    } finally {
        while ( ob_get_level() > $buffer_level ) { ob_end_clean(); }
        foreach ( $keys as $key ) {
            if ( array_key_exists( $key, $previous ) ) { $GLOBALS[ $key ] = $previous[ $key ]; }
            else { unset( $GLOBALS[ $key ] ); }
        }
        if ( $had_uri ) { $_SERVER['REQUEST_URI'] = $previous_uri; }
        else { unset( $_SERVER['REQUEST_URI'] ); }
    }
}

function bsml_custom_content( $request ) {
    $section = bsml_content_section( sanitize_key( $request->get_param( 'section' ) ?? '' ), sanitize_key( $request->get_param( 'child' ) ?? '' ) );
    if ( is_wp_error( $section ) ) { return $section; }
    if ( ! in_array( $section['type'], array( 'content', 'page' ), true ) ) { return new WP_Error( 'bsml_content_type', 'This section does not contain custom content.', array( 'status' => 400 ) ); }
    return bsml_render_content_payload( $section );
}

function bsml_appointment_content() {
    $state = bsml_membership_state();
    if ( is_wp_error( $state ) ) { return $state; }
    if ( empty( $state['appointment']['eligible'] ) ) {
        return new WP_Error( 'bsml_appointment', 'This benefit is not available to your account.', array( 'status' => 403 ) );
    }
    $settings = bsml_settings();
    $booked = ! empty( $state['appointment']['booked'] );
    $content = $settings[ $booked ? 'appointment_booked' : 'appointment_available' ];
    if ( ! $content ) { $content = $booked ? '<p>Your accelerator session has been booked for this cycle.</p>' : '<p>Booking instructions will appear here when configured.</p>'; }
    return bsml_render_content_payload( array( 'type' => 'content', 'content' => $content ) );
}

function bsml_render_content_payload( $section ) {
    global $shortcode_tags;
    $library_shortcode = $shortcode_tags['bs_my_library'] ?? null;
    add_shortcode( 'bs_my_library', '__return_empty_string' );
    try {
        $html = $section['type'] === 'page' ? bsml_render_page_content( $section['page_id'] ) : bsml_render_custom_content( $section['content'] );
        // Return only enqueued assets, never global page head/footer hooks.
        ob_start();
        wp_styles()->do_items();
        wp_scripts()->do_items( false, 1 );
        $assets = ob_get_clean();
    } finally {
        if ( $library_shortcode ) { add_shortcode( 'bs_my_library', $library_shortcode ); }
        else { remove_shortcode( 'bs_my_library' ); }
    }
    return array( 'html' => $html, 'assets' => $assets );
}

function bsml_render_page_content( $page_id ) {
    $keys = array( 'wp_query', 'wp_the_query', 'post', 'id', 'authordata', 'currentday', 'currentmonth', 'page', 'pages', 'multipage', 'more', 'numpages' );
    $previous = array();
    foreach ( $keys as $key ) { if ( array_key_exists( $key, $GLOBALS ) ) { $previous[$key] = $GLOBALS[$key]; } }
    $had_uri = isset( $_SERVER['REQUEST_URI'] ); $uri = $_SERVER['REQUEST_URI'] ?? '';
    $level = ob_get_level();
    try {
        $GLOBALS['wp_query'] = new WP_Query( array( 'page_id' => $page_id, 'post_type' => 'page', 'post_status' => 'publish', 'cache_results' => false ) );
        $GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
        $_SERVER['REQUEST_URI'] = wp_make_link_relative( get_permalink( $page_id ) );
        ob_start();
        while ( have_posts() ) {
            the_post();
            if ( ! did_action( 'wp_enqueue_scripts' ) ) { wp_enqueue_scripts(); }
            the_content();
        }
        return ob_get_clean();
    } finally {
        while ( ob_get_level() > $level ) { ob_end_clean(); }
        foreach ( $keys as $key ) {
            if ( array_key_exists( $key, $previous ) ) { $GLOBALS[$key] = $previous[$key]; }
            else { unset( $GLOBALS[$key] ); }
        }
        if ( $had_uri ) { $_SERVER['REQUEST_URI'] = $uri; } else { unset( $_SERVER['REQUEST_URI'] ); }
    }
}

function bsml_render_custom_content( $content ) {
    $has_blocks = has_blocks( $content );
    $content = do_blocks( $content );
    if ( ! $has_blocks ) { $content = wpautop( $content ); }
    return do_shortcode( shortcode_unautop( $content ) );
}

function bsml_library_post_type( $taxonomy ) {
    return $taxonomy === 'ld_course_category' ? 'sfwd-courses' : 'clearing';
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
    if ( $kind === 'related' && ! $tab['show_related'] ) { return new WP_Error( 'bsml_related', 'Recommendations are disabled for this section.', array( 'status' => 404 ) ); }
    $is_product = $kind !== 'library';
    if ( $is_product && ! function_exists( 'wc_get_product' ) ) { return new WP_Error( 'bsml_woo', 'WooCommerce is required for this section.', array( 'status' => 503 ) ); }
    $taxonomy = $is_product ? 'product_cat' : $tab['taxonomy'];
    $scope = bsml_scope( $tab );
    $filters = bsml_filters( $tab['taxonomy'], $scope, $tab['filters'] );
    $term = absint( $request->get_param( 'term' ) );
    if ( $tab['type'] === 'standard' && ! $tab['show_terms'] ) { $filters = array(); $term = 0; }
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
        $related = bsml_related_categories( $tab, $term );
        $scope = array( 'include' => $related, 'exclude' => $tab['related_exclude'], 'descendants' => $tab['related_descendants'], 'exclude_descendants' => $tab['related_exclude_descendants'] );
    }
    $args = array( 'post_type' => $is_product ? 'product' : bsml_library_post_type( $taxonomy ), 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => 200, 'paged' => 1, 'orderby' => 'ID', 'order' => 'ASC', 'cache_results' => false, 'update_post_meta_cache' => false, 'update_post_term_cache' => false, 'no_found_rows' => true, 's' => mb_substr( sanitize_text_field( $request->get_param( 'search' ) ?? '' ), 0, 150 ) );
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
            $item = array( 'id' => (int) $id, 'title' => html_entity_decode( get_the_title( $id ), ENT_QUOTES, get_bloginfo( 'charset' ) ), 'date' => get_post_field( 'post_date_gmt', $id ), 'event' => get_post_meta( $event_product, '_sa_event_date', true ), 'image' => get_the_post_thumbnail_url( $id, 'medium_large' ) ?: '', 'url' => get_permalink( $id ), 'clearing' => ! $is_product && $args['post_type'] === 'clearing' ? (int) $id : 0, 'postType' => $args['post_type'], 'price' => $product ? (float) $product->get_price() : 0, 'priceHtml' => $product ? wp_kses_post( $product->get_price_html() ) : '', 'wishlisted' => in_array( (int) $id, $wishlist_ids, true ) );
            if ( $kind === 'related' ) { $item['cartHtml'] = bsml_cart_html( $product ); }
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
