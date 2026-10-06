<?php
/** CLI page-content rendering fixture. Creates/removes one page; external HTTP is blocked. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$_SERVER['HTTP_HOST'] = 'localhost'; $_SERVER['REQUEST_METHOD'] = 'GET';
require dirname( __DIR__, 4 ) . '/wp-load.php';
add_filter( 'pre_http_request', function () { return new WP_Error( 'fixture_blocked', 'External HTTP blocked' ); } );
$failures = 0; $page_id = 0;
function verify_page_content( $ok, $label ) { global $failures; if ( ! $ok ) { $failures++; } echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . PHP_EOL; }
add_action( 'wp_head', function () { echo '<div>UNWANTED-SITE-HEAD</div>'; } );
add_action( 'wp_footer', function () { echo '<footer>UNWANTED-SITE-FOOTER</footer>'; } );
add_shortcode( 'bsml_page_fixture', function () {
    wp_register_script( 'bsml-page-fixture', false, array(), false, true ); wp_enqueue_script( 'bsml-page-fixture' );
    wp_add_inline_script( 'bsml-page-fixture', 'window.bsmlPageContentReady=true;' );
    return '<strong>Selected page shortcode</strong>';
} );
try {
    $page_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Not a template heading', 'post_content' => '<p>Selected page text</p>[bsml_page_fixture]' ) );
    $section = array( 'type' => 'page', 'page_id' => $page_id, 'page_new_tab' => true );
    $url = bsml_section_url( $section, 'test-page' );
    verify_page_content( strpos( $url, 'bsml_embed=section' ) !== false, 'New-tab pages use the content-only viewer URL' );
    $section['page_new_tab'] = false;
    verify_page_content( $url === bsml_section_url( $section, 'test-page' ), 'Inline and new-tab pages use the same protected content rendering' );
    $section['page_content_only'] = false;
    $full_url = bsml_section_url( $section, 'test-page' );
    verify_page_content( strpos( $full_url, 'bsml_embed=section' ) !== false, 'Unchecked option retains the header-free iframe viewer URL' );
    $section['page_new_tab'] = true;
    verify_page_content( $full_url === bsml_section_url( $section, 'test-page' ), 'Iframe mode works independently of opening a new tab' );
    $settings = bsml_defaults();
    $settings['tabs'][0] = array_merge( $settings['tabs'][0], $section, array( 'children' => array( array_merge( $section, array( 'id' => 'child', 'label' => 'Child', 'enabled' => true ) ) ) ) );
    $saved = bsml_sanitize_settings( $settings );
    verify_page_content( $saved['tabs'][0]['page_content_only'] === false && $saved['tabs'][0]['children'][0]['page_content_only'] === false, 'Unchecked content-only setting survives saving for pages and submenus' );
    verify_page_content( bsml_content_fields( array() )['page_content_only'] === true, 'Older page configurations default to content-only' );
    $section['page_content_only'] = true;
    $previous_query = $GLOBALS['wp_query'];
    $direct = bsml_render_page_content( $page_id );
    verify_page_content( strpos( $direct, 'Selected page text' ) !== false && strpos( $direct, '<strong>Selected page shortcode</strong>' ) !== false && strpos( $direct, '<html' ) === false && strpos( $direct, '<iframe' ) === false, 'Direct page rendering returns content and shortcodes without a document or iframe' );
    verify_page_content( $GLOBALS['wp_query'] === $previous_query, 'Direct page rendering restores the caller query context' );
    $GLOBALS['bsml_content_section'] = $section;
    ob_start(); include BSML_DIR . 'templates/embed.php'; $html = ob_get_clean();
    verify_page_content( strpos( $html, 'Selected page text' ) !== false && strpos( $html, '<strong>Selected page shortcode</strong>' ) !== false, 'Selected page content and shortcodes render' );
    verify_page_content( strpos( $html, 'window.bsmlPageContentReady=true;' ) !== false, 'Page shortcode assets are retained' );
    verify_page_content( strpos( $html, 'UNWANTED-SITE-' ) === false && strpos( $html, 'assistant.thrivedesk.com' ) === false, 'Site-wide head/footer widgets are omitted' );
    verify_page_content( strpos( $html, '<h1' ) === false, 'No extra page-template heading is added' );
} catch ( Throwable $error ) { $failures++; echo 'FAIL ' . $error->getMessage() . PHP_EOL; }
finally { if ( $page_id ) { wp_delete_post( $page_id, true ); } }
exit( $failures ? 1 : 0 );
