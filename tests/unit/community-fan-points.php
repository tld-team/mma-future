<?php
/** Fan-ranking arithmetic that needs no database: the points for each position and the order of the standings. */
define('ABSPATH', dirname(__DIR__, 2) . '/');
$includes = ABSPATH . 'wp-content/plugins/mma-future-data-engine/includes/';
spl_autoload_register(static function ($class) use ($includes) {
    $prefix = 'MMAF\\DataEngine\\';
    if (str_starts_with($class, $prefix)) {
        $file = $includes . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) { require_once $file; }
    }
});
$GLOBALS['fan_points_filter'] = null;
function apply_filters($tag, $value) { return 'mmaf_community_fan_points' === $tag && null !== $GLOBALS['fan_points_filter'] ? $GLOBALS['fan_points_filter'] : $value; }

use MMAF\DataEngine\Services\Community\FanLists;
use MMAF\DataEngine\Services\Community\FanRanking;

function fan_check(bool $ok, string $label): void {
    if (!$ok) { throw new RuntimeException($label); }
    echo "PASS: $label\n";
}

$points = FanRanking::points();
fan_check(array_keys($points) === range(1, FanLists::SIZE), 'There are points for positions 1 to 10');
fan_check($points === [1 => 25, 18, 15, 12, 10, 8, 6, 4, 2, 1], 'The default schedule is 25, 18, 15, 12, 10, 8, 6, 4, 2, 1');
$graded = true;
for ($position = 2; $position <= FanLists::SIZE; $position++) { $graded = $graded && $points[$position] < $points[$position - 1]; }
fan_check($graded && $points[FanLists::SIZE] > 0, 'Every position is worth less than the one above it, and tenth place still scores');

$GLOBALS['fan_points_filter'] = [10, 9, 8, 7, 6, 5, 4, 3, 2, 1];
fan_check(FanRanking::points() === [1 => 10, 9, 8, 7, 6, 5, 4, 3, 2, 1], 'A site can set its own schedule');
$GLOBALS['fan_points_filter'] = ['5', -3, 'x', 1, 1, 1, 1, 1, 1, 1];
fan_check(FanRanking::points() === [1 => 5, 0, 0, 1, 1, 1, 1, 1, 1, 1], 'Values are read as whole, non-negative points');
foreach (['too short' => [3, 2, 1], 'too long' => range(11, 1), 'not a list' => 'ten'] as $why => $bad) {
    $GLOBALS['fan_points_filter'] = $bad;
    fan_check(FanRanking::points() === $points, "A schedule that is $why falls back to the default");
}
$GLOBALS['fan_points_filter'] = null;

$row = static fn(int $id, int $points, int $picks, int $firsts): array => ['fighter_id' => $id, 'points' => $points, 'picks' => $picks, 'firsts' => $firsts];
$official = [11 => 1, 12 => 2, 13 => 3, 14 => 4, 15 => 5, 16 => 6];
$ordered = FanRanking::order([$row(13, 40, 2, 0), $row(11, 40, 2, 0), $row(12, 50, 3, 1), $row(14, 40, 2, 1), $row(15, 40, 3, 0), $row(99, 500, 9, 9)], $official);
fan_check(array_column($ordered, 'fighter_id') === [12, 14, 15, 11, 13], 'Standings order by points, then first places, then lists, then the better official rank');
fan_check(!in_array(99, array_column($ordered, 'fighter_id'), true), 'A fighter the official ranking does not list is left out, whatever their points');
fan_check(FanRanking::order([], $official) === [] && FanRanking::order([$row(11, 1, 1, 0)], []) === [], 'No picks, or no official ranking, gives an empty board');
fan_check($ordered === FanRanking::order(array_reverse($ordered), $official), 'The order does not depend on the order the rows arrive in');
echo "community-fan-points: ok\n";
