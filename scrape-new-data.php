<?php

declare(strict_types=1);

// Annual scraper for Draft Signal. Run after the NFL season completes and PFR updates.
// Usage: php scrape-new-data.php [--year=2025] [--reset]

require_once __DIR__ . '/vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use Symfony\Component\CssSelector\CssSelectorConverter;
use Symfony\Component\DomCrawler\Crawler;

function scrapeLog(string $message): void
{
	echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
}

function getWebpage(string $url): Crawler
{
	$apiKey = getenv('SCRAPINGBEE_API_KEY');
	if (!$apiKey) {
		throw new RuntimeException('SCRAPINGBEE_API_KEY environment variable is not set');
	}

	$scrapingBeeUrl = 'https://app.scrapingbee.com/api/v1?' . http_build_query([
		'api_key' => $apiKey,
		'url' => $url,
		'premium_proxy' => 'true',
		'stealth_proxy' => 'true',
		'country_code' => 'us',
		'js_scenario' => '{"instructions":[{"wait": 2000}]}',
	]);

	$httpClient = new Client();
	$maxRetries = 1;
	$attempt = 0;

	while ($attempt <= $maxRetries) {
		try {
			$httpResponse = $httpClient->request('GET', $scrapingBeeUrl, ['timeout' => 75]);
			return new Crawler(trim($httpResponse->getBody()->getContents()));
		} catch (ConnectException $e) {
			$attempt++;
			if ($attempt > $maxRetries) {
				throw $e;
			}
			sleep(2);
		}
	}

	throw new RuntimeException('Failed to fetch webpage: ' . $url);
}

function queryDb(string $sql, array $params = []): array
{
	$httpClient = new Client();
	$maxRetries = 1;
	$attempt = 0;

	while ($attempt <= $maxRetries) {
		try {
			$response = $httpClient->request(
				'POST',
				'https://api.cloudflare.com/client/v4/accounts/' . getenv('CLOUDFLARE_ACCOUNT_ID') . '/d1/database/' . getenv('CLOUDFLARE_DATABASE_ID') . '/query',
				[
					'json' => ['sql' => $sql, 'params' => $params],
					'headers' => [
						'Content-Type' => 'application/json',
						'Authorization' => 'Bearer ' . getenv('CLOUDFLARE_API_TOKEN'),
					],
					'timeout' => 10,
				]
			);
			return json_decode($response->getBody()->getContents(), true);
		} catch (ConnectException $e) {
			$attempt++;
			if ($attempt > $maxRetries) {
				throw $e;
			}
			sleep(1);
		}
	}

	throw new RuntimeException('Failed to query database');
}

function normalizeTeamAbbreviation(string $rawAbbrev): string
{
	return match ($rawAbbrev) {
		'STL' => 'LAR', 'OAK' => 'LVR', 'SDG' => 'LAC',
		default => $rawAbbrev,
	};
}

function normalizeTeamName(string $rawName): string
{
	return match ($rawName) {
		'St. Louis Rams' => 'Los Angeles Rams',
		'Oakland Raiders' => 'Las Vegas Raiders',
		'San Diego Chargers' => 'Los Angeles Chargers',
		'Washington Redskins' => 'Washington Commanders',
		'Washington Football Team' => 'Washington Commanders',
		default => $rawName,
	};
}

function getTextContentFromElement(Crawler $parentDomNode, string $selector, ?string $backupSelector = null): string
{
	$firstTry = $parentDomNode->filter($selector);
	if ($firstTry->count() === 0 && $backupSelector !== null) {
		return trim($parentDomNode->filter($backupSelector)->text());
	}
	return trim($firstTry->text());
}

function cssSelector(string $selector): string
{
	return (new CssSelectorConverter())->toXPath($selector);
}

