<?php
defined( 'ABSPATH' ) || exit;
show_admin_bar( false );
$bsml_custom_content = isset( $GLOBALS['bsml_content_section'] ) && $GLOBALS['bsml_content_section']['type'] === 'content';
// Render shortcode/player output BEFORE wp_head so enqueued dependencies are available.
ob_start();
if ( isset( $GLOBALS['bsml_embed_id'] ) ) {
    $id = $GLOBALS['bsml_embed_id'];
    $GLOBALS['wp_query'] = new WP_Query( array( 'p' => $id, 'post_type' => 'clearing', 'post_status' => 'publish', 'cache_results' => false ) );
    $GLOBALS['post'] = get_post( $id ); setup_postdata( $GLOBALS['post'] );
    echo '<article class="bsml-clearing"><h1>' . esc_html( get_the_title( $id ) ) . '</h1>';
    if ( function_exists( '_sa_display_clearing_event_date_time' ) ) { echo '<div class="clearing-date">'; _sa_display_clearing_event_date_time( $id ); echo '</div>'; }
    if ( function_exists( '_sa_display_clearing_video' ) ) { echo '<div class="clearing-replay">'; _sa_display_clearing_video( $id ); echo '</div>'; }
    echo '<div class="clearing-text">'; the_content(); echo '</div></article>';
    wp_reset_postdata();
} elseif ( isset( $GLOBALS['bsml_content_section'] ) ) {
    $section = $GLOBALS['bsml_content_section'];
    // Avoid recursively embedding the library inside itself, including through shortcodes.
    remove_shortcode( 'bs_my_library' );
    add_shortcode( 'bs_my_library', '__return_empty_string' );
    if ( $section['type'] === 'page' ) {
        $GLOBALS['wp_query'] = new WP_Query( array( 'page_id' => $section['page_id'], 'post_type' => 'page', 'post_status' => 'publish', 'cache_results' => false ) );
        while ( have_posts() ) { the_post(); the_content(); }
    } else {
        // Custom text is not a WordPress page: do not run theme/builder content filters.
        echo bsml_render_custom_content( $section['content'] );
    }
} else {
    $state = $GLOBALS['bsml_appointment_state']; $settings = bsml_settings();
    $content = $state['appointment']['booked'] ? $settings['appointment_booked'] : $settings['appointment_available'];
    echo apply_filters( 'the_content', $content ?: '<p>Booking instructions will appear here when configured.</p>' );
}
$content = ob_get_clean();
?><!doctype html>
<html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width, initial-scale=1"><base target="_blank">
<?php
if ( $bsml_custom_content ) {
    // Print assets enqueued by the content/shortcodes without site-wide head hooks.
    wp_print_styles();
    wp_scripts()->do_head_items();
} else { wp_head(); }
?>
<style>html,body{margin:0!important;padding:0!important;background:#fff}body{padding:16px!important;box-sizing:border-box}img,video{max-width:100%;height:auto}iframe{max-width:100%}.bsml-clearing{max-width:100%;overflow-wrap:anywhere}.bsml-clearing h1{margin-top:0}a{color:#611203}</style>
</head><body <?php if ( $bsml_custom_content ) { echo 'class="bsml-embedded bsml-custom-content"'; } else { body_class( 'bsml-embedded' ); } ?>><?php echo $content; // Trusted WordPress template/shortcode rendering. ?>
<?php
if ( $bsml_custom_content ) {
    // Keep registered footer dependencies, but omit global widgets/popups/footer markup.
    wp_scripts()->do_footer_items();
} else { wp_footer(); }
?>
<script>(function(){function resize(){parent.postMessage({type:'bsml-height',height:document.body.scrollHeight},location.origin)}new ResizeObserver(resize).observe(document.body);addEventListener('load',resize);resize()})();</script>
</body></html>
