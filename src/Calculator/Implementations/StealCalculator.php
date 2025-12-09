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
		if ($player->overallPick === 1) {
			return new CalculatorResult(
				playerId: $player->id,
				playerName: $player->name,
				tier: $tier,
				score: 0.0,
				data: ['isSteal' => false],
			);
		}
		if ($player->firstStintGamesPlayed === 0) {
			return new CalculatorResult(
				playerId: $player->id,
				playerName: $player->name,
				tier: $tier,
				score: 0.0,
				data: ['isSteal' => false],
			);
		}
		$expectedAv = max(1, $this->getConfigValue('expectedAv', $tier, 1));
		$expectedRegSnaps = max(1, $this->getConfigValue('expectedRegSnaps', $tier, 1));
		$expectedStSnaps = max(1, $this->getConfigValue('expectedStSnaps', $tier, 1));
		$expectedRegPct = max(1, $this->getConfigValue('expectedRegPct', $tier, 1));
		$expectedStPct = max(1, $this->getConfigValue('expectedStPct', $tier, 1));
		$stealCfg = $this->config['steal'] ?? [];
		$stealThreshold = $stealCfg['threshold'][$tier] ?? 0.6;
		$ratioAv = min(1.5, $player->firstStintAv / $expectedAv);
		$ratioRegSnaps = min(1.0, $player->firstStintRegSnaps / $expectedRegSnaps);
		$ratioStSnaps = min(1.0, $player->firstStintStSnaps / $expectedStSnaps);
		$ratioRegPct = min(1.0, $player->firstStintRegSnapPct / $expectedRegPct);
		$ratioStPct = min(1.0, $player->firstStintStSnapPct / $expectedStPct);
		$earlyRoundTiers = $this->config['earlyRoundTiers'] ?? ['A','B','C','D','E','F'];
		$lateRoundTiers = $this->config['lateRoundTiers']  ?? ['L','M','N','O'];
		if (in_array($tier, $earlyRoundTiers, true)) {
			$usageBase = 0.6 * $ratioRegSnaps + 0.3 * $ratioRegPct + 0.05 * $ratioStSnaps + 0.05 * $ratioStPct;
		} elseif (in_array($tier, $lateRoundTiers, true)) {
			$usageBase = 0.25 * $ratioRegSnaps + 0.25 * $ratioRegPct + 0.25 * $ratioStSnaps + 0.25 * $ratioStPct;
		} else {
			$usageBase = 0.45 * $ratioRegSnaps + 0.25 * $ratioRegPct + 0.15 * $ratioStSnaps  + 0.15 * $ratioStPct;
		}
		$usageBase = $this->clamp($usageBase);
		$usageOver = max(0.0, $usageBase - 0.6);
		$usageOverScore = $usageOver > 0 ? min(1.0, $usageOver / 0.4) : 0.0;
		$avOver = max(0.0, $ratioAv - 1.0);
		$avOverScore = $avOver > 0 ? min(1.0, $avOver / 1.5) : 0.0;
		$awardScore = $this->calculateAwardScore($player, $tier, $stealCfg);
		$avOverWeight = $stealCfg['avOverWeight']    ?? 0.55;
		$awardWeight = $stealCfg['awardWeight']     ?? 0.30;
		$usageOverWeight = $stealCfg['usageOverWeight'] ?? 0.15;
		$stealScore = $avOverWeight * $avOverScore + $awardWeight * $awardScore + $usageOverWeight * $usageOverScore;
		$stealScore = $this->clamp($stealScore);
		$isSteal = ($stealScore >= $stealThreshold);
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
	
	private function calculateAwardScore(PlayerStats $player, string $tier, array $stealCfg): float {
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
		return $this->clamp($score);
	}
}