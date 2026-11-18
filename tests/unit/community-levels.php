<?php
/** Levels, tenure and the badge are plain arithmetic and markup, and need no database. */
define('ABSPATH', dirname(__DIR__, 2) . '/');
$includes = ABSPATH . 'wp-content/plugins/mma-future-data-engine/includes/';
spl_autoload_register(static function ($class) use ($includes) {
    $prefix = 'MMAF\\DataEngine\\';
    if (str_starts_with($class, $prefix)) {
        $file = $includes . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) { require_once $file; }
    }
});
function esc_attr($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }

use MMAF\DataEngine\Services\Community\Badge;
use MMAF\DataEngine\Services\Community\Levels;

function levels_check(bool $ok, string $label): void {
    if (!$ok) { throw new RuntimeException($label); }
    echo "PASS: $label\n";
}

$expected = [0 => [1, 'Spectator'], 24 => [1, 'Spectator'], 25 => [2, 'Spotter'], 99 => [2, 'Spotter'], 100 => [3, 'Scout'], 300 => [4, 'Senior Scout'], 750 => [5, 'Head Scout'], 2000 => [6, 'Matchmaker'], 4999 => [6, 'Matchmaker'], 5000 => [7, 'Oracle'], 90000 => [7, 'Oracle']];
foreach ($expected as $xp => [$level, $name]) {
    $info = Levels::for_xp($xp);
    levels_check($level === $info['level'] && $name === $info['name'], "$xp XP is level $level ($name)");
}
$info = Levels::for_xp(-40);
levels_check(0 === $info['xp'] && 1 === $info['level'], 'XP is never below zero');

$scout = Levels::for_xp(200);
levels_check(100 === $scout['floor'] && 300 === $scout['next'] && 'Senior Scout' === $scout['next_name'] && 100 === $scout['to_next'] && 0.5 === $scout['progress'] && 4 === $scout['eighths'], 'Half way through Scout lights four of eight sides');
levels_check(0 === Levels::for_xp(100)['eighths'] && 7 === Levels::for_xp(299)['eighths'], 'A side lights only for a full eighth, and the eighth side waits for the next level');
$oracle = Levels::for_xp(5000);
levels_check(null === $oracle['next'] && 0 === $oracle['to_next'] && 1.0 === $oracle['progress'] && 8 === $oracle['eighths'], 'The top level is complete');

$start = gmmktime(12, 0, 0, 1, 15, 2026);
$at = static fn (int $months, int $days = 0): int => gmmktime(12, 0, 0, 1 + $months, 15 + $days, 2026);
levels_check(0 === Levels::tenure($start, $at(2, 30)) && 10 === Levels::tenure($start, $at(3)) && 20 === Levels::tenure($start, $at(6)), 'Tenure adds 10 for every full three months');
levels_check(80 === Levels::tenure($start, $at(24)) && 80 === Levels::tenure($start, $at(120)), 'Tenure stops at 80');
levels_check(0 === Levels::tenure($start, $start - 1000) && 0 === Levels::tenure(0, $at(12)), 'No tenure for a future or unknown registration');
levels_check(Levels::tenure($start, $at(120)) < Levels::THRESHOLDS[3], 'Tenure alone cannot reach Scout');

levels_check('steel' === Levels::tier(1) && 'steel' === Levels::tier(2) && 'blue' === Levels::tier(3) && 'blue' === Levels::tier(6) && 'gold' === Levels::tier(7), 'Badge colours follow the level');

$svg = Badge::svg($scout, 'lg');
levels_check(8 === substr_count($svg, 'class="mmafc-badge__side') && 4 === substr_count($svg, 'is-lit'), 'The badge has eight sides with four lit');
levels_check(str_contains($svg, 'aria-label="Level 3, Scout"') && str_contains($svg, 'role="img"') && str_contains($svg, '>3</text>') && str_contains($svg, 'mmafc-badge--blue') && str_contains($svg, 'width="64"'), 'The badge names its level for screen readers and shows the number');
$small = Badge::svg(Levels::for_xp(5000), 'sm');
levels_check(8 === substr_count($small, 'is-lit') && str_contains($small, 'width="20"') && str_contains($small, 'mmafc-badge--gold'), 'The small Oracle badge is complete and gold');
levels_check(str_contains(Badge::svg(['level' => 2, 'name' => 'Spot"ter', 'eighths' => 3]), 'aria-label="Level 2, Spot&quot;ter"'), 'The badge label is escaped');
echo "community-levels: ok\n";
