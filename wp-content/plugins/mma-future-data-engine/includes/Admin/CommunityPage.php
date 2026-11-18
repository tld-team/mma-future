<?php
namespace MMAF\DataEngine\Admin;

use MMAF\DataEngine\Services\Community\Avatars;
use MMAF\DataEngine\Services\Community\Config;
use MMAF\DataEngine\Services\Community\FanRanking;
use MMAF\DataEngine\Services\Community\Reputation;
use MMAF\DataEngine\Services\Notifications\FollowersReport;
use MMAF\DataEngine\Services\Community\Profiles;
use MMAF\DataEngine\Services\Community\Schema;
use MMAF\DataEngine\Support\Capabilities;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Reader profiles: who has one, and the two moderation actions a profile can need (hide it, remove its photo). */
final class CommunityPage {
	private const PAGE_SLUG = 'mmaf-community';
	private const PER_PAGE  = 50;
	/** Not "mmaf_": admin POSTs with that prefix take the canonical write guard, which has nothing to do with profiles. */
	private const ACTION = 'mmafc_profile_moderate';
	private const AREAS_ACTION = 'mmafc_community_areas';
	/** What each area turns on, in the order they build on each other. */
	private const AREA_LABELS = array(
		'profiles' => array( 'Profili', 'Profil čitaoca, javna strana /fan/{handle}/ i tabovi naloga.' ),
		'fan'        => array( 'Fan Top 10', 'My Top 10, prikaz Fans na rang listi i fan rang na strani borca. Traži profile.' ),
		'reputation' => array( 'Kredibilitet i nivoi', 'XP, Scout nivoi i bedž na profilu; uz Fan Top 10 i props na liste. Traži šemu 1.21.0.' ),
		'comments'   => array( 'Komentari', 'Diskusija ispod članaka, sa glasovima gore/dole i prijavom. Pišu samo čitaoci sa profilom; stari WordPress obrazac je tada zatvoren za sve osim moderatora.' ),
	);
	private const EXPORT_ACTION = 'mmafc_community_export';

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'moderate' ) );
		add_action( 'admin_post_' . self::AREAS_ACTION, array( self::class, 'save_areas' ) );
		add_action( 'admin_post_' . self::EXPORT_ACTION, array( self::class, 'export' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) { wp_die( 'Forbidden', '', array( 'response' => 403 ) ); }
		echo '<div class="wrap"><h1>Zajednica</h1>';
		if ( ! Config::schema_ready() ) { echo '<div class="notice notice-warning"><p>Tabele zajednice još ne postoje. Pokrenite migraciju baze na šemu ' . esc_html( Config::SCHEMA_VERSION ) . '.</p></div></div>'; return; }
		if ( ! Config::feature_enabled() ) { self::render_disabled(); }
		elseif ( ! Config::area_enabled( 'profiles' ) ) { echo '<div class="notice notice-info"><p>Profili su isključeni prekidačem ispod.</p></div>'; }
		if ( ! empty( $_GET['mmafc_done'] ) ) { echo '<div class="notice notice-success is-dismissible"><p>Izmena je sačuvana.</p></div>'; }
		self::render_areas();

		global $wpdb;
		$table  = Schema::tables()['profiles'];
		$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		$paged  = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$where  = '1=1';
		$args   = array();
		if ( '' !== $search ) {
			$like  = '%' . $wpdb->esc_like( $search ) . '%';
			$where = '(p.handle LIKE %s OR p.display_name LIKE %s OR u.user_email LIKE %s)';
			$args  = array( $like, $like, $like );
		}
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names and a fixed WHERE template.
		$count_sql = "SELECT COUNT(*) FROM {$table} p LEFT JOIN {$wpdb->users} u ON u.ID=p.user_id WHERE {$where}";
		$total     = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) ) : $wpdb->get_var( $count_sql ) );
		$rows      = $wpdb->get_results( $wpdb->prepare( "SELECT p.*, u.user_email, u.user_registered FROM {$table} p LEFT JOIN {$wpdb->users} u ON u.ID=p.user_id WHERE {$where} ORDER BY p.created_at DESC, p.id DESC LIMIT %d OFFSET %d", array_merge( $args, array( self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE ) ) ), ARRAY_A ) ?: array();
		$levels    = Config::reputation_schema_ready();
		$summary   = $wpdb->get_row( "SELECT COUNT(*) AS profiles, SUM(avatar_path<>'') AS photos, SUM(bio<>'') AS bios, SUM(status='hidden') AS hidden FROM {$table}", ARRAY_A ) ?: array();
		// phpcs:enable
		?>
		<p>Javni profili čitalaca. Skriven profil se ne prikazuje posetiocima, a vlasnik i dalje može da ga uređuje. Profil isključen iz rangiranja ostaje javan i vlasnik ništa ne primećuje, ali se njegov Top 10 i props koje daje ne računaju.</p>
		<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::EXPORT_ACTION ), self::EXPORT_ACTION ) ); ?>">Izvezi profile (CSV)</a></p>
		<div style="display:grid;grid-template-columns:repeat(4,minmax(150px,1fr));gap:12px;max-width:960px;margin:16px 0">
			<?php foreach ( array( 'Profila' => $summary['profiles'] ?? 0, 'Sa fotografijom' => $summary['photos'] ?? 0, 'Sa biografijom' => $summary['bios'] ?? 0, 'Skrivenih' => $summary['hidden'] ?? 0 ) as $label => $value ) : ?>
				<div class="card" style="margin:0;padding:12px 16px"><h2 style="margin:0"><?php echo esc_html( (string) (int) $value ); ?></h2><p style="margin:4px 0 0"><?php echo esc_html( $label ); ?></p></div>
			<?php endforeach; ?>
		</div>
		<form method="get" style="margin:12px 0">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
			<label class="screen-reader-text" for="mmafc-search">Pretraga profila</label>
			<input type="search" id="mmafc-search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Handle, ime ili email">
			<button class="button">Pretraži</button>
			<?php if ( '' !== $search ) : ?><a class="button-link" style="margin-left:8px" href="<?php echo esc_url( self::page_url() ); ?>">Poništi</a><?php endif; ?>
		</form>
		<table class="widefat striped">
			<thead><tr><th style="width:56px">Foto</th><th>Handle</th><th>Ime</th><th>Email</th><?php if ( $levels ) : ?><th>Nivo</th><?php endif; ?><th>Status</th><th>Napravljen</th><th>Akcije</th></tr></thead>
			<tbody>
			<?php if ( ! $rows ) : ?>
				<tr><td colspan="<?php echo $levels ? 8 : 7; ?>"><?php echo '' !== $search ? 'Nema profila za ovu pretragu.' : 'Još nema profila.'; ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $rows as $row ) : $hidden = 'hidden' === $row['status']; $shadow = 'shadow' === $row['status']; ?>
				<tr>
					<td><img src="<?php echo esc_url( Avatars::url( (string) $row['avatar_path'] ) ); ?>" alt="" width="40" height="40" style="border-radius:50%;object-fit:cover"></td>
					<td><?php if ( $hidden ) : ?>@<?php echo esc_html( $row['handle'] ); ?><?php else : ?><a href="<?php echo esc_url( Profiles::url( (string) $row['handle'] ) ); ?>" target="_blank" rel="noopener">@<?php echo esc_html( $row['handle'] ); ?></a><?php endif; ?></td>
					<td><?php echo esc_html( $row['display_name'] ); ?><?php if ( '' !== (string) $row['bio'] ) : ?><br><span class="description"><?php echo esc_html( wp_html_excerpt( (string) $row['bio'], 120, '…' ) ); ?></span><?php endif; ?></td>
					<td><?php echo esc_html( (string) ( $row['user_email'] ?? '—' ) ); ?></td>
					<?php if ( $levels ) : $lv = Reputation::summary( (int) $row['user_id'], $row ); ?><td><?php echo (int) $lv['level']['level']; ?> · <?php echo esc_html( $lv['level']['name'] ); ?><br><span class="description"><?php echo (int) $lv['xp']; ?> XP</span></td><?php endif; ?>
					<td><?php echo $hidden ? '<strong>Skriven</strong>' : ( $shadow ? '<strong>Isključen iz rangiranja</strong>' : 'Aktivan' ); ?></td>
					<td><?php echo esc_html( mysql2date( 'Y-m-d', (string) $row['created_at'] ) ); ?></td>
					<td>
						<?php if ( $hidden || $shadow ) : self::action_button( (int) $row['user_id'], 'show', $hidden ? 'Prikaži' : 'Vrati u rangiranje' ); else : self::action_button( (int) $row['user_id'], 'hide', 'Sakrij' ); self::action_button( (int) $row['user_id'], 'shadow', 'Isključi iz rangiranja' ); endif; ?>
						<?php if ( '' !== (string) $row['avatar_path'] ) { self::action_button( (int) $row['user_id'], 'remove_avatar', 'Ukloni foto' ); } ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( (string) paginate_links( array( 'base' => add_query_arg( 'paged', '%#%', self::page_url( array( 's' => $search ) ) ), 'format' => '', 'current' => $paged, 'total' => $pages ) ) ) . '</div></div>';
		}
		self::render_fan_ranking();
		if ( $levels ) { self::render_reputation(); }
		echo '</div>';
	}

	/** The top of the fan ranking as it stands, with the number of lists behind it. */
	private static function render_fan_ranking(): void {
		$ranking   = new FanRanking();
		$standings = $ranking->standings();
		$top       = array_slice( $standings['rows'], 0, 20 );
		$fighters  = $ranking->fighters( array_column( $top, 'fighter_id' ) );
		echo '<h2>Fan ranking</h2><p>Na osnovu ' . (int) $standings['lists'] . ' lista koje se računaju (potvrđen email, nalog stariji od ' . (int) Config::fan_min_account_hours() . 'h, profil nije skriven ni isključen).</p>';
		echo '<table class="widefat striped" style="max-width:960px"><thead><tr><th>#</th><th>Borac</th><th>Poeni</th><th>Na listama</th><th>Prvo mesto</th></tr></thead><tbody>';
		if ( ! $top ) { echo '<tr><td colspan="5">Još nema lista koje se računaju.</td></tr>'; }
		foreach ( $top as $index => $row ) {
			$name = (string) ( $fighters[ $row['fighter_id'] ]['display_name'] ?? ( 'Borac #' . $row['fighter_id'] ) );
			echo '<tr><td>' . (int) ( $index + 1 ) . '</td><td>' . esc_html( $name ) . '</td><td>' . (int) $row['points'] . '</td><td>' . (int) $row['picks'] . '</td><td>' . (int) $row['firsts'] . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/** Who has the most XP, and who gave the most props lately: the place a ring of accounts rating each other shows up. */
	private static function render_reputation(): void {
		global $wpdb;
		$t = Schema::tables();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only.
		$leaders = $wpdb->get_results( "SELECT p.user_id, p.handle, p.status, p.rep_points, (SELECT COUNT(*) FROM {$t['ratings']} r WHERE r.owner_user_id=p.user_id) AS received FROM {$t['profiles']} p ORDER BY p.rep_points DESC, p.id ASC LIMIT 20", ARRAY_A ) ?: array();
		$raters  = $wpdb->get_results( $wpdb->prepare( "SELECT r.rater_user_id, p.handle, p.status, COUNT(*) AS given, SUM(r.value=3) AS elite, COUNT(DISTINCT r.owner_user_id) AS owners FROM {$t['ratings']} r LEFT JOIN {$t['profiles']} p ON p.user_id=r.rater_user_id WHERE r.updated_at>=%s GROUP BY r.rater_user_id, p.handle, p.status ORDER BY given DESC LIMIT 20", gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ) ), ARRAY_A ) ?: array();
		// phpcs:enable
		echo '<h2>Kredibilitet</h2><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(420px,1fr));gap:16px;max-width:1400px">';
		echo '<div><h3>Najviše XP</h3><table class="widefat striped"><thead><tr><th>Handle</th><th>Nivo</th><th>XP</th><th>Primljeni props</th></tr></thead><tbody>';
		if ( ! $leaders ) { echo '<tr><td colspan="4">Još nema profila.</td></tr>'; }
		foreach ( $leaders as $row ) {
			$lv = Reputation::summary( (int) $row['user_id'], $row );
			echo '<tr><td>@' . esc_html( (string) $row['handle'] ) . ( 'active' !== $row['status'] ? ' <span class="description">(' . esc_html( (string) $row['status'] ) . ')</span>' : '' ) . '</td><td>' . (int) $lv['level']['level'] . ' · ' . esc_html( $lv['level']['name'] ) . '</td><td>' . (int) $lv['xp'] . '</td><td>' . (int) $row['received'] . '</td></tr>';
		}
		echo '</tbody></table></div>';
		echo '<div><h3>Najviše datih props, poslednjih 7 dana</h3><table class="widefat striped"><thead><tr><th>Handle</th><th>Dato</th><th>Od toga Elite</th><th>Različitih lista</th></tr></thead><tbody>';
		if ( ! $raters ) { echo '<tr><td colspan="4">Nema props u poslednjih 7 dana.</td></tr>'; }
		foreach ( $raters as $row ) {
			echo '<tr><td>' . ( null !== $row['handle'] ? '@' . esc_html( (string) $row['handle'] ) : 'Nalog #' . (int) $row['rater_user_id'] ) . ( null !== $row['status'] && 'active' !== $row['status'] ? ' <span class="description">(' . esc_html( (string) $row['status'] ) . ')</span>' : '' ) . '</td><td>' . (int) $row['given'] . '</td><td>' . (int) $row['elite'] . '</td><td>' . (int) $row['owners'] . '</td></tr>';
		}
		echo '</tbody></table><p class="description">Mnogo Elite ocena malom broju lista može značiti dogovor naloga. Takav nalog isključite iz rangiranja: profil ostaje, a njegove ocene i Top 10 prestaju da se računaju.</p></div></div>';
	}

	/** Every profile as CSV, for Excel; cells a spreadsheet would run as a formula are neutralised. */
	public static function export(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) { wp_die( 'Forbidden', '', array( 'response' => 403 ) ); }
		check_admin_referer( self::EXPORT_ACTION );
		global $wpdb;
		$t      = Schema::tables();
		$levels = Config::reputation_schema_ready();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only.
		$rows   = $wpdb->get_results( "SELECT p.*, u.user_email FROM {$t['profiles']} p LEFT JOIN {$wpdb->users} u ON u.ID=p.user_id ORDER BY p.created_at, p.id", ARRAY_A ) ?: array();
		$out    = array();
		foreach ( $rows as $row ) {
			$line = array( '@' . $row['handle'], $row['display_name'], (string) ( $row['user_email'] ?? '' ), $row['status'], '' !== (string) $row['avatar_path'] ? 'da' : 'ne', '' !== (string) $row['bio'] ? 'da' : 'ne', mysql2date( 'Y-m-d', (string) $row['created_at'] ) );
			if ( $levels ) { $lv = Reputation::summary( (int) $row['user_id'], $row ); $line[] = $lv['level']['level'] . ' ' . $lv['level']['name']; $line[] = $lv['xp']; }
			$out[] = $line;
		}
		$headers = array( 'Handle', 'Ime', 'Email', 'Status', 'Foto', 'Biografija', 'Napravljen' );
		if ( $levels ) { $headers[] = 'Nivo'; $headers[] = 'XP'; }
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="mma-future-profili-' . gmdate( 'Y-m-d' ) . '.csv"' );
		echo FollowersReport::csv( $headers, $out ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV, not HTML.
		exit;
	}

	/**
	 * The switches below do nothing while the feature itself is off, so say exactly what keeps it off and how to fix it.
	 */
	private static function render_disabled(): void {
		$texts = array(
			'schema'           => 'Baza još nije migrirana na šemu ' . esc_html( Config::SCHEMA_VERSION ) . '.',
			'constant_missing' => 'Konstanta <code>MMAF_COMMUNITY_ENABLED</code> nije definisana u <code>wp-config.php</code>.',
			'constant_value'   => 'Konstanta <code>MMAF_COMMUNITY_ENABLED</code> postoji, ali ima vrednost <code>' . esc_html( defined( 'MMAF_COMMUNITY_ENABLED' ) ? var_export( MMAF_COMMUNITY_ENABLED, true ) : '' ) . '</code>. Mora biti <code>true</code>, bez navodnika.', // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
			'accounts'         => 'Nalozi čitalaca su isključeni: zajednica traži i <code>MMAF_NOTIFICATIONS_ENABLED</code> = <code>true</code>.',
			'filter'           => 'Isključuje je filter <code>mmaf_community_feature_enabled</code> (kod u nekom pluginu ili temi).',
		);
		echo '<div class="notice notice-warning"><p><strong>Zajednica je isključena, pa čitaoci ne vide ništa od nje</strong> (profil, Top 10, nivoi). Prekidači oblasti ispod počinju da važe tek kada se ovo reši:</p><ul style="list-style:disc;margin-left:20px">';
		foreach ( Config::disabled_reasons() as $reason ) { echo '<li>' . ( $texts[ $reason ] ?? esc_html( $reason ) ) . '</li>'; } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup, dynamic parts escaped above.
		echo '</ul>';
		if ( array_intersect( array( 'constant_missing', 'constant_value' ), Config::disabled_reasons() ) ) {
			echo '<p>U <code>wp-config.php</code> dodajte ovu liniju <strong>iznad</strong> reda <code>/* That\'s all, stop editing! */</code> (linija ispod njega se učitava prekasno):</p><pre style="background:#f6f7f7;padding:8px 12px;display:inline-block">define( \'MMAF_COMMUNITY_ENABLED\', true );</pre>';
		}
		echo '</div>';
	}

	/** One checkbox per area. Saved to the `mmaf_community_areas` option, which the `mmaf_community_area_enabled` filter can still override. */
	private static function render_areas(): void {
		$on = Config::areas();
		echo '<h2>Oblasti</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( self::AREAS_ACTION ) . '">';
		wp_nonce_field( self::AREAS_ACTION );
		echo '<fieldset><legend class="screen-reader-text">Uključene oblasti</legend>';
		foreach ( Config::AREAS as $area ) {
			$label = self::AREA_LABELS[ $area ] ?? array( $area, '' );
			echo '<p><label><input type="checkbox" name="areas[]" value="' . esc_attr( $area ) . '"' . checked( in_array( $area, $on, true ), true, false ) . '> <strong>' . esc_html( $label[0] ) . '</strong></label>' . ( '' !== $label[1] ? ' <span class="description">' . esc_html( $label[1] ) . '</span>' : '' ) . '</p>';
		}
		echo '</fieldset>';
		submit_button( 'Sačuvaj oblasti', 'secondary', 'submit', false );
		echo '</form>';
	}

	public static function save_areas(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) { wp_die( 'Forbidden', '', array( 'response' => 403 ) ); }
		check_admin_referer( self::AREAS_ACTION );
		$picked = array_map( 'sanitize_key', (array) wp_unslash( $_POST['areas'] ?? array() ) );
		Config::save_areas( $picked );
		wp_safe_redirect( self::page_url( array( 'mmafc_done' => 1 ) ) );
		exit;
	}

	public static function moderate(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) { wp_die( 'Forbidden', '', array( 'response' => 403 ) ); }
		check_admin_referer( self::ACTION );
		$user_id  = absint( $_POST['user_id'] ?? 0 );
		$task     = sanitize_key( $_POST['task'] ?? '' );
		$profiles = new Profiles();
		if ( $user_id && Config::schema_ready() && $profiles->find_by_user( $user_id ) ) {
			if ( 'hide' === $task ) { $profiles->set_status( $user_id, 'hidden' ); }
			elseif ( 'show' === $task ) { $profiles->set_status( $user_id, 'active' ); }
			elseif ( 'shadow' === $task ) { $profiles->set_status( $user_id, 'shadow' ); }
			elseif ( 'remove_avatar' === $task ) { Avatars::delete( $profiles->set_avatar( $user_id, '' ) ); }
		}
		wp_safe_redirect( self::page_url( array( 'mmafc_done' => 1 ) ) );
		exit;
	}

	private static function action_button( int $user_id, string $task, string $label ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:0 4px 4px 0">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="task" value="' . esc_attr( $task ) . '"><input type="hidden" name="user_id" value="' . esc_attr( (string) $user_id ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<button class="button button-small">' . esc_html( $label ) . '</button></form>';
	}

	private static function page_url( array $args = array() ): string {
		return add_query_arg( array_filter( array( 'page' => self::PAGE_SLUG ) + $args, static fn( $value ) => '' !== $value && null !== $value ), admin_url( 'admin.php' ) );
	}
}
