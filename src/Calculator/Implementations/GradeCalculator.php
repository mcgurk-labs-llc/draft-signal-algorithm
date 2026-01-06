<?php

namespace DraftSignal\Algorithm\Calculator\Implementations;

use DraftSignal\Algorithm\Calculator\AbstractCalculator;
use DraftSignal\Algorithm\Calculator\CalculatorInterface;
use DraftSignal\Algorithm\Calculator\CalculatorResult;
use DraftSignal\Algorithm\Data\PlayerDataProviderInterface;
use DraftSignal\Algorithm\Data\PlayerStats;

final readonly class GradeCalculator extends AbstractCalculator implements CalculatorInterface {
	public function calculate(PlayerStats $player): CalculatorResult {
		$tier = $this->tierResolver->resolve($player->overallPick, $player->draftRound);

		// UDFA use UDFA tier for grading expectations
		if ($player->isUndrafted()) {
			$tier = 'UDFA';
		}

		// No games played = 0.0 grade (complete failure, zero production)
		if ($player->firstStintGamesPlayed === 0) {
			return new CalculatorResult(
				playerId: $player->id,
				playerName: $player->name,
				tier: $tier,
				score: 0.0,
			);
		}

		// Tier-level expectations
		$expectedAv       = max(1, $this->getConfigValue('expectedAv',       $tier, 1));
		$expectedRegSnaps = max(1, $this->getConfigValue('expectedRegSnaps', $tier, 1));
		$expectedStSnaps  = max(1, $this->getConfigValue('expectedStSnaps',  $tier, 1));
		$expectedRegPct   = max(1, $this->getConfigValue('expectedRegPct',   $tier, 1));
		$expectedStPct    = max(1, $this->getConfigValue('expectedStPct',    $tier, 1));
		$expectedSeasons  = max(1, $this->getConfigValue('expectedSeasons',  $tier, 3.0));

		// 1) AV Score - meeting expectations is highly valued, exceeding is rewarded
		// Curve: 0x = 0.0, 0.5x = 0.45, 1x = 0.9 (met expectations), 2x = 1.0, 3x = 1.05, 4x = 1.1
		// Allow scores above 1.0 to reward extreme overperformance (especially for late picks with no awards)
		$ratioAv = $player->firstStintAv / $expectedAv;
		$ratioAv = min($ratioAv, 4.0); // cap extreme outliers at 4x

		if ($ratioAv <= 1.0) {
			// Below or at expectation: linear 0.0 -> 0.9
			$avScore = 0.9 * $ratioAv;
		} elseif ($ratioAv <= 2.0) {
			// 1x to 2x: 0.9 -> 1.0
			$avScore = 0.9 + ($ratioAv - 1.0) * 0.1;
		} else {
			// Above 2x: 1.0 -> 1.1 for extreme overperformers (3x-4x)
			$avScore = 1.0 + min(($ratioAv - 2.0), 2.0) * 0.05;
		}

		// 2) Usage Score - full spectrum mapping (not just "over expectation")
		$ratioRegSnaps = min(1.0, $player->firstStintRegSnaps / $expectedRegSnaps);
		$ratioStSnaps  = min(1.0, $player->firstStintStSnaps  / $expectedStSnaps);
		$ratioRegPct   = min(1.0, $player->firstStintRegSnapPct / $expectedRegPct);
		$ratioStPct    = min(1.0, $player->firstStintStSnapPct  / $expectedStPct);

		$earlyRoundTiers = $this->config['earlyRoundTiers'] ?? ['A','B','C','D','E','F'];
		$lateRoundTiers  = $this->config['lateRoundTiers']  ?? ['L','M','N','O'];

		if (in_array($tier, $earlyRoundTiers, true)) {
			$usageScore =
				0.6 * $ratioRegSnaps +
				0.3 * $ratioRegPct +
				0.05 * $ratioStSnaps +
				0.05 * $ratioStPct;
		} elseif (in_array($tier, $lateRoundTiers, true)) {
			$usageScore = 0.25 * $ratioRegSnaps + 0.25 * $ratioRegPct + 0.25 * $ratioStSnaps + 0.25 * $ratioStPct;
		} else {
			$usageScore = 0.45 * $ratioRegSnaps + 0.25 * $ratioRegPct + 0.15 * $ratioStSnaps + 0.15 * $ratioStPct;
		}
		$usageScore = $this->clamp($usageScore);

		// 3) Awards score - recognize Pro Bowls, All-Pro, MVP, etc.
		$awardScore = $this->calculateAwardScore($player, $tier, $lateRoundTiers);

		// 4) Starter factor: reward players who actually start games
		$starterScore = 0.0;
		$stealCfg = $this->config['steal'] ?? [];
		$minGamesForStarterCredit = $stealCfg['minGamesForStarterCredit'] ?? 16;
		if ($player->firstStintGamesPlayed >= $minGamesForStarterCredit) {
			$starterRatio = $player->firstStintGamesStarted / $player->firstStintGamesPlayed;
			$starterScore = $this->clamp($starterRatio);
		}

		// 5) Combine all factors with weights (production-focused)
		$gradeCfg = $this->config['grade'] ?? [];
		$avWeight      = $gradeCfg['avWeight']      ?? 0.70;  // Production is king
		$awardWeight   = $gradeCfg['awardWeight']   ?? 0.10;  // Nice to have, not required
		$usageWeight   = $gradeCfg['usageWeight']   ?? 0.12;  // Supporting metric
		$starterWeight = $gradeCfg['starterWeight'] ?? 0.08;  // Supporting metric

		$rawGrade =
			$avWeight      * $avScore +
			$awardWeight   * $awardScore +
			$usageWeight   * $usageScore +
			$starterWeight * $starterScore;

		// 6) Apply longevity factor - penalize flash-in-the-pan or injury-shortened careers
		$longevityFactor = 1.0;
		if ($player->firstStintSeasonsPlayed < $expectedSeasons) {
			$seasonRatio = $player->firstStintSeasonsPlayed / $expectedSeasons;
			// Same as steal: 0.5 + 0.5*ratio (so Trey Sermon with 1/3 seasons = 0.5 + 0.167 = 0.667 multiplier)
			$longevityFactor = 0.5 + (0.5 * $seasonRatio);
		}

		$finalGrade = $rawGrade * $longevityFactor;
		$finalGrade = $this->clamp($finalGrade);

		return new CalculatorResult(
			playerId: $player->id,
			playerName: $player->name,
			tier: $tier,
			score: round($finalGrade, 4),
		);
	}

	private function calculateAwardScore(PlayerStats $player, string $tier, array $lateRoundTiers): float {
		$stealCfg = $this->config['steal'] ?? [];
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
	public function formatLine(CalculatorResult $result): string {
		return sprintf(
			'%s (Tier %s): %.3f',
			$result->playerName,
			$result->tier,
			$result->score
		);
	}
	public function persistResult(CalculatorResult $result, PlayerDataProviderInterface $dataProvider): void {
		$dataProvider->updateGrade($result->playerId, $result->score);
	}
	public function persistResults(array $results, PlayerDataProviderInterface $dataProvider): void {
		$updates = [];
		foreach ($results as $result) {
			$updates[$result->playerId] = [
				'score' => $result->score,
			];
		}
		$dataProvider->bulkUpdateGrade($updates);
	}
}