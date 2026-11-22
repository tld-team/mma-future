<?php
/** Gender comes from what the Tapology profile states; a weight class may only ever suggest "male". */
define('ABSPATH', dirname(__DIR__, 2) . '/');
$includes = ABSPATH . 'wp-content/plugins/mma-future-data-engine/includes/';
spl_autoload_register(static function ($class) use ($includes) {
    $prefix = 'MMAF\\DataEngine\\';
    if (str_starts_with($class, $prefix)) {
        $file = $includes . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) { require_once $file; }
    }
});
function gender_check(bool $ok, string $label): void {
    if (!$ok) { throw new RuntimeException($label); }
    echo "PASS: $label\n";
}
use MMAF\DataEngine\Services\DataPipeline\ProfileStore as Store;
use MMAF\DataEngine\Services\FieldProvenanceService as Provenance;
use MMAF\DataEngine\Services\Import\GenderInferenceService as Inference;
use MMAF\DataEngine\Services\Import\OpponentGenderConsensusService as Consensus;
use MMAF\DataEngine\Support\WeightClassResolver as Resolver;

$service = new Inference();
$evidence = static fn(array $overrides = []) => array_merge(
    ['pronoun' => 'he', 'women_p4p_ranking' => false, 'female_grouping_note' => false, 'status' => 'explicit', 'excerpt' => 'He is only ranked in regions where he has fought at least 2 times out of his last 6 MMA bouts.'],
    $overrides
);
$she = $evidence(['pronoun' => 'she', 'women_p4p_ranking' => true, 'female_grouping_note' => true]);

// The reported case: a man Tapology lists at Strawweight.
$paulo = $service->infer(['weight_class' => 'Strawweight', 'gender' => 'male', 'gender_evidence' => $evidence()]);
gender_check('male' === $paulo['gender'] && Inference::SOURCE_EXPLICIT === $paulo['provenance_source_type'] && 'high' === $paulo['confidence'], 'a Strawweight whose profile says "he" is male, from the explicit source');
gender_check(null === $service->infer(['weight_class' => 'Strawweight'])['gender'], 'Strawweight alone no longer decides a gender');
gender_check(null === $service->infer(['weight_class' => 'Atom'])['gender'], 'Atomweight alone does not decide a gender');
gender_check(null === $service->infer(['weight_class' => 'Lightweight'])['gender'], 'Lightweight alone does not decide a gender (women fight there too)');
foreach (['Flyweight', 'Bantamweight', 'Featherweight'] as $class) {
    gender_check(null === $service->infer(['weight_class' => $class])['gender'], "$class alone does not decide a gender");
}
foreach (['Welterweight', 'Middleweight', 'Light Heavy', 'Light Heavyweight', 'Heavyweight', 'Super Heavy'] as $class) {
    $result = $service->infer(['weight_class' => $class]);
    gender_check('male' === $result['gender'] && Inference::SOURCE_WEIGHT_CLASS === $result['provenance_source_type'] && 'medium' === $result['confidence'], "$class alone suggests male as a correctable inference");
}

$woman = $service->infer(['weight_class' => 'Lightweight', 'gender' => 'female', 'gender_evidence' => $she]);
gender_check('female' === $woman['gender'] && Inference::SOURCE_EXPLICIT === $woman['provenance_source_type'], 'a profile that says "she" is female whatever the weight class');
gender_check('female' === $service->infer(['weight_class' => 'Welterweight', 'gender' => 'female', 'gender_evidence' => $she])['gender'], 'explicit evidence wins over a male-only weight class');
gender_check('female' === $service->infer(['gender' => 'female', 'gender_evidence' => $evidence(['pronoun' => null, 'women_p4p_ranking' => true])])['gender'], 'the women\'s ranking line alone is explicit enough');

$bad = $service->infer(['weight_class' => 'Heavyweight', 'gender' => 'female', 'gender_evidence' => $evidence()]);
gender_check(null === $bad['gender'] && 'gender_evidence_inconsistent' === $bad['warning'], 'a stated gender that its own evidence contradicts is rejected and not replaced by a weight-class guess');
$mixed = $service->infer(['weight_class' => 'Heavyweight', 'gender' => 'male', 'gender_evidence' => $evidence(['women_p4p_ranking' => true])]);
gender_check(null === $mixed['gender'] && 'gender_evidence_inconsistent' === $mixed['warning'], '"he" together with a women\'s ranking line is rejected');
$conflict = $service->infer(['weight_class' => 'Heavyweight', 'gender' => null, 'gender_evidence' => $evidence(['status' => 'conflict'])]);
gender_check(null === $conflict['gender'] && 'gender_evidence_conflict' === $conflict['warning'], 'a conflict reported by the scraper yields no gender');
gender_check(null === $service->infer(['gender' => 'male'])['gender'], 'a stated gender without evidence is not trusted');
gender_check(null === $service->infer(['gender' => 'other', 'gender_evidence' => $evidence()])['gender'], 'an unknown gender value is ignored');
gender_check('male' === $service->infer(['weight_class' => 'Middleweight', 'gender' => null, 'gender_evidence' => ['pronoun' => null, 'women_p4p_ranking' => false, 'female_grouping_note' => false, 'status' => 'ineligible', 'excerpt' => '']])['gender'], 'an unranked fighter falls back to the weight class');
gender_check(240 === strlen($service->infer(['gender' => 'male', 'gender_evidence' => $evidence(['excerpt' => str_repeat('a', 500)])])['evidence_excerpt']), 'the evidence excerpt is bounded');

