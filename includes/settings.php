<?php
defined( 'ABSPATH' ) || exit;

function bsml_defaults() {
    $names = array( 'books-audios' => 'My Books & Audios', 'chakra' => 'My Chakra Series', 'classes' => 'My Classes', 'clearings' => 'My Clearings', 'gifts' => 'My Free Gifts', 'journeys' => 'My Guided Journeys', 'vip' => 'My VIP Membership', 'packages' => 'My Packages', 'programs' => 'My Programs', 'purchased' => 'My Purchased', 'wishlist' => 'My Wishlist' );
    $tabs = array();
    foreach ( $names as $id => $label ) {
        $tabs[] = array( 'id' => $id, 'label' => $label, 'type' => $id === 'vip' ? 'membership' : ( $id === 'wishlist' ? 'wishlist' : 'standard' ), 'enabled' => true, 'taxonomy' => 'topic', 'include' => array(), 'exclude' => array(), 'descendants' => true, 'exclude_descendants' => true, 'filters' => array(), 'related' => array(), 'related_exclude' => array(), 'related_descendants' => true, 'related_exclude_descendants' => true, 'filter_map' => array(), 'sort' => 'newest', 'show_related' => true, 'show_terms' => true, 'page_id' => 0, 'content' => '', 'children' => array() );
    }
    $benefits = array();
    foreach ( array( 'live' => 'Live GEC', 'replay' => 'Replays' ) as $key => $label ) {
        $tags = array();
        for ( $i = 1; $i <= 4; $i++ ) { $tags[ $i ] = $i . ( $key === 'live' ? ' membership_live_gc_added' : ' membership_replay_added' ); }
        $benefits[ $key ] = array( 'label' => $label, 'include' => $key === 'live' ? array( 156 ) : array(), 'exclude' => array(), 'descendants' => true, 'exclude_descendants' => true, 'tags' => $tags );
    }
    return array( 'tabs' => $tabs, 'default_tab' => 'books-audios', 'pages' => array(), 'page_size' => 12, 'colors' => array( 'primary' => '#611203', 'accent' => '#efcb82', 'soft' => '#fad5bb' ), 'benefits' => $benefits,
        'tiers' => array(
            array( 'label' => 'Level 1', 'tag' => 'level-1', 'live' => 1, 'replay' => 1, 'appointment' => false ),
            array( 'label' => 'Level 2', 'tag' => 'level-2', 'live' => 2, 'replay' => 2, 'appointment' => false ),
            array( 'label' => 'Level 3', 'tag' => 'level-3', 'live' => 2, 'replay' => 3, 'appointment' => true ),
        ), 'appointment_tag' => 'membership appointment booked', 'appointment_available' => '', 'appointment_booked' => '<p>Your accelerator session has been booked for this cycle.</p>' );
}
function bsml_settings() {
    $settings = array_replace( bsml_defaults(), get_option( 'bsml_settings', array() ) );
    foreach ( $settings['tabs'] as &$tab ) {
        $tab = array_merge( array( 'show_related' => true, 'show_terms' => true, 'page_id' => 0, 'content' => '', 'children' => array() ), $tab );
    }
    unset( $tab );
    return $settings;
}
function bsml_content_fields( $row ) {
    return array( 'page_id' => absint( $row['page_id'] ?? 0 ), 'content' => wp_kses_post( $row['content'] ?? '' ) );
}
function bsml_public_tabs() {
    $tabs = array();
    foreach ( bsml_settings()['tabs'] as $tab ) {
        if ( empty( $tab['enabled'] ) ) { continue; }
        $public = array_intersect_key( $tab, array_flip( array( 'id', 'label', 'type', 'sort', 'show_related', 'show_terms' ) ) );
        $public['children'] = array();
        if ( in_array( $tab['type'], array( 'page', 'content' ), true ) ) {
            $public['url'] = bsml_section_url( $tab, $tab['id'] );
            foreach ( $tab['children'] as $child ) {
                if ( empty( $child['enabled'] ) ) { continue; }
                $public['children'][] = array( 'id' => $child['id'], 'label' => $child['label'], 'type' => $child['type'], 'url' => bsml_section_url( $child, $tab['id'], $child['id'] ) );
            }
        }
        $tabs[] = $public;
    }
    return $tabs;
}
function bsml_section_url( $section, $parent, $child = '' ) {
    $url = $section['type'] === 'page' && ! empty( $section['page_id'] ) ? get_permalink( $section['page_id'] ) : home_url( '/' );
    return add_query_arg( array( 'bsml_embed' => 'section', 'bsml_section' => $parent, 'bsml_child' => $child ), $url ?: home_url( '/' ) );
}
function bsml_content_section( $parent, $child = '' ) {
    if ( ! is_user_logged_in() ) { return new WP_Error( 'bsml_login', 'Please log in to view this content.' ); }
    foreach ( bsml_settings()['tabs'] as $tab ) {
        if ( $tab['id'] !== $parent || empty( $tab['enabled'] ) || ! in_array( $tab['type'], array( 'page', 'content' ), true ) ) { continue; }
        $section = $tab;
        if ( $child !== '' ) {
            $section = null;
            foreach ( $tab['children'] as $candidate ) {
                if ( $candidate['id'] === $child && ! empty( $candidate['enabled'] ) ) { $section = $candidate; break; }
            }
        }
        if ( ! $section ) { break; }
        if ( $section['type'] === 'page' ) {
            $page = get_post( $section['page_id'] );
            if ( ! $page || $page->post_type !== 'page' || ! bsml_has_access( $page->ID ) || post_password_required( $page ) ) {
                return new WP_Error( 'bsml_access', 'This page is not available to your account.' );
            }
        }
        return $section;
    }
    return new WP_Error( 'bsml_section', 'This section is unavailable.' );
}
function bsml_ids( $value ) {
    return array_values( array_unique( array_filter( array_map( 'absint', is_array( $value ) ? $value : explode( ',', (string) $value ) ) ) ) );
}
function bsml_scope( $row ) {
    return array( 'include' => bsml_ids( $row['include'] ?? array() ), 'exclude' => bsml_ids( $row['exclude'] ?? array() ), 'descendants' => ! empty( $row['descendants'] ), 'exclude_descendants' => ! empty( $row['exclude_descendants'] ) );
}
function bsml_sanitize_settings( $raw ) {
    $out = bsml_defaults();
    $out['tabs'] = array(); $seen = array();
    foreach ( (array) ( $raw['tabs'] ?? array() ) as $tab ) {
        $id = sanitize_key( $tab['id'] ?? '' );
        if ( ! $id || isset( $seen[ $id ] ) ) { continue; }
        $seen[ $id ] = true;
        $out['tabs'][] = array_merge( bsml_scope( $tab ), array(
            'id' => $id, 'label' => sanitize_text_field( $tab['label'] ?? $id ), 'enabled' => ! empty( $tab['enabled'] ),
            'type' => in_array( $tab['type'] ?? '', array( 'standard', 'membership', 'wishlist', 'page', 'content' ), true ) ? $tab['type'] : 'standard',
            'taxonomy' => sanitize_key( $tab['taxonomy'] ?? 'topic' ), 'filters' => bsml_ids( $tab['filters'] ?? array() ),
            'related' => bsml_ids( $tab['related'] ?? array() ), 'related_exclude' => bsml_ids( $tab['related_exclude'] ?? array() ),
            'related_descendants' => ! empty( $tab['related_descendants'] ), 'related_exclude_descendants' => ! empty( $tab['related_exclude_descendants'] ),
            'show_related' => ! empty( $tab['show_related'] ), 'show_terms' => ! empty( $tab['show_terms'] ),
            'page_id' => absint( $tab['page_id'] ?? 0 ), 'content' => wp_kses_post( $tab['content'] ?? '' ), 'children' => array(),
            'filter_map' => bsml_sanitize_map( $tab['filter_map'] ?? '' ), 'sort' => bsml_sort_key( $tab['sort'] ?? 'newest' ),
        ) );
        $index = count( $out['tabs'] ) - 1;
        if ( in_array( $out['tabs'][$index]['type'], array( 'page', 'content' ), true ) ) {
            $child_seen = array();
            foreach ( (array) ( $tab['children'] ?? array() ) as $child ) {
                $child_id = sanitize_key( $child['id'] ?? '' );
                if ( ! $child_id || isset( $child_seen[$child_id] ) ) { continue; }
                $child_seen[$child_id] = true;
                $out['tabs'][$index]['children'][] = array_merge( bsml_content_fields( $child ), array( 'id' => $child_id, 'label' => sanitize_text_field( $child['label'] ?? $child_id ), 'enabled' => ! empty( $child['enabled'] ), 'type' => ( $child['type'] ?? '' ) === 'page' ? 'page' : 'content' ) );
            }
        }
    }
    $out['default_tab'] = sanitize_key( $raw['default_tab'] ?? '' );
    $out['pages'] = bsml_ids( $raw['pages'] ?? '' );
    $out['page_size'] = max( 6, min( 48, absint( $raw['page_size'] ?? 12 ) ) );
    foreach ( $out['colors'] as $key => $color ) { $out['colors'][ $key ] = sanitize_hex_color( $raw['colors'][ $key ] ?? '' ) ?: $color; }
    foreach ( $out['benefits'] as $key => $benefit ) {
        $row = $raw['benefits'][ $key ] ?? array();
        $out['benefits'][ $key ] = array_merge( $benefit, bsml_scope( $row ) );
        $out['benefits'][ $key ]['label'] = sanitize_text_field( $row['label'] ?? $benefit['label'] );
        foreach ( $benefit['tags'] as $n => $tag ) { $out['benefits'][ $key ]['tags'][ $n ] = sanitize_text_field( $row['tags'][ $n ] ?? $tag ); }
    }
    foreach ( $out['tiers'] as $i => $tier ) {
        $row = $raw['tiers'][ $i ] ?? array();
        foreach ( array( 'label', 'tag' ) as $field ) { $out['tiers'][ $i ][ $field ] = sanitize_text_field( $row[ $field ] ?? $tier[ $field ] ); }
        foreach ( array( 'live', 'replay' ) as $field ) { $out['tiers'][ $i ][ $field ] = min( 4, absint( $row[ $field ] ?? $tier[ $field ] ) ); }
        foreach ( array( 'live', 'replay' ) as $field ) {
            $out['tiers'][ $i ][ 'custom_' . $field ] = ! empty( $row[ 'custom_' . $field ] );
            $out['tiers'][ $i ][ $field . '_scope' ] = bsml_scope( $row[ $field . '_scope' ] ?? array() );
        }
        $out['tiers'][ $i ]['appointment'] = ! empty( $row['appointment'] );
    }
    $out['appointment_tag'] = sanitize_text_field( $raw['appointment_tag'] ?? $out['appointment_tag'] );
    foreach ( array( 'appointment_available', 'appointment_booked' ) as $key ) { $out[ $key ] = wp_kses_post( $raw[ $key ] ?? '' ); }
    return $out;
}
function bsml_sanitize_map( $value ) {
    if ( is_array( $value ) ) { $data = $value; } else { $data = json_decode( (string) $value, true ); }
    $map = array();
    foreach ( (array) $data as $term => $ids ) { if ( absint( $term ) ) { $map[ absint( $term ) ] = bsml_ids( $ids ); } }
    return $map;
}
function bsml_sort_key( $key ) { return in_array( $key, array( 'newest', 'oldest', 'az', 'za', 'price_asc', 'price_desc', 'event_asc', 'event_desc' ), true ) ? $key : 'newest'; }
function bsml_used( $tags, $mapping ) {
    $used = 0;
    foreach ( $mapping as $count => $tag ) { if ( $tag !== '' && in_array( $tag, $tags, true ) ) { $used = max( $used, (int) $count ); } }
    return $used;
}
function bsml_tier( $tags, $tiers ) {
    $found = null;
    foreach ( $tiers as $tier ) { if ( $tier['tag'] !== '' && in_array( $tier['tag'], $tags, true ) ) { $found = $tier; } }
    return $found;
}
function bsml_benefit_config( $key, $tier, $settings ) {
    $benefit = $settings['benefits'][ $key ];
    if ( $tier && ! empty( $tier[ 'custom_' . $key ] ) ) { $benefit = array_merge( $benefit, bsml_scope( $tier[ $key . '_scope' ] ?? array() ) ); }
    return $benefit;
}
