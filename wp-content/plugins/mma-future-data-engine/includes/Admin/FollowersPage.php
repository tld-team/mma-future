<?php
namespace MMAF\DataEngine\Admin;

use MMAF\DataEngine\Services\Notifications\FollowersReport;
use MMAF\DataEngine\Support\Capabilities;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Who follows which fighter. Fighters first, because the question an import raises is
 * "how many readers hear about this fight"; the reader view answers "what does this person follow".
 */
final class FollowersPage {
	private const PAGE_SLUG = 'mmaf-followers';

	public static function register(): void {
		add_action( 'admin_post_mmaf_followers_export', array( self::class, 'export' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) { wp_die( 'Forbidden', '', array( 'response' => 403 ) ); }
		$report  = new FollowersReport();
		$view    = self::current_view();
		$filters = self::current_filters( $view );
		$summary = $report->summary();
		?>
		<div class="wrap">
			<h1>Pratioci boraca</h1>
			<p>Ko prati koje borce. Kada se uveze kalendar sa novom borbom, mejl dobijaju pratioci boraca iz te borbe.</p>
			<div style="display:grid;grid-template-columns:repeat(4,minmax(150px,1fr));gap:12px;max-width:960px;margin:16px 0">
				<?php foreach ( array( 'Korisnika koji prate' => $summary['readers'], 'Ukupno praćenja' => $summary['follows'], 'Praćenih boraca' => $summary['fighters'], 'Prosek po korisniku' => $summary['readers'] ? number_format_i18n( $summary['follows'] / $summary['readers'], 1 ) : '0' ) as $label => $value ) : ?>
					<div class="card" style="margin:0;padding:12px 16px"><h2 style="margin:0"><?php echo esc_html( (string) $value ); ?></h2><p style="margin:4px 0 0"><?php echo esc_html( $label ); ?></p></div>
				<?php endforeach; ?>
			</div>
			<?php if ( $summary['top'] ) : ?><p>Najpraćeniji borac: <strong><?php echo esc_html( $summary['top']['display_name'] ); ?></strong> (<?php echo (int) $summary['top']['followers']; ?>)</p><?php endif; ?>

			<h2 class="nav-tab-wrapper">
				<a class="nav-tab <?php echo 'fighters' === $view ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( self::page_url( array( 'view' => 'fighters' ) ) ); ?>">Borci</a>
				<a class="nav-tab <?php echo 'users' === $view ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( self::page_url( array( 'view' => 'users' ) ) ); ?>">Korisnici</a>
			</h2>
			<?php
			self::render_scope_notice( $view, $filters );
			self::render_toolbar( $view, $filters );
			if ( 'users' === $view ) { self::render_users( $report, $filters ); } else { self::render_fighters( $report, $filters ); }
			?>
		</div>
		<?php
	}

	public static function export(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) { wp_die( 'Forbidden', '', array( 'response' => 403 ) ); }
		check_admin_referer( 'mmaf_followers_export' );
		$view = sanitize_key( $_POST['export'] ?? '' );
		$view = in_array( $view, array( 'fighters', 'users', 'pairs' ), true ) ? $view : 'fighters';
		$data = ( new FollowersReport() )->export( $view, array(
			'search'     => sanitize_text_field( wp_unslash( $_POST['s'] ?? '' ) ),
			'fighter_id' => absint( $_POST['fighter_id'] ?? 0 ),
			'user_id'    => absint( $_POST['user_id'] ?? 0 ),
			'orderby'    => sanitize_key( $_POST['orderby'] ?? '' ),
			'order'      => sanitize_key( $_POST['order'] ?? '' ),
		) );
		$names = array( 'fighters' => 'borci', 'users' => 'korisnici', 'pairs' => 'parovi' );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: attachment; filename="mma-future-pratioci-' . $names[ $view ] . '-' . gmdate( 'Y-m-d' ) . '.csv"' );
		echo FollowersReport::csv( $data['headers'], $data['rows'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	private static function render_fighters( FollowersReport $report, array $filters ): void {
		$total = $report->count_fighters( $filters );
		$rows  = $report->fighters( $filters );
		self::render_pagination( $total, $filters, 'fighters' );
		?>
		<table class="widefat striped">
			<thead><tr>
				<?php self::sortable_header( 'name', 'Borac', $filters, 'fighters' ); ?>
				<th>Kategorija</th>
				<?php self::sortable_header( 'followers', 'Pratilaca', $filters, 'fighters' ); ?>
				<?php self::sortable_header( 'last_followed', 'Poslednje praćenje (UTC)', $filters, 'fighters' ); ?>
				<?php self::sortable_header( 'next_fight', 'Sledeća najavljena borba', $filters, 'fighters' ); ?>
			</tr></thead>
			<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<td><strong><a href="<?php echo esc_url( admin_url( 'admin.php?page=mmaf-fighters&action=edit&fighter_id=' . (int) $row['fighter_id'] ) ); ?>"><?php echo esc_html( $row['display_name'] ); ?></a></strong><?php if ( $row['profile_url'] ) : ?> · <a href="<?php echo esc_url( $row['profile_url'] ); ?>" target="_blank" rel="noopener">profil</a><?php endif; ?></td>
					<td><?php echo esc_html( (string) $row['weight_class'] ); ?></td>
					<td><a href="<?php echo esc_url( self::page_url( array( 'view' => 'users', 'fighter_id' => (int) $row['fighter_id'] ) ) ); ?>" title="Prikaži pratioce"><strong><?php echo (int) $row['followers']; ?></strong></a></td>
					<td><?php echo esc_html( self::short_time( $row['last_followed'] ) ); ?></td>
					<td><?php echo $row['next_fight'] ? esc_html( $row['next_fight'] ) : '<span style="color:#787c82">nema</span>'; ?></td>
				</tr>
			<?php endforeach; ?>
			<?php if ( ! $rows ) : ?><tr><td colspan="5">Još niko ne prati nijednog borca<?php echo '' !== $filters['search'] ? ' sa tim imenom' : ''; ?>.</td></tr><?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	private static function render_users( FollowersReport $report, array $filters ): void {
		$total = $report->count_users( $filters );
		$rows  = $report->users( $filters );
		self::render_pagination( $total, $filters, 'users' );
		?>
		<table class="widefat striped">
			<thead><tr>
				<?php self::sortable_header( 'email', 'Email', $filters, 'users' ); ?>
				<th>Ime</th>
				<?php self::sortable_header( 'registered', 'Registrovan (UTC)', $filters, 'users' ); ?>
				<th>Obaveštenja</th>
				<th>Vremenska zona</th>
				<?php self::sortable_header( 'follows', 'Prati', $filters, 'users' ); ?>
				<th>Borci</th>
				<th>Podsetnici</th>
				<th>Poslednji mejl (UTC)</th>
			</tr></thead>
			<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<td><a href="<?php echo esc_url( get_edit_user_link( (int) $row['user_id'] ) ); ?>"><?php echo esc_html( $row['user_email'] ); ?></a></td>
					<td><?php echo esc_html( $row['display_name'] ); ?></td>
					<td><?php echo esc_html( self::short_time( $row['user_registered'] ) ); ?></td>
					<td><?php echo 'active' === $row['delivery'] ? esc_html( $row['delivery_label'] ) : '<span style="color:#b32d2e">' . esc_html( $row['delivery_label'] ) . '</span>'; ?></td>
					<td><?php echo esc_html( $row['timezone'] ); ?></td>
					<td><a href="<?php echo esc_url( self::page_url( array( 'view' => 'fighters', 'user_id' => (int) $row['user_id'] ) ) ); ?>" title="Prikaži borce"><strong><?php echo (int) $row['follows']; ?></strong></a></td>
					<td><?php echo esc_html( wp_html_excerpt( (string) $row['fighters'], 140, '…' ) ); ?></td>
					<td><?php echo (int) $row['reminders']; ?></td>
					<td><?php echo $row['last_sent'] ? esc_html( self::short_time( $row['last_sent'] ) ) : '<span style="color:#787c82">još nijedan</span>'; ?></td>
				</tr>
			<?php endforeach; ?>
			<?php if ( ! $rows ) : ?><tr><td colspan="9">Nema korisnika koji prate borce<?php echo '' !== $filters['search'] ? ' za tu pretragu' : ''; ?>.</td></tr><?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	/** Names the fighter or reader the list is narrowed to, with a way out. */
	private static function render_scope_notice( string $view, array $filters ): void {
		global $wpdb;
		$label = '';
		if ( 'users' === $view && $filters['fighter_id'] ) {
			$name  = $wpdb->get_var( $wpdb->prepare( 'SELECT display_name FROM ' . \MMAF\DataEngine\Migrations\Schema::table_names()['fighters'] . ' WHERE id=%d', $filters['fighter_id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$label = 'Prikazani su pratioci borca: ' . ( $name ?: '#' . $filters['fighter_id'] );
		} elseif ( 'fighters' === $view && $filters['user_id'] ) {
			$user  = get_userdata( $filters['user_id'] );
			$label = 'Prikazani su borci koje prati: ' . ( $user ? $user->user_email : '#' . $filters['user_id'] );
		}
		if ( '' === $label ) { return; }
		echo '<div class="notice notice-info inline" style="margin:12px 0"><p>' . esc_html( $label ) . ' · <a href="' . esc_url( self::page_url( array( 'view' => $view ) ) ) . '">prikaži sve</a></p></div>';
	}

	private static function render_toolbar( string $view, array $filters ): void {
		?>
		<div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;margin:12px 0">
			<form method="get" style="display:flex;gap:6px;align-items:center">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
				<input type="hidden" name="view" value="<?php echo esc_attr( $view ); ?>">
				<?php if ( $filters['fighter_id'] ) : ?><input type="hidden" name="fighter_id" value="<?php echo (int) $filters['fighter_id']; ?>"><?php endif; ?>
				<?php if ( $filters['user_id'] ) : ?><input type="hidden" name="user_id" value="<?php echo (int) $filters['user_id']; ?>"><?php endif; ?>
				<label class="screen-reader-text" for="mmaf-followers-search">Pretraga</label>
				<input type="search" id="mmaf-followers-search" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php echo esc_attr( 'users' === $view ? 'Email ili ime' : 'Ime borca' ); ?>">
				<select name="per_page" aria-label="Redova po strani"><?php foreach ( array( 25, 50, 100 ) as $size ) : ?><option value="<?php echo (int) $size; ?>" <?php selected( $filters['per_page'], $size ); ?>><?php echo (int) $size; ?> po strani</option><?php endforeach; ?></select>
				<button type="submit" class="button">Pretraži</button>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:6px;align-items:center">
				<input type="hidden" name="action" value="mmaf_followers_export">
				<input type="hidden" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>">
				<input type="hidden" name="fighter_id" value="<?php echo (int) $filters['fighter_id']; ?>">
				<input type="hidden" name="user_id" value="<?php echo (int) $filters['user_id']; ?>">
				<input type="hidden" name="orderby" value="<?php echo esc_attr( $filters['orderby'] ); ?>">
				<input type="hidden" name="order" value="<?php echo esc_attr( $filters['order'] ); ?>">
				<?php wp_nonce_field( 'mmaf_followers_export' ); ?>
				<span>Export CSV:</span>
				<button type="submit" class="button <?php echo 'fighters' === $view ? 'button-primary' : ''; ?>" name="export" value="fighters">Borci</button>
				<button type="submit" class="button <?php echo 'users' === $view ? 'button-primary' : ''; ?>" name="export" value="users">Korisnici</button>
				<button type="submit" class="button" name="export" value="pairs" title="Jedan red po paru korisnik–borac">Svi parovi</button>
			</form>
		</div>
		<?php
	}

	private static function current_view(): string {
		return 'users' === sanitize_key( $_GET['view'] ?? '' ) ? 'users' : 'fighters';
	}

	private static function current_filters( string $view ): array {
		$sorts    = 'users' === $view ? FollowersReport::USER_SORTS : FollowersReport::FIGHTER_SORTS;
		$default  = 'users' === $view ? 'follows' : 'followers';
		$orderby  = sanitize_key( $_GET['orderby'] ?? '' );
		$per_page = absint( $_GET['per_page'] ?? 50 );
		return array(
			'search'     => sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ),
			// Each view takes only the scope that narrows it; the other is dropped so a stale parameter cannot hide rows.
			'fighter_id' => 'users' === $view ? absint( $_GET['fighter_id'] ?? 0 ) : 0,
			'user_id'    => 'fighters' === $view ? absint( $_GET['user_id'] ?? 0 ) : 0,
			'orderby'    => isset( $sorts[ $orderby ] ) ? $orderby : $default,
			'order'      => 'asc' === sanitize_key( $_GET['order'] ?? '' ) ? 'asc' : 'desc',
			'per_page'   => in_array( $per_page, array( 25, 50, 100 ), true ) ? $per_page : 50,
			'paged'      => max( 1, absint( $_GET['paged'] ?? 1 ) ),
		);
	}

	private static function page_url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::PAGE_SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/** URL arguments that keep the current list state, without empty values or defaults. */
	private static function state_args( array $filters, string $view ): array {
		return array_filter( array( 'view' => $view, 's' => $filters['search'], 'fighter_id' => $filters['fighter_id'], 'user_id' => $filters['user_id'], 'per_page' => 50 === $filters['per_page'] ? 0 : $filters['per_page'] ) );
	}

	private static function sortable_header( string $key, string $label, array $filters, string $view ): void {
		$is_current = $key === $filters['orderby'];
		// Names and emails read naturally A–Z; counts and dates are most useful largest or newest first.
		$default = in_array( $key, array( 'name', 'email' ), true ) ? 'asc' : 'desc';
		$next    = $is_current ? ( 'asc' === $filters['order'] ? 'desc' : 'asc' ) : $default;
		$class   = $is_current ? 'sorted ' . $filters['order'] : 'sortable ' . $default;
		$url     = self::page_url( array_merge( self::state_args( $filters, $view ), array( 'orderby' => $key, 'order' => $next ) ) );
		echo '<th class="' . esc_attr( $class ) . '"><a href="' . esc_url( $url ) . '"><span>' . esc_html( $label ) . '</span><span class="sorting-indicator"></span></a></th>';
	}

	private static function render_pagination( int $total, array $filters, string $view ): void {
		$pages = max( 1, (int) ceil( $total / $filters['per_page'] ) );
		echo '<div class="tablenav top" style="margin:10px 0"><div class="alignleft actions"><span class="displaying-num">' . esc_html( 'Ukupno: ' . number_format_i18n( $total ) ) . '</span></div>';
		if ( $pages > 1 ) {
			$base = add_query_arg( array_merge( self::state_args( $filters, $view ), array( 'orderby' => $filters['orderby'], 'order' => $filters['order'], 'paged' => '%#%' ) ), self::page_url() );
			echo '<div class="tablenav-pages">' . wp_kses_post( paginate_links( array( 'base' => $base, 'format' => '', 'current' => min( $filters['paged'], $pages ), 'total' => $pages, 'prev_text' => '&laquo;', 'next_text' => '&raquo;' ) ) ) . '</div>';
		}
		echo '<br class="clear"></div>';
	}

	private static function short_time( ?string $utc ): string {
		return $utc ? substr( $utc, 0, 16 ) : '';
	}
}
