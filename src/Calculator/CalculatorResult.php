<?php

namespace DraftSignal\Algorithm\Calculator;

final readonly class CalculatorResult {
	public function __construct(
		public int $playerId,
		public string $playerName,
		public bool $isBust,
		public float $bustScore,
		public string $tier,
	) {}

	public function toArray(): array {
		return [
			'player_id' => $this->playerId,
			'player_name' => $this->playerName,
			'is_bust' => $this->isBust,
			'bust_score' => $this->bustScore,
			'tier' => $this->tier,
		];
	}
}
