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
		$this->assertStringContainsString('bust_score = CASE', $body['sql']);
		$this->assertStringContainsString('WHERE id IN (?)', $body['sql']);

		// Params: bool CASE (id, value), score CASE (id, value), WHERE IN (id)
		$expectedParams = [100, 1, 100, 0.85, 100];
		$this->assertEquals($expectedParams, $body['params']);
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

		// Verify SQL structure
		$this->assertStringContainsString('WHEN id = ? THEN ?', $body['sql']);
		$this->assertStringContainsString('WHERE id IN (?,?,?)', $body['sql']);

		// Params order: all bool CASE params, then all score CASE params, then WHERE IN ids
		$expectedParams = [
			100, 1, 200, 0, 300, 1,       // bool CASE params
			100, 0.85, 200, 0.25, 300, 0.95, // score CASE params
			100, 200, 300                 // WHERE IN
		];
		$this->assertEquals($expectedParams, $body['params']);
	}

	public function testBulkUpdateBustScoresConvertsBooleansCorrectly(): void {
		$provider = $this->createProviderWithMock();

		$provider->bulkUpdateBustScores([
			1 => ['isBust' => true, 'score' => 1.0],
			2 => ['isBust' => false, 'score' => 0.0],
		]);

		$body = $this->getLastRequestBody();

		// Params: [1, 1, 2, 0, 1, 1.0, 2, 0.0, 1, 2]
		// Check that true becomes 1 and false becomes 0
		$this->assertEquals(1, $body['params'][1]); // player 1: true -> 1
		$this->assertEquals(0, $body['params'][3]); // player 2: false -> 0
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
		$this->assertStringContainsString('steal_score = CASE', $body['sql']);
		$this->assertStringContainsString('WHERE id IN (?)', $body['sql']);

		$expectedParams = [100, 1, 100, 0.90, 100];
		$this->assertEquals($expectedParams, $body['params']);
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
		$this->assertStringContainsString('steal_score = CASE', $body['sql']);

		// Params order: all bool CASE params, then all score CASE params, then WHERE IN ids
		$expectedParams = [
			10, 0, 20, 1,     // bool CASE params
			10, 0.10, 20, 0.75, // score CASE params
			10, 20            // WHERE IN
		];
		$this->assertEquals($expectedParams, $body['params']);
	}

	// =========================================================================
	// Batching Tests
	// =========================================================================

	public function testBulkUpdateSplitsIntoBatchesOf500(): void {
		$responses = [
			new Response(200, [], json_encode(['result' => [['results' => []]]])),
			new Response(200, [], json_encode(['result' => [['results' => []]]])),
		];
		$provider = $this->createProviderWithMock($responses);

		// Create 600 updates to trigger 2 batches (500 + 100)
		$updates = [];
		for ($i = 1; $i <= 600; $i++) {
			$updates[$i] = ['isBust' => $i % 2 === 0, 'score' => $i / 1000];
		}

		$provider->bulkUpdateBustScores($updates);

		$this->assertCount(2, $this->requestHistory);

		$bodies = $this->getAllRequestBodies();

		// First batch should have 500 players
		$firstBatchPlaceholders = substr_count($bodies[0]['sql'], '?');
		// Each player: 2 params for bool CASE + 2 params for score CASE + 1 for WHERE IN = 5 params
		// 500 players = 2500 params total
		$this->assertEquals(2500, $firstBatchPlaceholders);

		// Second batch should have 100 players = 500 params
		$secondBatchPlaceholders = substr_count($bodies[1]['sql'], '?');
		$this->assertEquals(500, $secondBatchPlaceholders);
	}

	public function testBulkUpdateExactlyAtBatchSizeMakesSingleRequest(): void {
		$provider = $this->createProviderWithMock();

		$updates = [];
		for ($i = 1; $i <= 500; $i++) {
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
		for ($i = 1; $i <= 501; $i++) {
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

		// Verify the overall structure
		$expectedPattern = 'UPDATE players SET is_bust = CASE WHEN id = ? THEN ? WHEN id = ? THEN ? END, bust_score = CASE WHEN id = ? THEN ? WHEN id = ? THEN ? END WHERE id IN (?,?)';
		$this->assertEquals($expectedPattern, $sql);
	}

	/**
	 * CRITICAL: This test verifies that SQL placeholders align with parameter order.
	 * The SQL has: bool CASE (all players), then score CASE (all players), then WHERE IN.
	 * Params MUST match this order for correct database updates.
	 */
	public function testBulkUpdateParamsAlignWithSqlPlaceholders(): void {
		$provider = $this->createProviderWithMock();

		// Use distinct values to verify correct alignment
		$provider->bulkUpdateBustScores([
			100 => ['isBust' => true, 'score' => 0.111],
			200 => ['isBust' => false, 'score' => 0.222],
		]);

		$body = $this->getLastRequestBody();

		// SQL structure: is_bust = CASE WHEN id=? THEN ? WHEN id=? THEN ? END,
		//                bust_score = CASE WHEN id=? THEN ? WHEN id=? THEN ? END
		//                WHERE id IN (?,?)
		//
		// Params must be ordered to match SQL placeholders:
		// [100, 1, 200, 0, 100, 0.111, 200, 0.222, 100, 200]
		//  ^bool CASE^    ^score CASE^           ^WHERE^

		$expectedParams = [100, 1, 200, 0, 100, 0.111, 200, 0.222, 100, 200];
		$this->assertEquals($expectedParams, $body['params']);
	}

	public function testBulkUpdatePreservesPlayerIdOrder(): void {
		$provider = $this->createProviderWithMock();

		// Use non-sequential IDs to verify order is preserved
		$provider->bulkUpdateBustScores([
			999 => ['isBust' => true, 'score' => 0.1],
			123 => ['isBust' => false, 'score' => 0.2],
			456 => ['isBust' => true, 'score' => 0.3],
		]);

		$body = $this->getLastRequestBody();

		// The WHERE IN should contain ids in the order they were in the array
		$whereInIds = array_slice($body['params'], -3);
		$this->assertEquals([999, 123, 456], $whereInIds);
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
		$this->assertEquals(0.0, $body['params'][3]); // score value
	}

	public function testBulkUpdateHandlesMaxScores(): void {
		$provider = $this->createProviderWithMock();

		$provider->bulkUpdateBustScores([
			1 => ['isBust' => true, 'score' => 1.0],
		]);

		$body = $this->getLastRequestBody();
		$this->assertEquals(1.0, $body['params'][3]); // score value
	}

	public function testBulkUpdateHandlesFloatScoresWithPrecision(): void {
		$provider = $this->createProviderWithMock();

		$provider->bulkUpdateBustScores([
			1 => ['isBust' => true, 'score' => 0.123456789],
		]);

		$body = $this->getLastRequestBody();
		$this->assertEquals(0.123456789, $body['params'][3]);
	}

	public function testBulkUpdateHandlesLargePlayerIds(): void {
		$provider = $this->createProviderWithMock();

		$provider->bulkUpdateBustScores([
			999999999 => ['isBust' => true, 'score' => 0.5],
		]);

		$body = $this->getLastRequestBody();
		$this->assertEquals(999999999, $body['params'][0]);
		$this->assertEquals(999999999, $body['params'][4]); // WHERE IN
	}
}