function buildAwardRowsToInsert(string $rawAwardString): array
{
	$s = trim($rawAwardString);
	if ($s === '') {
		return [];
	}

	$found = [];
	foreach (array_map('trim', explode(',', $s)) as $t) {
		if ($t === '') continue;
		if (preg_match('/\bPB\b/', $t)) $found['PB'] = true;
		if (preg_match('/\bAP-1\b/', $t)) $found['AP-1'] = true;
		if (preg_match('/\bAP-2\b/', $t)) $found['AP-2'] = true;
		if (preg_match('/\bMVP-(\d+)\b/', $t, $m) && (int) $m[1] === 1) $found['MVP'] = true;
		if (preg_match('/\bOPoY-(\d+)\b/', $t, $m) && (int) $m[1] === 1) $found['OPOY'] = true;
		if (preg_match('/\bDPoY-(\d+)\b/', $t, $m) && (int) $m[1] === 1) $found['DPOY'] = true;
		if (preg_match('/\bORoY-(\d+)\b/', $t, $m) && (int) $m[1] === 1) $found['OROY'] = true;
		if (preg_match('/\bDRoY-(\d+)\b/', $t, $m) && (int) $m[1] === 1) $found['DROY'] = true;
	}

	$out = [];
	foreach (['PB', 'MVP', 'OPOY', 'DPOY', 'OROY', 'DROY', 'AP-1', 'AP-2'] as $k) {
		if (!empty($found[$k])) $out[] = $k;
	}
	return $out;
}

// Call BEFORE each ScrapingBee request; set $stopwatch = microtime(true) AFTER each request.
function applyRateLimit(float $stopwatch): void
{
	if ($stopwatch > 0) {
		$elapsed = microtime(true) - $stopwatch;
		$targetDelay = 5 + mt_rand(0, 3000) / 1000;
		$remaining = $targetDelay - $elapsed;
		if ($remaining > 0) {
			usleep((int) ceil($remaining * 1_000_000));
		}
	}
}

function loadProgress(string $filePath, int $year): array
{
	if (file_exists($filePath)) {
		$data = json_decode(file_get_contents($filePath), true);
		if ($data && ($data['year'] ?? null) === $year) {
			return $data;
		}
	}
	return [
		'year' => $year,
		'draft_picks_scraped' => false,
		'undrafted_players_scraped' => false,
		'pfr_links_completed_ids' => [],
		'new_players_completed_ids' => [],
		'existing_players_completed_ids' => [],
	];
}

function saveProgress(string $filePath, array $progress): void
{
	file_put_contents($filePath, json_encode($progress, JSON_PRETTY_PRINT));
}

// Extract season stats rows from a player's PFR page.
// If $targetYear is set, only returns rows for that season.
function extractSeasonStats(Crawler $dom, int $playerId, ?int $targetYear = null): array
{
	$playerTeamSeasons = [];
	$lastYear = null;

	try {
		$table = $dom->filterXPath(cssSelector("table[id]:not(#last5)"))->first();
		if ($table->count() === 0) {
			return [];
		}
	} catch (Throwable) {
		return [];
	}

	$table->filterXPath(cssSelector('tbody tr:not(.spacer)'))->each(
		function (Crawler $row) use (&$playerTeamSeasons, &$lastYear, $playerId, $targetYear) {
			try {
				try {
					$teamString = normalizeTeamAbbreviation(
						getTextContentFromElement($row, 'td[data-stat="team_name_abbr"]', 'td[data-stat="team"]')
					);
				} catch (Throwable) {
					return;
				}

				if (str_contains($teamString, 'Did not play') || str_contains($teamString, 'Missed season')) {
					return;
				}

				$seasonYear = (int) getTextContentFromElement($row, 'th[data-stat="year_id"] a', 'th[data-stat="year_id"]');
				if ($seasonYear === 0 && $lastYear === null) {
					return;
				} elseif ($seasonYear === 0) {
					$seasonYear = $lastYear;
				} else {
					$lastYear = $seasonYear;
				}

				if ($targetYear !== null && $seasonYear !== $targetYear) {
					return;
				}

				$awards = [];
				try {
					$awards = buildAwardRowsToInsert(getTextContentFromElement($row, 'td[data-stat="awards"]'));
				} catch (Throwable) {}

				if (str_contains($teamString, 'TM')) {
					if (!empty($awards)) {
						$playerTeamSeasons[] = [
							'player_id' => $playerId, 'team_abbrev' => $teamString,
							'season_year' => $seasonYear, 'is_multi_team_summary' => true, 'awards' => $awards,
						];
					}
					return;
				}

				$gamesPlayed = (int) getTextContentFromElement($row, 'td[data-stat="games"]', 'td[data-stat="g"]');
				$gamesStarted = (int) getTextContentFromElement($row, 'td[data-stat="games_started"]', 'td[data-stat="gs"]');
				$av = null;
				try {
					$avText = getTextContentFromElement($row, 'td[data-stat="av"]');
					$av = $avText === '' ? null : (int) $avText;
				} catch (Throwable) {}

				$playerTeamSeasons[] = [
					'player_id' => $playerId, 'team_abbrev' => $teamString, 'season_year' => $seasonYear,
					'games_played' => $gamesPlayed, 'games_started' => $gamesStarted, 'av' => $av,
					'awards' => $awards, 'is_multi_team_summary' => false,
				];
			} catch (Throwable $e) {
				scrapeLog('    Warning: error parsing stats row - ' . $e->getMessage());
			}
		}
	);

	return $playerTeamSeasons;
}

