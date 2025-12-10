<?php

namespace DraftSignal\Algorithm\Tests\Unit;

use DraftSignal\Algorithm\Data\CloudflarePlayerDataProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CloudflarePlayerDataProviderTest extends TestCase {
	private array $requestHistory = [];

	private function createProviderWithMock(array $responses = []): CloudflarePlayerDataProvider {
		$this->requestHistory = [];

		if (empty($responses)) {
			$responses = [new Response(200, [], json_encode(['result' => [['results' => []]]]))];
		}

		$mock = new MockHandler($responses);
		$handlerStack = HandlerStack::create($mock);
		$handlerStack->push(Middleware::history($this->requestHistory));

		$client = new Client(['handler' => $handlerStack]);

		return new CloudflarePlayerDataProvider(
			client: $client,
			accountId: 'test-account',
			databaseId: 'test-database',
			apiToken: 'test-token',
			maxRetries: 0
		);
	}

	private function getLastRequestBody(): array {
		if (empty($this->requestHistory)) {
			return [];
		}
		$lastRequest = end($this->requestHistory);
		return json_decode($lastRequest['request']->getBody()->getContents(), true);
	}

	private function getAllRequestBodies(): array {
		$bodies = [];
		foreach ($this->requestHistory as $transaction) {
			$transaction['request']->getBody()->rewind();
			$bodies[] = json_decode($transaction['request']->getBody()->getContents(), true);
		}
		return $bodies;
	}

	// =========================================================================
	// Constructor Tests
	// =========================================================================

	public function testConstructorThrowsWhenMissingAccountId(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Missing required Cloudflare environment variables');

		new CloudflarePlayerDataProvider(
			accountId: '',
			databaseId: 'test-db',
			apiToken: 'test-token'
		);
	}

	public function testConstructorThrowsWhenMissingDatabaseId(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Missing required Cloudflare environment variables');

		new CloudflarePlayerDataProvider(
			accountId: 'test-account',
			databaseId: '',
			apiToken: 'test-token'
		);
	}

	public function testConstructorThrowsWhenMissingApiToken(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Missing required Cloudflare environment variables');

		new CloudflarePlayerDataProvider(
			accountId: 'test-account',
			databaseId: 'test-db',
			apiToken: ''
		);
	}

	// =========================================================================
	// bulkUpdateBustScores Tests
	// =========================================================================

	public function testBulkUpdateBustScoresWithEmptyArrayMakesNoRequests(): void {
		$provider = $this->createProviderWithMock();

		$provider->bulkUpdateBustScores([]);

		$this->assertEmpty($this->requestHistory);
	}

	public function testBulkUpdateBustScoresWithSinglePlayer(): void {
		$provider = $this->createProviderWithMock();

		$provider->bulkUpdateBustScores([
			100 => ['isBust' => true, 'score' => 0.85],
		]);

		$this->assertCount(1, $this->requestHistory);

		$body = $this->getLastRequestBody();
		$this->assertStringContainsString('UPDATE players SET is_bust = CASE', $body['sql']);
		$this->assertStringContainsString('WHEN id = 100 THEN 1', $body['sql']);
		$this->assertStringContainsString('bust_score = CASE', $body['sql']);
		$this->assertStringContainsString('WHERE id IN (100)', $body['sql']);

		// Only score is bound
		$this->assertEquals([0.85], $body['params']);
	}

	public function testBulkUpdateBustScoresWithMultiplePlayers(): void {
		$provider = $this->createProviderWithMock();

		$provider->bulkUpdateBustScores([
			100 => ['isBust' => true, 'score' => 0.85],
			200 => ['isBust' => false, 'score' => 0.25],
			300 => ['isBust' => true, 'score' => 0.95],
		]);

		$this->assertCount(1, $this->requestHistory);

		$body = $this->getLastRequestBody();

		// IDs and bools are inlined
		$this->assertStringContainsString('WHEN id = 100 THEN 1', $body['sql']);
		$this->assertStringContainsString('WHEN id = 200 THEN 0', $body['sql']);
		$this->assertStringContainsString('WHEN id = 300 THEN 1', $body['sql']);
		$this->assertStringContainsString('WHERE id IN (100,200,300)', $body['sql']);

		// Only scores are bound
		$this->assertEquals([0.85, 0.25, 0.95], $body['params']);
	}

	public function testBulkUpdateBustScoresConvertsBooleansCorrectly(): void {
		$provider = $this->createProviderWithMock();

		$provider->bulkUpdateBustScores([
			1 => ['isBust' => true, 'score' => 1.0],
			2 => ['isBust' => false, 'score' => 0.0],
		]);

		$body = $this->getLastRequestBody();

		// Booleans are inlined in SQL
		$this->assertStringContainsString('WHEN id = 1 THEN 1', $body['sql']);
		$this->assertStringContainsString('WHEN id = 2 THEN 0', $body['sql']);
	}

	// =========================================================================
	// bulkUpdateStealScores Tests
	// =========================================================================

	public function testBulkUpdateStealScoresWithEmptyArrayMakesNoRequests(): void {
		$provider = $this->createProviderWithMock();

		$provider->bulkUpdateStealScores([]);

		$this->assertEmpty($this->requestHistory);
	}

	public function testBulkUpdateStealScoresWithSinglePlayer(): void {
		$provider = $this->createProviderWithMock();

		$provider->bulkUpdateStealScores([
			100 => ['isSteal' => true, 'score' => 0.90],
		]);

		$this->assertCount(1, $this->requestHistory);

		$body = $this->getLastRequestBody();
		$this->assertStringContainsString('UPDATE players SET is_steal = CASE', $body['sql']);
		$this->assertStringContainsString('WHEN id = 100 THEN 1', $body['sql']);
		$this->assertStringContainsString('steal_score = CASE', $body['sql']);
		$this->assertStringContainsString('WHERE id IN (100)', $body['sql']);

		// Only score is bound
		$this->assertEquals([0.90], $body['params']);
	}

	public function testBulkUpdateStealScoresWithMultiplePlayers(): void {
		$provider = $this->createProviderWithMock();

		$provider->bulkUpdateStealScores([
			10 => ['isSteal' => false, 'score' => 0.10],
			20 => ['isSteal' => true, 'score' => 0.75],
		]);

		$this->assertCount(1, $this->requestHistory);

		$body = $this->getLastRequestBody();
		$this->assertStringContainsString('is_steal = CASE', $body['sql']);
		$this->assertStringContainsString('WHEN id = 10 THEN 0', $body['sql']);
		$this->assertStringContainsString('WHEN id = 20 THEN 1', $body['sql']);
		$this->assertStringContainsString('WHERE id IN (10,20)', $body['sql']);

		// Only scores are bound
		$this->assertEquals([0.10, 0.75], $body['params']);
	}

	// =========================================================================
	// Batching Tests
	// =========================================================================

	public function testBulkUpdateSplitsIntoBatchesOf100(): void {
		$responses = [
			new Response(200, [], json_encode(['result' => [['results' => []]]])),
			new Response(200, [], json_encode(['result' => [['results' => []]]])),
		];
		$provider = $this->createProviderWithMock($responses);

		// Create 120 updates to trigger 2 batches (100 + 20)
		$updates = [];
		for ($i = 1; $i <= 120; $i++) {
			$updates[$i] = ['isBust' => $i % 2 === 0, 'score' => $i / 1000];
		}

		$provider->bulkUpdateBustScores($updates);

		$this->assertCount(2, $this->requestHistory);

		$bodies = $this->getAllRequestBodies();

		// First batch: 100 players, only scores bound
		$this->assertCount(100, $bodies[0]['params']);

		// Second batch: 20 players
		$this->assertCount(20, $bodies[1]['params']);
	}

	public function testBulkUpdateExactlyAtBatchSizeMakesSingleRequest(): void {
		$provider = $this->createProviderWithMock();

		$updates = [];
		for ($i = 1; $i <= 100; $i++) {
			$updates[$i] = ['isBust' => true, 'score' => 0.5];
		}

		$provider->bulkUpdateBustScores($updates);

		$this->assertCount(1, $this->requestHistory);
	}

	public function testBulkUpdateJustOverBatchSizeMakesTwoRequests(): void {
		$responses = [
			new Response(200, [], json_encode(['result' => [['results' => []]]])),
			new Response(200, [], json_encode(['result' => [['results' => []]]])),
		];
		$provider = $this->createProviderWithMock($responses);

		$updates = [];
		for ($i = 1; $i <= 101; $i++) {
			$updates[$i] = ['isBust' => true, 'score' => 0.5];
		}

		$provider->bulkUpdateBustScores($updates);

		$this->assertCount(2, $this->requestHistory);
	}

	// =========================================================================
	// SQL Structure Tests
	// =========================================================================

	public function testBulkUpdateGeneratesValidSqlStructure(): void {
		$provider = $this->createProviderWithMock();

		$provider->bulkUpdateBustScores([
			1 => ['isBust' => true, 'score' => 0.5],
			2 => ['isBust' => false, 'score' => 0.3],
		]);

		$body = $this->getLastRequestBody();
		$sql = $body['sql'];

		// IDs and bools inlined, only scores bound
		$expected = 'UPDATE players SET is_bust = CASE WHEN id = 1 THEN 1 WHEN id = 2 THEN 0 END, bust_score = CASE WHEN id = 1 THEN ? WHEN id = 2 THEN ? END WHERE id IN (1,2)';
		$this->assertEquals($expected, $sql);
	}

	public function testBulkUpdateParamsAlignWithSqlPlaceholders(): void {
		$provider = $this->createProviderWithMock();

		$provider->bulkUpdateBustScores([
			100 => ['isBust' => true, 'score' => 0.111],
			200 => ['isBust' => false, 'score' => 0.222],
		]);

		$body = $this->getLastRequestBody();

		// Only scores are bound, in iteration order
		$this->assertEquals([0.111, 0.222], $body['params']);
	}

	public function testBulkUpdatePreservesPlayerIdOrder(): void {
		$provider = $this->createProviderWithMock();

		$provider->bulkUpdateBustScores([
			999 => ['isBust' => true, 'score' => 0.1],
			123 => ['isBust' => false, 'score' => 0.2],
			456 => ['isBust' => true, 'score' => 0.3],
		]);

		$body = $this->getLastRequestBody();

		// IDs inlined in SQL in order
		$this->assertStringContainsString('WHERE id IN (999,123,456)', $body['sql']);
		// Scores in same order
		$this->assertEquals([0.1, 0.2, 0.3], $body['params']);
	}

	// =========================================================================
	// Edge Cases
	// =========================================================================

	public function testBulkUpdateHandlesZeroScores(): void {
		$provider = $this->createProviderWithMock();

		$provider->bulkUpdateBustScores([
			1 => ['isBust' => false, 'score' => 0.0],
		]);

		$body = $this->getLastRequestBody();
		$this->assertEquals([0.0], $body['params']);
	}

	public function testBulkUpdateHandlesMaxScores(): void {
		$provider = $this->createProviderWithMock();

		$provider->bulkUpdateBustScores([
			1 => ['isBust' => true, 'score' => 1.0],
		]);

		$body = $this->getLastRequestBody();
		$this->assertEquals([1.0], $body['params']);
	}

	public function testBulkUpdateHandlesFloatScoresWithPrecision(): void {
		$provider = $this->createProviderWithMock();

		$provider->bulkUpdateBustScores([
			1 => ['isBust' => true, 'score' => 0.123456789],
		]);

		$body = $this->getLastRequestBody();
		$this->assertEquals([0.123456789], $body['params']);
	}

	public function testBulkUpdateHandlesLargePlayerIds(): void {
		$provider = $this->createProviderWithMock();

		$provider->bulkUpdateBustScores([
			999999999 => ['isBust' => true, 'score' => 0.5],
		]);

		$body = $this->getLastRequestBody();
		$this->assertStringContainsString('WHEN id = 999999999 THEN', $body['sql']);
		$this->assertStringContainsString('WHERE id IN (999999999)', $body['sql']);
		$this->assertEquals([0.5], $body['params']);
	}

	// =========================================================================
	// Robustness & Edge Case Tests
	// =========================================================================

	public function testBulkUpdateSqlHasNoElseClause(): void {
		$provider = $this->createProviderWithMock();

		$provider->bulkUpdateBustScores([
			1 => ['isBust' => true, 'score' => 0.5],
		]);

		$body = $this->getLastRequestBody();
		$this->assertStringNotContainsString('ELSE', $body['sql']);
	}

	public function testBatchBoundaryPreservesCorrectMapping(): void {
		$responses = [
			new Response(200, [], json_encode(['result' => [['results' => []]]])),
			new Response(200, [], json_encode(['result' => [['results' => []]]])),
		];
		$provider = $this->createProviderWithMock($responses);

		$updates = [];
		for ($i = 1; $i <= 101; $i++) {
			$updates[$i] = ['isBust' => $i % 2 === 0, 'score' => $i / 1000];
		}

		$provider->bulkUpdateBustScores($updates);

		$bodies = $this->getAllRequestBodies();

		// First batch: players 1-100
		$this->assertCount(100, $bodies[0]['params']);
		$this->assertEquals(0.001, $bodies[0]['params'][0]);  // First score
		$this->assertEquals(0.1, $bodies[0]['params'][99]);   // Last score (100/1000)
		$this->assertStringContainsString('WHEN id = 100 THEN 1', $bodies[0]['sql']);

		// Second batch: player 101 only
		$this->assertEquals([0.101], $bodies[1]['params']);
		$this->assertStringContainsString('WHEN id = 101 THEN 0', $bodies[1]['sql']);
	}

	public function testNonSequentialIdsArePreservedAcrossBatches(): void {
		$responses = [
			new Response(200, [], json_encode(['result' => [['results' => []]]])),
			new Response(200, [], json_encode(['result' => [['results' => []]]])),
		];
		$provider = $this->createProviderWithMock($responses);

		$updates = [];
		for ($i = 0; $i < 101; $i++) {
			$playerId = 10000 + ($i * 7);
			$updates[$playerId] = ['isBust' => true, 'score' => 0.5];
		}

		$provider->bulkUpdateBustScores($updates);

		$bodies = $this->getAllRequestBodies();

		// First batch: first 100 IDs inlined
		$this->assertStringContainsString('WHEN id = 10000 THEN', $bodies[0]['sql']);
		$this->assertStringContainsString('WHERE id IN (10000,', $bodies[0]['sql']);

		// Second batch: 101st ID
		$expectedId = 10000 + (100 * 7);
		$this->assertStringContainsString("WHEN id = {$expectedId} THEN", $bodies[1]['sql']);
	}

	public function testNumericStringKeysAreHandled(): void {
		$provider = $this->createProviderWithMock();

		$provider->bulkUpdateBustScores([
			'100' => ['isBust' => true, 'score' => 0.5],
		]);

		$body = $this->getLastRequestBody();

		// ID is inlined in SQL as integer
		$this->assertStringContainsString('WHEN id = 100 THEN', $body['sql']);
		$this->assertStringContainsString('WHERE id IN (100)', $body['sql']);
	}

	public function testFalsyScoreValuesAreIncluded(): void {
		$provider = $this->createProviderWithMock();

		$provider->bulkUpdateBustScores([
			1 => ['isBust' => false, 'score' => 0],
			2 => ['isBust' => false, 'score' => 0.0],
		]);

		$body = $this->getLastRequestBody();
		$this->assertEquals([0, 0.0], $body['params']);
	}

	public function testAllFalsyValuesAreProcessed(): void {
		$provider = $this->createProviderWithMock();

		$provider->bulkUpdateBustScores([
			1 => ['isBust' => false, 'score' => 0],
		]);

		$this->assertCount(1, $this->requestHistory);

		$body = $this->getLastRequestBody();
		$this->assertStringContainsString('WHEN id = 1 THEN 0', $body['sql']);
		$this->assertEquals([0], $body['params']);
	}

	// =========================================================================
	// Input Validation / Error Handling Tests
	// =========================================================================

	public function testMissingScoreKeyThrowsError(): void {
		$provider = $this->createProviderWithMock();

		$this->expectException(\ErrorException::class);

		set_error_handler(function($severity, $message) {
			throw new \ErrorException($message, 0, $severity);
		});

		try {
			$provider->bulkUpdateBustScores([
				1 => ['isBust' => true],
			]);
		} finally {
			restore_error_handler();
		}
	}

	public function testMissingBoolKeyThrowsError(): void {
		$provider = $this->createProviderWithMock();

		$this->expectException(\ErrorException::class);

		set_error_handler(function($severity, $message) {
			throw new \ErrorException($message, 0, $severity);
		});

		try {
			$provider->bulkUpdateBustScores([
				1 => ['score' => 0.5],
			]);
		} finally {
			restore_error_handler();
		}
	}

	public function testNegativePlayerIdIsPassedThrough(): void {
		$provider = $this->createProviderWithMock();

		$provider->bulkUpdateBustScores([
			-1 => ['isBust' => true, 'score' => 0.5],
		]);

		$body = $this->getLastRequestBody();
		$this->assertStringContainsString('WHEN id = -1 THEN', $body['sql']);
	}

	public function testNegativeScoreIsPassedThrough(): void {
		$provider = $this->createProviderWithMock();

		$provider->bulkUpdateBustScores([
			1 => ['isBust' => true, 'score' => -0.5],
		]);

		$body = $this->getLastRequestBody();
		$this->assertEquals([-0.5], $body['params']);
	}

	public function testScoreOverOneIsPassedThrough(): void {
		$provider = $this->createProviderWithMock();

		$provider->bulkUpdateBustScores([
			1 => ['isBust' => true, 'score' => 999.99],
		]);

		$body = $this->getLastRequestBody();
		$this->assertEquals([999.99], $body['params']);
	}
}
