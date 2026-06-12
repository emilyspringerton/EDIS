<?php
// Variables available: $signals (array), $ticker (string)
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="edis-signals" data-ticker="<?php echo esc_attr( $ticker ); ?>">
    <ul class="edis-signals__list">
    <?php foreach ( $signals as $sig ) :
        $event_type  = isset( $sig['event_type'] )  ? $sig['event_type']  : '';
        $headline    = isset( $sig['headline'] )     ? $sig['headline']    : '';
        $score       = isset( $sig['signal_score'] ) ? (float) $sig['signal_score'] : 0;
        $filing_date = isset( $sig['filing_date'] )  ? $sig['filing_date'] : '';
        $score_class = $score >= 0.7 ? 'high' : ( $score >= 0.4 ? 'medium' : 'low' );
    ?>
        <li class="edis-signals__item edis-signals__item--<?php echo esc_attr( $score_class ); ?>">
            <span class="edis-signals__type"><?php echo esc_html( str_replace( '_', ' ', $event_type ) ); ?></span>
            <span class="edis-signals__date"><?php echo esc_html( $filing_date ); ?></span>
            <span class="edis-signals__score" title="Signal score"><?php echo esc_html( number_format( $score, 2 ) ); ?></span>
            <p class="edis-signals__headline"><?php echo esc_html( $headline ); ?></p>
        </li>
    <?php endforeach; ?>
    </ul>
</div>
