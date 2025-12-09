<?php

namespace DraftSignal\Algorithm\Data;

interface PlayerDataProviderInterface {
	/**
	 * @return PlayerStats[]
	 */
	public function getPlayersForTeam(int $teamId): array;

	/**
	 * @return PlayerStats[]
	 */
	public function getAllPlayers(): array;

	public function updateBustScore(int $playerId, bool $isBust, float $bustScore): void;
}
