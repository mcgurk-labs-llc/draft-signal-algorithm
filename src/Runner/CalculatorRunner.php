<?php

namespace DraftSignal\Algorithm\Runner;

use DraftSignal\Algorithm\Calculator\CalculatorInterface;
use DraftSignal\Algorithm\Data\PlayerDataProviderInterface;

final readonly class CalculatorRunner {
	public function __construct(
		private CalculatorInterface $calculator,
	) {}

	public function run(array $players, bool $persist, PlayerDataProviderInterface $dataProvider): void {
		echo sprintf("Processing %d players\n", count($players));
		echo str_repeat('-', 60) . "\n";

		foreach ($players as $player) {
			$result = $this->calculator->calculate($player);

			if ($persist) {
				$this->calculator->persistResult($result, $dataProvider);
			}

			echo $this->calculator->formatLine($result) . "\n";
		}

		echo str_repeat('-', 60) . "\n";
		if ($persist) {
			echo "(Database updated)\n";
		} else {
			echo "(Dry run - no database updates made)\n";
		}
	}
}
