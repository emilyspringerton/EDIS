<?php
// Variables available: $entity (array), $ticker (string)
if ( ! defined( 'ABSPATH' ) ) { exit; }
$auditor    = isset( $entity['auditor']['name'] )   ? $entity['auditor']['name']   : '';
$directors  = isset( $entity['directors'] )         ? (array) $entity['directors'] : [];
$score      = isset( $entity['signal_score'] )      ? (float) $entity['signal_score'] : 0;
?>
<div class="edis-entity" data-ticker="<?php echo esc_attr( $ticker ); ?>">
    <?php if ( $auditor ) : ?>
        <p class="edis-entity__auditor"><strong>Auditor:</strong> <?php echo esc_html( $auditor ); ?></p>
    <?php endif; ?>
    <?php if ( $score > 0 ) : ?>
        <p class="edis-entity__score"><strong>Signal score:</strong> <?php echo esc_html( number_format( $score, 2 ) ); ?></p>
    <?php endif; ?>
    <?php if ( ! empty( $directors ) ) : ?>
        <h4 class="edis-entity__directors-heading">Directors</h4>
        <ul class="edis-entity__directors">
            <?php foreach ( array_slice( $directors, 0, 8 ) as $d ) :
                $name  = isset( $d['name'] )  ? $d['name']  : '';
                $role  = isset( $d['role'] )  ? $d['role']  : '';
                $flags = isset( $d['flags'] ) ? (array) $d['flags'] : [];
            ?>
            <li>
                <?php echo esc_html( $name ); ?>
                <?php if ( $role ) : ?><em>(<?php echo esc_html( $role ); ?>)</em><?php endif; ?>
                <?php foreach ( $flags as $flag ) : ?>
                    <span class="edis-entity__flag"><?php echo esc_html( $flag ); ?></span>
                <?php endforeach; ?>
            </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
