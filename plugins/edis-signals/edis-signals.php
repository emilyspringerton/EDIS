<?php
/**
 * Plugin Name: EDIS Signals
 * Plugin URI:  https://github.com/emilyspringerton/EDIS
 * Description: Governance signal shortcodes and widgets. Requires EDIS Core.
 * Version:     0.1.0
 * Author:      EINHORN INDUSTRIAL
 * License:     MIT
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'EDIS_SIGNALS_DIR', plugin_dir_path( __FILE__ ) );
define( 'EDIS_SIGNALS_URL', plugin_dir_url( __FILE__ ) );

register_activation_hook( __FILE__, function () {
    if ( ! is_plugin_active( 'edis-core/edis-core.php' ) ) {
        deactivate_plugins( plugin_basename( __FILE__ ) );
        wp_die( 'EDIS Signals requires EDIS Core to be installed and active.' );
    }
} );

// ── Shortcodes ────────────────────────────────────────────────────────────────

add_shortcode( 'edis_signals', 'edis_signals_shortcode' );
function edis_signals_shortcode( array $atts ): string {
    $atts = shortcode_atts( [ 'ticker' => '', 'limit' => '5', 'type' => '' ], $atts );
    $ticker = strtoupper( sanitize_text_field( $atts['ticker'] ) );
    $limit  = max( 1, min( 20, (int) $atts['limit'] ) );
    $type   = sanitize_text_field( $atts['type'] );

    if ( empty( $ticker ) ) {
        return '<p class="edis-error">edis_signals: ticker attribute required.</p>';
    }
    $data = edis_get_signals( $ticker, $limit, $type );
    if ( is_wp_error( $data ) ) {
        return '<p class="edis-error">' . esc_html( $data->get_error_message() ) . '</p>';
    }

    $signals = isset( $data['signals'] ) ? $data['signals'] : ( is_array( $data ) ? $data : [] );
    if ( empty( $signals ) ) {
        return '<p class="edis-empty">No governance signals found for ' . esc_html( $ticker ) . '.</p>';
    }

    ob_start();
    include EDIS_SIGNALS_DIR . 'templates/signals-list.php';
    return ob_get_clean();
}

add_shortcode( 'edis_entity', 'edis_entity_shortcode' );
function edis_entity_shortcode( array $atts ): string {
    $atts   = shortcode_atts( [ 'ticker' => '' ], $atts );
    $ticker = strtoupper( sanitize_text_field( $atts['ticker'] ) );
    if ( empty( $ticker ) ) {
        return '<p class="edis-error">edis_entity: ticker attribute required.</p>';
    }
    $entity = edis_get_entity( $ticker );
    if ( is_wp_error( $entity ) ) {
        return '<p class="edis-error">' . esc_html( $entity->get_error_message() ) . '</p>';
    }
    ob_start();
    include EDIS_SIGNALS_DIR . 'templates/entity-card.php';
    return ob_get_clean();
}

add_shortcode( 'edis_eps', 'edis_eps_shortcode' );
function edis_eps_shortcode( array $atts ): string {
    $atts    = shortcode_atts( [ 'ticker' => '', 'periods' => '8' ], $atts );
    $ticker  = strtoupper( sanitize_text_field( $atts['ticker'] ) );
    $periods = max( 1, min( 20, (int) $atts['periods'] ) );
    if ( empty( $ticker ) ) {
        return '<p class="edis-error">edis_eps: ticker attribute required.</p>';
    }
    $eps = edis_get_eps( $ticker, $periods );
    if ( is_wp_error( $eps ) ) {
        return '<p class="edis-error">' . esc_html( $eps->get_error_message() ) . '</p>';
    }
    ob_start();
    include EDIS_SIGNALS_DIR . 'templates/eps-table.php';
    return ob_get_clean();
}

// ── Sidebar Widget ────────────────────────────────────────────────────────────

add_action( 'widgets_init', function () {
    register_widget( 'EDIS_Signals_Widget' );
} );

class EDIS_Signals_Widget extends WP_Widget {
    public function __construct() {
        parent::__construct( 'edis_signals_widget', 'EDIS: Governance Signals', [
            'description' => 'Display recent governance signals for a ticker.',
        ] );
    }

    public function widget( $args, $instance ) {
        $ticker = ! empty( $instance['ticker'] ) ? strtoupper( sanitize_text_field( $instance['ticker'] ) ) : 'AAPL';
        $limit  = isset( $instance['limit'] ) ? max( 1, min( 10, (int) $instance['limit'] ) ) : 5;
        echo $args['before_widget'];
        echo $args['before_title'] . esc_html( $ticker ) . ' · Signals' . $args['after_title'];
        echo edis_signals_shortcode( [ 'ticker' => $ticker, 'limit' => $limit ] );
        echo $args['after_widget'];
    }

    public function form( $instance ) {
        $ticker = ! empty( $instance['ticker'] ) ? esc_attr( $instance['ticker'] ) : 'AAPL';
        $limit  = ! empty( $instance['limit'] )  ? (int) $instance['limit'] : 5;
        ?>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'ticker' ) ); ?>">Ticker</label>
            <input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'ticker' ) ); ?>"
                   name="<?php echo esc_attr( $this->get_field_name( 'ticker' ) ); ?>"
                   type="text" value="<?php echo $ticker; ?>">
        </p>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'limit' ) ); ?>">Max signals</label>
            <input class="tiny-text" id="<?php echo esc_attr( $this->get_field_id( 'limit' ) ); ?>"
                   name="<?php echo esc_attr( $this->get_field_name( 'limit' ) ); ?>"
                   type="number" min="1" max="10" value="<?php echo $limit; ?>">
        </p>
        <?php
    }

    public function update( $new_instance, $old_instance ) {
        return [
            'ticker' => strtoupper( sanitize_text_field( $new_instance['ticker'] ) ),
            'limit'  => max( 1, min( 10, (int) $new_instance['limit'] ) ),
        ];
    }
}