gender_check('female' === $service->infer_from_weight_class("Women's Flyweight")['gender'], 'a label that says women\'s is female');
gender_check(null === $service->infer_from_weight_class('female_flyweight')['gender'], '"female_" is not mistaken for the "male_" prefix');
gender_check('male' === $service->infer_from_weight_class("Men's Flyweight")['gender'], 'a label that says men\'s is male');
gender_check(null === $service->infer_from_weight_class('')['gender'] && null === $service->infer_from_weight_class(null)['gender'], 'no label gives no gender');

// Weight class follows gender.
gender_check('flyweight' === Resolver::canonical('Strawweight', 'male'), 'a male Strawweight is stored as flyweight');
gender_check('women_strawweight' === Resolver::canonical('Strawweight', 'female'), 'a female Strawweight is stored as women_strawweight');
gender_check(null === Resolver::canonical('Strawweight', null) && null === Resolver::canonical('Strawweight', ''), 'a Strawweight of unknown gender is not stored');
gender_check('women_flyweight' === Resolver::canonical('Flyweight', 'female') && 'women_bantamweight' === Resolver::canonical('Bantamweight', 'female') && 'women_featherweight' === Resolver::canonical('Featherweight', 'female'), 'a woman\'s division gets the women_ prefix');
gender_check('flyweight' === Resolver::canonical('Flyweight', 'male') && 'flyweight' === Resolver::canonical('Flyweight', null), 'a bare class stays as it is for men and for unknown gender');
gender_check(null === Resolver::canonical('Lightweight', 'female') && null === Resolver::canonical('Heavyweight', 'female'), 'a woman above featherweight has no storable division');
gender_check('light_heavyweight' === Resolver::canonical('Light Heavy', 'male') && 'light_heavyweight' === Resolver::canonical('light_heavyweight', 'male'), 'Light Heavy is light_heavyweight');
gender_check(null === Resolver::canonical('Atom', 'female') && null === Resolver::canonical('Super Heavy', 'male') && null === Resolver::canonical('Catchweight', 'male'), 'classes without a board are not stored');
gender_check('women_strawweight' === Resolver::canonical('women_strawweight', 'female') && 'women_strawweight' === Resolver::canonical("Women's Strawweight", null), 'an explicit women\'s label is kept');
gender_check(null === Resolver::canonical('women_strawweight', 'male'), 'a women\'s label on a man is not stored');
gender_check(null === Resolver::canonical('', 'male') && null === Resolver::canonical(null, 'male') && null === Resolver::canonical('unknown', 'male'), 'an empty or unknown label is not stored');
gender_check(!Resolver::is_compatible('male', 'women_strawweight') && Resolver::is_compatible('male', 'flyweight') && !Resolver::is_compatible('female', 'flyweight') && Resolver::is_compatible('female', 'women_flyweight') && Resolver::is_compatible('male', 'unknown') && Resolver::is_compatible(null, 'women_flyweight'), 'compatibility follows the women_ prefix');

gender_check('flyweight' === Resolver::realign('women_strawweight', 'male') && 'women_flyweight' === Resolver::realign('flyweight', 'female') && 'women_strawweight' === Resolver::realign('women_strawweight', 'female') && null === Resolver::realign('lightweight', 'female') && null === Resolver::realign('unknown', 'male'), 'a stored division is realigned when the gender becomes known');

// Opponents decide a gender only when at least two trusted ones agree.
gender_check(['gender' => 'male', 'reason' => 'opponents_agree'] === Consensus::decide(['male', 'male']), 'two agreeing opponents decide the gender');
gender_check(['gender' => 'female', 'reason' => 'opponents_agree'] === Consensus::decide(['female', 'female', 'female']), 'three agreeing opponents decide the gender');
gender_check(null === Consensus::decide(['male'])['gender'] && 'too_few_trusted_opponents' === Consensus::decide(['male'])['reason'], 'one opponent is not enough');
gender_check(null === Consensus::decide([])['gender'], 'no opponents decide nothing');
gender_check(null === Consensus::decide(['male', 'male', 'female'])['gender'] && 'opponents_disagree' === Consensus::decide(['male', 'male', 'female'])['reason'], 'a single dissenting opponent blocks the decision');
gender_check(null === Consensus::decide(['male', '', 'unknown'])['gender'], 'opponents without a real gender do not count');

