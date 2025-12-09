<?php

namespace DraftSignal\Algorithm\Data;

final readonly class PlayerStats {
	public function __construct(
		public int $id,
		public string $name,
		public int $draftYear,
		public int $draftRound,
		public int $overallPick,
		public int $firstStintAv,
		public int $firstStintGamesPlayed,
		public int $firstStintGamesStarted,
		public int $firstStintRegSnaps,
		public int $firstStintStSnaps,
		public float $firstStintRegSnapPct,
		public float $firstStintStSnapPct,
		public int $firstStintSeasonsPlayed,
		public string $position,
	) {}

	public static function fromDatabaseRow(array $row): self {
		return new self(
			id: (int) $row['id'],
			name: (string) $row['player_name'],
			draftYear: (int) $row['draft_year'],
			draftRound: (int) $row['draft_round'],
			overallPick: (int) $row['overall_pick'],
			firstStintAv: (int) $row['first_stint_av'],
			firstStintGamesPlayed: (int) $row['first_stint_games_played'],
			firstStintGamesStarted: (int) $row['first_stint_games_started'],
			firstStintRegSnaps: (int) $row['first_stint_reg_snaps'],
			firstStintStSnaps: (int) $row['first_stint_st_snaps'],
			firstStintRegSnapPct: (float) $row['first_stint_reg_snap_pct'],
			firstStintStSnapPct: (float) $row['first_stint_st_snap_pct'],
			firstStintSeasonsPlayed: (int) $row['first_stint_seasons_played'],
			position: (string) $row['position'],
		);
	}

	public static function fromArray(array $data): self {
		return new self(
			id: $data['id'],
			name: $data['name'],
			draftYear: $data['draftYear'],
			draftRound: $data['draftRound'],
			overallPick: $data['overallPick'],
			firstStintAv: $data['firstStintAv'],
			firstStintGamesPlayed: $data['firstStintGamesPlayed'],
			firstStintGamesStarted: $data['firstStintGamesStarted'],
			firstStintRegSnaps: $data['firstStintRegSnaps'],
			firstStintStSnaps: $data['firstStintStSnaps'],
			firstStintRegSnapPct: $data['firstStintRegSnapPct'],
			firstStintStSnapPct: $data['firstStintStSnapPct'],
			firstStintSeasonsPlayed: $data['firstStintSeasonsPlayed'],
			position: $data['position'],
		);
	}

	public function toArray(): array {
		return [
			'id' => $this->id,
			'name' => $this->name,
			'draftYear' => $this->draftYear,
			'draftRound' => $this->draftRound,
			'overallPick' => $this->overallPick,
			'firstStintAv' => $this->firstStintAv,
			'firstStintGamesPlayed' => $this->firstStintGamesPlayed,
			'firstStintGamesStarted' => $this->firstStintGamesStarted,
			'firstStintRegSnaps' => $this->firstStintRegSnaps,
			'firstStintStSnaps' => $this->firstStintStSnaps,
			'firstStintRegSnapPct' => $this->firstStintRegSnapPct,
			'firstStintStSnapPct' => $this->firstStintStSnapPct,
			'firstStintSeasonsPlayed' => $this->firstStintSeasonsPlayed,
			'position' => $this->position,
		];
	}
}
