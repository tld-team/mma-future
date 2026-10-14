<?php
namespace MMAF\DataEngine\Services\RegularImport;
final class Repository {
    public array $tables;
    public function __construct() { $this->tables = Schema::tables(); }
    public function active(): ?array {
        global $wpdb; $t = $this->tables['jobs'];
        return $this->decode($wpdb->get_row("SELECT * FROM $t WHERE status NOT IN ('completed','completed_with_review','rejected','discarded') ORDER BY id LIMIT 1", ARRAY_A));
    }
    public function get(int $id): array {
        global $wpdb;
        $row = $this->decode($wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['jobs']} WHERE id=%d", $id), ARRAY_A));
        if (!$row) { throw new ImportException('Import was not found.', 'not_found'); }
        return $row;
    }
    public function recent(): array {
        global $wpdb;
        return array_map([$this,'decode'], $wpdb->get_results("SELECT * FROM {$this->tables['jobs']} ORDER BY id DESC LIMIT 50", ARRAY_A) ?: []);
    }
    public function create(string $filename, int $bytes, int $actor): int {
        global $wpdb; $now = gmdate('Y-m-d H:i:s');
        self::check($wpdb->insert($this->tables['jobs'], [
            'status'=>'uploading','phase'=>'upload','filename'=>$filename,'created_by'=>$actor,
            'meta_json'=>wp_json_encode(['upload_bytes'=>$bytes,'site_url'=>home_url('/'),'received_chunks'=>[]]),
            'created_at'=>$now,'updated_at'=>$now,
        ]));
        return (int)$wpdb->insert_id;
    }
    public function update(int $id, array $fields): void {
        global $wpdb;
        foreach (['meta','error'] as $key) {
            if (array_key_exists($key,$fields)) { $fields[$key.'_json'] = $fields[$key] === null ? null : wp_json_encode($fields[$key]); unset($fields[$key]); }
        }
        $fields['updated_at'] = gmdate('Y-m-d H:i:s');
        self::check($wpdb->update($this->tables['jobs'], $fields, ['id'=>$id]));
    }
    public function addBatch(int $job, string $kind, int $index, string $hash, int $count): void {
        global $wpdb;
        self::check($wpdb->insert($this->tables['batches'], ['job_id'=>$job,'kind'=>$kind,'batch_index'=>$index,'payload_hash'=>$hash,'row_count'=>$count]));
    }
    public function batches(int $job, ?string $kind = null): array {
        global $wpdb;
        $sql = $wpdb->prepare("SELECT * FROM {$this->tables['batches']} WHERE job_id=%d",$job);
        if ($kind !== null) { $sql .= $wpdb->prepare(' AND kind=%s',$kind); }
        return $wpdb->get_results($sql.' ORDER BY batch_index,id', ARRAY_A) ?: [];
    }
    public function nextBatch(int $job, string $kind, bool $checking): ?array {
        global $wpdb; $column = $checking ? 'checked' : 'completed';
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['batches']} WHERE job_id=%d AND kind=%s AND $column=0 ORDER BY batch_index LIMIT 1",$job,$kind),ARRAY_A) ?: null;
    }
    public function batchUpdate(int $id, array $fields): void {
        global $wpdb;
        if (isset($fields['summary'])) { $fields['summary_json']=wp_json_encode($fields['summary']);unset($fields['summary']); }
        self::check($wpdb->update($this->tables['batches'],$fields,['id'=>$id]));
    }
    public function putItem(int $job,string $kind,string $key,string $source,string $category,array $payload,?int $review = null): void {
        if(strlen($kind)>64){throw new \InvalidArgumentException('Import item kind exceeds its supported storage length.');}
        global $wpdb;
        // Repeated fighter references are one logical plan item, across every event batch.
        $sql=$wpdb->prepare("INSERT INTO {$this->tables['items']} (job_id,kind,item_key,source_id,category,payload_json,review_item_id) VALUES (%d,%s,%s,%s,%s,%s,NULLIF(%d,0)) ON DUPLICATE KEY UPDATE category=VALUES(category),payload_json=VALUES(payload_json),review_item_id=COALESCE(VALUES(review_item_id),review_item_id)",$job,$kind,hash('sha256',$key),$source,$category,wp_json_encode($payload),$review ?: 0);
        self::check($wpdb->query($sql));
    }
    public function items(int $job,string $kind,int $limit=10000): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->tables['items']} WHERE job_id=%d AND kind=%s ORDER BY id LIMIT %d",$job,$kind,$limit),ARRAY_A) ?: [];
    }
    public function planCounts(int $job): array {
        global $wpdb; $out=[];
        foreach ($wpdb->get_results($wpdb->prepare("SELECT kind,category,COUNT(*) total FROM {$this->tables['items']} WHERE job_id=%d GROUP BY kind,category",$job),ARRAY_A) ?: [] as $r) { $out[$r['kind']][$r['category']] = (int)$r['total']; }
        return $out;
    }
    public function resetCheck(int $job): void {
        global $wpdb;
        self::check($wpdb->query($wpdb->prepare("DELETE FROM {$this->tables['items']} WHERE job_id=%d",$job)));
        self::check($wpdb->update($this->tables['batches'],['checked'=>0],['job_id'=>$job]));
    }
    public function decode(?array $row): ?array {
        if (!$row) { return null; }
        foreach (['id','cursor_index','attempts','pause_requested','writes_started','created_by'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        $row['approved_by'] = $row['approved_by'] === null ? null : (int) $row['approved_by'];
        $row['meta'] = json_decode($row['meta_json'],true) ?: [];
        $row['error'] = empty($row['error_json']) ? null : json_decode($row['error_json'],true);
        unset($row['meta_json'],$row['error_json']);
        return $row;
    }
    public static function check($result): void {
        if (false === $result) { global $wpdb; throw new \RuntimeException('Import database operation failed: '.$wpdb->last_error); }
    }
}
