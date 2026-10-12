<?php
namespace MMAF\DataEngine\Services\Audit;

use MMAF\DataEngine\Migrations\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FighterDuplicateAuditService {
	public function audit( int $limit = 50 ): array {
		global $wpdb;

		$tables = Schema::table_names();
		$limit  = max( 1, min( 100, $limit ) );

		$exact_groups = $wpdb->get_results(
			"
			SELECT normalized_name, COUNT(*) AS fighter_count, GROUP_CONCAT(id ORDER BY id ASC) AS fighter_ids
			FROM {$tables['fighters']}
			WHERE deleted_soft = 0
				AND normalized_name IS NOT NULL
				AND normalized_name <> ''
			GROUP BY normalized_name
			HAVING fighter_count > 1
			ORDER BY fighter_count DESC, normalized_name ASC
			LIMIT 50
			", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

        // Materialize normalized identity keys in bounded batches. The former nested
        // PHP scan kept every fighter in memory and compared every pair.
        $suffix=bin2hex(random_bytes(6));$scraped_table='mmaf_audit_scraped_'.$suffix;$existing_table='mmaf_audit_existing_'.$suffix;
        $definition="(id bigint unsigned NOT NULL,display_name varchar(255) NOT NULL,normalized_key varchar(255) NULL,surname varchar(255) NULL,first_initial varchar(1) NULL,nationality varchar(191) NOT NULL,weight_class varchar(191) NOT NULL,source_fighter_id varchar(255) NOT NULL,KEY normalized_key(normalized_key),KEY surname(surname),KEY fighter_id(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin";
        $candidates=[];$total=0;
        try{
            foreach([$scraped_table,$existing_table] as $temp){\MMAF\DataEngine\Services\RegularImport\Repository::check($wpdb->query("CREATE TEMPORARY TABLE $temp $definition"));}
            $cursor=0;$max=(int)$wpdb->get_var("SELECT MAX(id) FROM {$tables['fighters']}");
            do{
                $rows=$wpdb->get_results($wpdb->prepare("SELECT id,display_name,normalized_name,nationality,weight_class,status FROM {$tables['fighters']} WHERE id>%d AND id<=%d AND deleted_soft=0 ORDER BY id LIMIT 250",$cursor,$max),ARRAY_A);
                if(!$rows){break;}
                $ids=implode(',',array_map('intval',array_column($rows,'id')));$sources=[];
                foreach($wpdb->get_results("SELECT DISTINCT fighter_id,source_fighter_id FROM {$tables['fighter_sources']} WHERE fighter_id IN ($ids) AND source_type='tapology'",ARRAY_A) as $source){$sources[(int)$source['fighter_id']][]=$source['source_fighter_id'];}
                $scraped_rows=[];$existing_rows=[];
                foreach($rows as $fighter){
                    $cursor=(int)$fighter['id'];$tokens=$this->tokens((string)$fighter['display_name']);$name=$this->normalized_name($fighter);
                    $row=['id'=>$cursor,'display_name'=>$fighter['display_name'],'normalized_key'=>$name===''?null:$name,'surname'=>count($tokens)>=2?end($tokens):null,'first_initial'=>$tokens?substr($tokens[0],0,1):null,'nationality'=>strtolower(trim((string)$fighter['nationality'])),'weight_class'=>strtolower(trim((string)$fighter['weight_class'])),'source_fighter_id'=>''];
                    if(!isset($sources[$cursor])){$existing_rows[]=$row;}
                    elseif($fighter['status']==='provisional'){foreach($sources[$cursor] as $source){$row['source_fighter_id']=(string)$source;$scraped_rows[]=$row;}}
                }
                \MMAF\DataEngine\Services\Operations\BulkWriter::insert($scraped_table,$scraped_rows);
                \MMAF\DataEngine\Services\Operations\BulkWriter::insert($existing_table,$existing_rows);
            }while(count($rows)===250);
            $metadata="((s.nationality<>'' AND s.nationality=e.nationality)+(s.weight_class<>'' AND s.weight_class=e.weight_class))";
            $same_name="s.normalized_key=e.normalized_key";
            $surname_where="(s.first_initial=e.first_initial OR $metadata>=2) AND COALESCE($same_name,0)=0";
            $exact_join="$scraped_table s JOIN $existing_table e ON $same_name";
            $surname_join="$scraped_table s JOIN $existing_table e ON s.surname=e.surname WHERE $surname_where";
            $total=(int)$wpdb->get_var("SELECT COUNT(*) FROM $exact_join")+(int)$wpdb->get_var("SELECT COUNT(*) FROM $surname_join");
            $columns="s.id scraped_fighter_id,s.display_name scraped_display_name,s.source_fighter_id scraped_source_fighter_id,e.id existing_fighter_id,e.display_name existing_display_name,COALESCE($same_name,0) exact_match,(s.first_initial=e.first_initial) initial_match,$metadata metadata_matches";
            // MySQL temporary tables cannot be reopened in a UNION. Merge two
            // already bounded result pages; no complete candidate set enters PHP.
            $rows=[];
            foreach([$exact_join,$surname_join] as $join){
                $page=$wpdb->get_results($wpdb->prepare("SELECT $columns FROM $join ORDER BY s.id,e.id,s.source_fighter_id LIMIT %d",$limit),ARRAY_A);
                if($wpdb->last_error){throw new \RuntimeException('Duplicate audit query failed.');}$rows=array_merge($rows,$page);
            }
            usort($rows,static fn($a,$b)=>[(int)$a['scraped_fighter_id'],(int)$a['existing_fighter_id'],$a['scraped_source_fighter_id']]<=>[(int)$b['scraped_fighter_id'],(int)$b['existing_fighter_id'],$b['scraped_source_fighter_id']]);$rows=array_slice($rows,0,$limit);
            foreach($rows as $row){
                $exact=(bool)$row['exact_match'];$initial=(bool)$row['initial_match'];$metadata_matches=(int)$row['metadata_matches'];
                $candidates[]=['scraped_fighter_id'=>(int)$row['scraped_fighter_id'],'scraped_display_name'=>$row['scraped_display_name'],'scraped_source_fighter_id'=>$row['scraped_source_fighter_id'],'existing_fighter_id'=>(int)$row['existing_fighter_id'],'existing_display_name'=>$row['existing_display_name'],'reason'=>$exact?'same normalized_name':($initial?($metadata_matches>0?'same surname, first initial, and shared nationality/weight class':'same surname and first initial'):'same surname, nationality, and weight class'),'confidence'=>$exact?'high':($initial && $metadata_matches>0?'medium':'low'),'action'=>'review manually later'];
            }
        }finally{foreach([$scraped_table,$existing_table] as $temp){$wpdb->query("DROP TEMPORARY TABLE IF EXISTS $temp");}}

		return array(
			'exact_normalized_name_groups_count' => count( $exact_groups ),
			'exact_normalized_name_groups'       => $exact_groups,
			'likely_duplicates_count'            => $total,
			'review_candidates'                  => $candidates,
		);
	}

	private function match_reason( array $scraped, array $existing ): ?array {
		$scraped_name = $this->normalized_name( $scraped );
		$existing_name = $this->normalized_name( $existing );

		if ( '' !== $scraped_name && $scraped_name === $existing_name ) {
			return array(
				'reason'     => 'same normalized_name',
				'confidence' => 'high',
			);
		}

		$scraped_tokens = $this->tokens( (string) $scraped['display_name'] );
		$existing_tokens = $this->tokens( (string) $existing['display_name'] );
		if ( count( $scraped_tokens ) < 2 || count( $existing_tokens ) < 2 ) {
			return null;
		}

		$scraped_last = $scraped_tokens[ count( $scraped_tokens ) - 1 ];
		$existing_last = $existing_tokens[ count( $existing_tokens ) - 1 ];
		if ( $scraped_last !== $existing_last ) {
			return null;
		}

		$same_first_initial = substr( $scraped_tokens[0], 0, 1 ) === substr( $existing_tokens[0], 0, 1 );
		$metadata_matches   = $this->metadata_matches( $scraped, $existing );

		if ( $same_first_initial && $metadata_matches > 0 ) {
			return array(
				'reason'     => 'same surname, first initial, and shared nationality/weight class',
				'confidence' => 'medium',
			);
		}

		if ( $same_first_initial ) {
			return array(
				'reason'     => 'same surname and first initial',
				'confidence' => 'low',
			);
		}

		if ( $metadata_matches >= 2 ) {
			return array(
				'reason'     => 'same surname, nationality, and weight class',
				'confidence' => 'low',
			);
		}

		return null;
	}

	private function normalized_name( array $fighter ): string {
		$name = (string) ( $fighter['normalized_name'] ?? '' );

		return '' === $name ? $this->normalize( (string) ( $fighter['display_name'] ?? '' ) ) : $this->normalize( $name );
	}

	private function tokens( string $name ): array {
		$normalized = $this->normalize( $name );
		if ( '' === $normalized ) {
			return array();
		}

		return array_values( array_filter( explode( ' ', $normalized ) ) );
	}

	private function normalize( string $value ): string {
		$value = strtolower( remove_accents( $value ) );
		$value = preg_replace( '/[^a-z0-9 ]+/', ' ', $value );
		$value = preg_replace( '/\s+/', ' ', (string) $value );

		return trim( (string) $value );
	}

	private function metadata_matches( array $a, array $b ): int {
		$matches = 0;

		foreach ( array( 'nationality', 'weight_class' ) as $field ) {
			$a_value = strtolower( trim( (string) ( $a[ $field ] ?? '' ) ) );
			$b_value = strtolower( trim( (string) ( $b[ $field ] ?? '' ) ) );

			if ( '' !== $a_value && '' !== $b_value && $a_value === $b_value ) {
				++$matches;
			}
		}

		return $matches;
	}
}
