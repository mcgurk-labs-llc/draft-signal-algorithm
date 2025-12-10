<?php

namespace DraftSignal\Algorithm\Calculator\Implementations;

use DraftSignal\Algorithm\Calculator\AbstractCalculator;
use DraftSignal\Algorithm\Calculator\CalculatorInterface;
use DraftSignal\Algorithm\Data\PlayerDataProviderInterface;
use DraftSignal\Algorithm\Data\PlayerStats;
use DraftSignal\Algorithm\Calculator\CalculatorResult;

final readonly class StealCalculator extends AbstractCalculator implements CalculatorInterface {
	public function calculate(PlayerStats $player): CalculatorResult {
		$tier = $this->tierResolver->resolve($player->overallPick, $player->draftRound);
		// Pick 1 can never be a steal by definition
		if ($player->overallPick === 1) {
			return new CalculatorResult(
				playerId: $player->id,
				playerName: $player->name,
				tier: $tier,
				score: 0.0,
				data: ['isSteal' => false],
			);
		}
		// Undrafted players use UDFA tier logic, not early exit
		if ($player->isUndrafted()) {
			$tier = 'UDFA';
		}
		// No games for drafting team = no steal
		if ($player->firstStintGamesPlayed === 0) {
			return new CalculatorResult(
				playerId: $player->id,
				playerName: $player->name,
				tier: $tier,
				score: 0.0,
				data: ['isSteal' => false],
			);
		}
		// Auto-steal: elite awards + late draft capital = steal, no questions asked
		$autoStealCfg = $this->config['steal']['autoSteal'] ?? [];
		$minRoundForAutoSteal = $autoStealCfg['minRound'] ?? 4;
		$minAp1ForAutoSteal = $autoStealCfg['minAp1'] ?? 2;
		$minPbForAutoSteal = $autoStealCfg['minPb'] ?? 4;
		if ($player->draftRound !== null && $player->draftRound >= $minRoundForAutoSteal) {
			if ($player->firstStintAp1s >= $minAp1ForAutoSteal || $player->firstStintPbs >= $minPbForAutoSteal) {
				return new CalculatorResult(
					playerId: $player->id,
					playerName: $player->name,
					tier: $tier,
					score: 1.0,
					data: ['isSteal' => true, 'autoSteal' => true],
				);
			}
		}
		// Tier-level expectations
		$expectedAv       = max(1, $this->getConfigValue('expectedAv',       $tier, 1));
		$expectedRegSnaps = max(1, $this->getConfigValue('expectedRegSnaps', $tier, 1));
		$expectedStSnaps  = max(1, $this->getConfigValue('expectedStSnaps',  $tier, 1));
		$expectedRegPct   = max(1, $this->getConfigValue('expectedRegPct',   $tier, 1));
		$expectedStPct    = max(1, $this->getConfigValue('expectedStPct',    $tier, 1));
		$expectedSeasons  = max(1, $this->getConfigValue('expectedSeasons',  $tier, 3.0));
		$stealCfg      = $this->config['steal'] ?? [];
		$stealThreshold = $stealCfg['threshold'][$tier] ?? 0.6;
		// 1) AV over expectation (1x–4x -> 0–1)
		$ratioAv = $player->firstStintAv / $expectedAv;
		$ratioAv = min($ratioAv, 4.0); // cap extreme outliers
		if ($ratioAv <= 1.0) {
			// At or below expectation -> no steal credit from AV
			$avOverScore = 0.0;
		} else {
			// Map [1.0, 4.0] -> [0.0, 1.0]
			// 1x = 0.0, 2x ≈ 0.33, 3x ≈ 0.66, 4x = 1.0
			$avOverScore = ($ratioAv - 1.0) / 3.0;
			if ($avOverScore < 0.0) $avOverScore = 0.0;
			if ($avOverScore > 1.0) $avOverScore = 1.0;
		}
		// 2) Usage ratios (regular + ST, snaps + pct)
		$ratioRegSnaps = min(1.0, $player->firstStintRegSnaps / $expectedRegSnaps);
		$ratioStSnaps  = min(1.0, $player->firstStintStSnaps  / $expectedStSnaps);
		$ratioRegPct   = min(1.0, $player->firstStintRegSnapPct / $expectedRegPct);
		$ratioStPct    = min(1.0, $player->firstStintStSnapPct  / $expectedStPct);
		$earlyRoundTiers = $this->config['earlyRoundTiers'] ?? ['A','B','C','D','E','F'];
		$lateRoundTiers  = $this->config['lateRoundTiers']  ?? ['L','M','N','O'];
		if (in_array($tier, $earlyRoundTiers, true)) {
			$usageBase =
				0.6 * $ratioRegSnaps +
				0.3 * $ratioRegPct +
				0.05 * $ratioStSnaps +
				0.05 * $ratioStPct;
		} elseif (in_array($tier, $lateRoundTiers, true)) {
			$usageBase = 0.25 * $ratioRegSnaps + 0.25 * $ratioRegPct + 0.25 * $ratioStSnaps + 0.25 * $ratioStPct;
		} else {
			$usageBase = 0.45 * $ratioRegSnaps + 0.25 * $ratioRegPct + 0.15 * $ratioStSnaps + 0.15 * $ratioStPct;
		}
		$usageBase = $this->clamp($usageBase);
		// 3) Usage *over* expectation
		// Treat 0.5 as "met usage expectations"
		// 0.5 -> 0.0, 1.0 -> 1.0
		if ($usageBase <= 0.5) {
			$usageOverScore = 0.0;
		} else {
			$usageOverScore = ($usageBase - 0.5) * 2.0;
			if ($usageOverScore < 0.0) $usageOverScore = 0.0;
			if ($usageOverScore > 1.0) $usageOverScore = 1.0;
		}
		// 4) Awards score (with late-round multiplier for bias correction)
		$awardScore = $this->calculateAwardScore($player, $tier, $stealCfg, $lateRoundTiers);
		// 5) Starter factor: reward players who actually start games
		$starterScore = 0.0;
		$minGamesForStarterCredit = $stealCfg['minGamesForStarterCredit'] ?? 16;
		if ($player->firstStintGamesPlayed >= $minGamesForStarterCredit) {
			$starterRatio = $player->firstStintGamesStarted / $player->firstStintGamesPlayed;
			$starterScore = $this->clamp($starterRatio);
		}
		// 6) Longevity factor: reward sustained production, penalize flash-in-the-pan
		$longevityFactor = 1.0;
		if ($player->firstStintSeasonsPlayed < $expectedSeasons) {
			$seasonRatio = $player->firstStintSeasonsPlayed / $expectedSeasons;
			$longevityFactor = 0.5 + (0.5 * $seasonRatio);
		}
		// 7) Combine all factors, then apply longevity
		$avOverWeight    = $stealCfg['avOverWeight']    ?? 0.45;
		$awardWeight     = $stealCfg['awardWeight']     ?? 0.20;
		$usageOverWeight = $stealCfg['usageOverWeight'] ?? 0.15;
		$starterWeight   = $stealCfg['starterWeight']   ?? 0.20;
		$rawStealScore =
			$avOverWeight    * $avOverScore +
			$awardWeight     * $awardScore  +
			$usageOverWeight * $usageOverScore +
			$starterWeight   * $starterScore;
		$stealScore = $rawStealScore * $longevityFactor;
		$stealScore = $this->clamp($stealScore);
		$isSteal    = ($stealScore >= $stealThreshold);
		return new CalculatorResult(
			playerId: $player->id,
			playerName: $player->name,
			tier: $tier,
			score: round($stealScore, 4),
			data: ['isSteal' => $isSteal],
		);
	}
	public function formatLine(CalculatorResult $result): string {
		$status = $result->data['isSteal'] ? 'STEAL' : 'NOT STEAL';
		return sprintf(
			'[%s] %s (Tier %s): %.4f',
			$status,
			$result->playerName,
			$result->tier,
			$result->score
		);
	}
	public function persistResult(CalculatorResult $result, PlayerDataProviderInterface $dataProvider): void {
		$dataProvider->updateStealScore($result->playerId, $result->data['isSteal'], $result->score);
	}
	
	private function calculateAwardScore(PlayerStats $player, string $tier, array $stealCfg, array $lateRoundTiers): float {
		$weights = $stealCfg['awardPoints'] ?? [];
		$norms   = $stealCfg['awardNorm']   ?? [];
		$mvpW   = $weights['mvp']  ?? 80;
		$dpoyW  = $weights['dpoy'] ?? 50;
		$opoyW  = $weights['opoy'] ?? 50;
		$ap1W   = $weights['ap1']  ?? 35;
		$ap2W   = $weights['ap2']  ?? 20;
		$pbW    = $weights['pb']   ?? 12;
		$oroyW  = $weights['oroy'] ?? 20;
		$droyW  = $weights['droy'] ?? 20;
		$awardPoints =
			$player->firstStintMvps * $mvpW +
			$player->firstStintDpoys * $dpoyW +
			$player->firstStintOpoys * $opoyW +
			$player->firstStintAp1s  * $ap1W +
			$player->firstStintAp2s  * $ap2W +
			$player->firstStintPbs   * $pbW +
			($player->oroy ? $oroyW : 0) +
			($player->droy ? $droyW : 0);
		if ($awardPoints <= 0) {
			return 0.0;
		}
		$norm = $norms[$tier] ?? 40.0;
		if ($norm <= 0) {
			$norm = 40.0;
		}
		$score = $awardPoints / $norm;
		// Late-round players face bias in award voting - boost their award value
		if (in_array($tier, $lateRoundTiers, true) || $tier === 'UDFA') {
			$lateRoundAwardMultiplier = $stealCfg['lateRoundAwardMultiplier'] ?? 1.5;
			$score *= $lateRoundAwardMultiplier;
		}
		return $this->clamp($score);
	}
}