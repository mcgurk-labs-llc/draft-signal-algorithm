<?php

namespace DraftSignal\Algorithm\Data;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

final class CloudflarePlayerDataProvider implements PlayerDataProviderInterface {
	private Client $client;
	private string $accountId;
	private string $databaseId;
	private string $apiToken;
	private int $maxRetries;

    private const PLAYER_QUERY = <<<SQL
WITH first_other AS (
    SELECT
        pts.player_id,
        MIN(pts.season_year) AS first_other_year
    FROM player_team_seasons pts
    JOIN players p2 ON p2.id = pts.player_id
    WHERE pts.team_id != p2.draft_team_id
    GROUP BY pts.player_id
),
first_stint_awards AS (
    SELECT
        pa.player_id,
        ao.name AS award_name,
        COUNT(*) AS award_count
    FROM player_awards pa
    JOIN award_options ao ON ao.id = pa.award_option_id
    JOIN players p3 ON p3.id = pa.player_id
    LEFT JOIN first_other fo2 ON fo2.player_id = pa.player_id
    WHERE pa.team_id = p3.draft_team_id
      AND (fo2.first_other_year IS NULL OR pa.year_received < fo2.first_other_year)
    GROUP BY pa.player_id, ao.name
)
SELECT
    p.id,
    p.draft_year,
    p.draft_round,
    p.overall_pick,
    p.round_selection_position,
    p.name AS player_name,
    p.position,
    COALESCE(
        SUM(
            CASE
                WHEN pts.team_id = p.draft_team_id
                  AND (fo.first_other_year IS NULL OR pts.season_year < fo.first_other_year)
                  THEN COALESCE(pts.av, 0)
                ELSE 0
            END
        ),
        0
    ) AS first_stint_av,
    COALESCE(
        SUM(
            CASE
                WHEN pts.team_id = p.draft_team_id
                  AND (fo.first_other_year IS NULL OR pts.season_year < fo.first_other_year)
                  THEN COALESCE(pts.games_played, 0)
                ELSE 0
            END
        ),
        0
    ) AS first_stint_games_played,
    COALESCE(
        SUM(
            CASE
                WHEN pts.team_id = p.draft_team_id
                  AND (fo.first_other_year IS NULL OR pts.season_year < fo.first_other_year)
                  THEN COALESCE(pts.games_started, 0)
                ELSE 0
            END
        ),
        0
    ) AS first_stint_games_started,
    COALESCE(
        SUM(
            CASE
                WHEN pts.team_id = p.draft_team_id
                  AND (fo.first_other_year IS NULL OR pts.season_year < fo.first_other_year)
                  THEN COALESCE(pts.offense_snaps, 0) + COALESCE(pts.defense_snaps, 0)
                ELSE 0
            END
        ),
        0
    ) AS first_stint_reg_snaps,
    COALESCE(
        SUM(
            CASE
                WHEN pts.team_id = p.draft_team_id
                  AND (fo.first_other_year IS NULL OR pts.season_year < fo.first_other_year)
                  THEN COALESCE(pts.st_snaps, 0)
                ELSE 0
            END
        ),
        0
    ) AS first_stint_st_snaps,
    COALESCE(
        AVG(
            CASE
                WHEN pts.team_id = p.draft_team_id
                  AND (fo.first_other_year IS NULL OR pts.season_year < fo.first_other_year)
                  THEN COALESCE(pts.offense_snap_percentage, 0) + COALESCE(pts.defense_snap_percentage, 0)
                ELSE NULL
            END
        ),
        0
    ) AS first_stint_reg_snap_pct,
    COALESCE(
        AVG(
            CASE
                WHEN pts.team_id = p.draft_team_id
                  AND (fo.first_other_year IS NULL OR pts.season_year < fo.first_other_year)
                  THEN COALESCE(pts.st_snap_percentage, 0)
                ELSE NULL
            END
        ),
        0
    ) AS first_stint_st_snap_pct,
    COALESCE(
        COUNT(
            DISTINCT CASE
                WHEN pts.team_id = p.draft_team_id
                  AND (fo.first_other_year IS NULL OR pts.season_year < fo.first_other_year)
                  AND COALESCE(pts.games_played, 0) > 0
                THEN pts.season_year
                ELSE NULL
            END
        ),
        0
    ) AS first_stint_seasons_played,
    COALESCE((SELECT award_count FROM first_stint_awards fsa WHERE fsa.player_id = p.id AND fsa.award_name = 'MVP'), 0) AS first_stint_mvps,
    COALESCE((SELECT award_count FROM first_stint_awards fsa WHERE fsa.player_id = p.id AND fsa.award_name = 'PB'), 0) AS first_stint_pbs,
    COALESCE((SELECT award_count FROM first_stint_awards fsa WHERE fsa.player_id = p.id AND fsa.award_name = 'AP-1'), 0) AS first_stint_ap1s,
    COALESCE((SELECT award_count FROM first_stint_awards fsa WHERE fsa.player_id = p.id AND fsa.award_name = 'AP-2'), 0) AS first_stint_ap2s,
    COALESCE((SELECT award_count FROM first_stint_awards fsa WHERE fsa.player_id = p.id AND fsa.award_name = 'OPOY'), 0) AS first_stint_opoys,
    COALESCE((SELECT award_count FROM first_stint_awards fsa WHERE fsa.player_id = p.id AND fsa.award_name = 'DPOY'), 0) AS first_stint_dpoys,
    CASE WHEN EXISTS (SELECT 1 FROM first_stint_awards fsa WHERE fsa.player_id = p.id AND fsa.award_name = 'OROY') THEN 1 ELSE 0 END AS oroy,
    CASE WHEN EXISTS (SELECT 1 FROM first_stint_awards fsa WHERE fsa.player_id = p.id AND fsa.award_name = 'DROY') THEN 1 ELSE 0 END AS droy
FROM players p
LEFT JOIN player_team_seasons pts ON pts.player_id = p.id
LEFT JOIN first_other fo ON fo.player_id = p.id
SQL;

