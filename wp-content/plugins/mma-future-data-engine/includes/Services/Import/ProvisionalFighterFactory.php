<?php
namespace MMAF\DataEngine\Services\Import;
use MMAF\DataEngine\Repositories\{FighterRepository,FighterSourceRepository};
use MMAF\DataEngine\Services\{FighterPostSyncService,FieldProvenanceService,AuditLogService};
use MMAF\DataEngine\Support\{DateTime,Sanitizer,TapologyFighterUrl};
use MMAF\DataEngine\Services\RegularImport\{Mutex,Transaction,WriteGuard};
use MMAF\DataEngine\Migrations\Schema;

/** Both event imports and verified priority profiles create the same unpublished candidate. */
final class ProvisionalFighterFactory {
    private const SOURCE='tapology';
    private FighterRepository $fighters;
    private FighterSourceRepository $fighter_sources;
    private FighterPostSyncService $post_sync;
    private FieldProvenanceService $provenance;
    private AuditLogService $audit_log;
    private array $tables;
    public function __construct(){
        $this->fighters=new FighterRepository();$this->fighter_sources=new FighterSourceRepository();
        $this->post_sync=new FighterPostSyncService($this->fighters);$this->provenance=new FieldProvenanceService();
        $this->audit_log=new AuditLogService();$this->tables=Schema::table_names();
    }
    public function create(array $ref,int $actor): array {
        WriteGuard::assertAllowed();
        return Mutex::run(function()use($ref,$actor){return Transaction::run(function()use($ref,$actor){
            global $wpdb;$identity=TapologyFighterUrl::parse((string)($ref['url']??''));
            if(!$identity || !trim((string)($ref['name']??'')) || ($identity['source_fighter_id'] && ($ref['source_fighter_id']??null)!==$identity['source_fighter_id'])){throw new \InvalidArgumentException('Invalid provisional source identity/name.');}
            $table=$this->tables['fighter_sources'];
            $rows=$identity['source_fighter_id']?$wpdb->get_col($wpdb->prepare("SELECT DISTINCT fighter_id FROM $table WHERE source_type='tapology' AND source_fighter_id=%s",$identity['source_fighter_id'])):$wpdb->get_col($wpdb->prepare("SELECT DISTINCT fighter_id FROM $table WHERE source_type='tapology' AND source_url=%s",$identity['canonical_url']));
            if($wpdb->last_error || count($rows)>1){throw new \RuntimeException('Ambiguous source identity; resolve before creating a candidate.');}
            if($rows){return ['id'=>(int)$rows[0],'created'=>false];}
            return ['id'=>$this->insert($ref,$actor),'created'=>true];
        });});
    }
	private function insert( array $ref, int $user_id ): int {
		global $wpdb;

		$name = sanitize_text_field( (string) $ref['name'] );
		$row  = array(
			'wp_post_id'          => null,
			'display_name'        => $name,
			'nickname'            => null,
			'normalized_name'     => Sanitizer::normalize_name( $name ),
			'gender'              => null,
			'date_of_birth'       => null,
			'birth_year'          => null,
			'nationality'         => null,
			'weight_class'        => 'unknown',
			'status'              => 'provisional',
			'rankability_status'  => 'pending_review',
			'is_public'           => 0,
			'is_rankable'         => 0,
			'in_ufc'              => 0,
			'deleted_soft'        => 0,
		);

		\MMAF\DataEngine\Services\RegularImport\Transaction::begin();

		try {
			$fighter_id = $this->fighters->insert( $row );
			$fighter    = $this->fighters->find( $fighter_id );
			if ( ! $fighter ) {
				throw new \RuntimeException( 'Could not reload provisional fighter.' );
			}

			$post_id = $this->post_sync->sync( $fighter_id, $fighter );
			$this->insert_fighter_source( $fighter_id, $ref );

			foreach ( array( 'display_name', 'weight_class', 'status', 'rankability_status', 'is_public', 'is_rankable', 'in_ufc' ) as $field ) {
				$this->provenance->upsert_source( 'fighter', $fighter_id, $field, $row[ $field ] ?? null, self::SOURCE, (string) ( $ref['source_fighter_id'] ?? '' ), $user_id );
			}

			$after = $this->fighters->find( $fighter_id );
			$this->audit_log->write( 'fighter_created_from_scraper', 'fighter', $fighter_id, null, $after, 'Tapology scraper JSON import created a provisional fighter.', $user_id );

			\MMAF\DataEngine\Services\RegularImport\Transaction::commit();

			return $fighter_id;
		} catch ( \Throwable $error ) {
			\MMAF\DataEngine\Services\RegularImport\Transaction::rollback();
			throw $error;
		}
	}

	private function insert_fighter_source( int $fighter_id, array $ref ): void {
		global $wpdb;

		$now = DateTime::mysql_now();
		$tapology = TapologyFighterUrl::parse( (string) ( $ref['url'] ?? '' ) );
		$url = $tapology ? (string) $tapology['canonical_url'] : esc_url_raw( TapologyFighterUrl::normalize( (string) ( $ref['url'] ?? '' ) ) );
		$source_fighter_id = $tapology ? $tapology['source_fighter_id'] : ( isset( $ref['source_fighter_id'] ) ? (string) $ref['source_fighter_id'] : null );
		$source_numeric_id = $tapology ? $tapology['source_numeric_id'] : ( isset( $ref['source_fighter_numeric_id'] ) ? (string) $ref['source_fighter_numeric_id'] : null );
		$source_slug       = $tapology ? $tapology['source_slug'] : null;
		$identity_hash     = $this->fighter_sources->identity_hash_for_source( self::SOURCE, $source_fighter_id, $url );

		\MMAF\DataEngine\Services\RegularImport\Repository::check($wpdb->insert(
			$this->tables['fighter_sources'],
			array(
				'fighter_id'          => $fighter_id,
				'source_type'         => self::SOURCE,
				'source_fighter_id'   => $source_fighter_id,
				'source_numeric_id'   => $source_numeric_id,
				'source_url'          => '' !== $url ? $url : null,
				'source_slug'         => $source_slug,
				'identity_hash'       => $identity_hash,
				'confidence'          => 100,
				'is_verified'         => 0,
				'is_primary'          => 1,
				'created_at'          => $now,
				'updated_at'          => null,
			)
		));
	}

}
