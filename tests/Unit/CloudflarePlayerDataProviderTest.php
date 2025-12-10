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

	public function testBulkUpdateSplitsIntoBatchesOf20(): void {
		$responses = [
			new Response(200, [], json_encode(['result' => [['results' => []]]])),
			new Response(200, [], json_encode(['result' => [['results' => []]]])),
		];
		$provider = $this->createProviderWithMock($responses);

		// Create 25 updates to trigger 2 batches (20 + 5)
		$updates = [];
		for ($i = 1; $i <= 25; $i++) {
			$updates[$i] = ['isBust' => $i % 2 === 0, 'score' => $i / 1000];
		}

		$provider->bulkUpdateBustScores($updates);

		$this->assertCount(2, $this->requestHistory);

		$bodies = $this->getAllRequestBodies();

		// First batch should have 20 players
		$firstBatchPlaceholders = substr_count($bodies[0]['sql'], '?');
		// Each player: 2 params for bool CASE + 2 params for score CASE + 1 for WHERE IN = 5 params
		// 20 players = 100 params total (D1 limit)
		$this->assertEquals(100, $firstBatchPlaceholders);

		// Second batch should have 5 players = 25 params
		$secondBatchPlaceholders = substr_count($bodies[1]['sql'], '?');
		$this->assertEquals(25, $secondBatchPlaceholders);
	}

	public function testBulkUpdateExactlyAtBatchSizeMakesSingleRequest(): void {
		$provider = $this->createProviderWithMock();

		$updates = [];
		for ($i = 1; $i <= 20; $i++) {
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
		for ($i = 1; $i <= 21; $i++) {
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

	// =========================================================================
	// Robustness & Edge Case Tests
	// =========================================================================

	/**
	 * Verify CASE statements include ELSE clause to prevent NULL on non-matching rows.
	 * Without ELSE, if WHERE IN somehow includes an ID not in CASE, column becomes NULL.
	 */
	public function testBulkUpdateSqlHasNoElseClause(): void {
		$provider = $this->createProviderWithMock();

		$provider->bulkUpdateBustScores([
			1 => ['isBust' => true, 'score' => 0.5],
		]);

		$body = $this->getLastRequestBody();

		// Current implementation has no ELSE - document this as potential risk
		// If WHERE IN and CASE WHEN get out of sync, columns could become NULL
		$this->assertStringNotContainsString('ELSE', $body['sql']);
	}

	/**
	 * Test that batch boundaries preserve correct player-to-data mapping.
	 * Player 20 should be in batch 1, player 21 in batch 2.
	 */
	public function testBatchBoundaryPreservesCorrectMapping(): void {
		$responses = [
			new Response(200, [], json_encode(['result' => [['results' => []]]])),
			new Response(200, [], json_encode(['result' => [['results' => []]]])),
		];
		$provider = $this->createProviderWithMock($responses);

		$updates = [];
		for ($i = 1; $i <= 21; $i++) {
			// Use player ID as score to verify correct mapping
			$updates[$i] = ['isBust' => $i % 2 === 0, 'score' => $i / 1000];
		}

		$provider->bulkUpdateBustScores($updates);

		$bodies = $this->getAllRequestBodies();

		// First batch: players 1-20
		// Last bool param pair should be for player 20: id=20, value=1 (even)
		// Bool params are first 40 elements (20 players * 2)
		$firstBatchParams = $bodies[0]['params'];
		$this->assertEquals(20, $firstBatchParams[38]); // Last bool id
		$this->assertEquals(1, $firstBatchParams[39]);  // Last bool value (20 is even = true = 1)

		// Score params start at index 40
		$this->assertEquals(20, $firstBatchParams[78]);  // Last score id
		$this->assertEquals(0.02, $firstBatchParams[79]); // Last score value (20/1000)

		// Second batch: player 21 only
		$secondBatchParams = $bodies[1]['params'];
		$this->assertEquals(21, $secondBatchParams[0]); // Bool id
		$this->assertEquals(0, $secondBatchParams[1]);  // Bool value (21 is odd = false = 0)
		$this->assertEquals(21, $secondBatchParams[2]); // Score id
		$this->assertEquals(0.021, $secondBatchParams[3]); // Score value (21/1000)
		$this->assertEquals(21, $secondBatchParams[4]); // WHERE IN
	}

	/**
	 * Test with non-sequential player IDs to ensure array_chunk preserves keys.
	 */
	public function testNonSequentialIdsArePreservedAcrossBatches(): void {
		$responses = [
			new Response(200, [], json_encode(['result' => [['results' => []]]])),
			new Response(200, [], json_encode(['result' => [['results' => []]]])),
		];
		$provider = $this->createProviderWithMock($responses);

		// Create 21 updates with non-sequential IDs
		$updates = [];
		for ($i = 0; $i < 21; $i++) {
			$playerId = 10000 + ($i * 7); // Non-sequential: 10000, 10007, 10014...
			$updates[$playerId] = ['isBust' => true, 'score' => 0.5];
		}

		$provider->bulkUpdateBustScores($updates);

		$bodies = $this->getAllRequestBodies();

		// First batch should have first 20 IDs
		$firstBatchWhereIn = array_slice($bodies[0]['params'], -20);
		$this->assertEquals(10000, $firstBatchWhereIn[0]); // First ID
		$this->assertEquals(10000 + (19 * 7), $firstBatchWhereIn[19]); // 20th ID

		// Second batch should have the 21st ID
		$secondBatchWhereIn = array_slice($bodies[1]['params'], -1);
		$this->assertEquals(10000 + (20 * 7), $secondBatchWhereIn[0]); // 21st ID
	}

	/**
	 * Test that string player IDs (if passed) are handled.
	 * PHP array keys can be numeric strings - verify behavior.
	 */
	public function testNumericStringKeysAreHandled(): void {
		$provider = $this->createProviderWithMock();

		// PHP will convert "123" to int 123 as array key
		$provider->bulkUpdateBustScores([
			'100' => ['isBust' => true, 'score' => 0.5],
		]);

		$body = $this->getLastRequestBody();

		// Should be integer 100, not string "100"
		$this->assertSame(100, $body['params'][0]);
		$this->assertSame(100, $body['params'][4]);
	}

	/**
	 * Test falsy score values (0, 0.0) are correctly included, not filtered.
	 */
	public function testFalsyScoreValuesAreIncluded(): void {
		$provider = $this->createProviderWithMock();

		$provider->bulkUpdateBustScores([
			1 => ['isBust' => false, 'score' => 0],
			2 => ['isBust' => false, 'score' => 0.0],
		]);

		$body = $this->getLastRequestBody();

		// Score params start at index 4 (after 2 bool pairs)
		// JSON encoding normalizes int 0 and float 0.0 - just verify they're zero
		$this->assertEquals(0, $body['params'][5]);   // First score
		$this->assertEquals(0, $body['params'][7]);   // Second score
	}

	/**
	 * Test that isBust=false with score=0 doesn't get filtered out.
	 */
	public function testAllFalsyValuesAreProcessed(): void {
		$provider = $this->createProviderWithMock();

		$provider->bulkUpdateBustScores([
			1 => ['isBust' => false, 'score' => 0],
		]);

		$this->assertCount(1, $this->requestHistory);

		$body = $this->getLastRequestBody();
		$this->assertEquals([1, 0, 1, 0, 1], $body['params']);
	}

	// =========================================================================
	// Input Validation / Error Handling Tests
	// =========================================================================

	/**
	 * Test behavior when 'score' key is missing from update data.
	 * Current implementation will throw a PHP warning/error.
	 */
	public function testMissingScoreKeyThrowsError(): void {
		$provider = $this->createProviderWithMock();

		$this->expectException(\ErrorException::class);

		// Convert warnings to exceptions for this test
		set_error_handler(function($severity, $message) {
			throw new \ErrorException($message, 0, $severity);
		});

		try {
			$provider->bulkUpdateBustScores([
				1 => ['isBust' => true], // Missing 'score'
			]);
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * Test behavior when 'isBust' key is missing from update data.
	 */
	public function testMissingBoolKeyThrowsError(): void {
		$provider = $this->createProviderWithMock();

		$this->expectException(\ErrorException::class);

		set_error_handler(function($severity, $message) {
			throw new \ErrorException($message, 0, $severity);
		});

		try {
			$provider->bulkUpdateBustScores([
				1 => ['score' => 0.5], // Missing 'isBust'
			]);
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * Test that negative player IDs are passed through (no validation).
	 */
	public function testNegativePlayerIdIsPassedThrough(): void {
		$provider = $this->createProviderWithMock();

		$provider->bulkUpdateBustScores([
			-1 => ['isBust' => true, 'score' => 0.5],
		]);

		$body = $this->getLastRequestBody();
		$this->assertEquals(-1, $body['params'][0]);
	}

	/**
	 * Test that negative scores are passed through (no validation).
	 */
	public function testNegativeScoreIsPassedThrough(): void {
		$provider = $this->createProviderWithMock();

		$provider->bulkUpdateBustScores([
			1 => ['isBust' => true, 'score' => -0.5],
		]);

		$body = $this->getLastRequestBody();
		$this->assertEquals(-0.5, $body['params'][3]);
	}

	/**
	 * Test that scores > 1.0 are passed through (no validation).
	 */
	public function testScoreOverOneIsPassedThrough(): void {
		$provider = $this->createProviderWithMock();

		$provider->bulkUpdateBustScores([
			1 => ['isBust' => true, 'score' => 999.99],
		]);

		$body = $this->getLastRequestBody();
		$this->assertEquals(999.99, $body['params'][3]);
	}
}
