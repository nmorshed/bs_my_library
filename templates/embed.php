<?php
defined( 'ABSPATH' ) || exit;
show_admin_bar( false );
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
} else {
    $state = $GLOBALS['bsml_appointment_state']; $settings = bsml_settings();
    $content = $state['appointment']['booked'] ? $settings['appointment_booked'] : $settings['appointment_available'];
    echo apply_filters( 'the_content', $content ?: '<p>Booking instructions will appear here when configured.</p>' );
}
$content = ob_get_clean();
?><!doctype html>
<html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width, initial-scale=1"><base target="_blank">
<?php wp_head(); ?>
<style>html,body{margin:0!important;padding:0!important;background:#fff}body{padding:16px!important;box-sizing:border-box}img,video{max-width:100%;height:auto}iframe{max-width:100%}.bsml-clearing{max-width:100%;overflow-wrap:anywhere}.bsml-clearing h1{margin-top:0}a{color:#611203}</style>
</head><body <?php body_class( 'bsml-embedded' ); ?>><?php echo $content; // Trusted WordPress template/shortcode rendering. ?>
<?php wp_footer(); ?>
<script>(function(){function resize(){parent.postMessage({type:'bsml-height',height:document.body.scrollHeight},location.origin)}new ResizeObserver(resize).observe(document.body);addEventListener('load',resize);resize()})();</script>
</body></html>