function extractSnapCounts(Crawler $dom, array &$playerTeamSeasons): void
{
	for ($i = 0; $i < count($playerTeamSeasons); $i++) {
		$pts = &$playerTeamSeasons[$i];
		if (!empty($pts['is_multi_team_summary']) || isset($pts['st_snaps'])) {
			continue;
		}

		$seasonYear = $pts['season_year'];
		try {
			$snapRows = $dom->filterXPath(
				cssSelector("table#snap_counts tbody tr[id='snap_counts.{$seasonYear}']:not(.partial_table)")
			);
			if ($snapRows->count() === 0) {
				continue;
			}

			$snapRows->each(function (Crawler $row) use (&$playerTeamSeasons, &$pts, $dom, $i, $seasonYear) {
				$teamString = normalizeTeamAbbreviation(getTextContentFromElement($row, 'td[data-stat="team"]'));

				if (str_contains($teamString, 'TM')) {
					$j = 0;
					$dom->filterXPath(
						cssSelector("table#snap_counts tbody tr[id='snap_counts.{$seasonYear}']:not(.full_table)")
					)->each(function (Crawler $splitRow) use (&$playerTeamSeasons, $i, &$j) {
						$idx = $i + $j;
						if (isset($playerTeamSeasons[$idx])) {
							$playerTeamSeasons[$idx]['offense_snaps'] = (int) getTextContentFromElement($splitRow, 'td[data-stat="offense"]');
							$playerTeamSeasons[$idx]['defense_snaps'] = (int) getTextContentFromElement($splitRow, 'td[data-stat="defense"]');
							$playerTeamSeasons[$idx]['st_snaps'] = (int) getTextContentFromElement($splitRow, 'td[data-stat="special_teams"]');
							$playerTeamSeasons[$idx]['offense_snap_percentage'] = (int) rtrim(getTextContentFromElement($splitRow, 'td[data-stat="off_pct"]'), '%');
							$playerTeamSeasons[$idx]['defense_snap_percentage'] = (int) rtrim(getTextContentFromElement($splitRow, 'td[data-stat="def_pct"]'), '%');
							$playerTeamSeasons[$idx]['st_snap_percentage'] = (int) rtrim(getTextContentFromElement($splitRow, 'td[data-stat="st_pct"]'), '%');
						}
						$j++;
					});
				} else {
					$pts['offense_snaps'] = (int) getTextContentFromElement($row, 'td[data-stat="offense"]');
					$pts['defense_snaps'] = (int) getTextContentFromElement($row, 'td[data-stat="defense"]');
					$pts['st_snaps'] = (int) getTextContentFromElement($row, 'td[data-stat="special_teams"]');
					$pts['offense_snap_percentage'] = (int) rtrim(getTextContentFromElement($row, 'td[data-stat="off_pct"]'), '%');
					$pts['defense_snap_percentage'] = (int) rtrim(getTextContentFromElement($row, 'td[data-stat="def_pct"]'), '%');
					$pts['st_snap_percentage'] = (int) rtrim(getTextContentFromElement($row, 'td[data-stat="st_pct"]'), '%');
				}
			});
		} catch (Throwable $e) {
			scrapeLog('    Warning: snap count error - ' . $e->getMessage());
		}
	}
}

