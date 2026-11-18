<?php
/** Password rules, the staging allowlist boundary and address masking are plain logic and need no database. */
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
function apply_filters($hook, $value) { return $value; }
function get_option($name, $default = false) { return 'admin_email' === $name ? 'site@example.test' : ($GLOBALS['options'][$name] ?? $default); }
function get_bloginfo($show = '') { return 'MMA Future'; }
function wp_json_encode($value) { return json_encode($value); }
function add_action($hook, $callback) { $GLOBALS['actions'][$hook][] = $callback; }
function remove_action($hook, $callback) { $GLOBALS['actions'][$hook] = array_values(array_filter($GLOBALS['actions'][$hook] ?? array(), static fn($kept) => $kept !== $callback)); }
function wp_mail($to, $subject, $message, $headers = '') {
    $mailer = new stdClass();
    foreach ($GLOBALS['actions']['phpmailer_init'] ?? array() as $callback) { $callback($mailer); }
    $GLOBALS['sent'][] = array('wp_mail', $to);
    $GLOBALS['last_mail'] = array('headers' => (array) $headers, 'mailer' => $mailer);
    return true;
}
function get_transient($key) { return $GLOBALS['transients'][$key] ?? false; }
function set_transient($key, $value, $ttl = 0) { $GLOBALS['transients'][$key] = $value; return true; }
function update_option($name, $value, $autoload = null) { $GLOBALS['options'][$name] = $value; return true; }
function sanitize_key($key) { return strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $key)); }
ini_set('error_log', '/dev/null');
$GLOBALS['actions'] = $GLOBALS['transients'] = $GLOBALS['options'] = array();
function wp_remote_post($url, $args = array()) { $GLOBALS['sent'][] = array('sender', json_decode($args['body'], true)['to']['email']); return new class() { public function get_error_message() { return 'stubbed'; } }; }
function is_wp_error($thing) { return is_object($thing) && method_exists($thing, 'get_error_message'); }
$GLOBALS['sent'] = array();

use MMAF\DataEngine\Services\Notifications\Auth;
use MMAF\DataEngine\Services\Notifications\SenderTransport;
use MMAF\DataEngine\Services\Notifications\ServiceMailer;
use MMAF\DataEngine\Services\Notifications\TransportFactory;
use MMAF\DataEngine\Services\Notifications\WpMailTransport;

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

// Staging: sports email stops at the allowlist, account email (confirm an address, set a password) reaches anyone.
class_exists(TransportFactory::class); // Loads Transport.php, which holds every transport class.
$send = static fn($transport, string $to) => $transport->send($to, 'Subject', '<p>Body</p>', 'Body');
$blocked = ['status' => 'rejected', 'error' => 'staging_recipient_blocked', 'http_code' => 0];
$GLOBALS['environment'] = 'staging';
foreach ([WpMailTransport::class => 'wp_mail', SenderTransport::class => 'sender'] as $class => $via) {
    $GLOBALS['sent'] = [];
    account_check($send(new $class(), 'reader@example.test') === $blocked && $GLOBALS['sent'] === [], "Staging holds sports email for an unlisted address back from $via");
    $send(new $class(), 'Second.Tester@example.test');
    account_check($GLOBALS['sent'] === [[$via, 'Second.Tester@example.test']], "Staging sends sports email to an allow-listed address through $via, whatever its case");
    $GLOBALS['sent'] = [];
    $result = $send(new $class(true), 'reader@example.test');
    account_check($GLOBALS['sent'] === [[$via, 'reader@example.test']] && 'staging_recipient_blocked' !== ($result['error'] ?? ''), "Staging sends account email to an unlisted address through $via");
}
$GLOBALS['sent'] = [];
account_check($send(TransportFactory::make(), 'reader@example.test') === $blocked && $GLOBALS['sent'] === [], 'The default transport is the sports one and keeps the allowlist');
account_check('accepted' === $send(TransportFactory::make(true), 'reader@example.test')['status'] && $GLOBALS['sent'] === [['wp_mail', 'reader@example.test']], 'The account transport is not held to the allowlist');
// The host's mailer sends as the configured address, with bounces returning to it and the plain text beside the HTML.
$mail = $GLOBALS['last_mail'];
account_check(in_array('From: MMA Future <site@example.test>', $mail['headers'], true) && 'site@example.test' === $mail['mailer']->Sender && 'Body' === $mail['mailer']->AltBody, 'Host mail names the configured sender and carries the plain text');
account_check(($GLOBALS['actions']['phpmailer_init'] ?? []) === [], 'The sender is set for this message only, not for other mail the site sends');

// Staging may send through a mail server shared with other sites, so account email has a ceiling for the whole site.
$GLOBALS['sent'] = [];
for ($i = 1; $i <= 30; $i++) { ServiceMailer::send("reader$i@example.test", 'Subject', '<p>Body</p>', 'Body', 'register'); }
$over = ServiceMailer::send('reader31@example.test', 'Subject', '<p>Body</p>', 'Body', 'register');
$log  = ServiceMailer::log();
account_check(30 === count($GLOBALS['sent']) && ['status' => 'rejected', 'error' => 'hourly_limit', 'http_code' => 0] === $over, 'Staging stops account email after 30 in an hour');
account_check('hourly_limit' === end($log)['error'] && 'r***@example.test' === end($log)['to'] && 30 === count($log), 'The refused send is recorded for the administrator, and the record stays at 30 entries');
$GLOBALS['environment'] = 'production';
$GLOBALS['sent'] = [];
$GLOBALS['transients'] = [];
for ($i = 1; $i <= 40; $i++) { ServiceMailer::send("reader$i@example.test", 'Subject', '<p>Body</p>', 'Body', 'register'); }
account_check(40 === count($GLOBALS['sent']), 'Production has no ceiling unless one is configured');
$GLOBALS['sent'] = [];
$send(new WpMailTransport(), 'anyone@example.test');
account_check($GLOBALS['sent'] === [['wp_mail', 'anyone@example.test']], 'Production sends sports email to any address');

foreach (['nikola@example.test' => 'n***@example.test', 'a@b.co' => 'a***@b.co', '@example.test' => '***', 'not-an-address' => '***', '' => '***'] as $email => $masked) {
    account_check(ServiceMailer::mask((string) $email) === $masked, 'Masks ' . json_encode($email) . ' as ' . $masked);
}
echo "account-passwords: ok\n";
