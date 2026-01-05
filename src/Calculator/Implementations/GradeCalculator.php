<?php

namespace DraftSignal\Algorithm\Calculator\Implementations;

use DraftSignal\Algorithm\Calculator\AbstractCalculator;
use DraftSignal\Algorithm\Calculator\CalculatorInterface;
use DraftSignal\Algorithm\Calculator\CalculatorResult;
use DraftSignal\Algorithm\Data\PlayerDataProviderInterface;
use DraftSignal\Algorithm\Data\PlayerStats;

final readonly class GradeCalculator extends AbstractCalculator implements CalculatorInterface {
	public function calculate(PlayerStats $player): CalculatorResult {
		// TODO: Implement calculate() method.
		return new CalculatorResult();
	}
	public function formatLine(CalculatorResult $result): string {
		return sprintf(
			'%s (Tier %s): %.3f',
			$result->playerName,
			$result->tier,
			$result->score
		);
	}
	public function persistResult(CalculatorResult $result, PlayerDataProviderInterface $dataProvider): void {
		$dataProvider->updateGrade($result->playerId, $result->score);
	}
	public function persistResults(array $results, PlayerDataProviderInterface $dataProvider): void {
		$updates = [];
		foreach ($results as $result) {
			$updates[$result->playerId] = [
				'score' => $result->score,
			];
		}
		$dataProvider->bulkUpdateGrade($updates);
	}
}