<?php
// Variables: $entries (array of EarningsDate records), $ticker (string), $days (int)
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( empty( $entries ) ) : ?>
    <p class="edis-empty edis-earnings__empty">
        No upcoming earnings dates<?php echo $ticker ? ' for ' . esc_html( strtoupper( $ticker ) ) : ''; ?>
        in the next <?php echo esc_html( $days ); ?> day<?php echo $days !== 1 ? 's' : ''; ?>.
    </p>
<?php return;
endif;

// Group by report_date for a calendar-style layout.
$by_date = [];
foreach ( $entries as $row ) {
    $date = isset( $row['report_date'] ) ? $row['report_date'] : '';
    if ( $date ) {
        $by_date[ $date ][] = $row;
    }
}
ksort( $by_date );
$today     = gmdate( 'Y-m-d' );
$tomorrow  = gmdate( 'Y-m-d', strtotime( '+1 day' ) );
?>
<div class="edis-earnings">
    <table class="edis-earnings__table">
        <thead>
            <tr>
                <th class="edis-earnings__col-date">Date</th>
                <th class="edis-earnings__col-ticker">Ticker</th>
                <th class="edis-earnings__col-issuer">Company</th>
                <th class="edis-earnings__col-period">Period</th>
                <th class="edis-earnings__col-timing">Timing</th>
                <th class="edis-earnings__col-status">Confidence</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $by_date as $date => $rows ) :
            $is_today    = ( $date === $today );
            $is_tomorrow = ( $date === $tomorrow );
            $ts          = strtotime( $date );
            $date_label  = $is_today    ? 'Today'
                         : ( $is_tomorrow ? 'Tomorrow'
                         : date_i18n( 'D M j', $ts ) );
            $row_count   = count( $rows );
            $date_output = false;
            foreach ( $rows as $row ) :
                $ticker_val  = isset( $row['ticker'] )          ? strtoupper( esc_html( $row['ticker'] ) ) : '–';
                $issuer      = isset( $row['issuer'] )          ? esc_html( $row['issuer'] )               : '';
                $quarter     = isset( $row['fiscal_quarter'] )  ? esc_html( $row['fiscal_quarter'] )       : '';
                $year        = isset( $row['fiscal_year'] )     ? (int) $row['fiscal_year']               : 0;
                $period      = trim( $quarter . ( $year ? ' ' . $year : '' ) );
                $status      = isset( $row['status'] )          ? $row['status']                           : '';
                $bm          = isset( $row['before_market'] )   ? $row['before_market']                    : null;
                $timing      = $bm === true ? 'BMO' : ( $bm === false ? 'AMC' : '–' );
                $timing_cls  = $bm === true ? 'bmo' : ( $bm === false ? 'amc' : 'unknown' );
                $status_cls  = in_array( $status, [ 'confirmed', 'announced', 'backfilled' ], true )
                               ? $status : 'unknown';
                $status_label = [
                    'confirmed'  => 'Confirmed',
                    'announced'  => 'Announced',
                    'backfilled' => 'Approx.',
                ][ $status ] ?? ucfirst( $status );
                $row_cls = $is_today ? ' edis-earnings__row--today' : '';
            ?>
            <tr class="edis-earnings__row<?php echo $row_cls; ?>">
                <?php if ( ! $date_output ) : $date_output = true; ?>
                <td class="edis-earnings__date" rowspan="<?php echo esc_attr( $row_count ); ?>">
                    <span class="edis-earnings__date-label<?php echo $is_today ? ' edis-earnings__date-label--today' : ''; ?>">
                        <?php echo esc_html( $date_label ); ?>
                    </span>
                    <span class="edis-earnings__date-sub"><?php echo esc_html( $date ); ?></span>
                </td>
                <?php endif; ?>
                <td class="edis-earnings__ticker"><?php echo $ticker_val; ?></td>
                <td class="edis-earnings__issuer"><?php echo $issuer ?: '–'; ?></td>
                <td class="edis-earnings__period"><?php echo $period ?: '–'; ?></td>
                <td class="edis-earnings__timing edis-earnings__timing--<?php echo esc_attr( $timing_cls ); ?>">
                    <?php echo esc_html( $timing ); ?>
                </td>
                <td class="edis-earnings__status">
                    <span class="edis-earnings__badge edis-earnings__badge--<?php echo esc_attr( $status_cls ); ?>">
                        <?php echo esc_html( $status_label ); ?>
                    </span>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="edis-earnings__foot">
        Showing <?php echo esc_html( count( $entries ) ); ?> earnings date<?php echo count( $entries ) !== 1 ? 's' : ''; ?>.
        Confirmed = actual 8-K filing date. Announced = company press release. Approx. = derived from report date.
    </p>
</div>
