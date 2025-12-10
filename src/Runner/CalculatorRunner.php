<?php

namespace DraftSignal\Algorithm\Runner;

use DraftSignal\Algorithm\Calculator\CalculatorInterface;
use DraftSignal\Algorithm\Data\PlayerDataProviderInterface;

final readonly class CalculatorRunner {
	public function __construct(
		private CalculatorInterface $calculator,
	) {}

	public function run(array $players, bool $persist, PlayerDataProviderInterface $dataProvider, bool $debug = false): void {
		echo sprintf("Processing %d players\n", count($players));
		echo str_repeat('-', 60) . "\n";

		$debugData = [];
		$results = [];

		foreach ($players as $player) {
			$result = $this->calculator->calculate($player);
			$results[] = $result;

			if ($debug) {
				$debugData[] = [
					'input' => $player->toArray(),
					'output' => $result->toArray(),
				];
			}

			echo $this->calculator->formatLine($result) . "\n";
		}

		if ($persist) {
			echo "Persisting results...\n";
			$this->calculator->persistResults($results, $dataProvider);
		}

		if ($debug) {
			$this->writeDebugLog($debugData);
		}

		echo str_repeat('-', 60) . "\n";
		if ($persist) {
			echo "(Database updated)\n";
		} else {
			echo "(Dry run - no database updates made)\n";
		}

		if ($debug) {
			echo "(Debug log written to logs/debug-log.json)\n";
		}
	}

	private function writeDebugLog(array $data): void {
		$logsDir = 'logs';
		if (!is_dir($logsDir)) {
			mkdir($logsDir, 0755, true);
		}

		file_put_contents(
			$logsDir . '/debug-log.json',
			json_encode($data, JSON_PRETTY_PRINT)
		);
	}
}
