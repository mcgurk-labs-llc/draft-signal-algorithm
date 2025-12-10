<?php

namespace DraftSignal\Algorithm\Data;

interface PlayerDataProviderInterface {
	/**
	 * @return PlayerStats[]
	 */
	public function getPlayers(?int $teamId = null, ?int $year = null): array;

	public function updateBustScore(int $playerId, bool $isBust, float $bustScore): void;
	public function updateStealScore(int $playerId, bool $isSteal, float $stealScore): void;

	/**
	 * @param array<int, array{isBust: bool, score: float}> $updates Keyed by player ID
	 */
	public function bulkUpdateBustScores(array $updates): void;

	/**
	 * @param array<int, array{isSteal: bool, score: float}> $updates Keyed by player ID
	 */
	public function bulkUpdateStealScores(array $updates): void;
}
