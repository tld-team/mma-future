<?php
namespace MMAF\DataEngine\Admin;

use MMAF\DataEngine\Repositories\BoutParticipantRepository;
use MMAF\DataEngine\Repositories\BoutRepository;
use MMAF\DataEngine\Repositories\BoutSourceRepository;
use MMAF\DataEngine\Repositories\EventRepository;
use MMAF\DataEngine\Repositories\FighterRepository;
use MMAF\DataEngine\Services\BoutService;
use MMAF\DataEngine\Support\Capabilities;
use MMAF\DataEngine\Support\Sanitizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class BoutsPage {
	private const PAGE_SLUG = 'mmaf-bouts';

	public static function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mma-future-data-engine' ) );
		}

		$notice = null;

		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : '';

		if ( 'POST' === $request_method ) {
			$notice = self::handle_post();
		}

		$action  = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list';
		$bout_id = isset( $_GET['bout_id'] ) ? absint( $_GET['bout_id'] ) : 0;

		if ( isset( $_GET['mmaf_notice'] ) ) {
			$notice = array(
				'type'    => 'success',
				'message' => sanitize_text_field( wp_unslash( $_GET['mmaf_notice'] ) ),
			);
		}

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Canonical Bouts', 'mma-future-data-engine' ) . '</h1> ';
		echo '<a class="page-title-action" href="' . esc_url( self::page_url( array( 'action' => 'new' ) ) ) . '">' . esc_html__( 'Add New Bout', 'mma-future-data-engine' ) . '</a>';

		if ( $notice ) {
			self::render_notice( $notice['type'], $notice['message'] );
		}

		if ( 'new' === $action ) {
			self::render_form();
		} elseif ( 'edit' === $action && $bout_id > 0 ) {
			self::render_form( $bout_id );
		} else {
			self::render_list();
		}

		echo '</div>';
	}

	private static function handle_post(): ?array {
		if ( ! isset( $_POST['mmaf_bout_nonce'] ) ) {
			return array(
				'type'    => 'error',
				'message' => __( 'Missing security token.', 'mma-future-data-engine' ),
			);
		}

		check_admin_referer( 'mmaf_save_bout', 'mmaf_bout_nonce' );

		$action  = isset( $_POST['mmaf_action'] ) ? sanitize_key( wp_unslash( $_POST['mmaf_action'] ) ) : '';
		$bout_id = isset( $_POST['bout_id'] ) ? absint( $_POST['bout_id'] ) : 0;
		$service = new BoutService();

		try {
			if ( 'create' === $action ) {
				$bout = $service->create( $_POST, get_current_user_id() );
				wp_safe_redirect(
					self::page_url(
						array(
							'action'      => 'edit',
							'bout_id'     => (int) $bout['id'],
							'mmaf_notice' => self::save_message( __( 'Bout created.', 'mma-future-data-engine' ), $bout['_mmaf_notices'] ?? array() ),
						)
					)
				);
				exit;
			}

			if ( 'update' === $action && $bout_id > 0 ) {
				$bout = $service->update( $bout_id, $_POST, get_current_user_id() );
				wp_safe_redirect(
					self::page_url(
						array(
							'action'      => 'edit',
							'bout_id'     => $bout_id,
							'mmaf_notice' => self::save_message( __( 'Bout updated.', 'mma-future-data-engine' ), $bout['_mmaf_notices'] ?? array() ),
						)
					)
				);
				exit;
			}

			return array(
				'type'    => 'error',
				'message' => __( 'Invalid bout action.', 'mma-future-data-engine' ),
			);
		} catch ( \Throwable $error ) {
			return array(
				'type'    => 'error',
				'message' => $error->getMessage(),
			);
		}
	}

	private static function render_list(): void {
		$repository = new BoutRepository();
		$events     = new EventRepository();
		$filters    = array(
			'event_id' => isset( $_GET['event_id'] ) ? absint( $_GET['event_id'] ) : 0,
			'fighter'  => isset( $_GET['fighter'] ) ? sanitize_text_field( wp_unslash( $_GET['fighter'] ) ) : '',
			'status'   => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
		);
		$per_page   = self::current_per_page();
		$paged      = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$total      = $repository->count_filtered( $filters );
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$paged      = min( $paged, $total_pages );
		$offset     = ( $paged - 1 ) * $per_page;
		$bouts      = $repository->list( $filters, $per_page, $offset );
		?>
		<form method="get" style="margin: 16px 0;">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
			<select name="event_id">
				<option value="0"><?php echo esc_html__( 'All events', 'mma-future-data-engine' ); ?></option>
				<?php foreach ( $events->list_for_select() as $event ) : ?>
					<option value="<?php echo esc_attr( (string) $event['id'] ); ?>" <?php selected( (int) $filters['event_id'], (int) $event['id'] ); ?>><?php echo esc_html( self::event_label( $event ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="search" name="fighter" value="<?php echo esc_attr( $filters['fighter'] ); ?>" placeholder="<?php echo esc_attr__( 'Fighter name', 'mma-future-data-engine' ); ?>">
			<select name="status">
				<option value=""><?php echo esc_html__( 'All statuses', 'mma-future-data-engine' ); ?></option>
				<?php foreach ( BoutService::BOUT_STATUSES as $status ) : ?>
					<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $filters['status'], $status ); ?>><?php echo esc_html( self::option_label( $status ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php self::render_per_page_select( $per_page ); ?>
			<?php submit_button( __( 'Filter', 'mma-future-data-engine' ), 'secondary', '', false ); ?>
		</form>

		<?php self::render_pagination( $total, $paged, $per_page, $filters ); ?>

		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'ID', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Event', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Event date', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Fighter A', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Fighter B', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Result', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Method', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Round/time', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Weight class', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Card position', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Status', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Scoring candidate', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Created at', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Updated at', 'mma-future-data-engine' ); ?></th>
					<th><?php echo esc_html__( 'Actions', 'mma-future-data-engine' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $bouts ) ) : ?>
					<tr><td colspan="15"><?php echo esc_html__( 'No canonical bouts found.', 'mma-future-data-engine' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $bouts as $bout ) : ?>
					<tr>
						<td><?php echo esc_html( (string) $bout['id'] ); ?></td>
						<td><strong><?php echo esc_html( (string) $bout['event_name'] ); ?></strong></td>
						<td><?php echo esc_html( (string) $bout['event_date'] ); ?></td>
						<td><?php echo esc_html( (string) $bout['fighter_a_name'] ); ?></td>
						<td><?php echo esc_html( (string) $bout['fighter_b_name'] ); ?></td>
						<td><?php echo esc_html( self::result_label( $bout ) ); ?></td>
						<td><?php echo esc_html( trim( (string) $bout['method_category'] . ' ' . (string) $bout['method_detail'] ) ); ?></td>
						<td><?php echo esc_html( trim( (string) $bout['round_number'] . ' / ' . (string) $bout['time_in_round'], ' /' ) ); ?></td>
						<td><?php echo esc_html( (string) $bout['weight_class'] ); ?></td>
						<td><?php echo esc_html( (string) $bout['card_position'] ); ?></td>
						<td><?php echo esc_html( (string) $bout['status'] ); ?></td>
						<td><?php echo esc_html( self::yes_no( $bout['is_scoring_candidate'] ) ); ?></td>
						<td><?php echo esc_html( (string) $bout['created_at'] ); ?></td>
						<td><?php echo esc_html( (string) $bout['updated_at'] ); ?></td>
						<td><a href="<?php echo esc_url( self::page_url( array( 'action' => 'edit', 'bout_id' => (int) $bout['id'] ) ) ); ?>"><?php echo esc_html__( 'Edit', 'mma-future-data-engine' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php self::render_pagination( $total, $paged, $per_page, $filters ); ?>
		<?php
	}

	private static function render_form( int $bout_id = 0 ): void {
		$bouts             = new BoutRepository();
		$participants_repo = new BoutParticipantRepository();
		$sources           = new BoutSourceRepository();
		$events            = new EventRepository();
		$fighters          = new FighterRepository();
		$is_edit           = $bout_id > 0;
		$bout              = $is_edit ? $bouts->find( $bout_id ) : self::default_bout();

		if ( ! $bout ) {
			self::render_notice( 'error', __( 'Bout not found.', 'mma-future-data-engine' ) );
			return;
		}

		$participants = $is_edit ? $participants_repo->list_by_bout( $bout_id ) : array();
		$by_role      = self::participants_by_role( $participants );
		$source       = $is_edit ? $sources->find_first_for_bout( $bout_id ) : self::default_source();
		if ( ! $source ) {
			$source = self::default_source();
		}

		$winner = '';
		if ( isset( $by_role['fighter_a']['is_winner'] ) && '1' === (string) $by_role['fighter_a']['is_winner'] ) {
			$winner = 'fighter_a';
		} elseif ( isset( $by_role['fighter_b']['is_winner'] ) && '1' === (string) $by_role['fighter_b']['is_winner'] ) {
			$winner = 'fighter_b';
		}
		?>
		<hr class="wp-header-end">
		<form method="post" action="<?php echo esc_url( self::page_url( $is_edit ? array( 'action' => 'edit', 'bout_id' => $bout_id ) : array( 'action' => 'new' ) ) ); ?>">
			<?php wp_nonce_field( 'mmaf_save_bout', 'mmaf_bout_nonce' ); ?>
			<input type="hidden" name="mmaf_action" value="<?php echo esc_attr( $is_edit ? 'update' : 'create' ); ?>">
			<input type="hidden" name="bout_id" value="<?php echo esc_attr( (string) $bout_id ); ?>">

			<?php self::open_section( __( 'Canonical bout', 'mma-future-data-engine' ) ); ?>
				<table class="form-table" role="presentation">
					<tbody>
						<?php self::event_select_row( 'event_id', __( 'Event', 'mma-future-data-engine' ), (int) $bout['event_id'], $events->list_for_select() ); ?>
						<?php self::fighter_select_row( 'fighter_a', __( 'Fighter A', 'mma-future-data-engine' ), (int) ( $by_role['fighter_a']['fighter_id'] ?? 0 ), $fighters->list_for_select() ); ?>
						<?php self::fighter_select_row( 'fighter_b', __( 'Fighter B', 'mma-future-data-engine' ), (int) ( $by_role['fighter_b']['fighter_id'] ?? 0 ), $fighters->list_for_select() ); ?>
						<?php self::text_row( 'bout_order', __( 'Bout order', 'mma-future-data-engine' ), $bout['bout_order'], false, '', 'number' ); ?>
						<?php self::select_row( 'card_position', __( 'Card position', 'mma-future-data-engine' ), (string) $bout['card_position'], BoutService::CARD_POSITIONS ); ?>
						<?php self::select_row( 'weight_class', __( 'Weight class', 'mma-future-data-engine' ), (string) $bout['weight_class'], Sanitizer::WEIGHT_CLASSES ); ?>
						<?php self::text_row( 'weight_lbs', __( 'Weight lbs', 'mma-future-data-engine' ), $bout['weight_lbs'], false, '', 'number' ); ?>
						<?php self::select_row( 'status', __( 'Status', 'mma-future-data-engine' ), (string) $bout['status'], BoutService::BOUT_STATUSES ); ?>
						<?php self::select_row( 'result_type', __( 'Result type', 'mma-future-data-engine' ), (string) $bout['result_type'], BoutService::RESULT_TYPES ); ?>
						<?php self::winner_row( $winner ); ?>
						<?php self::select_row( 'method_category', __( 'Method category', 'mma-future-data-engine' ), (string) $bout['method_category'], BoutService::METHOD_CATEGORIES ); ?>
						<?php self::text_row( 'method_detail', __( 'Method detail', 'mma-future-data-engine' ), $bout['method_detail'] ); ?>
						<?php self::text_row( 'round_number', __( 'Round number', 'mma-future-data-engine' ), $bout['round_number'], false, '', 'number' ); ?>
						<?php self::text_row( 'time_in_round', __( 'Time in round', 'mma-future-data-engine' ), $bout['time_in_round'], false, 'M:SS or MM:SS' ); ?>
						<?php self::checkbox_row( 'is_scoring_candidate', __( 'Scoring candidate', 'mma-future-data-engine' ), $bout['is_scoring_candidate'], __( 'Allowed only for valid/completed win/loss bouts. Draw, no contest, cancelled, unknown, pending, hidden, deleted, and excluded bouts are forced off.', 'mma-future-data-engine' ) ); ?>
						<?php self::checkbox_row( 'deleted_soft', __( 'Deleted soft', 'mma-future-data-engine' ), $bout['deleted_soft'] ); ?>
					</tbody>
				</table>
			<?php self::close_section(); ?>

			<?php self::open_section( __( 'Prefight records', 'mma-future-data-engine' ) ); ?>
				<table class="form-table" role="presentation">
					<tbody>
						<?php self::record_rows( 'fighter_a', __( 'Fighter A', 'mma-future-data-engine' ), $by_role['fighter_a'] ?? array() ); ?>
						<?php self::record_rows( 'fighter_b', __( 'Fighter B', 'mma-future-data-engine' ), $by_role['fighter_b'] ?? array() ); ?>
					</tbody>
				</table>
			<?php self::close_section(); ?>

			<?php self::open_section( __( 'External source profile', 'mma-future-data-engine' ) ); ?>
				<table class="form-table" role="presentation">
					<tbody>
						<?php self::fixed_source_type_row(); ?>
						<?php self::text_row( 'source_url', __( 'Tapology Bout URL', 'mma-future-data-engine' ), $source['source_url'] ?? '', true, 'https://www.tapology.com/fightcenter/bouts/12345-fighter-a-vs-fighter-b', 'url', __( 'Required. Tapology bout URLs derive source_bout_id and source_bout_numeric_id internally. Technical source IDs are not entered here.', 'mma-future-data-engine' ) ); ?>
					</tbody>
				</table>
			<?php self::close_section(); ?>

			<?php submit_button( $is_edit ? __( 'Update Bout', 'mma-future-data-engine' ) : __( 'Create Bout', 'mma-future-data-engine' ) ); ?>
		</form>
		<?php
	}

	private static function text_row( string $name, string $label, $value, bool $required = false, string $placeholder = '', string $type = 'text', string $help = '' ): void {
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<input type="<?php echo esc_attr( $type ); ?>" id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>" class="regular-text" placeholder="<?php echo esc_attr( $placeholder ); ?>" <?php echo $required ? 'required' : ''; ?>>
				<?php if ( '' !== $help ) : ?>
					<p class="description"><?php echo esc_html( $help ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	private static function fixed_source_type_row(): void {
		?>
		<tr>
			<th scope="row"><?php echo esc_html__( 'Source type', 'mma-future-data-engine' ); ?></th>
			<td>
				<code>tapology</code>
				<input type="hidden" name="source_type" value="tapology">
				<p class="description"><?php echo esc_html__( 'Manual saves use Tapology URLs as canonical source identity mappings.', 'mma-future-data-engine' ); ?></p>
			</td>
		</tr>
		<?php
	}

	private static function select_row( string $name, string $label, string $value, array $options, string $help = '' ): void {
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<select id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>">
					<?php foreach ( $options as $option ) : ?>
						<option value="<?php echo esc_attr( $option ); ?>" <?php selected( $value, $option ); ?>><?php echo esc_html( self::option_label( $option ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php if ( '' !== $help ) : ?>
					<p class="description"><?php echo esc_html( $help ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	private static function event_select_row( string $name, string $label, int $value, array $events ): void {
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<select id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" required>
					<option value="0"><?php echo esc_html__( 'Select event', 'mma-future-data-engine' ); ?></option>
					<?php foreach ( $events as $event ) : ?>
						<option value="<?php echo esc_attr( (string) $event['id'] ); ?>" <?php selected( $value, (int) $event['id'] ); ?>><?php echo esc_html( self::event_label( $event ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<?php
	}

	private static function fighter_select_row( string $name, string $label, int $value, array $fighters ): void {
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<select id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" required>
					<option value="0"><?php echo esc_html__( 'Select fighter', 'mma-future-data-engine' ); ?></option>
					<?php foreach ( $fighters as $fighter ) : ?>
						<option value="<?php echo esc_attr( (string) $fighter['id'] ); ?>" <?php selected( $value, (int) $fighter['id'] ); ?>><?php echo esc_html( self::fighter_label( $fighter ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<?php
	}

	private static function winner_row( string $winner ): void {
		?>
		<tr>
			<th scope="row"><label for="winner"><?php echo esc_html__( 'Winner', 'mma-future-data-engine' ); ?></label></th>
			<td>
				<select id="winner" name="winner">
					<option value=""><?php echo esc_html__( 'No winner / not win-loss', 'mma-future-data-engine' ); ?></option>
					<option value="fighter_a" <?php selected( $winner, 'fighter_a' ); ?>><?php echo esc_html__( 'Fighter A', 'mma-future-data-engine' ); ?></option>
					<option value="fighter_b" <?php selected( $winner, 'fighter_b' ); ?>><?php echo esc_html__( 'Fighter B', 'mma-future-data-engine' ); ?></option>
				</select>
				<p class="description"><?php echo esc_html__( 'Required only when result type is win_loss. Draw, no contest, cancelled, and unknown clear winner/loser internally.', 'mma-future-data-engine' ); ?></p>
			</td>
		</tr>
		<?php
	}

	private static function record_rows( string $prefix, string $label, array $participant ): void {
		echo '<tr><th colspan="2"><h3 style="margin: 0;">' . esc_html( $label ) . '</h3></th></tr>';
		self::text_row( $prefix . '_prefight_wins', __( 'Prefight wins', 'mma-future-data-engine' ), $participant['prefight_wins'] ?? '', false, '', 'number' );
		self::text_row( $prefix . '_prefight_losses', __( 'Prefight losses', 'mma-future-data-engine' ), $participant['prefight_losses'] ?? '', false, '', 'number' );
		self::text_row( $prefix . '_prefight_draws', __( 'Prefight draws', 'mma-future-data-engine' ), $participant['prefight_draws'] ?? '', false, '', 'number' );
		self::text_row( $prefix . '_prefight_nc', __( 'Prefight NC', 'mma-future-data-engine' ), $participant['prefight_nc'] ?? '', false, '', 'number' );
		self::text_row( $prefix . '_prefight_record_raw', __( 'Prefight record raw', 'mma-future-data-engine' ), $participant['prefight_record_raw'] ?? '', false, 'e.g. 7-1-0' );
	}

	private static function checkbox_row( string $name, string $label, $checked, string $help = '' ): void {
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $label ); ?></th>
			<td>
				<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( (int) $checked, 1 ); ?>> <?php echo esc_html__( 'Yes', 'mma-future-data-engine' ); ?></label>
				<?php if ( '' !== $help ) : ?>
					<p class="description"><?php echo esc_html( $help ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	private static function open_section( string $title ): void {
		echo '<div class="postbox" style="max-width: 980px; margin-top: 18px;">';
		echo '<div class="postbox-header"><h2>' . esc_html( $title ) . '</h2></div>';
		echo '<div class="inside">';
	}

	private static function close_section(): void {
		echo '</div></div>';
	}

	private static function render_notice( string $type, string $message ): void {
		$class = 'error' === $type ? 'notice notice-error' : 'notice notice-success';
		echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( $message ) . '</p></div>';
	}

	private static function save_message( string $base, array $notices ): string {
		if ( empty( $notices ) ) {
			return $base;
		}

		return $base . ' ' . __( 'Adjusted values:', 'mma-future-data-engine' ) . ' ' . implode( ' ', $notices );
	}

	private static function page_url( array $args = array() ): string {
		return add_query_arg(
			array_merge( array( 'page' => self::PAGE_SLUG ), $args ),
			admin_url( 'admin.php' )
		);
	}

	private static function current_per_page(): int {
		$per_page = isset( $_GET['per_page'] ) ? absint( $_GET['per_page'] ) : 50;

		return in_array( $per_page, array( 25, 50, 100 ), true ) ? $per_page : 50;
	}

	private static function render_per_page_select( int $per_page ): void {
		?>
		<label for="mmaf-per-page" style="margin-left: 8px;"><?php echo esc_html__( 'Per page', 'mma-future-data-engine' ); ?></label>
		<select id="mmaf-per-page" name="per_page">
			<?php foreach ( array( 25, 50, 100 ) as $option ) : ?>
				<option value="<?php echo esc_attr( (string) $option ); ?>" <?php selected( $per_page, $option ); ?>><?php echo esc_html( (string) $option ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	private static function render_pagination( int $total, int $paged, int $per_page, array $filters ): void {
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$args        = array(
			'page'     => self::PAGE_SLUG,
			'per_page' => $per_page,
		);

		foreach ( $filters as $key => $value ) {
			if ( 'event_id' === $key && (int) $value <= 0 ) {
				continue;
			}

			if ( '' !== (string) $value ) {
				$args[ $key ] = $value;
			}
		}

		echo '<div class="tablenav top" style="margin: 10px 0;">';
		echo '<div class="alignleft actions"><span class="displaying-num">' . esc_html( sprintf( _n( '%s bout', '%s bouts', $total, 'mma-future-data-engine' ), number_format_i18n( $total ) ) ) . '</span></div>';

		if ( $total_pages > 1 ) {
			$base = add_query_arg( array_merge( $args, array( 'paged' => '%#%' ) ), admin_url( 'admin.php' ) );
			echo '<div class="tablenav-pages">' . wp_kses_post(
				paginate_links(
					array(
						'base'      => $base,
						'format'    => '',
						'current'   => $paged,
						'total'     => $total_pages,
						'prev_text' => __( '&laquo;', 'mma-future-data-engine' ),
						'next_text' => __( '&raquo;', 'mma-future-data-engine' ),
					)
				)
			) . '</div>';
		}

		echo '<br class="clear"></div>';
	}

	private static function default_bout(): array {
		return array(
			'id'                   => 0,
			'event_id'             => 0,
			'bout_order'           => '',
			'card_position'        => 'unknown',
			'weight_class'         => 'unknown',
			'weight_lbs'           => '',
			'status'               => 'valid',
			'result_type'          => 'unknown',
			'method_category'      => 'unknown',
			'method_detail'        => '',
			'round_number'         => '',
			'time_in_round'        => '',
			'is_scoring_candidate' => 0,
			'deleted_soft'         => 0,
		);
	}

	private static function default_source(): array {
		return array(
			'source_type' => 'tapology',
			'source_url'  => '',
		);
	}

	private static function participants_by_role( array $participants ): array {
		$by_role = array();
		foreach ( $participants as $participant ) {
			$by_role[ $participant['participant_role'] ] = $participant;
		}

		return $by_role;
	}

	private static function event_label( array $event ): string {
		return trim( '#' . $event['id'] . ' ' . (string) $event['event_name'] . ' | ' . (string) $event['event_date'] . ' | ' . (string) $event['promotion_name'], ' |' );
	}

	private static function fighter_label( array $fighter ): string {
		$nickname = ! empty( $fighter['nickname'] ) ? ' "' . $fighter['nickname'] . '"' : '';
		$weight   = ! empty( $fighter['weight_class'] ) ? ' | ' . $fighter['weight_class'] : '';

		return '#' . $fighter['id'] . ' ' . $fighter['display_name'] . $nickname . $weight;
	}

	private static function result_label( array $bout ): string {
		if ( 'win_loss' === (string) $bout['result_type'] ) {
			return trim( (string) $bout['fighter_a_result'] . ' / ' . (string) $bout['fighter_b_result'], ' /' );
		}

		return (string) $bout['result_type'];
	}

	private static function yes_no( $value ): string {
		return (int) $value ? __( 'Yes', 'mma-future-data-engine' ) : __( 'No', 'mma-future-data-engine' );
	}

	private static function option_label( string $option ): string {
		return str_replace( '_', ' ', $option );
	}
}
