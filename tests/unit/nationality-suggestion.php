<?php
/** Scraped profile nationality must become a nationality suggestion; birthplace never does. */
define('ABSPATH', dirname(__DIR__, 2) . '/');
$includes = ABSPATH . 'wp-content/plugins/mma-future-data-engine/includes/';
spl_autoload_register(static function ($class) use ($includes) {
    $prefix = 'MMAF\\DataEngine\\';
    if (str_starts_with($class, $prefix)) {
        $file = $includes . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) { require_once $file; }
    }
});
function nationality_check(bool $ok, string $label): void {
    if (!$ok) { throw new RuntimeException($label); }
    echo "PASS: $label\n";
}
use MMAF\DataEngine\Services\Import\FighterProfileEnrichmentPreviewService as Preview;

$flag = static fn(array $overrides = []) => array_merge(
    ['raw' => 'US', 'country_name' => 'United States', 'country_code' => 'USA', 'source' => 'profile_header_flag', 'is_missing' => false],
    $overrides
);
$context = ['born_location' => 'Belgrade, Serbia', 'fighting_out_of' => 'Zagreb, Croatia'];

nationality_check(Preview::nationality_suggestion(['nationality' => $flag()] + $context) === 'United States', 'a present header-flag nationality object suggests its country name');
nationality_check(Preview::nationality_suggestion(['nationality' => $flag(['country_name' => '  Bosnia and Herzegovina '])]) === 'Bosnia and Herzegovina', 'the suggested country name is trimmed');
nationality_check(Preview::nationality_suggestion(['nationality' => $flag(['is_missing' => true])] + $context) === null, 'a nationality marked missing is not suggested and birthplace is not used instead');
nationality_check(Preview::nationality_suggestion(['nationality' => $flag(['is_missing' => null])]) === null, 'is_missing must be explicitly false');
nationality_check(Preview::nationality_suggestion(['nationality' => $flag(['source' => 'born_location'])] + $context) === null, 'a nationality from any source other than the header flag is ignored');
nationality_check(Preview::nationality_suggestion(['nationality' => $flag(['country_name' => ''])]) === null, 'an empty country name is not suggested');
nationality_check(Preview::nationality_suggestion(['nationality' => $flag(['raw' => 'Atlantis', 'country_name' => null, 'country_code' => null])]) === null, 'an unrecognized flag label without a country name is not suggested');
nationality_check(Preview::nationality_suggestion(['nationality' => $flag(['country_name' => ['United States']])]) === null, 'a non-string country name is not suggested');
nationality_check(Preview::nationality_suggestion(['nationality' => $flag(['country_name' => str_repeat('A', 101)])]) === null, 'a country name longer than the column is not suggested');
nationality_check(Preview::nationality_suggestion(['nationality' => $flag(['is_missing' => true]), 'country' => 'Serbia']) === 'Serbia', 'an unusable object still falls back to the plain country field');
nationality_check(Preview::nationality_suggestion(['nationality' => ' Croatia ']) === 'Croatia', 'a plain string nationality is still accepted');
nationality_check(Preview::nationality_suggestion(['nationality' => '', 'country' => 'Serbia']) === 'Serbia', 'an empty string nationality falls back to the country field');
nationality_check(Preview::nationality_suggestion($context) === null, 'birthplace and fighting-out-of alone never produce a nationality');
nationality_check(Preview::nationality_suggestion([]) === null, 'a summary without nationality has no suggestion');