function persistSeasonStats(array $playerTeamSeasons): void
{
	foreach ($playerTeamSeasons as $pts) {
		if (!empty($pts['awards'])) {
			$teamForAward = str_contains($pts['team_abbrev'], 'TM') ? null : $pts['team_abbrev'];
			foreach ($pts['awards'] as $award) {
				queryDb(
					'INSERT INTO player_awards (player_id, award_option_id, year_received, team_id) VALUES (?, (SELECT id FROM award_options WHERE name = ?), ?, (SELECT id FROM teams WHERE team_abbreviation = ?))',
					[$pts['player_id'], $award, $pts['season_year'], $teamForAward]
				);
			}
		}

		if (!empty($pts['is_multi_team_summary'])) {
			continue;
		}

		queryDb(
			'INSERT INTO player_team_seasons (player_id, team_id, season_year, games_played, games_started, offense_snaps, offense_snap_percentage, defense_snaps, defense_snap_percentage, st_snaps, st_snap_percentage, av) VALUES (?, (SELECT id FROM teams WHERE team_abbreviation = ?), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
			[
				$pts['player_id'], $pts['team_abbrev'], $pts['season_year'],
				$pts['games_played'], $pts['games_started'],
				$pts['offense_snaps'] ?? null, $pts['offense_snap_percentage'] ?? null,
				$pts['defense_snaps'] ?? null, $pts['defense_snap_percentage'] ?? null,
				$pts['st_snaps'] ?? null, $pts['st_snap_percentage'] ?? null, $pts['av'],
			]
		);
	}
}

// Step 1: Scrape new draft picks from PFR
function scrapeDraftPicks(int $year): void
{
	scrapeLog("Step 1: Scraping {$year} draft picks from PFR...");
	$dom = getWebpage("https://www.pro-football-reference.com/years/{$year}/draft.htm");
	$xpath = (new CssSelectorConverter())->toXPath('table#drafts tbody tr:not(.thead)');

	$currentRound = 1;
	$currentPickInRound = 1;
	$players = [];

	$dom->filterXPath($xpath)->each(function (Crawler $row) use (&$players, &$currentRound, &$currentPickInRound) {
		$roundOnRow = trim($row->filter('th')->text(''));
		if ($roundOnRow !== '' && $roundOnRow != $currentRound) {
			$currentRound = (int) $roundOnRow;
			$currentPickInRound = 1;
		}

		$player = [
			'name' => '', 'team' => '', 'round' => (int) $currentRound,
			'pick_in_round' => (int) $currentPickInRound, 'overall_pick' => '', 'pfr_link' => '',
		];

		$teamNode = $row->filter('td[data-stat="team"] a');
		if ($teamNode->count() > 0) $player['team'] = trim($teamNode->text());

		$playerNode = $row->filter('td[data-stat="player"] a');
		if ($playerNode->count() > 0) {
			$player['name'] = trim($playerNode->text());
			$player['pfr_link'] = 'https://www.pro-football-reference.com' . $playerNode->attr('href');
		}

		$pickNode = $row->filter('td[data-stat="draft_pick"]');
		if ($pickNode->count() > 0) $player['overall_pick'] = (int) trim($pickNode->text());

		if ($player['name'] !== '') $players[] = $player;
		$currentPickInRound++;
	});

	scrapeLog("  Found " . count($players) . " draft picks");
	foreach ($players as $player) {
		$teamAbbrev = normalizeTeamAbbreviation($player['team']);
		$existing = queryDb('SELECT id FROM players WHERE pro_football_reference_link = ? LIMIT 1', [$player['pfr_link']]);
		if (!empty($existing['result'][0]['results'])) {
			continue;
		}

		queryDb(
			'INSERT INTO players (name, draft_team_id, draft_year, draft_round, round_selection_position, overall_pick, pro_football_reference_link) VALUES (?, (SELECT id FROM teams WHERE team_abbreviation = ?), ?, ?, ?, ?, ?)',
			[$player['name'], $teamAbbrev, $year, $player['round'], $player['pick_in_round'], $player['overall_pick'], $player['pfr_link']]
		);
		scrapeLog("  Inserted {$player['name']}");
	}
}

// Step 2: Scrape undrafted players from Wikipedia
function scrapeUndraftedPlayers(int $year): void
{
	scrapeLog("Step 2: Scraping {$year} undrafted players from Wikipedia...");
	$dom = getWebpage("https://en.wikipedia.org/wiki/{$year}_NFL_draft");

	$table = $dom->filterXPath(
		'(//*[@id="Notable_undrafted_players"])[1]
		 /following::table[contains(concat(" ", normalize-space(@class), " "), " wikitable ")][1]'
	);
	if ($table->count() === 0) {
		scrapeLog("  No undrafted players table found");
		return;
	}

	$players = array_filter($table->filter('tbody > tr')->each(function (Crawler $row) {
		$cells = $row->filter('td');
		if ($cells->count() === 0) return null;
		$wikiLink = null;
		try { $wikiLink = 'https://en.wikipedia.org' . $cells->eq(1)->filter('a')->attr('href'); } catch (Throwable) {}
		return ['team' => trim($cells->eq(0)->text()), 'player' => trim($cells->eq(1)->text()), 'pos' => trim($cells->eq(2)->text()), 'wiki_link' => $wikiLink];
	}));

	scrapeLog("  Found " . count($players) . " undrafted players");
	foreach ($players as $player) {
		if (empty($player['wiki_link'])) continue;
		$existing = queryDb('SELECT id FROM players WHERE wikipedia_link = ? LIMIT 1', [$player['wiki_link']]);
		if (!empty($existing['result'][0]['results'])) continue;

		queryDb(
			'INSERT INTO players (name, draft_team_id, draft_year, draft_round, round_selection_position, overall_pick, position, wikipedia_link) VALUES (?, (SELECT id FROM teams WHERE name = ?), ?, NULL, NULL, NULL, ?, ?)',
			[$player['player'], normalizeTeamName($player['team']), $year, $player['pos'], $player['wiki_link']]
		);
		scrapeLog("  Inserted {$player['player']}");
	}
}

