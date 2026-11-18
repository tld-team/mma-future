<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

use MMAF\DataEngine\Services\Community\Badge;
use MMAF\DataEngine\Services\Community\Ratings;
use MMAF\DataEngine\Services\Community\Reputation;

/**
 * The reader's own level card on /account/profile/. Expects $mmafc_level from Reputation::breakdown(); the script
 * refreshes the marked parts after a save, from the same data the REST route returns.
 */
$mmafc_l    = $mmafc_level['level'];
$mmafc_part = $mmafc_level['breakdown'];
?>
<section class="mmafc-card mmafc-level" data-mmafc-level aria-labelledby="mmafc-level-title">
	<div class="mmafc-level__head">
		<span class="mmafc-level__badge" data-mmafc-level-badge><?php echo Badge::svg( $mmafc_l, 'lg' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from integers and an escaped label. ?></span>
		<div class="mmafc-level__body">
			<h2 class="mmafc-level__title" id="mmafc-level-title" data-mmafc-level-name>Level <?php echo (int) $mmafc_l['level']; ?> · <?php echo esc_html( $mmafc_l['name'] ); ?></h2>
			<p class="mmafc-level__xp"><span data-mmafc-level-xp><?php echo (int) $mmafc_l['xp']; ?> XP</span> · <span data-mmafc-level-next><?php echo null === $mmafc_l['next'] ? 'Top level' : (int) $mmafc_l['to_next'] . ' XP to ' . esc_html( (string) $mmafc_l['next_name'] ); ?></span></p>
			<div class="mmafc-meter" role="progressbar" aria-label="Progress to the next level" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo (int) round( $mmafc_l['progress'] * 100 ); ?>" data-mmafc-level-meter><span class="mmafc-meter__fill" style="width:<?php echo esc_attr( (string) round( $mmafc_l['progress'] * 100, 1 ) ); ?>%"></span></div>
		</div>
	</div>
	<dl class="mmafc-level__parts">
		<div><dt>Complete profile</dt><dd data-mmafc-level-part="profile"><?php echo (int) $mmafc_part['profile']; ?> / <?php echo (int) Reputation::PROFILE_POINTS; ?></dd></div>
		<div><dt>Full Top 10</dt><dd data-mmafc-level-part="top10"><?php echo (int) $mmafc_part['top10']; ?> / <?php echo (int) Reputation::TOP10_POINTS; ?></dd></div>
		<div><dt>Props on your Top 10</dt><dd data-mmafc-level-part="props"><?php echo (int) $mmafc_part['props']; ?></dd></div>
		<div><dt>Time on MMA Future</dt><dd data-mmafc-level-part="tenure"><?php echo (int) $mmafc_part['tenure']; ?></dd></div>
	</dl>
	<p class="mmafc-hint">A photo, a name and a bio earn <?php echo (int) Reputation::PROFILE_POINTS; ?> XP, a full Top 10 earns <?php echo (int) Reputation::TOP10_POINTS; ?>. Other fans give props to your Top 10: Solid, Sharp or Elite for 1, 2 or 3 XP. Every three months on the site adds 10, up to 80. From level <?php echo (int) Ratings::MIN_LEVEL; ?> you can give props yourself.</p>
</section>
