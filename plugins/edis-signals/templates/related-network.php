<?php
// Variables available: $ticker (string), $related (array of {ticker, weight, last_seen})
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="edis-related" data-ticker="<?php echo esc_attr( $ticker ); ?>">
    <h4 class="edis-related__heading"><?php echo esc_html( $ticker ); ?> · Related Companies</h4>
    <?php if ( empty( $related ) ) : ?>
        <p class="edis-related__empty">No co-occurrence data yet.</p>
    <?php else : ?>
        <ul class="edis-related__list">
            <?php foreach ( $related as $entry ) :
                $rel_ticker = isset( $entry['ticker'] ) ? strtoupper( esc_html( $entry['ticker'] ) ) : '';
                $weight     = isset( $entry['weight'] ) ? (int) $entry['weight'] : 0;
                $last_seen  = isset( $entry['last_seen'] ) ? esc_html( substr( $entry['last_seen'], 0, 10 ) ) : '';
            ?>
            <li class="edis-related__item" data-weight="<?php echo esc_attr( $weight ); ?>">
                <span class="edis-related__ticker"><?php echo $rel_ticker; ?></span>
                <span class="edis-related__weight" title="co-occurrence count"><?php echo $weight; ?></span>
                <?php if ( $last_seen ) : ?>
                    <span class="edis-related__date"><?php echo $last_seen; ?></span>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