	private const EXCLUDED_YEARS = [2024, 2025];

	public function __construct(
		?Client $client = null,
		?string $accountId = null,
		?string $databaseId = null,
		?string $apiToken = null,
		int $maxRetries = 1,
	) {
		$this->client = $client ?? new Client();
		$this->accountId = $accountId ?? getenv('CLOUDFLARE_ACCOUNT_ID') ?: '';
		$this->databaseId = $databaseId ?? getenv('CLOUDFLARE_DATABASE_ID') ?: '';
		$this->apiToken = $apiToken ?? getenv('CLOUDFLARE_API_TOKEN') ?: '';
		$this->maxRetries = $maxRetries;

		if (empty($this->accountId) || empty($this->databaseId) || empty($this->apiToken)) {
			throw new RuntimeException('Missing required Cloudflare environment variables');
		}
	}

	public function getPlayers(?int $teamId = null, ?int $year = null): array {
		$conditions = [];
		$params = [];

		if ($teamId !== null) {
			$conditions[] = 'p.draft_team_id = ?';
			$params[] = $teamId;
		}

		if ($year !== null) {
			$conditions[] = 'p.draft_year = ?';
			$params[] = $year;
		} else {
			$excludedYears = implode(',', self::EXCLUDED_YEARS);
			$conditions[] = "p.draft_year NOT IN ({$excludedYears})";
		}

		$whereClause = implode(' AND ', $conditions);
		$sql = self::PLAYER_QUERY . "\nWHERE {$whereClause}\nGROUP BY p.id, p.draft_team_id";

		$results = $this->query($sql, $params);
		return $this->hydrateResults($results);
	}

	public function updateBustScore(int $playerId, bool $isBust, float $bustScore): void {
		$this->query(
			'UPDATE players SET is_bust = ?, bust_score = ? WHERE id = ?',
			[$isBust ? 1 : 0, $bustScore, $playerId]
		);
	}
	
	public function updateStealScore(int $playerId, bool $isSteal, float $stealScore): void {
		$this->query(
			'UPDATE players SET is_steal = ?, steal_score = ? WHERE id = ?',
			[$isSteal ? 1 : 0, $stealScore, $playerId]
		);
	}

	public function bulkUpdateBustScores(array $updates): void {
		$this->bulkUpdate($updates, 'is_bust', 'bust_score', 'isBust');
	}

	public function bulkUpdateStealScores(array $updates): void {
		$this->bulkUpdate($updates, 'is_steal', 'steal_score', 'isSteal');
	}

	private function bulkUpdate(array $updates, string $boolColumn, string $scoreColumn, string $boolKey): void {
		if (empty($updates)) {
			return;
		}

		$batchSize = 20; // D1 limits to 100 bound params; each player uses 5 params
		$batches = array_chunk($updates, $batchSize, true);

		foreach ($batches as $batch) {
			$ids = array_keys($batch);
			$boolCases = [];
			$scoreCases = [];
			$boolParams = [];
			$scoreParams = [];

			foreach ($batch as $playerId => $data) {
				$boolCases[] = "WHEN id = ? THEN ?";
				$boolParams[] = $playerId;
				$boolParams[] = $data[$boolKey] ? 1 : 0;

				$scoreCases[] = "WHEN id = ? THEN ?";
				$scoreParams[] = $playerId;
				$scoreParams[] = $data['score'];
			}

			$idPlaceholders = implode(',', array_fill(0, count($ids), '?'));
			$params = array_merge($boolParams, $scoreParams, $ids);

			$sql = sprintf(
				"UPDATE players SET %s = CASE %s END, %s = CASE %s END WHERE id IN (%s)",
				$boolColumn,
				implode(' ', $boolCases),
				$scoreColumn,
				implode(' ', $scoreCases),
				$idPlaceholders
			);

			$this->query($sql, $params);
		}
	}

	/**
	 * @return PlayerStats[]
	 */
	private function hydrateResults(array $results): array {
		$players = [];
		foreach ($results as $row) {
			$players[] = PlayerStats::fromDatabaseRow($row);
		}
		return $players;
	}

	private function query(string $sql, array $params = []): array {
		$url = sprintf(
			'https://api.cloudflare.com/client/v4/accounts/%s/d1/database/%s/query',
			$this->accountId,
			$this->databaseId
		);

		$attempt = 0;
		$lastException = null;

		while ($attempt <= $this->maxRetries) {
			try {
				$response = $this->client->request('POST', $url, [
					'json' => [
						'sql' => $sql,
						'params' => $params,
					],
					'headers' => [
						'Content-Type' => 'application/json',
						'Authorization' => 'Bearer ' . $this->apiToken,
					],
					'timeout' => 30,
				]);

				$data = json_decode($response->getBody()->getContents(), true);
				return $data['result'][0]['results'] ?? [];
			} catch (ConnectException $e) {
				$lastException = $e;
				$attempt++;
				if ($attempt <= $this->maxRetries) {
					sleep(1);
				}
			} catch (GuzzleException $e) {
				throw new RuntimeException('Database query failed: ' . $e->getMessage(), 0, $e);
			}
		}

		throw new RuntimeException(
			'Database connection failed after ' . ($this->maxRetries + 1) . ' attempts',
			0,
			$lastException
		);
	}
}
