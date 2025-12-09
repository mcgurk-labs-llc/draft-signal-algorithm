<?php

namespace DraftSignal\Algorithm\Data;

interface PlayerDataProviderInterface {
	/**
	 * @return PlayerStats[]
	 */
	public function getPlayers(?int $teamId = null, ?int $year = null): array;

	public function updateBustScore(int $playerId, bool $isBust, float $bustScore): void;
}
