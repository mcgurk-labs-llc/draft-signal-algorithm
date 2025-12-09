<?php

namespace DraftSignal\Algorithm\Calculator\Implementations;

use DraftSignal\Algorithm\Calculator\CalculatorInterface;
use DraftSignal\Algorithm\Data\PlayerDataProviderInterface;
use DraftSignal\Algorithm\Data\PlayerStats;
use DraftSignal\Algorithm\Tier\TierResolver;
use DraftSignal\Algorithm\Calculator\CalculatorResult;

final class BustCalculator implements CalculatorInterface {
	private TierResolver $tierResolver;
	private array $config;

	public function __construct(TierResolver $tierResolver, array $bustConfig) {
		$this->tierResolver = $tierResolver;
		$this->config = $bustConfig;
	}
	public function calculate(PlayerStats $player): CalculatorResult {
		$tier = $this->tierResolver->resolve($player->overallPick, $player->draftRound);
		if ($player->isUndrafted()) {
			return new CalculatorResult(
				playerId: $player->id,
				playerName: $player->name,
				tier: $tier,
				score: 0.0,
				data: ['isBust' => false],
			);
		}
		if ($player->firstStintGamesPlayed === 0) {
			return new CalculatorResult(
				playerId: $player->id,
				playerName: $player->name,
				tier: $tier,
				score: 1.0,
				data: ['isBust' => true],
			);
		}
		$pos = strtoupper($player->position);
		$isQB = ($pos === 'QB');
		$isKicker = ($pos === 'K' || $pos === 'PK');
		$isPunter = ($pos === 'P');
		$isSpecialist = ($isKicker || $isPunter);
		$earlyQbTiers = $this->config['qb']['earlyTiers'] ?? ['A', 'B', 'C', 'D', 'E'];
		$isHighCapitalQB = $isQB && in_array($tier, $earlyQbTiers, true);
		$isHighCapitalSpecialist = $isSpecialist && $player->draftRound !== null && $player->draftRound <= ($this->config['specialist']['highCapitalMaxRound'] ?? 3);
		$expectedAvBase   = $this->getConfigValue('expectedAv',   $tier, 1);
		$expectedRegSnaps = max(1, $this->getConfigValue('expectedRegSnaps', $tier, 1));
		$expectedStSnaps  = max(1, $this->getConfigValue('expectedStSnaps',  $tier, 1));
		$expectedRegPct   = max(1, $this->getConfigValue('expectedRegPct',   $tier, 1));
		$expectedStPct    = max(1, $this->getConfigValue('expectedStPct',    $tier, 1));
		$expectedSeasons  = max(0.1, $this->getConfigValue('expectedSeasons', $tier, 3.0));
		$bustThreshold    = $this->getConfigValue('bustThreshold', $tier, 0.7);
		$expectedAv = $expectedAvBase;
		if ($isHighCapitalQB) {
			$qbMultiplier = $this->config['qb']['expectedAvMultiplier'] ?? 1.3;
			$expectedAv *= $qbMultiplier;
		}
		if ($isHighCapitalSpecialist) {
			$specMultiplier = $this->config['specialist']['expectedAvMultiplier'] ?? 1.3;
			$expectedAv *= $specMultiplier;
		}
		$expectedAv = max(1, $expectedAv);
		$av = $player->firstStintAv;
		$av = $this->applySpecialTeamsRescue($av, $player, $tier, $expectedAv);
		$ratioAv       = min(1.0, $av / $expectedAv);
		$ratioRegSnaps = min(1.0, $player->firstStintRegSnaps / $expectedRegSnaps);
		$ratioStSnaps  = min(1.0, $player->firstStintStSnaps / $expectedStSnaps);
		$ratioRegPct   = min(1.0, $player->firstStintRegSnapPct / $expectedRegPct);
		$ratioStPct    = min(1.0, $player->firstStintStSnapPct / $expectedStPct);
		$usageScore = $this->calculateUsageScore(
			$tier,
			$ratioRegSnaps,
			$ratioRegPct,
			$ratioStSnaps,
			$ratioStPct
		);
		$avWeight    = $this->config['weights']['avWeight']    ?? 0.6;
		$usageWeight = $this->config['weights']['usageWeight'] ?? 0.4;
		if ($isHighCapitalQB) {
			$avWeight    = $this->config['qb']['weights']['avWeight']    ?? 0.8;
			$usageWeight = $this->config['qb']['weights']['usageWeight'] ?? 0.2;
		} elseif ($isHighCapitalSpecialist) {
			$avWeight    = $this->config['specialist']['weights']['avWeight']    ?? 0.7;
			$usageWeight = $this->config['specialist']['weights']['usageWeight'] ?? 0.3;
		}
		$successScore = ($avWeight * $ratioAv) + ($usageWeight * $usageScore);
		$successScore = $this->clamp($successScore);
		$careerLengthFactor = 0.0;
		if ($player->firstStintSeasonsPlayed > 0) {
			$careerLengthFactor = min(1.0, $player->firstStintSeasonsPlayed / $expectedSeasons);
		}
		$successScore *= $careerLengthFactor;
		$successScore = $this->clamp($successScore);
		$bustScore = 1.0 - $successScore;
		$isBust = $bustScore >= $bustThreshold;
		return new CalculatorResult(
			playerId: $player->id,
			playerName: $player->name,
			tier: $tier,
			score: round($bustScore, 4),
			data: ['isBust' => $isBust],
		);
	}

