<?php

namespace DraftSignal\Algorithm\Tests\Unit;

use DraftSignal\Algorithm\Config\ConfigLoader;
use DraftSignal\Algorithm\Tier\TierResolver;
use PHPUnit\Framework\TestCase;

final class TierResolverTest extends TestCase {
	private TierResolver $resolver;

	protected function setUp(): void {
		$configLoader = new ConfigLoader();
		$tierConfig = $configLoader->loadTierMappings();
		$this->resolver = new TierResolver($tierConfig);
	}

	public function testFirstOverallPickIsTierA(): void {
		$this->assertEquals('A', $this->resolver->resolve(1, 1));
	}

	public function testPicksInRangeResolveCorrectly(): void {
		$this->assertEquals('B', $this->resolver->resolve(2, 1));
		$this->assertEquals('B', $this->resolver->resolve(5, 1));
		$this->assertEquals('C', $this->resolver->resolve(6, 1));
		$this->assertEquals('C', $this->resolver->resolve(10, 1));
		$this->assertEquals('F', $this->resolver->resolve(21, 1));
		$this->assertEquals('F', $this->resolver->resolve(32, 1));
	}

	public function testSecondRoundFallback(): void {
		$this->assertEquals('I', $this->resolver->resolve(55, 2));
		$this->assertEquals('I', $this->resolver->resolve(64, 2));
	}

	public function testThirdRoundSplit(): void {
		$this->assertEquals('J', $this->resolver->resolve(70, 3));
		$this->assertEquals('J', $this->resolver->resolve(100, 3));
		$this->assertEquals('K', $this->resolver->resolve(101, 3));
		$this->assertEquals('K', $this->resolver->resolve(110, 3));
	}

	public function testLateRounds(): void {
		$this->assertEquals('L', $this->resolver->resolve(120, 4));
		$this->assertEquals('M', $this->resolver->resolve(150, 5));
		$this->assertEquals('N', $this->resolver->resolve(180, 6));
		$this->assertEquals('O', $this->resolver->resolve(220, 7));
	}

	public function testUnknownRoundDefaultsToO(): void {
		$this->assertEquals('O', $this->resolver->resolve(300, 8));
	}
}
