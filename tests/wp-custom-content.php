<?php
/** CLI rendering regression: does not change saved content or contact records. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$_SERVER['HTTP_HOST'] = 'localhost'; $_SERVER['REQUEST_METHOD'] = 'GET';
require dirname( __DIR__, 4 ) . '/wp-load.php';
if ( ! defined( 'BSML_VERSION' ) ) { require dirname( __DIR__ ) . '/bs-my-library.php'; }
add_filter( 'pre_http_request', function () { return new WP_Error( 'fixture_blocked', 'No external HTTP in rendering test' ); } );
$failures = 0;
function verify_custom( $condition, $label ) {
    global $failures;
    if ( ! $condition ) { $failures++; }
    echo ( $condition ? 'PASS ' : 'FAIL ' ) . $label . PHP_EOL;
}
add_filter( 'the_content', function ( $content ) { return $content . '<aside>UNWANTED-CONTENT-FILTER</aside>'; }, 999 );
add_action( 'wp_head', function () { echo '<script>UNWANTED-HEAD-HOOK</script>'; }, 999 );
add_action( 'wp_footer', function () { echo '<aside>UNWANTED-FOOTER-WIDGET</aside>'; }, 999 );
add_shortcode( 'bsml_render_fixture', function () {
    wp_register_style( 'bsml-render-fixture', false );
    wp_enqueue_style( 'bsml-render-fixture' );
    wp_add_inline_style( 'bsml-render-fixture', '.fixture-content{color:purple}' );
    wp_register_script( 'bsml-render-fixture', false, array( 'jquery' ), false, true );
    wp_enqueue_script( 'bsml-render-fixture' );
    wp_add_inline_script( 'bsml-render-fixture', 'window.bsmlFixtureReady=true;' );
    return '<button class="fixture-content">Shortcode output</button>';
} );
$GLOBALS['bsml_content_section'] = array( 'type' => 'content', 'content' => "Custom introduction\n\n[bsml_render_fixture]\n\n[bs_my_library]" );
ob_start(); include BSML_DIR . 'templates/embed.php'; $html = ob_get_clean();
verify_custom( strpos( $html, '<p>Custom introduction</p>' ) !== false, 'Custom text keeps paragraph formatting' );
verify_custom( strpos( $html, '<button class="fixture-content">Shortcode output</button>' ) !== false, 'Custom shortcodes render their own output' );
verify_custom( strpos( $html, '.fixture-content{color:purple}' ) !== false && strpos( $html, 'window.bsmlFixtureReady=true;' ) !== false, 'Shortcode styles and footer scripts are retained' );
verify_custom( strpos( $html, 'jquery-core-js' ) !== false, 'Shortcode script dependencies are retained' );
verify_custom( strpos( $html, 'UNWANTED-' ) === false, 'Global page content, head, and footer injections are omitted' );
verify_custom( strpos( $html, 'assistant.thrivedesk.com' ) === false, 'Site-wide support widget is not duplicated in custom content' );
verify_custom( strpos( $html, 'bsml-root' ) === false && strpos( $html, '[bs_my_library]' ) === false, 'Nested library shortcode cannot recursively load the library' );
verify_custom( preg_match( '/<p\b[^>]*>Block text<\/p>/', bsml_render_custom_content( '<!-- wp:paragraph --><p>Block text</p><!-- /wp:paragraph -->' ) ) === 1, 'WordPress block content still renders' );
exit( $failures ? 1 : 0 );
