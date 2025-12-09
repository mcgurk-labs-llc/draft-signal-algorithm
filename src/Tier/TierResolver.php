<?php

namespace DraftSignal\Algorithm\Tier;

final class TierResolver {
	private array $pickRanges;
	private array $roundFallbacks;
	private int $round3Cutoff;

	public function __construct(array $config) {
		$this->pickRanges = $config['pickRanges'] ?? [];
		$this->roundFallbacks = $config['roundFallbacks'] ?? [];
		$this->round3Cutoff = $config['round3Cutoff'] ?? 100;
	}

	public function resolve(int $overallPick, int $round): string {
		foreach ($this->pickRanges as $range) {
			if ($overallPick >= $range['minPick'] && $overallPick <= $range['maxPick']) {
				return $range['tier'];
			}
		}

		if ($round === 2) {
			return $this->roundFallbacks['2'] ?? 'I';
		}

		if ($round === 3) {
			if ($overallPick <= $this->round3Cutoff) {
				return $this->roundFallbacks['3_early'] ?? 'J';
			}
			return $this->roundFallbacks['3_late'] ?? 'K';
		}

		return match ($round) {
			4 => $this->roundFallbacks['4'] ?? 'L',
			5 => $this->roundFallbacks['5'] ?? 'M',
			6 => $this->roundFallbacks['6'] ?? 'N',
			default => $this->roundFallbacks['7'] ?? 'O',
		};
	}
}
