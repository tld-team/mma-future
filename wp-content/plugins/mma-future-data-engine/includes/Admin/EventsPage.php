<?php
namespace MMAF\DataEngine\Admin;

use MMAF\DataEngine\Repositories\EventRepository;
use MMAF\DataEngine\Repositories\EventSourceRepository;
use MMAF\DataEngine\Services\EventService;
use MMAF\DataEngine\Support\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EventsPage {
	private const PAGE_SLUG = 'mmaf-events';

	public static function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mma-future-data-engine' ) );
		}

		$notice = null;

		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : '';

		if ( 'POST' === $request_method ) {
			$notice = self::handle_post();
		}

		$action   = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list';
		$event_id = isset( $_GET['event_id'] ) ? absint( $_GET['event_id'] ) : 0;

		if ( isset( $_GET['mmaf_notice'] ) ) {
			$notice = array(
				'type'    => 'success',
				'message' => sanitize_text_field( wp_unslash( $_GET['mmaf_notice'] ) ),
			);
		}

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Canonical Events', 'mma-future-data-engine' ) . '</h1> ';
		echo '<a class="page-title-action" href="' . esc_url( self::page_url( array( 'action' => 'new' ) ) ) . '">' . esc_html__( 'Add New Event', 'mma-future-data-engine' ) . '</a>';

		if ( $notice ) {
			self::render_notice( $notice['type'], $notice['message'] );
		}

		if ( 'new' === $action ) {
			self::render_form();
		} elseif ( 'edit' === $action && $event_id > 0 ) {
			self::render_form( $event_id );
		} else {
			self::render_list();
		}

		echo '</div>';
	}

	private static function handle_post(): ?array {
		if ( ! isset( $_POST['mmaf_event_nonce'] ) ) {
			return array(
				'type'    => 'error',
				'message' => __( 'Missing security token.', 'mma-future-data-engine' ),
			);
		}

		check_admin_referer( 'mmaf_save_event', 'mmaf_event_nonce' );

		$action   = isset( $_POST['mmaf_action'] ) ? sanitize_key( wp_unslash( $_POST['mmaf_action'] ) ) : '';
		$event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
		$service  = new EventService();

		try {
			if ( 'create' === $action ) {
				$event = $service->create( $_POST, get_current_user_id() );
				wp_safe_redirect(
					self::page_url(
						array(
							'action'      => 'edit',
							'event_id'    => (int) $event['id'],
							'mmaf_notice' => self::save_message( __( 'Event created.', 'mma-future-data-engine' ), $event['_mmaf_notices'] ?? array() ),
						)
					)
				);
				exit;
			}

			if ( 'update' === $action && $event_id > 0 ) {
				$event = $service->update( $event_id, $_POST, get_current_user_id() );
				wp_safe_redirect(
					self::page_url(
						array(
							'action'      => 'edit',
							'event_id'    => $event_id,
							'mmaf_notice' => self::save_message( __( 'Event updated.', 'mma-future-data-engine' ), $event['_mmaf_notices'] ?? array() ),
						)
					)
				);
				exit;
			}

			return array(
				'type'    => 'error',
				'message' => __( 'Invalid event action.', 'mma-future-data-engine' ),
			);
		} catch ( \Throwable $error ) {
			return array(
				'type'    => 'error',
				'message' => $error->getMessage(),
			);
		}
	}

	private static function render_list(): void {
		$events_repository = new EventRepository();
		$sources           = new EventSourceRepository();
		$search            = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$orderby           = self::current_orderby();
		$order             = self::current_order();
		$per_page          = self::current_per_page();
		$paged             = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$total             = $events_repository->count( $search );
		$total_pages       = max( 1, (int) ceil( $total / $per_page ) );
		$paged             = min( $paged, $total_pages );
		$offset            = ( $paged - 1 ) * $per_page;
		$events            = $events_repository->list( $search, $per_page, $offset, $orderby, $order );
		$query_filters     = array(
			's'        => $search,
			'per_page' => $per_page,
			'orderby' => $orderby,
			'order'   => $order,
		);
		?>
		<form method="get" style="margin: 16px 0;">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
			<label class="screen-reader-text" for="mmaf-event-search"><?php echo esc_html__( 'Search events', 'mma-future-data-engine' ); ?></label>
			<input id="mmaf-event-search" type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php echo esc_attr__( 'Search event or promotion', 'mma-future-data-engine' ); ?>">
			<?php self::render_per_page_select( $per_page ); ?>
			<?php self::render_sort_controls( $orderby, $order ); ?>
			<?php submit_button( __( 'Search', 'mma-future-data-engine' ), 'secondary', '', false ); ?>
		</form>

		<?php self::render_pagination( $total, $paged, $per_page, $query_filters ); ?>

		<table class="widefat striped">
			<thead>
				<tr>
					<?php self::sortable_header( 'id', __( 'ID', 'mma-future-data-engine' ), $orderby, $order, $query_filters ); ?>
					<?php self::sortable_header( 'event_name', __( 'Event name', 'mma-future-data-engine' ), $orderby, $order, $query_filters ); ?>
					<?php self::sortable_header( 'event_date', __( 'Event date', 'mma-future-data-engine' ), $orderby, $order, $query_filters ); ?>
					<?php self::sortable_header( 'promotion_name', __( 'Promotion', 'mma-future-data-engine' ), $orderby, $order, $query_filters ); ?>
					<?php self::sortable_header( 'venue', __( 'Venue', 'mma-future-data-engine' ), $orderby, $order, $query_filters ); ?>
					<?php self::sortable_header( 'location', __( 'Location', 'mma-future-data-engine' ), $orderby, $order, $query_filters ); ?>
					<?php self::sortable_header( 'city', __( 'City', 'mma-future-data-engine' ), $orderby, $order, $query_filters ); ?>
					<?php self::sortable_header( 'country', __( 'Country', 'mma-future-data-engine' ), $orderby, $order, $query_filters ); ?>
					<?php self::sortable_header( 'status', __( 'Status', 'mma-future-data-engine' ), $orderby, $order, $query_filters ); ?>
					<th><?php echo esc_html__( 'Source profile', 'mma-future-data-engine' ); ?></th>
					<?php self::sortable_header( 'created_at', __( 'Created at', 'mma-future-data-engine' ), $orderby, $order, $query_filters ); ?>
					<?php self::sortable_header( 'updated_at', __( 'Updated at', 'mma-future-data-engine' ), $orderby, $order, $query_filters ); ?>
					<th><?php echo esc_html__( 'Actions', 'mma-future-data-engine' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $events ) ) : ?>
					<tr><td colspan="13"><?php echo esc_html__( 'No canonical events found.', 'mma-future-data-engine' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $events as $event ) : ?>
					<?php $source = $sources->find_first_for_event( (int) $event['id'] ); ?>
					<tr>
						<td><?php echo esc_html( (string) $event['id'] ); ?></td>
						<td><strong><?php echo esc_html( $event['event_name'] ); ?></strong></td>
						<td><?php echo esc_html( (string) $event['event_date'] ); ?></td>
						<td><?php echo esc_html( (string) $event['promotion_name'] ); ?></td>
						<td><?php echo esc_html( (string) $event['venue'] ); ?></td>
						<td><?php echo esc_html( (string) $event['location'] ); ?></td>
						<td><?php echo esc_html( (string) $event['city'] ); ?></td>
						<td><?php echo esc_html( (string) $event['country'] ); ?></td>
						<td><?php echo esc_html( (string) $event['status'] ); ?></td>
						<td><?php echo esc_html( $source ? (string) $source['source_type'] : __( 'None', 'mma-future-data-engine' ) ); ?></td>
						<td><?php echo esc_html( (string) $event['created_at'] ); ?></td>
						<td><?php echo esc_html( (string) $event['updated_at'] ); ?></td>
						<td><a href="<?php echo esc_url( self::page_url( array( 'action' => 'edit', 'event_id' => (int) $event['id'] ) ) ); ?>"><?php echo esc_html__( 'Edit', 'mma-future-data-engine' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php self::render_pagination( $total, $paged, $per_page, $query_filters ); ?>
		<?php
	}

	private static function render_form( int $event_id = 0 ): void {
		$events = new EventRepository();
		$sources = new EventSourceRepository();
		$is_edit = $event_id > 0;
		$event = $is_edit ? $events->find( $event_id ) : self::default_event();

		if ( ! $event ) {
			self::render_notice( 'error', __( 'Event not found.', 'mma-future-data-engine' ) );
			return;
		}

		$source = $is_edit ? $sources->find_first_for_event( $event_id ) : self::default_source();
		if ( ! $source ) {
			$source = self::default_source();
		}

		$source_slug = $source['source_slug'] ?? self::derive_event_slug_for_display( (string) ( $source['source_url'] ?? '' ) );
		?>
		<hr class="wp-header-end">
		<form method="post" action="<?php echo esc_url( self::page_url( $is_edit ? array( 'action' => 'edit', 'event_id' => $event_id ) : array( 'action' => 'new' ) ) ); ?>">
			<?php wp_nonce_field( 'mmaf_save_event', 'mmaf_event_nonce' ); ?>
			<input type="hidden" name="mmaf_action" value="<?php echo esc_attr( $is_edit ? 'update' : 'create' ); ?>">
			<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $event_id ); ?>">

			<?php self::open_section( __( 'Canonical event', 'mma-future-data-engine' ) ); ?>
				<p><?php echo esc_html__( 'Event date is the local/source event date if exact timezone is not known. UTC datetime is optional and should only be used when reliable. Do not invent timezone data.', 'mma-future-data-engine' ); ?></p>
				<table class="form-table" role="presentation">
					<tbody>
						<?php self::text_row( 'event_name', __( 'Event name', 'mma-future-data-engine' ), $event['event_name'], true ); ?>
						<?php self::text_row( 'event_date', __( 'Event date', 'mma-future-data-engine' ), $event['event_date'], false, 'YYYY-MM-DD', 'date' ); ?>
						<?php self::text_row( 'event_datetime_utc', __( 'UTC datetime', 'mma-future-data-engine' ), $event['event_datetime_utc'], false, 'YYYY-MM-DD HH:MM:SS' ); ?>
						<?php self::text_row( 'timezone_raw', __( 'Timezone raw', 'mma-future-data-engine' ), $event['timezone_raw'] ); ?>
						<?php self::text_row( 'promotion_name', __( 'Promotion name', 'mma-future-data-engine' ), $event['promotion_name'] ); ?>
						<?php self::text_row( 'venue', __( 'Venue', 'mma-future-data-engine' ), $event['venue'] ); ?>
						<?php self::text_row( 'location', __( 'Location', 'mma-future-data-engine' ), $event['location'] ); ?>
						<?php self::text_row( 'city', __( 'City', 'mma-future-data-engine' ), $event['city'] ); ?>
						<?php self::text_row( 'country', __( 'Country', 'mma-future-data-engine' ), $event['country'] ); ?>
						<?php self::text_row( 'region', __( 'Region', 'mma-future-data-engine' ), $event['region'] ); ?>
						<?php self::select_row( 'status', __( 'Status', 'mma-future-data-engine' ), $event['status'], EventService::EVENT_STATUSES, __( 'deleted_soft is for intentional hiding/removal from normal use, not normal event review.', 'mma-future-data-engine' ) ); ?>
						<?php self::checkbox_row( 'deleted_soft', __( 'Deleted soft', 'mma-future-data-engine' ), $event['deleted_soft'], __( 'Use only for intentional hiding/removal. Events are not hard-deleted in this admin flow.', 'mma-future-data-engine' ) ); ?>
					</tbody>
				</table>
			<?php self::close_section(); ?>

			<?php self::open_section( __( 'External source profile', 'mma-future-data-engine' ) ); ?>
				<p><?php echo esc_html__( 'Tapology Event URL is required for every manual event save. Manual edits remain allowed and are tracked through audit/provenance. A source profile is an identity reference, not automatic truth for every field.', 'mma-future-data-engine' ); ?></p>
				<table class="form-table" role="presentation">
					<tbody>
						<?php self::fixed_source_type_row(); ?>
						<?php self::text_row( 'source_url', __( 'Tapology Event URL', 'mma-future-data-engine' ), $source['source_url'] ?? '', true, 'https://www.tapology.com/fightcenter/events/12345-event-name', 'url', __( 'Required. Tapology event URLs derive source_event_id and source_event_numeric_id internally.', 'mma-future-data-engine' ) ); ?>
						<?php self::readonly_text_row( 'source_slug', __( 'Source event slug', 'mma-future-data-engine' ), $source_slug, __( 'Derived from a Tapology event URL when safely parseable.', 'mma-future-data-engine' ) ); ?>
						<?php self::text_row( 'source_promotion_url', __( 'Source promotion URL', 'mma-future-data-engine' ), $source['source_promotion_url'] ?? '', false, '', 'url', __( 'Optional. Tapology promotion URLs derive source_promotion_id and source_promotion_numeric_id internally.', 'mma-future-data-engine' ) ); ?>
						<?php self::checkbox_row( 'is_verified', __( 'Source verified', 'mma-future-data-engine' ), $source['is_verified'] ?? 0 ); ?>
						<?php self::checkbox_row( 'is_primary', __( 'Primary source', 'mma-future-data-engine' ), $source['is_primary'] ?? 0 ); ?>
					</tbody>
				</table>
			<?php self::close_section(); ?>

			<?php submit_button( $is_edit ? __( 'Update Event', 'mma-future-data-engine' ) : __( 'Create Event', 'mma-future-data-engine' ) ); ?>
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

	private static function readonly_text_row( string $name, string $label, $value, string $help = '' ): void {
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<input type="text" id="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>" class="regular-text" readonly>
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
						<option value="<?php echo esc_attr( $option ); ?>" <?php selected( $value, $option ); ?>><?php echo esc_html( str_replace( '_', ' ', $option ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php if ( '' !== $help ) : ?>
					<p class="description"><?php echo esc_html( $help ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
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

		return $base . ' ' . __( 'Notes:', 'mma-future-data-engine' ) . ' ' . implode( ' ', $notices );
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

	private static function current_orderby(): string {
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'event_date';

		return array_key_exists( $orderby, EventRepository::sortable_columns() ) ? $orderby : 'event_date';
	}

	private static function current_order(): string {
		$order = isset( $_GET['order'] ) ? strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) : 'desc';

		return in_array( $order, array( 'asc', 'desc' ), true ) ? $order : 'desc';
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

	private static function render_sort_controls( string $orderby, string $order ): void {
		?>
		<label for="mmaf-orderby" style="margin-left: 8px;"><?php echo esc_html__( 'Sort by', 'mma-future-data-engine' ); ?></label>
		<select id="mmaf-orderby" name="orderby">
			<?php foreach ( self::sort_labels() as $key => $label ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $orderby, $key ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<label for="mmaf-order" style="margin-left: 8px;"><?php echo esc_html__( 'Order', 'mma-future-data-engine' ); ?></label>
		<select id="mmaf-order" name="order">
			<option value="desc" <?php selected( $order, 'desc' ); ?>><?php echo esc_html__( 'Descending', 'mma-future-data-engine' ); ?></option>
			<option value="asc" <?php selected( $order, 'asc' ); ?>><?php echo esc_html__( 'Ascending', 'mma-future-data-engine' ); ?></option>
		</select>
		<?php
	}

	private static function sortable_header( string $key, string $label, string $current_orderby, string $current_order, array $filters ): void {
		$is_current = $key === $current_orderby;
		$default_order = self::default_sort_order( $key );
		$next_order = $is_current && $default_order === $current_order ? self::opposite_order( $current_order ) : $default_order;
		$class = $is_current ? 'sorted ' . $current_order : 'sortable ' . $default_order;
		$args = array_merge(
			$filters,
			array(
				'orderby' => $key,
				'order'   => $next_order,
			)
		);
		?>
		<th class="<?php echo esc_attr( $class ); ?>">
			<a href="<?php echo esc_url( self::page_url( $args ) ); ?>">
				<span><?php echo esc_html( $label ); ?></span>
				<span class="sorting-indicator"></span>
			</a>
		</th>
		<?php
	}

	private static function default_sort_order( string $key ): string {
		return in_array( $key, array( 'id', 'event_date', 'created_at', 'updated_at' ), true ) ? 'desc' : 'asc';
	}

	private static function opposite_order( string $order ): string {
		return 'asc' === $order ? 'desc' : 'asc';
	}

	private static function sort_labels(): array {
		return array(
			'event_date'     => __( 'Event date', 'mma-future-data-engine' ),
			'created_at'     => __( 'Created at', 'mma-future-data-engine' ),
			'updated_at'     => __( 'Updated at', 'mma-future-data-engine' ),
			'id'             => __( 'ID', 'mma-future-data-engine' ),
			'event_name'     => __( 'Event name', 'mma-future-data-engine' ),
			'promotion_name' => __( 'Promotion', 'mma-future-data-engine' ),
			'venue'          => __( 'Venue', 'mma-future-data-engine' ),
			'location'       => __( 'Location', 'mma-future-data-engine' ),
			'city'           => __( 'City', 'mma-future-data-engine' ),
			'country'        => __( 'Country', 'mma-future-data-engine' ),
			'status'         => __( 'Status', 'mma-future-data-engine' ),
		);
	}

	private static function render_pagination( int $total, int $paged, int $per_page, array $filters ): void {
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$args        = array(
			'page'     => self::PAGE_SLUG,
			'per_page' => $per_page,
		);

		foreach ( $filters as $key => $value ) {
			if ( '' !== (string) $value ) {
				$args[ $key ] = $value;
			}
		}

		echo '<div class="tablenav top" style="margin: 10px 0;">';
		echo '<div class="alignleft actions"><span class="displaying-num">' . esc_html( sprintf( _n( '%s event', '%s events', $total, 'mma-future-data-engine' ), number_format_i18n( $total ) ) ) . '</span></div>';

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

	private static function default_event(): array {
		return array(
			'id'                    => 0,
			'event_name'            => '',
			'normalized_event_name' => '',
			'event_date'            => '',
			'event_datetime_utc'    => '',
			'timezone_raw'          => '',
			'promotion_name'        => '',
			'venue'                 => '',
			'location'              => '',
			'city'                  => '',
			'country'               => '',
			'region'                => '',
			'status'                => 'valid',
			'deleted_soft'          => 0,
		);
	}

	private static function default_source(): array {
		return array(
			'source_type' => 'tapology',
			'source_url'  => '',
			'source_slug' => '',
			'source_promotion_url' => '',
			'is_verified' => 0,
			'is_primary'  => 0,
		);
	}

	private static function derive_event_slug_for_display( string $url ): string {
		if ( preg_match( '~^https?://(?:www\.)?tapology\.com/fightcenter/events/\d+-([^/?#]+)~i', $url, $matches ) ) {
			return sanitize_title( $matches[1] );
		}

		return '';
	}
}
