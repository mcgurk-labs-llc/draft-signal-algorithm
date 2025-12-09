<?php

require_once 'vendor/autoload.php';
if (file_exists('env.php')) {
	require_once 'env.php';
}

use DraftSignal\Algorithm\Calculator\BustCalculator;
use DraftSignal\Algorithm\Config\ConfigLoader;
use DraftSignal\Algorithm\Data\CloudflarePlayerDataProvider;
use DraftSignal\Algorithm\Runner\CalculatorRunner;
use DraftSignal\Algorithm\Tier\TierResolver;

$options = getopt('', ['persist', 'team:', 'help']);
$command = strtolower($argv[count($argv) - 1]);
if (str_contains($command, '.php') || !in_array($command, ['busts', 'steals', 'grades'])) {
	echo "You must pass one of: busts|steals|grades as the command to calculate.\n";
	exit(1);
}

$configLoader = new ConfigLoader();
$bustConfig = $configLoader->loadBustThresholds();
$tierConfig = $configLoader->loadTierMappings();

$tierResolver = new TierResolver($tierConfig);
if ($command === 'busts') {
	$calculator = new BustCalculator($tierResolver, $bustConfig);
} else {
	echo "The {$command} calculator is not implemented yet.";
	exit(1);
}

$runner = new CalculatorRunner($calculator);

if (isset($options['help'])) {
	echo "Usage: php bin/calculate.php [--persist] [--team=ID] [--help] <command>\n";
	exit;
}

$persist = isset($options['persist']);
$teamId = isset($options['team']) ? (int) $options['team'] : null;

try {
	$dataProvider = new CloudflarePlayerDataProvider();
	
	if ($teamId !== null) {
		$players = $dataProvider->getPlayersForTeam($teamId);
	} else {
		$players = $dataProvider->getAllPlayers();
	}
	
	$runner->run($players, $persist, $dataProvider);
	exit;
} catch (Throwable $e) {
	fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
	fwrite(STDERR, "Stack trace:\n" . $e->getTraceAsString() . "\n");
	exit(1);
}