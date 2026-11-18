<?php
namespace MMAF\DataEngine\Admin;

use MMAF\DataEngine\Services\Community\Avatars;
use MMAF\DataEngine\Services\Community\Config;
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
		'fan'      => array( 'Fan Top 10', 'My Top 10, prikaz Fans na rang listi i fan rang na strani borca. Traži profile.' ),
	);

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'moderate' ) );
		add_action( 'admin_post_' . self::AREAS_ACTION, array( self::class, 'save_areas' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) { wp_die( 'Forbidden', '', array( 'response' => 403 ) ); }
		echo '<div class="wrap"><h1>Zajednica</h1>';
		if ( ! Config::schema_ready() ) { echo '<div class="notice notice-warning"><p>Tabele zajednice još ne postoje. Pokrenite migraciju baze na šemu ' . esc_html( Config::SCHEMA_VERSION ) . '.</p></div></div>'; return; }
		if ( ! Config::feature_enabled() ) { echo '<div class="notice notice-info"><p>Zajednica je trenutno isključena. Uključuje se konstantom <code>MMAF_COMMUNITY_ENABLED</code> u <code>wp-config.php</code>; prekidači ispod važe tek tada.</p></div>'; }
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
		$summary   = $wpdb->get_row( "SELECT COUNT(*) AS profiles, SUM(avatar_path<>'') AS photos, SUM(bio<>'') AS bios, SUM(status='hidden') AS hidden FROM {$table}", ARRAY_A ) ?: array();
		// phpcs:enable
		?>
		<p>Javni profili čitalaca. Skriven profil se ne prikazuje posetiocima, a vlasnik i dalje može da ga uređuje.</p>
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
			<thead><tr><th style="width:56px">Foto</th><th>Handle</th><th>Ime</th><th>Email</th><th>Status</th><th>Napravljen</th><th>Akcije</th></tr></thead>
			<tbody>
			<?php if ( ! $rows ) : ?>
				<tr><td colspan="7"><?php echo '' !== $search ? 'Nema profila za ovu pretragu.' : 'Još nema profila.'; ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $rows as $row ) : $hidden = 'hidden' === $row['status']; ?>
				<tr>
					<td><img src="<?php echo esc_url( Avatars::url( (string) $row['avatar_path'] ) ); ?>" alt="" width="40" height="40" style="border-radius:50%;object-fit:cover"></td>
					<td><?php if ( $hidden ) : ?>@<?php echo esc_html( $row['handle'] ); ?><?php else : ?><a href="<?php echo esc_url( Profiles::url( (string) $row['handle'] ) ); ?>" target="_blank" rel="noopener">@<?php echo esc_html( $row['handle'] ); ?></a><?php endif; ?></td>
					<td><?php echo esc_html( $row['display_name'] ); ?><?php if ( '' !== (string) $row['bio'] ) : ?><br><span class="description"><?php echo esc_html( wp_html_excerpt( (string) $row['bio'], 120, '…' ) ); ?></span><?php endif; ?></td>
					<td><?php echo esc_html( (string) ( $row['user_email'] ?? '—' ) ); ?></td>
					<td><?php echo $hidden ? '<strong>Skriven</strong>' : 'Aktivan'; ?></td>
					<td><?php echo esc_html( mysql2date( 'Y-m-d', (string) $row['created_at'] ) ); ?></td>
					<td>
						<?php self::action_button( (int) $row['user_id'], $hidden ? 'show' : 'hide', $hidden ? 'Prikaži' : 'Sakrij' ); ?>
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
