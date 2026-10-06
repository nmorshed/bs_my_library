<?php
defined( 'ABSPATH' ) || exit;

/**
 * Edit calendar labels, descriptions and URLs here.
 * Place [bsml_appointment_calendars] in the available appointment content.
 * Membership eligibility is enforced by the containing membership section.
 */
function bsml_appointment_calendars_shortcode() {
    $calendars = array(
        array( 'label' => 'Chris Williams', 'description' => '', 'url' => 'https://app.acuityscheduling.com/schedule.php?owner=14229738&appointmentType=8538331' ),
        array( 'label' => 'Michael Higgins', 'description' => '', 'url' => 'https://app.acuityscheduling.com/schedule.php?owner=14229738&appointmentType=42259484' ),
        array( 'label' => 'Paro Simone', 'description' => '', 'url' => 'https://app.acuityscheduling.com/schedule.php?owner=14229738&appointmentType=79844709', 'payment' => true ),
        array( 'label' => 'Saroja Nimmagadda (Rosie)', 'description' => '', 'url' => 'https://app.acuityscheduling.com/schedule.php?owner=14229738&appointmentType=43071107' ),
        array( 'label' => 'Tina von Schachtmeyer', 'description' => '', 'url' => 'https://app.acuityscheduling.com/schedule.php?owner=14229738&appointmentType=41624524' ),
        array( 'label' => 'Tracey McPhee', 'description' => '', 'url' => 'https://app.acuityscheduling.com/schedule.php?owner=14229738&appointmentType=22030756' ),
    );
    wp_enqueue_style( 'bsml-appointment-calendars', BSML_URL . 'assets/appointment-calendars.css', array(), BSML_VERSION );
    wp_enqueue_script( 'bsml-appointment-calendars', BSML_URL . 'assets/appointment-calendars.js', array(), BSML_VERSION, true );
    $html = '<div class="bsml-appointment-calendars">';
    foreach ( $calendars as $calendar ) {
        $html .= '<details class="bsml-calendar"><summary>' . esc_html( $calendar['label'] ) . '</summary><div class="bsml-calendar-panel">';
        if ( ! empty( $calendar['description'] ) ) {
            $html .= '<div class="bsml-calendar-description">' . wp_kses_post( $calendar['description'] ) . '</div>';
        }
        $html .= '<div class="bsml-calendar-mount" data-calendar-url="' . esc_url( $calendar['url'] ) . '" data-calendar-title="' . esc_attr( $calendar['label'] . ' appointment calendar' ) . '" data-calendar-payment="' . ( empty( $calendar['payment'] ) ? '0' : '1' ) . '"></div>';
        $html .= '<p><a href="' . esc_url( $calendar['url'] ) . '" target="_blank" rel="noopener">Open this calendar in a new tab</a></p></div></details>';
    }
    return $html . '</div>';
}
add_shortcode( 'bsml_appointment_calendars', 'bsml_appointment_calendars_shortcode' );
