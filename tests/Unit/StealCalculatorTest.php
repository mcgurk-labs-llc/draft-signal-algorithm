<?php

namespace DraftSignal\Algorithm\Tests\Unit;

use DraftSignal\Algorithm\Calculator\Implementations\StealCalculator;
use DraftSignal\Algorithm\Config\ConfigLoader;
use DraftSignal\Algorithm\Data\PlayerStats;
use DraftSignal\Algorithm\Tier\TierResolver;
use PHPUnit\Framework\TestCase;

final class StealCalculatorTest extends TestCase {
	private StealCalculator $calculator;
	private array $fixtureData;

	protected function setUp(): void {
		$configLoader = new ConfigLoader();
		$config = $configLoader->loadBustThresholds();
		$tierConfig = $configLoader->loadTierMappings();

		$tierResolver = new TierResolver($tierConfig);
		$this->calculator = new StealCalculator($tierResolver, $config);

		$fixturesPath = dirname(__DIR__) . '/fixtures/known-players.json';
		$this->fixtureData = json_decode(file_get_contents($fixturesPath), true);
	}

	public function testPickOneIsNeverSteal(): void {
		$player = new PlayerStats(
			id: 999,
			name: 'First Overall Pick',
			draftYear: 2020,
			draftRound: 1,
			overallPick: 1,
			firstStintAv: 100,
			firstStintGamesPlayed: 80,
			firstStintGamesStarted: 80,
			firstStintRegSnaps: 5000,
			firstStintStSnaps: 0,
			firstStintRegSnapPct: 95.0,
			firstStintStSnapPct: 0.0,
			firstStintSeasonsPlayed: 5,
			position: 'QB',
			firstStintMvps: 2,
			firstStintPbs: 5,
			firstStintAp1s: 3,
			firstStintAp2s: 0,
			firstStintOpoys: 1,
			firstStintDpoys: 0,
			oroy: false,
			droy: false,
		);

		$result = $this->calculator->calculate($player);

		$this->assertFalse($result->data['isSteal']);
		$this->assertEquals(0.0, $result->score);
	}

	public function testZeroGamesIsNotSteal(): void {
		$player = new PlayerStats(
			id: 998,
			name: 'Zero Games Player',
			draftYear: 2020,
			draftRound: 7,
			overallPick: 220,
			firstStintAv: 0,
			firstStintGamesPlayed: 0,
			firstStintGamesStarted: 0,
			firstStintRegSnaps: 0,
			firstStintStSnaps: 0,
			firstStintRegSnapPct: 0.0,
			firstStintStSnapPct: 0.0,
			firstStintSeasonsPlayed: 0,
			position: 'WR',
			firstStintMvps: 0,
			firstStintPbs: 0,
			firstStintAp1s: 0,
			firstStintAp2s: 0,
			firstStintOpoys: 0,
			firstStintDpoys: 0,
			oroy: false,
			droy: false,
		);

		$result = $this->calculator->calculate($player);

		$this->assertFalse($result->data['isSteal']);
		$this->assertEquals(0.0, $result->score);
	}

	public function testAutoStealWithTwoAp1sInRound4Plus(): void {
		$player = new PlayerStats(
			id: 997,
			name: 'Late Round All-Pro',
			draftYear: 2018,
			draftRound: 5,
			overallPick: 150,
			firstStintAv: 30,
			firstStintGamesPlayed: 60,
			firstStintGamesStarted: 55,
			firstStintRegSnaps: 3000,
			firstStintStSnaps: 100,
			firstStintRegSnapPct: 75.0,
			firstStintStSnapPct: 5.0,
			firstStintSeasonsPlayed: 4,
			position: 'LB',
			firstStintMvps: 0,
			firstStintPbs: 2,
			firstStintAp1s: 2,
			firstStintAp2s: 1,
			firstStintOpoys: 0,
			firstStintDpoys: 0,
			oroy: false,
			droy: false,
		);

		$result = $this->calculator->calculate($player);

		$this->assertTrue($result->data['isSteal']);
		$this->assertEquals(1.0, $result->score);
		$this->assertTrue($result->data['autoSteal'] ?? false);
	}

