<?php

namespace DraftSignal\Algorithm\Calculator;

use DraftSignal\Algorithm\Data\PlayerDataProviderInterface;
use DraftSignal\Algorithm\Data\PlayerStats;
use DraftSignal\Algorithm\Calculator\CalculatorResult;

interface CalculatorInterface {
	public function calculate(PlayerStats $player): CalculatorResult;
	public function persistResult(CalculatorResult $result, PlayerDataProviderInterface $dataProvider): void;
	public function formatLine(CalculatorResult $result): string;
}
