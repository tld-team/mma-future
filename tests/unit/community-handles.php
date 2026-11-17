<?php
/** Handle and social-link rules are pure string logic: a profile URL or a printed link is only ever built from what passes them. */
define('ABSPATH', dirname(__DIR__, 2) . '/');
$includes = ABSPATH . 'wp-content/plugins/mma-future-data-engine/includes/';
spl_autoload_register(static function ($class) use ($includes) {
    $prefix = 'MMAF\\DataEngine\\';
    if (str_starts_with($class, $prefix)) {
        $file = $includes . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) { require_once $file; }
    }
});

use MMAF\DataEngine\Services\Community\Handles;
use MMAF\DataEngine\Services\Community\Socials;
use MMAF\DataEngine\Services\Community\ValidationFailed;

function community_check(bool $ok, string $label): void {
    if (!$ok) { throw new RuntimeException($label); }
    echo "PASS: $label\n";
}

community_check(Handles::normalize('  @Nikola_Grappler ') === 'nikola_grappler', 'A handle is trimmed, lower-cased and loses its @');
foreach (['nik', 'nikola_grappler', 'scout_00042', 'a1b2c3', str_repeat('a', 20)] as $ok) {
    community_check(Handles::problem($ok) === '', "Accepts $ok");
}
$rejected = [
    '' => 'empty', 'ab' => 'too short', str_repeat('a', 21) => 'too long', 'nikola grappler' => 'space', 'nikola-grappler' => 'dash',
    'nikola.g' => 'dot', 'никола' => 'non-latin', '12345' => 'digits only', '___' => 'no letter', 'admin' => 'reserved',
    'fan' => 'route', 'mmafuture' => 'brand', 'mmafuture_news' => 'brand prefix', 'mma_future_hq' => 'brand prefix with underscore',
    '<script>' => 'markup', "nik\n" => 'newline', 'nik/../x' => 'path',
];
foreach ($rejected as $handle => $why) {
    community_check(Handles::problem((string) $handle) !== '', "Rejects a handle that is $why");
}
community_check(Handles::problem('rival_site', ['rival_site']) !== '' && Handles::problem('rival_site') === '', 'Site-specific reserved handles are honoured');
community_check(Handles::problem(Handles::suggestion(42)) === '' && Handles::suggestion(42) === 'scout_00042', 'The suggested handle is valid and not derived from personal data');
community_check(Handles::problem(Handles::suggestion(-123456789)) === '', 'Any seed yields a valid suggestion');

$usernames = [
    ['instagram', 'nikola.g', 'nikola.g'],
    ['instagram', '@nikola.g', 'nikola.g'],
    ['instagram', 'https://www.instagram.com/nikola.g/', 'nikola.g'],
    ['instagram', 'instagram.com/nikola.g?igshid=abc', 'nikola.g'],
    ['x', 'https://twitter.com/Nikola_G', 'Nikola_G'],
    ['x', 'https://x.com/Nikola_G/status/1', 'Nikola_G'],
    ['youtube', 'https://www.youtube.com/@nikola-g', 'nikola-g'],
    ['tiktok', 'https://m.tiktok.com/@nikola.g', 'nikola.g'],
    ['facebook', 'facebook.com/nikola.grappler', 'nikola.grappler'],
    ['instagram', '   ', ''],
];
foreach ($usernames as [$platform, $raw, $expected]) {
    community_check(Socials::username($platform, $raw) === $expected, "Reads the $platform username from " . json_encode($raw));
}
$invalid = [
    ['instagram', 'https://evil.example/nikola.g'], ['instagram', 'javascript:alert(1)'], ['instagram', 'nikola g'],
    ['instagram', 'https://instagram.com.evil.example/nikola'], ['x', 'this_name_is_far_too_long'], ['x', 'nik"onmouseover="x'],
    ['facebook', 'abc'], ['myspace', 'nikola'], ['youtube', '<b>nikola</b>'],
];
foreach ($invalid as [$platform, $raw]) {
    community_check(Socials::username($platform, $raw) === null, "Refuses $platform value " . json_encode($raw));
}

[$values, $errors] = Socials::clean(['instagram' => '@nikola.g', 'x' => '', 'youtube' => ['array'], 'tiktok' => 'https://evil.example/x', 'unknown' => 'x']);
community_check($values === ['instagram' => 'nikola.g'], 'Only valid, non-empty usernames on known platforms are kept');
community_check(array_keys($errors) === ['socials.tiktok'], 'A bad value is reported against its own field');
community_check(Socials::clean('not an array') === [[], []], 'Non-array input is treated as no links');

$links = Socials::links(['instagram' => 'nikola.g', 'x' => 'bad name', 'facebook' => 'nikola.grappler', 'unknown' => 'x', 'tiktok' => ['array']]);
community_check(array_column($links, 'url') === ['https://www.instagram.com/nikola.g/', 'https://www.facebook.com/nikola.grappler'], 'Links are composed from the allow-list and invalid stored values are dropped');
foreach ($links as $link) {
    community_check(str_starts_with($link['url'], 'https://') && !preg_match('/["\'<>\s]/', $link['url']), 'A composed ' . $link['label'] . ' link is a plain https URL');
}

$failure = new ValidationFailed(['handle' => 'Choose a handle.', 'bio' => 'Too long.']);
community_check($failure->getMessage() === 'Choose a handle.' && array_keys($failure->fields()) === ['handle', 'bio'], 'A validation failure carries one message per field');
echo "community-handles: ok\n";
