<?php

namespace DraftSignal\Algorithm\Calculator;

final readonly class CalculatorResult {
	public function __construct(
		public int $playerId,
		public string $playerName,
		public string $tier,
		public float $score,
		public array $data = [],
	) {}

	public function toArray(): array {
		return array_merge([
			'player_id' => $this->playerId,
			'player_name' => $this->playerName,
			'tier' => $this->tier,
			'score' => $this->score,
		], $this->data);
	}
}
