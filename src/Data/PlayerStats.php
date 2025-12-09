<?php

namespace DraftSignal\Algorithm\Data;

final readonly class PlayerStats {
	public function __construct(
		public int $id,
		public string $name,
		public ?int $draftYear,
		public ?int $draftRound,
		public ?int $overallPick,
		public int $firstStintAv,
		public int $firstStintGamesPlayed,
		public int $firstStintGamesStarted,
		public int $firstStintRegSnaps,
		public int $firstStintStSnaps,
		public float $firstStintRegSnapPct,
		public float $firstStintStSnapPct,
		public int $firstStintSeasonsPlayed,
		public string $position,
		public int $firstStintMvps,
		public int $firstStintPbs,
		public int $firstStintAp1s,
		public int $firstStintAp2s,
		public int $firstStintOpoys,
		public int $firstStintDpoys,
		public bool $oroy,
		public bool $droy,
	) {}

	public static function fromDatabaseRow(array $row): self {
		return new self(
			id: (int) $row['id'],
			name: (string) $row['player_name'],
			draftYear: $row['draft_year'] !== null ? (int) $row['draft_year'] : null,
			draftRound: $row['draft_round'] !== null ? (int) $row['draft_round'] : null,
			overallPick: $row['overall_pick'] !== null ? (int) $row['overall_pick'] : null,
			firstStintAv: (int) $row['first_stint_av'],
			firstStintGamesPlayed: (int) $row['first_stint_games_played'],
			firstStintGamesStarted: (int) $row['first_stint_games_started'],
			firstStintRegSnaps: (int) $row['first_stint_reg_snaps'],
			firstStintStSnaps: (int) $row['first_stint_st_snaps'],
			firstStintRegSnapPct: (float) $row['first_stint_reg_snap_pct'],
			firstStintStSnapPct: (float) $row['first_stint_st_snap_pct'],
			firstStintSeasonsPlayed: (int) $row['first_stint_seasons_played'],
			position: (string) $row['position'],
			firstStintMvps: (int) $row['first_stint_mvps'],
			firstStintPbs: (int) $row['first_stint_pbs'],
			firstStintAp1s: (int) $row['first_stint_ap1s'],
			firstStintAp2s: (int) $row['first_stint_ap2s'],
			firstStintOpoys: (int) $row['first_stint_opoys'],
			firstStintDpoys: (int) $row['first_stint_dpoys'],
			oroy: (bool) $row['oroy'],
			droy: (bool) $row['droy'],
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
			firstStintMvps: $data['firstStintMvps'],
			firstStintPbs: $data['firstStintPbs'],
			firstStintAp1s: $data['firstStintAp1s'],
			firstStintAp2s: $data['firstStintAp2s'],
			firstStintOpoys: $data['firstStintOpoys'],
			firstStintDpoys: $data['firstStintDpoys'],
			oroy: $data['oroy'],
			droy: $data['droy'],
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
			'firstStintMvps' => $this->firstStintMvps,
			'firstStintPbs' => $this->firstStintPbs,
			'firstStintAp1s' => $this->firstStintAp1s,
			'firstStintAp2s' => $this->firstStintAp2s,
			'firstStintOpoys' => $this->firstStintOpoys,
			'firstStintDpoys' => $this->firstStintDpoys,
			'oroy' => $this->oroy,
			'droy' => $this->droy,
		];
	}

	public function isUndrafted(): bool {
		return $this->overallPick === null;
	}
}