	public function testAutoStealWithFourPlusProBowlsInRound4Plus(): void {
		$player = new PlayerStats(
			id: 996,
			name: 'Late Round Pro Bowler',
			draftYear: 2015,
			draftRound: 6,
			overallPick: 190,
			firstStintAv: 10,
			firstStintGamesPlayed: 100,
			firstStintGamesStarted: 5,
			firstStintRegSnaps: 500,
			firstStintStSnaps: 2000,
			firstStintRegSnapPct: 10.0,
			firstStintStSnapPct: 50.0,
			firstStintSeasonsPlayed: 8,
			position: 'WR',
			firstStintMvps: 0,
			firstStintPbs: 5,
			firstStintAp1s: 0,
			firstStintAp2s: 1,
			firstStintOpoys: 0,
			firstStintDpoys: 0,
			oroy: false,
			droy: false,
		);

		$result = $this->calculator->calculate($player);

		$this->assertTrue($result->data['isSteal']);
		$this->assertEquals(1.0, $result->score);
		$this->assertTrue($result->data['autoSteal'] ?? false);
	}

	public function testNoAutoStealInEarlyRounds(): void {
		$player = new PlayerStats(
			id: 995,
			name: 'Early Round All-Pro',
			draftYear: 2018,
			draftRound: 2,
			overallPick: 40,
			firstStintAv: 50,
			firstStintGamesPlayed: 60,
			firstStintGamesStarted: 58,
			firstStintRegSnaps: 3500,
			firstStintStSnaps: 50,
			firstStintRegSnapPct: 85.0,
			firstStintStSnapPct: 2.0,
			firstStintSeasonsPlayed: 4,
			position: 'WR',
			firstStintMvps: 0,
			firstStintPbs: 3,
			firstStintAp1s: 2,
			firstStintAp2s: 0,
			firstStintOpoys: 0,
			firstStintDpoys: 0,
			oroy: false,
			droy: false,
		);

		$result = $this->calculator->calculate($player);

		$this->assertArrayNotHasKey('autoSteal', $result->data);
	}

	public function testKnownPlayersMatchExpectedStealStatus(): void {
		$failures = [];

		foreach ($this->fixtureData['players'] as $playerData) {
			$player = PlayerStats::fromArray($playerData);
			$result = $this->calculator->calculate($player);

			$expectedIsSteal = $playerData['expectedIsSteal'];

			if ($result->data['isSteal'] !== $expectedIsSteal) {
				$failures[] = sprintf(
					"%s: expected %s, got %s (score: %.4f, tier: %s, reason: %s)",
					$playerData['name'],
					$expectedIsSteal ? 'STEAL' : 'NOT STEAL',
					$result->data['isSteal'] ? 'STEAL' : 'NOT STEAL',
					$result->score,
					$result->tier,
					$playerData['_reason'] ?? 'N/A'
				);
			}
		}

		if (!empty($failures)) {
			$this->fail("Steal status mismatches:\n" . implode("\n", $failures));
		}

		$this->assertTrue(true);
	}

	public function testStealScoreIsWithinValidRange(): void {
		foreach ($this->fixtureData['players'] as $playerData) {
			$player = PlayerStats::fromArray($playerData);
			$result = $this->calculator->calculate($player);

			$this->assertGreaterThanOrEqual(0.0, $result->score, "Score for {$playerData['name']} below 0");
			$this->assertLessThanOrEqual(1.0, $result->score, "Score for {$playerData['name']} above 1");
		}
	}

	public function testUdfaPlayersGetUdfaTier(): void {
		$player = new PlayerStats(
			id: 994,
			name: 'UDFA Player',
			draftYear: 2020,
			draftRound: null,
			overallPick: null,
			firstStintAv: 10,
			firstStintGamesPlayed: 32,
			firstStintGamesStarted: 16,
			firstStintRegSnaps: 1000,
			firstStintStSnaps: 200,
			firstStintRegSnapPct: 50.0,
			firstStintStSnapPct: 15.0,
			firstStintSeasonsPlayed: 2,
			position: 'LB',
			firstStintMvps: 0,
			firstStintPbs: 0,
			firstStintAp1s: 0,
			firstStintAp2s: 0,
			firstStintOpoys: 0,
			firstStintDpoys: 0,
			oroy: false,
			droy: false,
		);

		$result = $this->calculator->calculate($player);

		$this->assertEquals('UDFA', $result->tier);
	}

