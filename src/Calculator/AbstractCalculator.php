<?php

namespace DraftSignal\Algorithm\Calculator;

use DraftSignal\Algorithm\Calculator\CalculatorInterface;
use DraftSignal\Algorithm\Tier\TierResolver;

abstract readonly class AbstractCalculator implements CalculatorInterface {
	
	public function __construct(protected TierResolver $tierResolver, protected array $config) {}
	protected function getConfigValue(string $key, string $tier, float $default): float {
		return (float) ($this->config[$key][$tier] ?? $default);
	}
	protected function clamp(float $value, float $min = 0.0, float $max = 1.0): float {
		return max($min, min($max, $value));
	}
}
