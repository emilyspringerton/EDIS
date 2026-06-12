<?php
/**
 * Plugin Name: EDIS Ask Emily
 * Plugin URI:  https://github.com/emilyspringerton/EDIS
 * Description: Ask Emily chat widget, shortcode, and WP REST proxy to Emily Prime. Requires EDIS Core.
 * Version:     0.1.0
 * Author:      EINHORN INDUSTRIAL
 * License:     MIT
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'EDIS_ASK_DIR', plugin_dir_path( __FILE__ ) );
define( 'EDIS_ASK_URL', plugin_dir_url( __FILE__ ) );

const EDIS_ASK_LIMIT_PER_DAY = 5; // free tier

register_activation_hook( __FILE__, function () {
    if ( ! is_plugin_active( 'edis-core/edis-core.php' ) ) {
        deactivate_plugins( plugin_basename( __FILE__ ) );
        wp_die( 'EDIS Ask Emily requires EDIS Core to be installed and active.' );
    }
} );

// ── WP REST endpoint: POST /wp-json/edis/v1/ask ──────────────────────────────

add_action( 'rest_api_init', function () {
    register_rest_route( 'edis/v1', '/ask', [
        'methods'             => 'POST',
        'callback'            => 'edis_ask_rest_handler',
        'permission_callback' => '__return_true',
    ] );
    register_rest_route( 'edis/v1', '/waitlist', [
        'methods'             => 'POST',
        'callback'            => 'edis_waitlist_rest_handler',
        'permission_callback' => '__return_true',
    ] );
} );

function edis_ask_rest_handler( WP_REST_Request $request ): WP_REST_Response|WP_Error {
    $body   = $request->get_json_params();
    $question = isset( $body['question'] ) ? sanitize_text_field( $body['question'] ) : '';
    $ticker   = isset( $body['ticker'] )   ? strtoupper( sanitize_text_field( $body['ticker'] ) ) : '';

    if ( empty( $question ) ) {
        return new WP_Error( 'edis_bad_request', 'question required', [ 'status' => 400 ] );
    }

    // Rate limit: 5 questions/day per IP.
    $ip      = edis_ask_client_ip();
    $day_key = 'edis_ask_' . md5( $ip ) . '_' . gmdate( 'Y-m-d' );
    $count   = (int) get_transient( $day_key );
    if ( $count >= EDIS_ASK_LIMIT_PER_DAY ) {
        return new WP_Error( 'edis_rate_limited',
            'Daily limit reached (5 questions/day). Upgrade to Emily+ for unlimited access.',
            [ 'status' => 429 ] );
    }
    set_transient( $day_key, $count + 1, DAY_IN_SECONDS );

    // Build message with optional ticker context.
    $message    = $question;
    $session_id = 'edis-' . md5( $ip );
    if ( $ticker ) {
        $ctx = edis_ask_build_ticker_context( $ticker );
        $message = $ctx
            ? "[FatBaby context for {$ticker}]\n{$ctx}\n\n[Question] {$question}"
            : "[Ticker: {$ticker}] {$question}";
    }

    $result = edis_api()->ask_emily( $message, $session_id );
    if ( is_wp_error( $result ) ) {
        return new WP_REST_Response( [ 'error' => $result->get_error_message() ], 503 );
    }
    $answer = isset( $result['reply'] ) ? $result['reply'] : ( isset( $result['answer'] ) ? $result['answer'] : '' );
    $resp   = [ 'answer' => $answer ];
    if ( $ticker ) {
        $resp['ticker'] = $ticker;
    }
    return new WP_REST_Response( $resp, 200 );
}

function edis_waitlist_rest_handler( WP_REST_Request $request ): WP_REST_Response {
    $body  = $request->get_json_params();
    $email = isset( $body['email'] ) ? sanitize_email( $body['email'] ) : '';
    if ( ! is_email( $email ) ) {
        return new WP_REST_Response( [ 'error' => 'valid email required' ], 400 );
    }
    // Store as WP option list (simple MVP — no external SMTP needed).
    $list = get_option( 'edis_waitlist', [] );
    if ( ! in_array( $email, $list, true ) ) {
        $list[] = $email;
        update_option( 'edis_waitlist', $list );
    }
    // Also mail admin.
    wp_mail( get_option( 'admin_email' ), 'EDIS waitlist signup', $email );
    return new WP_REST_Response( [ 'status' => 'ok', 'message' => "You're on the list!" ], 200 );
}

// ── Shortcode: [ask_emily] ────────────────────────────────────────────────────

add_shortcode( 'ask_emily', 'edis_ask_emily_shortcode' );
function edis_ask_emily_shortcode( array $atts ): string {
    $atts = shortcode_atts( [ 'ticker' => '' ], $atts );
    $ticker = strtoupper( sanitize_text_field( $atts['ticker'] ) );
    wp_enqueue_script(
        'edis-ask-emily',
        EDIS_ASK_URL . 'assets/ask-emily.js',
        [],
        '0.1.0',
        true
    );
    wp_localize_script( 'edis-ask-emily', 'edisAsk', [
        'apiUrl'     => rest_url( 'edis/v1/ask' ),
        'nonce'      => wp_create_nonce( 'wp_rest' ),
        'defaultTicker' => $ticker,
    ] );
    ob_start();
    ?>
    <div class="edis-ask" id="edis-ask-widget">
        <div class="edis-ask__form">
            <input type="text" class="edis-ask__ticker" placeholder="Ticker (e.g. AAPL)"
                   value="<?php echo esc_attr( $ticker ); ?>">
            <textarea class="edis-ask__question" rows="3"
                      placeholder="Ask anything about this company's governance, board, or earnings…"></textarea>
            <button class="edis-ask__submit" type="button">Ask Emily</button>
            <span class="edis-ask__limit-note">Free tier: 5 questions/day</span>
        </div>
        <div class="edis-ask__answer" style="display:none">
            <p class="edis-ask__answer-text"></p>
        </div>
        <div class="edis-ask__error" style="display:none"></div>
    </div>
    <?php
    return ob_get_clean();
}

// ── Sidebar Widget ────────────────────────────────────────────────────────────

add_action( 'widgets_init', function () {
    register_widget( 'EDIS_Ask_Emily_Widget' );
} );

class EDIS_Ask_Emily_Widget extends WP_Widget {
    public function __construct() {
        parent::__construct( 'edis_ask_emily_widget', 'EDIS: Ask Emily', [
            'description' => 'Ask Emily chat interface in the sidebar.',
        ] );
    }
    public function widget( $args, $instance ) {
        $ticker = ! empty( $instance['ticker'] ) ? sanitize_text_field( $instance['ticker'] ) : '';
        echo $args['before_widget'];
        echo $args['before_title'] . 'Ask Emily' . $args['after_title'];
        echo edis_ask_emily_shortcode( [ 'ticker' => $ticker ] );
        echo $args['after_widget'];
    }
    public function form( $instance ) {
        $ticker = ! empty( $instance['ticker'] ) ? esc_attr( $instance['ticker'] ) : '';
        ?>
        <p>
            <label>Default ticker (optional)<br>
            <input class="widefat" name="<?php echo esc_attr( $this->get_field_name( 'ticker' ) ); ?>"
                   type="text" value="<?php echo $ticker; ?>"></label>
        </p>
        <?php
    }
    public function update( $new_instance, $old_instance ) {
        return [ 'ticker' => strtoupper( sanitize_text_field( $new_instance['ticker'] ) ) ];
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function edis_ask_build_ticker_context( string $ticker ): string {
    $parts = [];
    $signals = edis_get_signals( $ticker, 5 );
    if ( ! is_wp_error( $signals ) ) {
        $rows = isset( $signals['signals'] ) ? $signals['signals'] : ( is_array( $signals ) ? $signals : [] );
        if ( ! empty( $rows ) ) {
            $lines = [ "Recent governance signals for {$ticker}:" ];
            foreach ( array_slice( $rows, 0, 5 ) as $s ) {
                $type = isset( $s['event_type'] )  ? $s['event_type']  : '';
                $date = isset( $s['filing_date'] )  ? $s['filing_date']  : '';
                $hl   = isset( $s['headline'] )     ? $s['headline']     : '';
                $sc   = isset( $s['signal_score'] ) ? number_format( (float) $s['signal_score'], 2 ) : '';
                $lines[] = "- {$type} [{$date}] score={$sc}: " . mb_substr( $hl, 0, 200 );
            }
            $parts[] = implode( "\n", $lines );
        }
    }
    $entity = edis_get_entity( $ticker );
    if ( ! is_wp_error( $entity ) ) {
        if ( ! empty( $entity['auditor']['name'] ) ) {
            $parts[] = 'Auditor: ' . $entity['auditor']['name'];
        }
        $dirs = isset( $entity['directors'] ) ? (array) $entity['directors'] : [];
        if ( ! empty( $dirs ) ) {
            $names = array_map( fn($d) => $d['name'] ?? '', array_slice( $dirs, 0, 5 ) );
            $parts[] = 'Directors: ' . implode( ', ', array_filter( $names ) );
        }
    }
    return implode( "\n", $parts );
}

function edis_ask_client_ip(): string {
    $fwd = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ( $fwd ) {
        $ip = trim( explode( ',', $fwd )[0] );
        if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            return $ip;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}