	public function testLongevityFactorPenalizesShortStints(): void {
		$shortStintPlayer = new PlayerStats(
			id: 993,
			name: 'Short Stint Star',
			draftYear: 2020,
			draftRound: 5,
			overallPick: 150,
			firstStintAv: 20,
			firstStintGamesPlayed: 16,
			firstStintGamesStarted: 16,
			firstStintRegSnaps: 1000,
			firstStintStSnaps: 50,
			firstStintRegSnapPct: 90.0,
			firstStintStSnapPct: 5.0,
			firstStintSeasonsPlayed: 1,
			position: 'LB',
			firstStintMvps: 0,
			firstStintPbs: 0,
			firstStintAp1s: 0,
			firstStintAp2s: 0,
			firstStintOpoys: 0,
			firstStintDpoys: 0,
			oroy: false,
			droy: false,
		);

		$longStintPlayer = new PlayerStats(
			id: 992,
			name: 'Long Stint Star',
			draftYear: 2020,
			draftRound: 5,
			overallPick: 150,
			firstStintAv: 20,
			firstStintGamesPlayed: 64,
			firstStintGamesStarted: 64,
			firstStintRegSnaps: 4000,
			firstStintStSnaps: 200,
			firstStintRegSnapPct: 90.0,
			firstStintStSnapPct: 5.0,
			firstStintSeasonsPlayed: 4,
			position: 'LB',
			firstStintMvps: 0,
			firstStintPbs: 0,
			firstStintAp1s: 0,
			firstStintAp2s: 0,
			firstStintOpoys: 0,
			firstStintDpoys: 0,
			oroy: false,
			droy: false,
		);

		$shortResult = $this->calculator->calculate($shortStintPlayer);
		$longResult = $this->calculator->calculate($longStintPlayer);

		$this->assertLessThan(
			$longResult->score,
			$shortResult->score,
			'Short stint player should score lower than long stint player with same AV'
		);
	}

	public function testStarterFactorRewardsHighStartRate(): void {
		$starterPlayer = new PlayerStats(
			id: 991,
			name: 'High Start Rate',
			draftYear: 2020,
			draftRound: 5,
			overallPick: 150,
			firstStintAv: 15,
			firstStintGamesPlayed: 48,
			firstStintGamesStarted: 45,
			firstStintRegSnaps: 2500,
			firstStintStSnaps: 100,
			firstStintRegSnapPct: 75.0,
			firstStintStSnapPct: 5.0,
			firstStintSeasonsPlayed: 3,
			position: 'LB',
			firstStintMvps: 0,
			firstStintPbs: 0,
			firstStintAp1s: 0,
			firstStintAp2s: 0,
			firstStintOpoys: 0,
			firstStintDpoys: 0,
			oroy: false,
			droy: false,
		);

		$backupPlayer = new PlayerStats(
			id: 990,
			name: 'Low Start Rate',
			draftYear: 2020,
			draftRound: 5,
			overallPick: 150,
			firstStintAv: 15,
			firstStintGamesPlayed: 48,
			firstStintGamesStarted: 10,
			firstStintRegSnaps: 2500,
			firstStintStSnaps: 100,
			firstStintRegSnapPct: 75.0,
			firstStintStSnapPct: 5.0,
			firstStintSeasonsPlayed: 3,
			position: 'LB',
			firstStintMvps: 0,
			firstStintPbs: 0,
			firstStintAp1s: 0,
			firstStintAp2s: 0,
			firstStintOpoys: 0,
			firstStintDpoys: 0,
			oroy: false,
			droy: false,
		);

		$starterResult = $this->calculator->calculate($starterPlayer);
		$backupResult = $this->calculator->calculate($backupPlayer);

		$this->assertGreaterThan(
			$backupResult->score,
			$starterResult->score,
			'High start rate player should score higher than low start rate player'
		);
	}
}
