<?php
/** Cached public payloads are stored as ASCII-only JSON and read back unchanged. */
namespace MMAF\DataEngine\Repositories {
    final class RankingRunRepository {
        public function get_active_ranking_run_id(): int { return 7; }
    }
}

namespace {
    define('ABSPATH', dirname(__DIR__, 2) . '/');
    define('MINUTE_IN_SECONDS', 60);
    $includes = ABSPATH . 'wp-content/plugins/mma-future-data-engine/includes/';
    spl_autoload_register(static function ($class) use ($includes) {
        $prefix = 'MMAF\\DataEngine\\';
        if (str_starts_with($class, $prefix)) {
            $file = $includes . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) { require_once $file; }
        }
    });

    final class WP_Error {}
    $GLOBALS['transients'] = [];
    $GLOBALS['transient_writes'] = 0;
    function get_option($name, $default = false) { return $default; }
    function wp_json_encode($data, $options = 0, $depth = 512) { return json_encode($data, $options, $depth); }
    function is_wp_error($value) { return $value instanceof WP_Error; }
    function get_transient($key) { return $GLOBALS['transients'][$key] ?? false; }
    function set_transient($key, $value, $ttl) { $GLOBALS['transient_writes']++; $GLOBALS['transients'][$key] = $value; return true; }
    function cache_check(bool $ok, string $label): void {
        if (!$ok) { throw new RuntimeException($label); }
        echo "PASS: $label\n";
    }

    use MMAF\DataEngine\Services\PublicCacheService as Cache;

    $calls = 0;
    $remember = static function (string $type, array $args, $value) use (&$calls) {
        return Cache::remember($type, $args, static function () use (&$calls, $value) { $calls++; return $value; });
    };
    $stored = static fn(string $type, array $args) => $GLOBALS['transients'][Cache::key($type, $args)] ?? false;
    $ascii = static fn($value) => is_string($value) && 0 === preg_match('/[^\x00-\x7F]/', $value);

    $payload = [
        'board' => 'overall', 'page' => 1, 'per_page' => 100, 'total' => 171, 'message' => null,
        'items' => [
            ['fighter_id' => 12, 'display_name' => 'Bojković', 'nationality' => 'Србија', 'score' => 38.5, 'raw_score' => 40.0, 'rank' => 1,
             'breakdown' => ['per_fight_items' => [['result' => 'win', 'points' => 2.25]], 'flags' => []],
             'image' => null, 'in_ufc' => false, 'label' => 'Women’s Flyweight – “The Hammer”'],
            ['fighter_id' => 13, 'display_name' => 'José Aldo Júnior', 'score' => 0.1 + 0.2, 'rank' => 2, 'record' => ['wins' => 10, 'losses' => 0]],
        ],
        'by_post' => [301 => ['rank' => 1], 17 => ['rank' => 2]],
        'available_boards' => ['overall', 'male'],
        'empty' => [],
    ];

    $first = $remember('rankings', ['per_page' => 100], $payload);
    cache_check($first === $payload && 1 === $calls, 'a cache miss returns the loader value unchanged');
    $raw = $stored('rankings', ['per_page' => 100]);
    cache_check($ascii($raw), 'the stored value is an ASCII-only string');
    cache_check(str_contains($raw, 'Bojkovi' . chr(92) . 'u0107') && str_contains($raw, '"score":38.5') && str_contains($raw, '"raw_score":40.0'), 'non-ASCII text is escaped and float scores keep their form');
    $second = $remember('rankings', ['per_page' => 100], ['must not' => 'load']);
    cache_check(1 === $calls && $second === $payload, 'a cache hit returns exactly the original structure without calling the loader');
    cache_check('Bojković' === $second['items'][0]['display_name'] && 38.5 === $second['items'][0]['score'] && is_float($second['items'][0]['raw_score']), 'names and float scores survive the round trip');
    cache_check(array_keys($second['by_post']) === [301, 17] && [] === $second['empty'], 'integer-keyed maps and empty arrays survive the round trip');

    foreach ([
        'a legacy serialized array' => ['legacy' => true],
        'a non-string value' => 123,
        'an undecodable string' => '{"value":',
        'JSON without the value envelope' => '{"items":[]}',
        'an empty string' => '',
    ] as $label => $legacy) {
        $args = ['legacy' => $label];
        $GLOBALS['transients'][Cache::key('history', $args)] = $legacy;
        $before = $calls;
        $result = $remember('history', $args, ['fresh' => $label]);
        cache_check($result === ['fresh' => $label] && $calls === $before + 1 && $ascii($stored('history', $args)), "$label in the cache is treated as a miss and replaced");
    }

    $cached_null = $remember('history', ['null'], null);
    $before = $calls;
    cache_check(null === $cached_null && null === $remember('history', ['null'], 'other') && $calls === $before, 'a cached null is a hit, not a miss');

    $writes = $GLOBALS['transient_writes'];
    $error = $remember('rankings', ['error'], new WP_Error());
    cache_check($error instanceof WP_Error && $writes === $GLOBALS['transient_writes'], 'a WP_Error is returned but not cached');

    $object = (object)['a' => 1];
    $writes = $GLOBALS['transient_writes'];
    cache_check($remember('rankings', ['object'], ['item' => $object])['item'] === $object && $writes === $GLOBALS['transient_writes'], 'a value that would not round trip unchanged is returned but not cached');
    cache_check(null === Cache::encode(['bad' => "\xB1\x31"]) && null === Cache::encode(['nan' => NAN]), 'invalid UTF-8 and NAN are not cacheable');

    $large = ['items' => []];
    for ($i = 0; $i < 4000; $i++) {
        $large['items'][] = ['fighter_id' => $i, 'display_name' => "Bojković $i", 'score' => $i + 0.5, 'note' => str_repeat('č', 100)];
    }
    $calls = 0;
    $remember('rankings', ['large'], $large);
    $raw = $stored('rankings', ['large']);
    cache_check(strlen($raw) > 1000000 && $ascii($raw), 'a payload over 1 MB with non-ASCII text is stored as ASCII');
    cache_check($remember('rankings', ['large'], null) === $large && 1 === $calls, 'the large payload reads back unchanged');
}
