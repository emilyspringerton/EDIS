<?php
// Variables: $press_releases (array), $ticker (string)
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( empty( $press_releases ) ) : ?>
    <p class="edis-empty">No press releases found for <?php echo esc_html( $ticker ); ?>.</p>
<?php return;
endif;
?>
<div class="edis-press-releases">
    <?php foreach ( $press_releases as $pr ) :
        $url         = isset( $pr['document_url'] ) ? esc_url( $pr['document_url'] ) : '';
        $snippet     = isset( $pr['snippet'] )      ? esc_html( $pr['snippet'] )      : '';
        $filing_date = isset( $pr['filing_date'] ) && $pr['filing_date']
                       ? esc_html( $pr['filing_date'] ) : '';
        $persisted   = isset( $pr['persisted_at'] ) ? $pr['persisted_at'] : '';
        $display_date = $filing_date ?: ( $persisted ? esc_html( substr( $persisted, 0, 10 ) ) : '' );
        $snippet_short = $snippet ? ( mb_strlen( $snippet ) > 240 ? mb_substr( $snippet, 0, 240 ) . '…' : $snippet ) : '';
        $first_line  = $snippet ? strtok( $snippet, "\n" ) : '';
        $title       = $first_line ? ( mb_strlen( $first_line ) > 120 ? mb_substr( $first_line, 0, 120 ) . '…' : $first_line ) : 'Press Release';
    ?>
    <div class="edis-pr">
        <div class="edis-pr__meta">
            <?php if ( $display_date ) : ?>
                <span class="edis-pr__date"><?php echo $display_date; ?></span>
            <?php endif; ?>
        </div>
        <p class="edis-pr__title">
            <?php if ( $url ) : ?>
                <a class="edis-pr__link" href="<?php echo $url; ?>" target="_blank" rel="noopener noreferrer">
                    <?php echo esc_html( $title ); ?>
                </a>
            <?php else : ?>
                <?php echo esc_html( $title ); ?>
            <?php endif; ?>
        </p>
        <?php if ( $snippet_short && $snippet_short !== $title ) : ?>
            <p class="edis-pr__snippet"><?php echo $snippet_short; ?></p>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
