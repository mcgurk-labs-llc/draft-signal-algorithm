<?php

namespace DraftSignal\Algorithm\Calculator;

use DraftSignal\Algorithm\Data\PlayerStats;

interface CalculatorInterface {
	public function calculate(PlayerStats $player): CalculatorResult;
}
