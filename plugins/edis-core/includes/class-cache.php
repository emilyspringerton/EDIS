<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * EDIS_Core_Cache — WP Transients wrapper for signalapi responses.
 *
 * Keys are hashed to stay under the 172-byte WP transient name limit.
 * Default TTL from EDIS_CACHE_TTL constant (default 60 seconds).
 */
class EDIS_Core_Cache {

    private static function key( string $path ): string {
        return 'edis_' . substr( md5( $path ), 0, 24 );
    }

    /**
     * Fetch from cache, or call $loader, cache the result, return it.
     *
     * @param string   $path   Cache key path (e.g. "signals/AAPL/10")
     * @param callable $loader Returns array|WP_Error
     * @param int      $ttl    TTL in seconds (0 = use EDIS_CACHE_TTL)
     * @return array|WP_Error
     */
    public static function remember( string $path, callable $loader, int $ttl = 0 ): mixed {
        $key    = self::key( $path );
        $cached = get_transient( $key );
        if ( $cached !== false ) {
            return $cached;
        }
        $result = $loader();
        if ( ! is_wp_error( $result ) ) {
            $effective_ttl = $ttl > 0 ? $ttl : EDIS_CACHE_TTL;
            set_transient( $key, $result, $effective_ttl );
        }
        return $result;
    }

    public static function forget( string $path ): void {
        delete_transient( self::key( $path ) );
    }

    public static function flush_all(): void {
        // WP doesn't have a prefix-based flush; we rely on natural expiry.
        // For manual flush: use a separate option as a version key.
        update_option( 'edis_cache_version', time() );
    }
}

// ── Cached accessors (used by shortcodes + widgets) ───────────────────────────

function edis_get_signals( string $ticker, int $limit = 10, string $type = '' ): array|WP_Error {
    $key = "signals/{$ticker}/{$limit}/{$type}";
    return EDIS_Core_Cache::remember( $key, fn() => edis_api()->get_governance_signals( $ticker, $limit, $type ), 60 );
}

function edis_get_entity( string $ticker ): array|WP_Error {
    return EDIS_Core_Cache::remember( "entity/{$ticker}", fn() => edis_api()->get_entity( $ticker ), 300 );
}

function edis_get_eps( string $ticker, int $periods = 8 ): array|WP_Error {
    return EDIS_Core_Cache::remember( "eps/{$ticker}/{$periods}", fn() => edis_api()->get_eps( $ticker, $periods ), 300 );
}
