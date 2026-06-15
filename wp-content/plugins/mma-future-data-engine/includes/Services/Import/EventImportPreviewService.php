<?php
namespace MMAF\DataEngine\Services\Import;

use MMAF\DataEngine\Migrations\Schema;
use MMAF\DataEngine\Repositories\EventRepository;
use MMAF\DataEngine\Repositories\EventSourceRepository;
use MMAF\DataEngine\Support\Sanitizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EventImportPreviewService {
	private EventRepository $events;
	private EventSourceRepository $event_sources;

	public function __construct() {
		$this->events        = new EventRepository();
		$this->event_sources = new EventSourceRepository();
	}

	public function preview_events( array $events, array &$warnings, array &$conflicts ): array {
		$previews        = array();
		$source_ids      = array();
		$identity_hashes = array();
		$actions         = array(
			'create_candidate'     => 0,
			'update_candidate'     => 0,
			'no_change_candidate'  => 0,
			'review_event_match'   => 0,
		);

		foreach ( $events as $index => $event ) {
			$source_event_id = (string) ( $event['source_event_id'] ?? '' );
			$identity_hash   = (string) ( $event['identity_hash'] ?? '' );

			if ( '' !== $source_event_id ) {
				if ( isset( $source_ids[ $source_event_id ] ) ) {
					$conflicts[] = 'Duplicate source_event_id in JSON: ' . $source_event_id;
				}
				$source_ids[ $source_event_id ] = true;
			}

			if ( '' !== $identity_hash ) {
				if ( isset( $identity_hashes[ $identity_hash ] ) ) {
					$conflicts[] = 'Duplicate event identity_hash in JSON: ' . $identity_hash;
				}
				$identity_hashes[ $identity_hash ] = true;
			}

			$preview = $this->preview_event( $event, $index, $warnings, $conflicts );
			$actions[ $preview['action'] ] = ( $actions[ $preview['action'] ] ?? 0 ) + 1;
			$previews[] = $preview;
		}

		return array(
			'items'   => $previews,
			'actions' => $actions,
			'by_source_event_id' => $this->index_by_source_event_id( $previews ),
		);
	}

	private function preview_event( array $event, int $index, array &$warnings, array &$conflicts ): array {
		$source_event_id = (string) ( $event['source_event_id'] ?? '' );
		$content_hash    = (string) ( $event['content_hash'] ?? '' );
		$identity_hash   = (string) ( $event['identity_hash'] ?? '' );
		$event_name      = (string) ( $event['event_name'] ?? '' );
		$event_date      = isset( $event['event_date'] ) ? (string) $event['event_date'] : null;
		$matched_event   = null;
		$matched_source  = null;
		$action          = 'create_candidate';
		$reason          = 'No source mapping or name/date match found.';
		$warning_count   = count( (array) ( $event['warnings'] ?? array() ) );

		if ( '' === $event_date ) {
			$event_date = null;
		}

		if ( '' !== $source_event_id ) {
			$matched_source = $this->event_sources->find_by_source( 'tapology', $source_event_id );
		}

		if ( $matched_source ) {
			$matched_event = ! empty( $matched_source['event_id'] ) ? $this->events->find( (int) $matched_source['event_id'] ) : null;
			$action        = (string) $matched_source['content_hash'] === $content_hash ? 'no_change_candidate' : 'update_candidate';
			$reason        = 'Exact source event mapping exists.';

			if ( ! empty( $matched_source['identity_hash'] ) && $matched_source['identity_hash'] !== $identity_hash ) {
				$conflicts[] = 'source_event_id ' . $source_event_id . ' is mapped but incoming identity_hash differs.';
			}
		} else {
			$duplicate = $this->events->find_duplicate_name_date( Sanitizer::normalize_name( $event_name ), $event_date );
			if ( $duplicate ) {
				$matched_event = $duplicate;
				$action        = 'review_event_match';
				$reason        = 'Canonical event with the same normalized name and date exists.';
			}
		}

		if ( null === $event_date ) {
			$warnings[] = 'Event ' . ( '' !== $source_event_id ? $source_event_id : '#' . ( $index + 1 ) ) . ' is missing event_date.';
			++$warning_count;
		}

		return array(
			'index'             => $index,
			'source_event_id'   => $source_event_id,
			'event_name'        => $event_name,
			'event_date'        => $event_date,
			'promotion_name'    => (string) ( $event['promotion_name'] ?? '' ),
			'venue'             => (string) ( $event['venue'] ?? '' ),
			'location'          => (string) ( $event['location'] ?? '' ),
			'source_url'        => (string) ( $event['event_url'] ?? '' ),
			'identity_hash'     => $identity_hash,
			'content_hash'      => $content_hash,
			'action'            => $action,
			'reason'            => $reason,
			'matched_event_id'  => $matched_event ? (int) $matched_event['id'] : null,
			'matched_event'     => $matched_event ? (string) $matched_event['event_name'] : null,
			'warning_count'     => $warning_count,
		);
	}

	private function index_by_source_event_id( array $previews ): array {
		$indexed = array();

		foreach ( $previews as $preview ) {
			if ( '' !== $preview['source_event_id'] ) {
				$indexed[ $preview['source_event_id'] ] = $preview;
			}
		}

		return $indexed;
	}
}
