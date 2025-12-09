<?php

require_once 'vendor/autoload.php';
if (file_exists('env.php')) {
    require_once 'env.php';
}

use DraftSignal\Algorithm\Calculator\BustCalculator;
use DraftSignal\Algorithm\Config\ConfigLoader;
use DraftSignal\Algorithm\Data\CloudflarePlayerDataProvider;
use DraftSignal\Algorithm\Tier\TierResolver;

$options = getopt('', ['dry-run', 'team:', 'help']);

if (isset($options['help'])) {
    echo <<<HELP
Draft Signal Bust Calculator

Usage: php bin/run-bust-calculator.php [options]

Options:
  --dry-run     Calculate bust scores without updating the database
  --team=ID     Only process players from a specific team ID
  --help        Show this help message

HELP;
    exit(0);
}

$dryRun = isset($options['dry-run']);
$teamId = isset($options['team']) ? (int) $options['team'] : null;

try {
    $configLoader = new ConfigLoader();
    $bustConfig = $configLoader->loadBustThresholds();
    $tierConfig = $configLoader->loadTierMappings();

    $tierResolver = new TierResolver($tierConfig);
    $calculator = new BustCalculator($tierResolver, $bustConfig);
    $dataProvider = new CloudflarePlayerDataProvider();

    if ($teamId !== null) {
        $players = $dataProvider->getPlayersForTeam($teamId);
        echo "Processing players for team ID: {$teamId}\n";
    } else {
        $players = $dataProvider->getAllPlayers();
        echo "Processing all players\n";
    }

    echo sprintf("Found %d players to process\n", count($players));
    echo str_repeat('-', 60) . "\n";

    $results = [];
    $bustCount = 0;
    $nonBustCount = 0;

    foreach ($players as $player) {
        $result = $calculator->calculate($player);
        $results[] = $result->toArray();

        if ($result->isBust) {
            $bustCount++;
        } else {
            $nonBustCount++;
        }

        if (!$dryRun) {
            $dataProvider->updateBustScore($result->playerId, $result->isBust, $result->bustScore);
        }

        $status = $result->isBust ? 'BUST' : 'OK';
        echo sprintf(
            "[%s] %s (Tier %s): %.4f\n",
            $status,
            $result->playerName,
            $result->tier,
            $result->bustScore
        );
    }

    echo str_repeat('-', 60) . "\n";
    echo sprintf("Summary: %d busts, %d non-busts out of %d players\n", $bustCount, $nonBustCount, count($players));
    if ($dryRun) {
        echo "(Dry run - no database updates made)\n";
    } else {
        echo "(Database updated)\n";
    }

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Stack trace:\n" . $e->getTraceAsString() . "\n");
    exit(1);
}
