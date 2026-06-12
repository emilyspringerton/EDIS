<?php
// Variables available: $eps (array), $ticker (string)
if ( ! defined( 'ABSPATH' ) ) { exit; }
$rows = isset( $eps['results'] ) ? (array) $eps['results'] : ( is_array( $eps ) ? $eps : [] );
if ( empty( $rows ) ) : ?>
    <p class="edis-empty">No EPS data for <?php echo esc_html( $ticker ); ?>.</p>
<?php else : ?>
<div class="edis-eps" data-ticker="<?php echo esc_attr( $ticker ); ?>">
    <table class="edis-eps__table">
        <thead>
            <tr>
                <th>Period</th>
                <th>Actual</th>
                <th>Estimate</th>
                <th>Surprise</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $rows as $row ) :
            $period   = isset( $row['period'] )           ? $row['period']           : '';
            $actual   = isset( $row['eps_actual'] )       ? $row['eps_actual']       : null;
            $estimate = isset( $row['eps_estimate'] )     ? $row['eps_estimate']     : null;
            $surprise = isset( $row['eps_surprise_pct'] ) ? $row['eps_surprise_pct'] : null;
            $cls = '';
            if ( $surprise !== null ) {
                $cls = (float) $surprise > 0 ? 'beat' : 'miss';
            }
        ?>
            <tr class="edis-eps__row edis-eps__row--<?php echo esc_attr( $cls ); ?>">
                <td><?php echo esc_html( $period ); ?></td>
                <td><?php echo $actual   !== null ? esc_html( number_format( (float) $actual, 2 ) )   : '–'; ?></td>
                <td><?php echo $estimate !== null ? esc_html( number_format( (float) $estimate, 2 ) ) : '–'; ?></td>
                <td><?php echo $surprise !== null ? esc_html( number_format( (float) $surprise, 1 ) ) . '%' : '–'; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
