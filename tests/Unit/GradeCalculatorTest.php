<?php

namespace DraftSignal\Algorithm\Tests\Unit;

use DraftSignal\Algorithm\Calculator\Implementations\GradeCalculator;
use DraftSignal\Algorithm\Config\ConfigLoader;
use DraftSignal\Algorithm\Data\PlayerStats;
use DraftSignal\Algorithm\Tier\TierResolver;
use PHPUnit\Framework\TestCase;

final class GradeCalculatorTest extends TestCase {
	private GradeCalculator $calculator;
	private array $fixtureData;

	protected function setUp(): void {
		$configLoader = new ConfigLoader();
		$config = $configLoader->loadBustThresholds();
		$tierConfig = $configLoader->loadTierMappings();

		$tierResolver = new TierResolver($tierConfig);
		$this->calculator = new GradeCalculator($tierResolver, $config);

		$fixturesPath = dirname(__DIR__) . '/fixtures/known-players.json';
		$this->fixtureData = json_decode(file_get_contents($fixturesPath), true);
	}

	public function testZeroGamesIsZeroGrade(): void {
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

		$this->assertEquals(0.0, $result->score);
	}

	public function testElitePlayerGetsHighGrade(): void {
		// Nick Bosa-type player: Pick #2, elite production
		$player = new PlayerStats(
			id: 999,
			name: 'Elite Second Pick',
			draftYear: 2019,
			draftRound: 1,
			overallPick: 2,
			firstStintAv: 100,
			firstStintGamesPlayed: 70,
			firstStintGamesStarted: 70,
			firstStintRegSnaps: 4500,
			firstStintStSnaps: 50,
			firstStintRegSnapPct: 85.0,
			firstStintStSnapPct: 5.0,
			firstStintSeasonsPlayed: 5,
			position: 'EDGE',
			firstStintMvps: 0,
			firstStintPbs: 4,
			firstStintAp1s: 3,
			firstStintAp2s: 1,
			firstStintOpoys: 0,
			firstStintDpoys: 1,
			oroy: true,
			droy: false,
		);

		$result = $this->calculator->calculate($player);

		// Should get a high grade (A range)
		$this->assertGreaterThan(0.8, $result->score);
		$this->assertEquals('B', $result->tier);
	}

	public function testMeetsExpectationsGetsReasonableGrade(): void {
		// Player who meets expectations but doesn't exceed
		$player = new PlayerStats(
			id: 997,
			name: 'Meets Expectations',
			draftYear: 2018,
			draftRound: 3,
			overallPick: 90,
			firstStintAv: 14, // Expected for tier K is 12, so 1.17x
			firstStintGamesPlayed: 40,
			firstStintGamesStarted: 25,
			firstStintRegSnaps: 800,
			firstStintStSnaps: 500,
			firstStintRegSnapPct: 30.0,
			firstStintStSnapPct: 35.0,
			firstStintSeasonsPlayed: 3,
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

		// Should be in the C/B- range (0.4-0.7)
		$this->assertGreaterThan(0.3, $result->score);
		$this->assertLessThan(0.8, $result->score);
	}

	public function testUDFAContributorGetsGraded(): void {
		// Jordan Mason-type: UDFA who becomes solid contributor
		$player = new PlayerStats(
			id: 996,
			name: 'UDFA Contributor',
			draftYear: 2022,
			draftRound: null,
			overallPick: null,
			firstStintAv: 8, // Expected for UDFA is 2, so 4x
			firstStintGamesPlayed: 30,
			firstStintGamesStarted: 16,
			firstStintRegSnaps: 800,
			firstStintStSnaps: 100,
			firstStintRegSnapPct: 35.0,
			firstStintStSnapPct: 20.0,
			firstStintSeasonsPlayed: 2,
			position: 'RB',
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

		// Should get a good grade for exceeding UDFA expectations
		$this->assertGreaterThan(0.5, $result->score);
		$this->assertEquals('UDFA', $result->tier);
	}

	public function testShortCareerLowersGrade(): void {
		// Trey Sermon-type: barely played
		$player = new PlayerStats(
			id: 995,
			name: 'Short Career',
			draftYear: 2021,
			draftRound: 3,
			overallPick: 88,
			firstStintAv: 2, // Expected for tier K is 12
			firstStintGamesPlayed: 8,
			firstStintGamesStarted: 0,
			firstStintRegSnaps: 100,
			firstStintStSnaps: 50,
			firstStintRegSnapPct: 15.0,
			firstStintStSnapPct: 20.0,
			firstStintSeasonsPlayed: 1,
			position: 'RB',
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

		// Should get a low grade (F range)
		$this->assertLessThan(0.3, $result->score);
	}
}
