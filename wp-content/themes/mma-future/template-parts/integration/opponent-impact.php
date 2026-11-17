<?php if ( ! defined( 'ABSPATH' ) ) { exit; } $part = mmaf_score_parts( $r )['opponents'];
if ( null === $part['value'] ) { ?><div class="rk-part"><span class="rk-impact-total">—</span><span class="rk-impact-note">Not available for this score</span></div><?php return; }
mmaf_render_score_part( $part, 'Opponent quality points' );