// Step 3: Find PFR links for undrafted players via their Wikipedia pages
function applyPfrLinksToUndrafteds(int $year, array &$progress, string $progressFile): void
{
	scrapeLog("Step 3: Finding PFR links for {$year} undrafted players...");
	$players = queryDb(
		'SELECT id, name, wikipedia_link FROM players WHERE draft_year = ? AND wikipedia_link IS NOT NULL AND pro_football_reference_link IS NULL AND draft_round IS NULL',
		[$year]
	)['result'][0]['results'] ?? [];

	$stopwatch = 0.0;
	foreach ($players as $player) {
		if (in_array($player['id'], $progress['pfr_links_completed_ids'])) continue;

		applyRateLimit($stopwatch);
		try {
			$dom = getWebpage($player['wikipedia_link']);
			$stopwatch = microtime(true);
			$xpath = (new CssSelectorConverter())->toXPath('a[href^="https://www.pro-football-reference.com/players"]');
			$matches = $dom->filterXPath($xpath);
			if ($matches->count() > 0) {
				queryDb('UPDATE players SET pro_football_reference_link = ? WHERE id = ?', [$matches->first()->attr('href'), $player['id']]);
				scrapeLog("  Found PFR link for {$player['name']}");
			} else {
				scrapeLog("  No PFR link found for {$player['name']}");
			}
		} catch (Throwable $e) {
			$stopwatch = microtime(true);
			scrapeLog("  Error for {$player['name']}: " . $e->getMessage());
		}

		$progress['pfr_links_completed_ids'][] = $player['id'];
		saveProgress($progressFile, $progress);
	}
}

// Step 4: Process new players - extract position, photo, and full career stats in one page fetch
function processNewPlayers(int $year, array &$progress, string $progressFile): void
{
	scrapeLog("Step 4: Processing new {$year} players (position, photo, stats)...");
	$players = queryDb(
		"SELECT id, pro_football_reference_link as pfr_link, name FROM players WHERE draft_year = ? AND completed_import = 0 AND no_data = 0 AND pro_football_reference_link IS NOT NULL AND pro_football_reference_link != ''",
		[$year]
	)['result'][0]['results'] ?? [];

	$stopwatch = 0.0;
	foreach ($players as $player) {
		if (in_array($player['id'], $progress['new_players_completed_ids'])) continue;
		if (empty($player['name'])) {
			queryDb('UPDATE players SET no_data = 1 WHERE id = ?', [$player['id']]);
			$progress['new_players_completed_ids'][] = $player['id'];
			saveProgress($progressFile, $progress);
			continue;
		}

		applyRateLimit($stopwatch);
		scrapeLog("  Processing {$player['name']}...");

		try {
			queryDb('DELETE FROM player_team_seasons WHERE player_id = ?', [$player['id']]);
			queryDb('DELETE FROM player_awards WHERE player_id = ?', [$player['id']]);

			$dom = getWebpage($player['pfr_link']);
			$stopwatch = microtime(true);

			// Position from #meta
			try {
				$posNode = $dom->filter('#meta strong')->reduce(fn(Crawler $n) => trim($n->text()) === 'Position');
				if ($posNode->count()) {
					$position = trim(str_replace(':', '', $posNode->getNode(0)->nextSibling->textContent));
					if ($position !== '') queryDb('UPDATE players SET position = ? WHERE id = ?', [$position, $player['id']]);
				}
			} catch (Throwable) {}

			// Photo from #meta
			try {
				$dom->filter('#meta .media-item img')->each(function (Crawler $node) use ($player) {
					$src = $node->attr('src');
					if ($src) queryDb('UPDATE players SET photo_url = ? WHERE id = ?', [$src, $player['id']]);
				});
			} catch (Throwable) {}

			// Career stats + snap counts
			$seasons = extractSeasonStats($dom, (int) $player['id']);
			extractSnapCounts($dom, $seasons);
			persistSeasonStats($seasons);

			queryDb('UPDATE players SET completed_import = 1, no_data = 0 WHERE id = ?', [$player['id']]);
		} catch (Throwable $e) {
			$stopwatch = microtime(true);
			scrapeLog("    Error: " . $e->getMessage());
			queryDb('UPDATE players SET no_data = 1 WHERE id = ?', [$player['id']]);
		}

		$progress['new_players_completed_ids'][] = $player['id'];
		saveProgress($progressFile, $progress);
	}
}

