<?php
/**
 * Plugin Name: EDIS Earnings Calendar
 * Plugin URI:  https://github.com/emilyspringerton/EDIS
 * Description: Earnings date calendar shortcode and sidebar widget. Requires EDIS Core.
 * Version:     0.1.0
 * Author:      EINHORN INDUSTRIAL
 * License:     MIT
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'EDIS_EARNINGS_DIR', plugin_dir_path( __FILE__ ) );
define( 'EDIS_EARNINGS_URL', plugin_dir_url( __FILE__ ) );

register_activation_hook( __FILE__, function () {
    if ( ! is_plugin_active( 'edis-core/edis-core.php' ) ) {
        deactivate_plugins( plugin_basename( __FILE__ ) );
        wp_die( 'EDIS Earnings Calendar requires EDIS Core to be installed and active.' );
    }
} );

// ── Styles ────────────────────────────────────────────────────────────────────

add_action( 'wp_enqueue_scripts', function () {
    wp_enqueue_style(
        'edis-earnings',
        EDIS_EARNINGS_URL . 'edis-earnings.css',
        [],
        '0.1.0'
    );
} );

// ── Shortcode: [edis_earnings_calendar] ──────────────────────────────────────
//
// Attributes:
//   ticker  — comma-separated list (e.g. "AAPL,MSFT") or "" for all watchlisted tickers
//   days    — lookahead window in days from today (default 30, max 365)
//   limit   — max rows returned (default 50, max 100)
//
// Example usage:
//   [edis_earnings_calendar]
//   [edis_earnings_calendar ticker="AAPL,MSFT,GOOGL" days="14"]

add_shortcode( 'edis_earnings_calendar', 'edis_earnings_calendar_shortcode' );
function edis_earnings_calendar_shortcode( array $atts ): string {
    $atts = shortcode_atts( [
        'ticker' => '',
        'days'   => '30',
        'limit'  => '50',
    ], $atts );

    $ticker = sanitize_text_field( $atts['ticker'] );
    $days   = max( 1, min( 365, (int) $atts['days'] ) );
    $limit  = max( 1, min( 100, (int) $atts['limit'] ) );

    $to = gmdate( 'Y-m-d', strtotime( "+{$days} days" ) );

    $data = edis_get_earnings_calendar( $ticker, $to, $limit );
    if ( is_wp_error( $data ) ) {
        return '<p class="edis-error">' . esc_html( $data->get_error_message() ) . '</p>';
    }

    $entries = isset( $data['calendar'] ) ? (array) $data['calendar'] : [];

    ob_start();
    include EDIS_EARNINGS_DIR . 'templates/calendar-table.php';
    return ob_get_clean();
}

// ── Widget ────────────────────────────────────────────────────────────────────

add_action( 'widgets_init', function () {
    register_widget( 'EDIS_Earnings_Widget' );
} );

class EDIS_Earnings_Widget extends WP_Widget {
    public function __construct() {
        parent::__construct( 'edis_earnings_widget', 'EDIS: Earnings Calendar', [
            'description' => 'Upcoming earnings dates from the FatBaby pipeline.',
        ] );
    }

    public function widget( $args, $instance ) {
        $ticker = isset( $instance['ticker'] ) ? sanitize_text_field( $instance['ticker'] ) : '';
        $days   = isset( $instance['days'] )   ? max( 1, min( 90, (int) $instance['days'] ) ) : 14;
        $limit  = isset( $instance['limit'] )  ? max( 1, min( 20, (int) $instance['limit'] ) ) : 10;

        echo $args['before_widget'];
        $title = ! empty( $instance['title'] ) ? esc_html( $instance['title'] ) : 'Upcoming Earnings';
        echo $args['before_title'] . $title . $args['after_title'];
        echo edis_earnings_calendar_shortcode( [
            'ticker' => $ticker,
            'days'   => $days,
            'limit'  => $limit,
        ] );
        echo $args['after_widget'];
    }

    public function form( $instance ) {
        $title  = ! empty( $instance['title'] )  ? esc_attr( $instance['title'] )  : 'Upcoming Earnings';
        $ticker = ! empty( $instance['ticker'] )  ? esc_attr( $instance['ticker'] ) : '';
        $days   = ! empty( $instance['days'] )    ? (int) $instance['days']         : 14;
        $limit  = ! empty( $instance['limit'] )   ? (int) $instance['limit']        : 10;
        ?>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>">Title</label>
            <input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"
                   name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>"
                   type="text" value="<?php echo $title; ?>">
        </p>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'ticker' ) ); ?>">Tickers (comma-sep, blank = all)</label>
            <input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'ticker' ) ); ?>"
                   name="<?php echo esc_attr( $this->get_field_name( 'ticker' ) ); ?>"
                   type="text" value="<?php echo $ticker; ?>" placeholder="AAPL,MSFT">
        </p>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'days' ) ); ?>">Lookahead (days)</label>
            <input class="tiny-text" id="<?php echo esc_attr( $this->get_field_id( 'days' ) ); ?>"
                   name="<?php echo esc_attr( $this->get_field_name( 'days' ) ); ?>"
                   type="number" min="1" max="90" value="<?php echo $days; ?>">
        </p>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'limit' ) ); ?>">Max rows</label>
            <input class="tiny-text" id="<?php echo esc_attr( $this->get_field_id( 'limit' ) ); ?>"
                   name="<?php echo esc_attr( $this->get_field_name( 'limit' ) ); ?>"
                   type="number" min="1" max="20" value="<?php echo $limit; ?>">
        </p>
        <?php
    }

    public function update( $new_instance, $old_instance ) {
        return [
            'title'  => sanitize_text_field( $new_instance['title'] ),
            'ticker' => sanitize_text_field( $new_instance['ticker'] ),
            'days'   => max( 1, min( 90,  (int) $new_instance['days'] ) ),
            'limit'  => max( 1, min( 20,  (int) $new_instance['limit'] ) ),
        ];
    }
}
