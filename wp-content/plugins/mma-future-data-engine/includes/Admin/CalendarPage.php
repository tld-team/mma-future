<?php
namespace MMAF\DataEngine\Admin;

use MMAF\DataEngine\Services\Upcoming\Editor;
use MMAF\DataEngine\Services\Upcoming\Importer;
use MMAF\DataEngine\Services\Upcoming\Repository;
use MMAF\DataEngine\Support\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CalendarPage {
	public static function register(): void {
		add_action( 'admin_post_mmaf_calendar_preview', array( self::class, 'handle_preview' ) );
		add_action( 'admin_post_mmaf_calendar_confirm', array( self::class, 'handle_confirm' ) );
		add_action( 'admin_post_mmaf_calendar_save', array( self::class, 'handle_save' ) );
		add_action( 'admin_post_mmaf_calendar_toggle_bout', array( self::class, 'handle_toggle_bout' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
	}

	public static function enqueue_assets( string $hook ): void {
		if ( 'mma-future_page_mmaf-calendar' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'mmaf-calendar-admin', plugins_url( 'assets/admin-calendar.css', MMAF_PLUGIN_FILE ), array(), MMAF_PLUGIN_VERSION );
	}

	public static function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'Nemate dozvolu za ovu stranicu.', 'mma-future-data-engine' ) );
		}
		$repo = new Repository();
		$view = sanitize_key( $_GET['view'] ?? 'events' );
		$event = 'edit' === $view ? $repo->find_event( absint( $_GET['event_id'] ?? 0 ) ) : null;
		?>
		<div class="wrap mmaf-calendar-admin">
			<h1>Kalendar događaja</h1>
			<?php self::notice(); ?>
			<nav class="nav-tab-wrapper">
				<a class="nav-tab <?php echo 'events' === $view ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=mmaf-calendar' ) ); ?>">Događaji</a>
				<a class="nav-tab <?php echo in_array( $view, array( 'new', 'edit' ), true ) ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=mmaf-calendar&view=new' ) ); ?>">Dodaj događaj</a>
				<a class="nav-tab <?php echo 'import' === $view ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=mmaf-calendar&view=import' ) ); ?>">ZIP import</a>
				<a class="nav-tab <?php echo 'history' === $view ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=mmaf-calendar&view=history' ) ); ?>">Istorija uvoza</a>
			</nav>
			<?php
			if ( in_array( $view, array( 'new', 'edit' ), true ) ) {
				self::event_form( $event );
			} elseif ( 'import' === $view ) {
				self::import_view();
			} elseif ( 'history' === $view ) {
				self::history( $repo );
			} else {
				self::events( $repo );
			}
			?>
		</div>
		<?php
	}

	public static function handle_preview(): void {
		self::authorize( 'mmaf_calendar_preview' );
		if ( empty( $_FILES['bundle']['tmp_name'] ) || UPLOAD_ERR_OK !== (int) $_FILES['bundle']['error'] ) {
			self::redirect_error( 'ZIP fajl nije primljen.' );
		}
		try {
			$directory = self::private_directory();
			$token = wp_generate_password( 32, false, false );
			$path = $directory . '/' . $token . '.zip';
			if ( ! move_uploaded_file( $_FILES['bundle']['tmp_name'], $path ) ) {
				throw new \RuntimeException( 'Upload nije moguće sačuvati.' );
			}
			$package = ( new Importer() )->inspect_zip( $path );
			$preview = ( new Importer() )->preview( $package );
			set_transient( self::transient_key( $token ), array( 'path' => $path, 'hash' => $package['bundle_hash'], 'preview' => $preview ), HOUR_IN_SECONDS );
			wp_safe_redirect( admin_url( 'admin.php?page=mmaf-calendar&view=import&preview=' . rawurlencode( $token ) ) );
			exit;
		} catch ( \Throwable $error ) {
			self::redirect_error( $error->getMessage() );
		}
	}

	public static function handle_confirm(): void {
		self::authorize( 'mmaf_calendar_confirm' );
		$token = sanitize_text_field( wp_unslash( $_POST['preview_token'] ?? '' ) );
		$stored = get_transient( self::transient_key( $token ) );
		if ( ! is_array( $stored ) || empty( $stored['path'] ) ) {
			self::redirect_error( 'Pregled je istekao. Učitajte paket ponovo.' );
		}
		try {
			$package = ( new Importer() )->inspect_zip( $stored['path'] );
			if ( ! hash_equals( (string) $stored['hash'], (string) $package['bundle_hash'] ) ) {
				throw new \RuntimeException( 'Paket se promenio nakon pregleda.' );
			}
			$result = ( new Importer() )->apply( $package, get_current_user_id() );
			delete_transient( self::transient_key( $token ) );
			wp_delete_file( $stored['path'] );
			wp_safe_redirect( admin_url( 'admin.php?page=mmaf-calendar&view=history&mmaf_notice=' . rawurlencode( $result['idempotent'] ? 'Paket je već bio uvezen; podaci nisu menjani.' : 'Paket je uspešno uvezen.' ) ) );
			exit;
		} catch ( \Throwable $error ) {
			self::redirect_error( $error->getMessage() );
		}
	}

	public static function handle_save(): void {
		self::authorize( 'mmaf_calendar_save' );
		try {
			$id = ( new Editor() )->save_event( $_POST, get_current_user_id() );
			wp_safe_redirect( admin_url( 'admin.php?page=mmaf-calendar&view=edit&event_id=' . $id . '&mmaf_notice=' . rawurlencode( 'Događaj je sačuvan.' ) ) );
			exit;
		} catch ( \Throwable $error ) {
			self::redirect_error( $error->getMessage(), 'new' );
		}
	}

	public static function handle_toggle_bout(): void {
		self::authorize( 'mmaf_calendar_toggle_bout' );
		( new Editor() )->set_bout_hidden( absint( $_POST['bout_id'] ?? 0 ), ! empty( $_POST['hidden'] ) );
		wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=mmaf-calendar' ) );
		exit;
	}

	private static function events( Repository $repo ): void {
		$events = $repo->admin_events();
		?><div class="mmaf-calendar-admin__section"><h2>Događaji</h2><p>Najave su odvojene od kanonskih rezultata i rangiranja.</p>
		<table class="widefat striped"><thead><tr><th>Datum</th><th>Naziv</th><th>Promocija</th><th>Poreklo</th><th>Status</th><th>Parovi</th><th></th></tr></thead><tbody>
		<?php foreach ( $events as $event ) : $full = $repo->find_event( (int) $event['id'] ); ?>
			<tr><td><?php echo esc_html( $event['event_date'] ); ?></td><td><strong><?php echo esc_html( $event['title'] ); ?></strong></td><td><?php echo esc_html( $event['promotion'] ?: '—' ); ?></td><td><?php echo esc_html( $event['is_manual'] ? 'Ručno' : 'Tapology' ); ?></td><td><?php echo esc_html( $event['hidden'] ? 'Sakriven' : $event['status'] ); ?></td><td><?php echo esc_html( (string) count( $full['bouts'] ?? array() ) ); ?></td><td><a href="<?php echo esc_url( admin_url( 'admin.php?page=mmaf-calendar&view=edit&event_id=' . (int) $event['id'] ) ); ?>">Uredi</a></td></tr>
		<?php endforeach; ?>
		<?php if ( ! $events ) : ?><tr><td colspan="7">Još nema kalendarskih događaja.</td></tr><?php endif; ?>
		</tbody></table></div><?php
	}

	private static function event_form( ?array $event ): void {
		$manual_bouts = array_filter( $event['bouts'] ?? array(), static fn( array $bout ): bool => ! empty( $bout['is_manual'] ) );
		$lines = array_map( static fn( array $bout ): string => implode( ' | ', array( $bout['fighter_a_name'], $bout['fighter_b_name'], $bout['weight_class'] ?? '', $bout['fighter_a_url'] ?? '', $bout['fighter_b_url'] ?? '' ) ), $manual_bouts );
		$source = $event ? json_decode( (string) $event['source_json'], true ) : array();
		?><div class="mmaf-calendar-admin__section"><h2><?php echo $event ? 'Uredi događaj' : 'Novi događaj'; ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="mmaf_calendar_save"><input type="hidden" name="event_id" value="<?php echo esc_attr( (string) ( $event['id'] ?? 0 ) ); ?>"><?php wp_nonce_field( 'mmaf_calendar_save' ); ?>
			<table class="form-table"><tbody>
			<?php self::field( 'title', 'Naziv', $event['title'] ?? '', true, $source['title'] ?? null, $event ); ?>
			<?php self::field( 'promotion', 'Promocija', $event['promotion'] ?? '', false, $source['promotion'] ?? null, $event ); ?>
			<?php self::field( 'event_date', 'Datum', $event['event_date'] ?? '', true, $source['date'] ?? null, $event, 'date' ); ?>
			<?php self::field( 'source_time_text', 'Vreme', $event['source_time_text'] ?? '', false, $source['source_time_text'] ?? null, $event ); ?>
			<?php self::field( 'source_timezone', 'Izvorna zona', $event['source_timezone'] ?? '', false, $source['source_timezone'] ?? null, $event ); ?>
			<?php self::field( 'venue', 'Mesto', $event['venue'] ?? '', false, $source['venue'] ?? null, $event ); ?>
			<?php self::field( 'location', 'Lokacija', $event['location'] ?? '', false, $source['location'] ?? null, $event ); ?>
			<?php self::field( 'source_url', 'Tapology URL', $event['source_url'] ?? '', false, $source['source_url'] ?? null, $event, 'url' ); ?>
			<tr><th><label for="mmaf-status">Status</label></th><td><select id="mmaf-status" name="status"><?php foreach ( array( 'draft' => 'Nacrt', 'published' => 'Objavljeno', 'postponed' => 'Odloženo', 'cancelled' => 'Otkazano' ) as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $event['status'] ?? 'draft', $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></td></tr>
			<tr><th>Obuhvat</th><td><label><input type="checkbox" name="scope_confirmed" value="1" <?php checked( ! empty( $event['scope_confirmed'] ) ); ?>> Potvrđujem da događaj i ručno dodati parovi pripadaju profesionalnom MMA obuhvatu.</label></td></tr>
			<tr><th>Vidljivost</th><td><label><input type="checkbox" name="hidden" value="1" <?php checked( ! empty( $event['hidden'] ) ); ?>> Sakrij događaj</label></td></tr>
			<tr><th><label for="mmaf-bouts">Ručni parovi</label></th><td><textarea id="mmaf-bouts" name="bouts" rows="8" class="large-text code"><?php echo esc_textarea( implode( "\n", $lines ) ); ?></textarea><p class="description">Jedan red: Borac A | Borac B | kategorija | URL A | URL B. Izvorni parovi se čuvaju odvojeno.</p></td></tr>
			</tbody></table><?php submit_button( $event ? 'Sačuvaj izmene' : 'Dodaj događaj' ); ?>
		</form>
		<?php if ( $event && $event['bouts'] ) : ?><h3>Program</h3><table class="widefat striped"><thead><tr><th>#</th><th>Par</th><th>Poreklo</th><th>Status</th><th>Vidljivost</th></tr></thead><tbody><?php foreach ( $event['bouts'] as $bout ) : ?><tr><td><?php echo esc_html( $bout['bout_order'] ); ?></td><td><?php echo esc_html( $bout['fighter_a_name'] . ' vs ' . $bout['fighter_b_name'] ); ?></td><td><?php echo $bout['is_manual'] ? 'Ručno' : 'Tapology'; ?></td><td><?php echo esc_html( $bout['status'] ); ?></td><td><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="mmaf_calendar_toggle_bout"><input type="hidden" name="bout_id" value="<?php echo esc_attr( $bout['id'] ); ?>"><input type="hidden" name="hidden" value="<?php echo $bout['hidden'] ? '0' : '1'; ?>"><?php wp_nonce_field( 'mmaf_calendar_toggle_bout' ); ?><button class="button button-small"><?php echo $bout['hidden'] ? 'Prikaži' : 'Sakrij'; ?></button></form></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
		</div><?php
	}

	private static function import_view(): void {
		$token = sanitize_text_field( wp_unslash( $_GET['preview'] ?? '' ) );
		$stored = $token ? get_transient( self::transient_key( $token ) ) : null;
		?><div class="mmaf-calendar-admin__section"><h2>Kontrolisani ZIP import</h2><p>Paket se prvo proverava i prikazuje bez izmene baze. Pokrivenost se ažurira tek nakon potvrđenog, potpunog uvoza.</p>
		<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="mmaf_calendar_preview"><?php wp_nonce_field( 'mmaf_calendar_preview' ); ?><input type="file" name="bundle" accept=".zip,application/zip" required><?php submit_button( 'Pregledaj paket', 'secondary', 'submit', false ); ?></form>
		<?php if ( is_array( $stored ) ) : $preview = $stored['preview']; ?><div class="mmaf-calendar-admin__preview"><h3>Pregled promena</h3><table class="widefat striped"><thead><tr><th></th><th>Novo</th><th>Promenjeno</th><th>Nepromenjeno</th><th>Pregled</th><th>Starije</th></tr></thead><tbody><tr><th>Događaji</th><td><?php echo (int) $preview['events']['new']; ?></td><td><?php echo (int) $preview['events']['changed']; ?></td><td><?php echo (int) $preview['events']['unchanged']; ?></td><td><?php echo (int) $preview['events']['review']; ?></td><td><?php echo (int) $preview['events']['stale']; ?></td></tr><tr><th>Parovi</th><td><?php echo (int) $preview['bouts']['new']; ?></td><td><?php echo (int) $preview['bouts']['changed']; ?></td><td><?php echo (int) $preview['bouts']['unchanged']; ?></td><td><?php echo (int) $preview['bouts']['review']; ?></td><td>—</td></tr></tbody></table><p>Zaštićena ručna polja: <strong><?php echo (int) $preview['protected_fields']; ?></strong>. Mogući duplikati: <strong><?php echo count( $preview['possible_duplicates'] ); ?></strong>.</p><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="mmaf_calendar_confirm"><input type="hidden" name="preview_token" value="<?php echo esc_attr( $token ); ?>"><?php wp_nonce_field( 'mmaf_calendar_confirm' ); ?><?php submit_button( 'Potvrdi uvoz', 'primary', 'submit', false ); ?></form></div><?php endif; ?>
		</div><?php
	}

	private static function history( Repository $repo ): void {
		?><div class="mmaf-calendar-admin__section"><h2>Istorija uvoza</h2><table class="widefat striped"><thead><tr><th>ID</th><th>Run</th><th>Period</th><th>Zona</th><th>Pokrivenost</th><th>Status</th><th>Završeno</th></tr></thead><tbody><?php foreach ( $repo->import_history() as $row ) : ?><tr><td><?php echo (int) $row['id']; ?></td><td><code><?php echo esc_html( $row['run_id'] ); ?></code></td><td><?php echo esc_html( $row['coverage_from'] . ' — ' . $row['coverage_to'] ); ?></td><td><?php echo esc_html( $row['coverage_timezone'] ); ?></td><td><?php echo $row['coverage_complete'] ? 'Potpuna' : 'Delimična'; ?></td><td><?php echo esc_html( $row['status'] ); ?></td><td><?php echo esc_html( $row['finished_at'] ?: '—' ); ?></td></tr><?php endforeach; ?></tbody></table></div><?php
	}

	private static function field( string $name, string $label, $value, bool $required, $source_value, ?array $event, string $type = 'text' ): void {
		?><tr><th><label for="mmaf-<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label></th><td><input id="mmaf-<?php echo esc_attr( $name ); ?>" type="<?php echo esc_attr( $type ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>" class="regular-text" <?php echo $required ? 'required' : ''; ?>><?php if ( $event && ! $event['is_manual'] ) : ?><p class="description">Izvor: <?php echo esc_html( null === $source_value || '' === $source_value ? 'prazno' : (string) $source_value ); ?> · <label><input type="checkbox" name="reset_source[<?php echo esc_attr( $name ); ?>]" value="1"> Vrati na izvor</label></p><?php endif; ?></td></tr><?php
	}

	private static function notice(): void {
		if ( ! empty( $_GET['mmaf_notice'] ) ) { echo '<div class="notice notice-success"><p>' . esc_html( wp_unslash( $_GET['mmaf_notice'] ) ) . '</p></div>'; }
		if ( ! empty( $_GET['mmaf_error'] ) ) { echo '<div class="notice notice-error"><p>' . esc_html( wp_unslash( $_GET['mmaf_error'] ) ) . '</p></div>'; }
	}

	private static function authorize( string $action ): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) { wp_die( 'Nedozvoljeno.', '', array( 'response' => 403 ) ); }
		check_admin_referer( $action );
	}

	private static function private_directory(): string {
		$uploads = wp_get_upload_dir();
		$directory = trailingslashit( $uploads['basedir'] ) . 'mma-future-private/upcoming';
		if ( ! wp_mkdir_p( $directory ) ) { throw new \RuntimeException( 'Privatni direktorijum nije dostupan.' ); }
		if ( ! is_file( $directory . '/.htaccess' ) ) { file_put_contents( $directory . '/.htaccess', "Deny from all\n" ); }
		if ( ! is_file( $directory . '/index.php' ) ) { file_put_contents( $directory . '/index.php', "<?php http_response_code(404); exit;\n" ); }
		return $directory;
	}

	private static function transient_key( string $token ): string { return 'mmaf_cal_' . get_current_user_id() . '_' . preg_replace( '/[^A-Za-z0-9]/', '', $token ); }
	private static function redirect_error( string $message, string $view = 'import' ): void { wp_safe_redirect( admin_url( 'admin.php?page=mmaf-calendar&view=' . $view . '&mmaf_error=' . rawurlencode( $message ) ) ); exit; }
}