// Which stored values a stronger source may correct.
$hash = static fn(string $value): string => Provenance::value_hash($value);
$row = static fn(string $type, string $value, int $locked = 0): array => ['source_type' => $type, 'source_id' => 'tapology_fighter_1', 'value_hash' => $hash($value), 'locked' => $locked];
$weak = Inference::INFERRED_SOURCE_TYPES;
$only = static fn(array $rows, string $current): bool => Provenance::only_from(Provenance::describe_sources($rows, $current), $weak);
gender_check($only([$row(Inference::SOURCE_WEIGHT_CLASS, 'female')], 'female'), 'a value only the weight-class inference put there is correctable');
gender_check($only([$row(Inference::SOURCE_OPPONENTS, 'male')], 'male'), 'a value only the opponent inference put there is correctable');
gender_check($only([$row(Inference::SOURCE_WEIGHT_CLASS, 'female'), $row(Inference::SOURCE_EXPLICIT, 'male')], 'female'), 'the disagreeing explicit evidence row does not block the correction');
gender_check(!$only([$row(Inference::SOURCE_WEIGHT_CLASS, 'female'), $row('manual_verified', 'female')], 'female'), 'a manually verified value is never correctable');
gender_check(!$only([$row(Inference::SOURCE_WEIGHT_CLASS, 'female'), $row('manual_verified', 'male')], 'female'), 'any manual decision on the field blocks the correction');
gender_check(!$only([$row(Inference::SOURCE_WEIGHT_CLASS, 'female', 1)], 'female'), 'a locked row blocks the correction');
gender_check(!$only([$row(Inference::SOURCE_WEIGHT_CLASS, 'female'), $row(Inference::SOURCE_EXPLICIT, 'female')], 'female'), 'a value the source confirms is not weak');
gender_check(!$only([$row(Inference::SOURCE_WEIGHT_CLASS, 'female'), $row('tapology', 'female')], 'female'), 'a value another source also backs is not weak');
gender_check(!$only([], 'female'), 'a value of unknown origin is not correctable');
gender_check(!$only([$row(Inference::SOURCE_WEIGHT_CLASS, 'male')], 'female'), 'an inference row for a different value does not make the current one weak');
gender_check(Provenance::value_hash(' Female ') === Provenance::value_hash('female'), 'value hashes ignore case and surrounding space');

// A stored capture may be completed with gender evidence, and with nothing else.
if (!function_exists('wp_json_encode')) { function wp_json_encode($value) { return json_encode($value); } }
$stored = ['source_fighter_id' => 'tapology_fighter_1', 'status' => 'success', 'profile_summary' => ['weight_class' => 'Strawweight', 'nickname' => null], 'fight_history' => [['result' => 'win']]];
$sorted = static function ($value) use (&$sorted) { if (is_array($value)) { if (!array_is_list($value)) { ksort($value); } $value = array_map($sorted, $value); } return $value; };
$stored_hash = hash('sha256', json_encode($sorted($stored)));
$payload = json_encode($stored + ['scraped_at' => '2026-11-17T10:00:00Z']);
$completed = $stored; $completed['profile_summary'] += ['gender' => 'male', 'gender_evidence' => $evidence()];
gender_check(Store::adds_only_gender_evidence($completed, $stored_hash, $payload), 'a stored capture plus gender evidence is the same capture');
$silent = $stored; $silent['profile_summary'] += ['gender' => null, 'gender_evidence' => $evidence(['pronoun' => null, 'status' => 'ineligible', 'excerpt' => ''])];
gender_check(Store::adds_only_gender_evidence($silent, $stored_hash, $payload), 'so is one whose page states no gender');
$edited = $completed; $edited['profile_summary']['weight_class'] = 'Flyweight';
gender_check(!Store::adds_only_gender_evidence($edited, $stored_hash, $payload), 'any other changed field keeps it a different capture');
$rows = $completed; $rows['fight_history'][] = ['result' => 'loss'];
gender_check(!Store::adds_only_gender_evidence($rows, $stored_hash, $payload), 'so does a changed fight history');
gender_check(!Store::adds_only_gender_evidence($stored, $stored_hash, $payload), 'a profile without gender evidence adds nothing');
$has = $completed; $flipped = $completed; $flipped['profile_summary']['gender'] = 'female';
gender_check(!Store::adds_only_gender_evidence($flipped, hash('sha256', json_encode($sorted($has))), json_encode($has)), 'gender evidence already stored is never replaced this way');
gender_check(!Store::adds_only_gender_evidence($completed, $stored_hash, 'not json'), 'an unreadable stored capture is not completed');