	private function applySpecialTeamsRescue(int $av, PlayerStats $player, string $tier, float $expectedAv): float {
		if ($av > 0 || $player->firstStintGamesPlayed < 12 || $player->firstStintStSnapPct < 20) {
			return (float) $av;
		}

		return match ($tier) {
			'M' => max($av, $expectedAv * 0.20),
			'N' => max($av, $expectedAv * 0.30),
			'O' => max($av, $expectedAv * 0.50),
			default => (float) $av,
		};
	}

	private function calculateUsageScore(string $tier, float $ratioRegSnaps, float $ratioRegPct, float $ratioStSnaps, float $ratioStPct): float {
		$earlyRoundTiers = $this->config['earlyRoundTiers'] ?? ['A', 'B', 'C', 'D', 'E', 'F'];
		$lateRoundTiers = $this->config['lateRoundTiers'] ?? ['L', 'M', 'N', 'O'];

		if (in_array($tier, $earlyRoundTiers, true)) {
			$usageScore = (0.6 * $ratioRegSnaps)
				+ (0.3 * $ratioRegPct)
				+ (0.05 * $ratioStSnaps)
				+ (0.05 * $ratioStPct);
		} elseif (in_array($tier, $lateRoundTiers, true)) {
			$usageScore = (0.25 * $ratioRegSnaps)
				+ (0.25 * $ratioRegPct)
				+ (0.25 * $ratioStSnaps)
				+ (0.25 * $ratioStPct);
		} else {
			$usageScore = (0.45 * $ratioRegSnaps)
				+ (0.25 * $ratioRegPct)
				+ (0.15 * $ratioStSnaps)
				+ (0.15 * $ratioStPct);
		}

		return $this->clamp($usageScore);
	}

	private function getConfigValue(string $key, string $tier, float $default): float {
		return (float) ($this->config[$key][$tier] ?? $default);
	}

	public function persistResult(CalculatorResult $result, PlayerDataProviderInterface $dataProvider): void {
		$dataProvider->updateBustScore($result->playerId, $result->data['isBust'], $result->score);
	}

	public function formatLine(CalculatorResult $result): string {
		$status = $result->data['isBust'] ? 'BUST' : 'NOT BUST';
		return sprintf(
			'[%s] %s (Tier %s): %.4f',
			$status,
			$result->playerName,
			$result->tier,
			$result->score
		);
	}

	private function clamp(float $value, float $min = 0.0, float $max = 1.0): float {
		return max($min, min($max, $value));
	}
}
