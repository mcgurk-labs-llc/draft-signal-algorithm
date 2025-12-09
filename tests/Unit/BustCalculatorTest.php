<?php

namespace DraftSignal\Algorithm\Tests\Unit;

use DraftSignal\Algorithm\Calculator\BustCalculator;
use DraftSignal\Algorithm\Config\ConfigLoader;
use DraftSignal\Algorithm\Data\PlayerStats;
use DraftSignal\Algorithm\Tier\TierResolver;
use PHPUnit\Framework\TestCase;

final class BustCalculatorTest extends TestCase {
	private BustCalculator $calculator;
	private array $fixtureData;

	protected function setUp(): void {
		$configLoader = new ConfigLoader();
		$bustConfig = $configLoader->loadBustThresholds();
		$tierConfig = $configLoader->loadTierMappings();

		$tierResolver = new TierResolver($tierConfig);
		$this->calculator = new BustCalculator($tierResolver, $bustConfig);

		$fixturesPath = dirname(__DIR__) . '/fixtures/known-players.json';
		$this->fixtureData = json_decode(file_get_contents($fixturesPath), true);
	}

	public function testZeroGamesIsAutoBust(): void {
		$player = new PlayerStats(
			id: 999,
			name: 'Zero Games Player',
			draftYear: 2020,
			draftRound: 1,
			overallPick: 10,
			firstStintAv: 0,
			firstStintGamesPlayed: 0,
			firstStintGamesStarted: 0,
			firstStintRegSnaps: 0,
			firstStintStSnaps: 0,
			firstStintRegSnapPct: 0.0,
			firstStintStSnapPct: 0.0,
			firstStintSeasonsPlayed: 0,
			position: 'QB',
		);

		$result = $this->calculator->calculate($player);

		$this->assertTrue($result->isBust);
		$this->assertEquals(1.0, $result->bustScore);
	}

	public function testKnownPlayersMatchExpectedBustStatus(): void {
		$failures = [];

		foreach ($this->fixtureData['players'] as $playerData) {
			$player = PlayerStats::fromArray($playerData);
			$result = $this->calculator->calculate($player);

			$expectedIsBust = $playerData['expectedIsBust'];

			if ($result->isBust !== $expectedIsBust) {
				$failures[] = sprintf(
					"%s: expected %s, got %s (score: %.4f, tier: %s)",
					$playerData['name'],
					$expectedIsBust ? 'BUST' : 'NOT BUST',
					$result->isBust ? 'BUST' : 'NOT BUST',
					$result->bustScore,
					$result->tier
				);
			}
		}

		if (!empty($failures)) {
			$this->fail("Bust status mismatches:\n" . implode("\n", $failures));
		}

		$this->assertTrue(true);
	}

	public function testBustScoreIsWithinValidRange(): void {
		foreach ($this->fixtureData['players'] as $playerData) {
			$player = PlayerStats::fromArray($playerData);
			$result = $this->calculator->calculate($player);

			$this->assertGreaterThanOrEqual(0.0, $result->bustScore);
			$this->assertLessThanOrEqual(1.0, $result->bustScore);
		}
	}

	public function testTierAssignmentIsCorrect(): void {
		$testCases = [
			['pick' => 1, 'round' => 1, 'expectedTier' => 'A'],
			['pick' => 3, 'round' => 1, 'expectedTier' => 'B'],
			['pick' => 10, 'round' => 1, 'expectedTier' => 'C'],
			['pick' => 15, 'round' => 1, 'expectedTier' => 'D'],
			['pick' => 20, 'round' => 1, 'expectedTier' => 'E'],
			['pick' => 32, 'round' => 1, 'expectedTier' => 'F'],
			['pick' => 40, 'round' => 2, 'expectedTier' => 'G'],
			['pick' => 50, 'round' => 2, 'expectedTier' => 'H'],
			['pick' => 60, 'round' => 2, 'expectedTier' => 'I'],
			['pick' => 80, 'round' => 3, 'expectedTier' => 'J'],
			['pick' => 105, 'round' => 3, 'expectedTier' => 'K'],
			['pick' => 120, 'round' => 4, 'expectedTier' => 'L'],
			['pick' => 150, 'round' => 5, 'expectedTier' => 'M'],
			['pick' => 180, 'round' => 6, 'expectedTier' => 'N'],
			['pick' => 220, 'round' => 7, 'expectedTier' => 'O'],
		];

		foreach ($testCases as $case) {
			$player = new PlayerStats(
				id: 1,
				name: 'Test Player',
				draftYear: 2020,
				draftRound: $case['round'],
				overallPick: $case['pick'],
				firstStintAv: 10,
				firstStintGamesPlayed: 16,
				firstStintGamesStarted: 8,
				firstStintRegSnaps: 500,
				firstStintStSnaps: 100,
				firstStintRegSnapPct: 50.0,
				firstStintStSnapPct: 10.0,
				firstStintSeasonsPlayed: 1,
				position: 'WR',
			);

			$result = $this->calculator->calculate($player);

			$this->assertEquals(
				$case['expectedTier'],
				$result->tier,
				sprintf('Pick %d round %d should be tier %s', $case['pick'], $case['round'], $case['expectedTier'])
			);
		}
	}

	public function testHighPerformerIsNotBust(): void {
		$player = new PlayerStats(
			id: 100,
			name: 'Elite Player',
			draftYear: 2018,
			draftRound: 1,
			overallPick: 1,
			firstStintAv: 60,
			firstStintGamesPlayed: 70,
			firstStintGamesStarted: 68,
			firstStintRegSnaps: 3000,
			firstStintStSnaps: 50,
			firstStintRegSnapPct: 90.0,
			firstStintStSnapPct: 2.0,
			firstStintSeasonsPlayed: 5,
			position: 'QB',
		);

		$result = $this->calculator->calculate($player);

		$this->assertFalse($result->isBust);
		$this->assertLessThan(0.3, $result->bustScore);
	}
}