// Step 5: Add new season stats for existing players who played last season
function updateExistingPlayers(int $year, array &$progress, string $progressFile): void
{
	$previousSeason = $year - 1;
	scrapeLog("Step 5: Updating existing players with {$year} season stats...");

	$players = queryDb(
		"SELECT DISTINCT p.id, p.pro_football_reference_link as pfr_link, p.name
		 FROM players p
		 JOIN player_team_seasons pts ON p.id = pts.player_id
		 WHERE pts.season_year = ?
		 AND p.completed_import = 1 AND p.no_data = 0
		 AND p.pro_football_reference_link IS NOT NULL AND p.pro_football_reference_link != ''
		 AND p.draft_year != ?",
		[$previousSeason, $year]
	)['result'][0]['results'] ?? [];

	scrapeLog("  " . count($players) . " existing players to check");

	$stopwatch = 0.0;
	foreach ($players as $player) {
		if (in_array($player['id'], $progress['existing_players_completed_ids'])) continue;

		applyRateLimit($stopwatch);
		scrapeLog("  Checking {$player['name']}...");

		try {
			$dom = getWebpage($player['pfr_link']);
			$stopwatch = microtime(true);

			$seasons = extractSeasonStats($dom, (int) $player['id'], $year);
			if (!empty($seasons)) {
				queryDb('DELETE FROM player_team_seasons WHERE player_id = ? AND season_year = ?', [$player['id'], $year]);
				queryDb('DELETE FROM player_awards WHERE player_id = ? AND year_received = ?', [$player['id'], $year]);
				extractSnapCounts($dom, $seasons);
				persistSeasonStats($seasons);
			}
		} catch (Throwable $e) {
			$stopwatch = microtime(true);
			scrapeLog("    Error: " . $e->getMessage());
		}

		$progress['existing_players_completed_ids'][] = $player['id'];
		saveProgress($progressFile, $progress);
	}
}

// ── Main ────────────────────────────────────────────────────────

$seasonYear = (function () use ($argv): int {
	foreach ($argv as $arg) {
		if (str_starts_with($arg, '--year=')) return (int) substr($arg, 7);
	}
	// NFL "2025 season" runs Sept 2025 - Feb 2026. Script runs after, so completed season = last year.
	return (int) date('Y') - 1;
})();

$progressFile = __DIR__ . "/scrape-progress-{$seasonYear}.json";

if (in_array('--reset', $argv)) {
	if (file_exists($progressFile)) unlink($progressFile);
	scrapeLog("Progress reset for {$seasonYear}");
}

$progress = loadProgress($progressFile, $seasonYear);
scrapeLog("Draft Signal Annual Scraper - Season {$seasonYear}");

if (!$progress['draft_picks_scraped']) {
	scrapeDraftPicks($seasonYear);
	$progress['draft_picks_scraped'] = true;
	saveProgress($progressFile, $progress);
} else {
	scrapeLog('Step 1: already done (skipping)');
}

if (!$progress['undrafted_players_scraped']) {
	scrapeUndraftedPlayers($seasonYear);
	$progress['undrafted_players_scraped'] = true;
	saveProgress($progressFile, $progress);
} else {
	scrapeLog('Step 2: already done (skipping)');
}

applyPfrLinksToUndrafteds($seasonYear, $progress, $progressFile);
processNewPlayers($seasonYear, $progress, $progressFile);
updateExistingPlayers($seasonYear, $progress, $progressFile);

if (file_exists($progressFile)) unlink($progressFile);
scrapeLog('All steps complete!');
