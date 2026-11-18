<?php
/** Password rules, the staging delivery note and address masking are plain string logic and need no database. */
define('ABSPATH', dirname(__DIR__, 2) . '/');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('MMAF_NOTIFICATION_STAGING_ALLOWLIST', 'qa@example.test, Second.Tester@Example.test');
$includes = ABSPATH . 'wp-content/plugins/mma-future-data-engine/includes/';
spl_autoload_register(static function ($class) use ($includes) {
    $prefix = 'MMAF\\DataEngine\\';
    if (str_starts_with($class, $prefix)) {
        $file = $includes . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) { require_once $file; }
    }
});
$GLOBALS['environment'] = 'production';
function wp_get_environment_type() { return $GLOBALS['environment']; }
function sanitize_email($email) { return trim((string) $email); }

use MMAF\DataEngine\Services\Notifications\Auth;
use MMAF\DataEngine\Services\Notifications\ServiceMailer;

function account_check(bool $ok, string $label): void {
    if (!$ok) { throw new RuntimeException($label); }
    echo "PASS: $label\n";
}

foreach (['correct horse battery', 'Tr0ub4dor&3x', str_repeat('ž', Auth::PASSWORD_MIN), ' spaces around ', str_repeat('a1', 64)] as $good) {
    account_check(Auth::password_problem($good, 'nikola@example.test') === '', 'Accepts ' . json_encode($good, JSON_UNESCAPED_UNICODE));
}
$bad = ['' => 'empty', 'seven77' => 'seven characters', '        ' => 'only spaces', 'password' => 'the word password', 'PASSWORD123' => 'a common password in capitals', '12345678' => 'a run of digits', 'nikola@example.test' => 'the email address', 'NIKOLA@example.test' => 'the email address in another case'];
foreach ($bad as $password => $why) {
    account_check(Auth::password_problem((string) $password, 'nikola@example.test') !== '', "Refuses a password that is $why");
}
account_check(Auth::password_problem('nikolagrappler', 'nikolagrappler@example.test') !== '', 'Refuses the part of the address before the @');
account_check(Auth::password_problem(str_repeat('x', Auth::PASSWORD_MAX)) === '' && Auth::password_problem(str_repeat('x', Auth::PASSWORD_MAX + 1)) !== '', 'The upper limit is ' . Auth::PASSWORD_MAX . ' characters');
account_check(Auth::password_problem('12345678', '') !== '' && Auth::password_problem('long enough here', '') === '', 'The rules hold when no address is known yet');

account_check(Auth::delivery_note('anyone@example.test') === '', 'Production says nothing about delivery');
$GLOBALS['environment'] = 'staging';
account_check(Auth::delivery_note('qa@example.test') === '' && Auth::delivery_note('second.tester@example.test') === '', 'Staging says nothing for an allow-listed address, whatever its case');
$note = Auth::delivery_note('reader@example.test');
account_check(str_contains($note, 'staging') && str_contains($note, 'reader@example.test') && str_contains($note, 'no message was sent'), 'Staging tells a tester that their address is not on the allowlist');
$GLOBALS['environment'] = 'production';

foreach (['nikola@example.test' => 'n***@example.test', 'a@b.co' => 'a***@b.co', '@example.test' => '***', 'not-an-address' => '***', '' => '***'] as $email => $masked) {
    account_check(ServiceMailer::mask((string) $email) === $masked, 'Masks ' . json_encode($email) . ' as ' . $masked);
}
echo "account-passwords: ok\n";
