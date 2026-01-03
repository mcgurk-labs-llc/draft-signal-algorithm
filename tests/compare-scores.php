<?php

require_once __DIR__ . '/../vendor/autoload.php';

use DraftSignal\Algorithm\Calculator\Implementations\StealCalculator;
use DraftSignal\Algorithm\Config\ConfigLoader;
use DraftSignal\Algorithm\Data\PlayerStats;
use DraftSignal\Algorithm\Tier\TierResolver;

$configLoader = new ConfigLoader();
$config = $configLoader->loadBustThresholds();
$tierConfig = $configLoader->loadTierMappings();

$tierResolver = new TierResolver($tierConfig);
$calculator = new StealCalculator($tierResolver, $config);

$fixturesPath = __DIR__ . '/fixtures/known-players.json';
$fixtureData = json_decode(file_get_contents($fixturesPath), true);

echo "Player Scores:\n";
echo str_repeat("=", 100) . "\n";

foreach ($fixtureData['players'] as $playerData) {
    $player = PlayerStats::fromArray($playerData);
    $result = $calculator->calculate($player);

    $expectedSteal = $playerData['expectedIsSteal'] ? 'STEAL' : 'NOT';
    $actualSteal = $result->data['isSteal'] ? 'STEAL' : 'NOT';
    $match = $expectedSteal === $actualSteal ? '✓' : '✗';

    printf(
        "%s %-25s | Pick %-3s | Tier %4s | Score: %.4f | Expected: %5s | Actual: %5s | %s\n",
        $match,
        substr($playerData['name'], 0, 25),
        $playerData['overallPick'] ?? 'UDFA',
        $result->tier,
        $result->score,
        $expectedSteal,
        $actualSteal,
        isset($result->data['autoSteal']) ? '(AUTO)' : ''
    );
}
