<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * EDIS_Core_API_Client — all signalapi + Emily Prime HTTP calls live here.
 *
 * Every method returns the decoded response body on success, WP_Error on failure.
 * Callers should check is_wp_error() before using the result.
 */
class EDIS_Core_API_Client {

    private string $signalapi_url;
    private string $emily_url;
    private int    $timeout = 8; // seconds

    public function __construct() {
        $this->signalapi_url = rtrim( EDIS_SIGNALAPI_URL, '/' );
        $this->emily_url     = rtrim( EDIS_EMILY_URL, '/' );
    }

    // ── Governance signals ────────────────────────────────────────────────────

    /**
     * Fetch recent governance signals for a ticker.
     *
     * @param string $ticker  e.g. "AAPL"
     * @param int    $limit   max results (default 10)
     * @param string $type    optional signal type filter
     * @return array|WP_Error
     */
    public function get_governance_signals( string $ticker, int $limit = 10, string $type = '' ) {
        if ( empty( $this->signalapi_url ) ) {
            return new WP_Error( 'edis_not_configured', '[EDIS] signalapi URL not set' );
        }
        $args = [
            'ticker' => strtoupper( $ticker ),
            'limit'  => $limit,
        ];
        if ( $type ) {
            $args['type'] = $type;
        }
        return $this->get( '/v1/governance-signals', $args );
    }

    /**
     * Fetch entity document (directors, auditor, score) for a ticker.
     *
     * @param string $ticker
     * @return array|WP_Error
     */
    public function get_entity( string $ticker ) {
        if ( empty( $this->signalapi_url ) ) {
            return new WP_Error( 'edis_not_configured', '[EDIS] signalapi URL not set' );
        }
        return $this->get( '/v1/entities/' . strtoupper( $ticker ) );
    }

    /**
     * Fetch EPS history for a ticker.
     *
     * @param string $ticker
     * @param int    $periods number of periods to return
     * @return array|WP_Error
     */
    public function get_eps( string $ticker, int $periods = 8 ) {
        if ( empty( $this->signalapi_url ) ) {
            return new WP_Error( 'edis_not_configured', '[EDIS] signalapi URL not set' );
        }
        return $this->get( '/v1/eps/' . strtoupper( $ticker ), [ 'periods' => $periods ] );
    }

    /**
     * Fetch upcoming earnings dates.
     *
     * @param string $ticker  Optional comma-separated tickers (e.g. "AAPL,MSFT"), empty = all
     * @param string $from    YYYY-MM-DD start date (empty = today)
     * @param string $to      YYYY-MM-DD end date (empty = no upper bound)
     * @param int    $limit   Max results (default 50)
     * @return array|WP_Error  { count: int, calendar: EarningsDate[] }
     */
    public function get_earnings_calendar( string $ticker = '', string $from = '', string $to = '', int $limit = 50 ) {
        if ( empty( $this->signalapi_url ) ) {
            return new WP_Error( 'edis_not_configured', '[EDIS] signalapi URL not set' );
        }
        $args = [ 'upcoming' => '1', 'limit' => $limit ];
        if ( $ticker !== '' ) {
            $args['ticker'] = strtoupper( $ticker );
        }
        if ( $from !== '' ) {
            $args['from'] = $from;
        }
        if ( $to !== '' ) {
            $args['to'] = $to;
        }
        return $this->get( '/v1/earnings-calendar', $args );
    }

    /**
     * Fetch press releases for a ticker.
     *
     * @param string $ticker  e.g. "AAPL"
     * @param int    $limit   max results (default 20)
     * @return array|WP_Error  { ticker: string, count: int, press_releases: PressRelease[] }
     */
    public function get_press_releases( string $ticker, int $limit = 20 ) {
        if ( empty( $this->signalapi_url ) ) {
            return new WP_Error( 'edis_not_configured', '[EDIS] signalapi URL not set' );
        }
        return $this->get( '/v1/press-releases/' . strtoupper( $ticker ), [ 'limit' => $limit ] );
    }

    /**
     * Fetch related entities (co-occurrence graph) for a ticker.
     * Returns { ticker, related: [{ticker, weight, last_seen}] }
     */
    public function get_related_entities( string $ticker ) {
        if ( empty( $this->signalapi_url ) ) {
            return new WP_Error( 'edis_not_configured', '[EDIS] signalapi URL not set' );
        }
        return $this->get( '/v1/entities/' . strtoupper( $ticker ) . '/related' );
    }

    // ── Emily Prime ───────────────────────────────────────────────────────────

    /**
     * Send a chat message to Emily Prime.
     *
     * @param string $message    The user's question (may include [FatBaby context] prefix)
     * @param string $session_id Stable session identifier
     * @return array|WP_Error    { reply: string }
     */
    public function ask_emily( string $message, string $session_id ) {
        if ( empty( $this->emily_url ) ) {
            return new WP_Error( 'edis_emily_not_configured', '[EDIS] Emily URL not set' );
        }
        $response = wp_remote_post(
            $this->emily_url . '/chat',
            [
                'timeout'     => 30,
                'headers'     => [ 'Content-Type' => 'application/json' ],
                'body'        => wp_json_encode( [
                    'message'    => $message,
                    'session_id' => $session_id,
                ] ),
            ]
        );
        if ( is_wp_error( $response ) ) {
            error_log( '[EDIS] emily request failed: ' . $response->get_error_message() );
            return $response;
        }
        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        if ( $code !== 200 ) {
            error_log( "[EDIS] emily returned HTTP {$code}: {$body}" );
            return new WP_Error( 'edis_emily_error', "Emily Prime returned HTTP {$code}" );
        }
        $decoded = json_decode( $body, true );
        if ( ! is_array( $decoded ) ) {
            return new WP_Error( 'edis_parse_error', '[EDIS] could not parse Emily response' );
        }
        return $decoded;
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    private function get( string $path, array $query = [] ) {
        $url = $this->signalapi_url . $path;
        if ( ! empty( $query ) ) {
            $url .= '?' . http_build_query( $query );
        }
        $response = wp_remote_get( $url, [ 'timeout' => $this->timeout ] );
        if ( is_wp_error( $response ) ) {
            error_log( '[EDIS] GET ' . $url . ' failed: ' . $response->get_error_message() );
            return $response;
        }
        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        if ( $code === 503 ) {
            return new WP_Error( 'edis_unavailable', '[EDIS] signalapi returned 503 — backend not configured' );
        }
        if ( $code !== 200 ) {
            error_log( "[EDIS] GET {$url} returned HTTP {$code}" );
            return new WP_Error( 'edis_http_error', "signalapi returned HTTP {$code}" );
        }
        $decoded = json_decode( $body, true );
        if ( ! is_array( $decoded ) ) {
            return new WP_Error( 'edis_parse_error', '[EDIS] could not decode response from ' . $path );
        }
        return $decoded;
    }
}

// Global singleton accessor.
function edis_api(): EDIS_Core_API_Client {
    static $instance = null;
    if ( $instance === null ) {
        $instance = new EDIS_Core_API_Client();
    }
    return $instance;
}
