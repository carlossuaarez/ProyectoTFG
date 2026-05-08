<?php

class TournamentMatch
{
    public int $id;
    public int $tournamentId;
    public int $roundNumber;
    public string $phaseLabel;
    public int $bracketSlot;
    public ?int $sourceMatchAId;
    public ?int $sourceMatchBId;
    public ?int $teamAId;
    public ?int $teamBId;
    public string $status;
    public int $scoreA;
    public int $scoreB;
    public ?int $winnerTeamId;
    public int $captainAConfirmed;
    public int $captainBConfirmed;
    public ?string $scheduledAt;
    public ?string $locationName;
    public ?string $finalizedAt;
    public ?string $createdAt;
    public ?string $updatedAt;
    public int $createdBy;

    public static function fromRow(array $row): self
    {
        $m = new self();
        $m->id = (int)($row['id'] ?? 0);
        $m->tournamentId = (int)($row['tournament_id'] ?? 0);
        $m->roundNumber = (int)($row['round_number'] ?? 0);
        $m->phaseLabel = (string)($row['phase_label'] ?? '');
        $m->bracketSlot = (int)($row['bracket_slot'] ?? 0);
        $m->sourceMatchAId = isset($row['source_match_a_id']) && $row['source_match_a_id'] !== null
            ? (int)$row['source_match_a_id'] : null;
        $m->sourceMatchBId = isset($row['source_match_b_id']) && $row['source_match_b_id'] !== null
            ? (int)$row['source_match_b_id'] : null;
        $m->teamAId = isset($row['team_a_id']) && $row['team_a_id'] !== null
            ? (int)$row['team_a_id'] : null;
        $m->teamBId = isset($row['team_b_id']) && $row['team_b_id'] !== null
            ? (int)$row['team_b_id'] : null;
        $m->status = (string)($row['status'] ?? 'pending');
        $m->scoreA = (int)($row['score_a'] ?? 0);
        $m->scoreB = (int)($row['score_b'] ?? 0);
        $m->winnerTeamId = isset($row['winner_team_id']) && $row['winner_team_id'] !== null
            ? (int)$row['winner_team_id'] : null;
        $m->captainAConfirmed = (int)($row['captain_a_confirmed'] ?? 0);
        $m->captainBConfirmed = (int)($row['captain_b_confirmed'] ?? 0);
        $m->scheduledAt = isset($row['scheduled_at']) ? (string)$row['scheduled_at'] : null;
        $m->locationName = isset($row['location_name']) ? (string)$row['location_name'] : null;
        $m->finalizedAt = isset($row['finalized_at']) ? (string)$row['finalized_at'] : null;
        $m->createdAt = isset($row['created_at']) ? (string)$row['created_at'] : null;
        $m->updatedAt = isset($row['updated_at']) ? (string)$row['updated_at'] : null;
        $m->createdBy = (int)($row['created_by'] ?? 0);
        return $m;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tournament_id' => $this->tournamentId,
            'round_number' => $this->roundNumber,
            'phase_label' => $this->phaseLabel,
            'bracket_slot' => $this->bracketSlot,
            'source_match_a_id' => $this->sourceMatchAId,
            'source_match_b_id' => $this->sourceMatchBId,
            'team_a_id' => $this->teamAId,
            'team_b_id' => $this->teamBId,
            'status' => $this->status,
            'score_a' => $this->scoreA,
            'score_b' => $this->scoreB,
            'winner_team_id' => $this->winnerTeamId,
            'captain_a_confirmed' => $this->captainAConfirmed,
            'captain_b_confirmed' => $this->captainBConfirmed,
            'scheduled_at' => $this->scheduledAt,
            'location_name' => $this->locationName,
            'finalized_at' => $this->finalizedAt,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'created_by' => $this->createdBy,
        ];
    }
}