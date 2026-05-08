<?php

class MatchRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function findTournamentById(int $tournamentId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, name, max_teams,
                   COALESCE(visibility, 'public') AS visibility,
                   access_code_hash, created_by,
                   COALESCE(format, 'single_elim') AS format
            FROM tournaments
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$tournamentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findMatchWithTournament(int $matchId, bool $forUpdate = false): ?array
    {
        $sql = "
            SELECT
                m.*,
                t.name AS tournament_name,
                t.created_by AS tournament_created_by,
                COALESCE(t.visibility, 'public') AS tournament_visibility,
                t.access_code_hash AS tournament_access_code_hash,
                t.max_teams AS tournament_max_teams,
                COALESCE(t.format, 'single_elim') AS tournament_format,
                ta.name AS team_a_name,
                ta.logo_url AS team_a_logo_url,
                ta.color_hex AS team_a_color_hex,
                ta.captain_id AS team_a_captain_id,
                tb.name AS team_b_name,
                tb.logo_url AS team_b_logo_url,
                tb.color_hex AS team_b_color_hex,
                tb.captain_id AS team_b_captain_id,
                tw.name AS winner_team_name,
                (
                    SELECT m2.id FROM tournament_matches m2
                    WHERE m2.source_match_a_id = m.id OR m2.source_match_b_id = m.id
                    ORDER BY m2.round_number ASC, m2.bracket_slot ASC
                    LIMIT 1
                ) AS next_match_id
            FROM tournament_matches m
            INNER JOIN tournaments t ON t.id = m.tournament_id
            LEFT JOIN teams ta ON ta.id = m.team_a_id
            LEFT JOIN teams tb ON tb.id = m.team_b_id
            LEFT JOIN teams tw ON tw.id = m.winner_team_id
            WHERE m.id = ?
            LIMIT 1
        ";
        if ($forUpdate) {
            $sql .= " FOR UPDATE";
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$matchId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function fetchTournamentMatchesFlat(int $tournamentId): array
    {
        $stmt = $this->db->prepare("
            SELECT
                m.*,
                ta.name AS team_a_name,
                ta.logo_url AS team_a_logo_url,
                ta.color_hex AS team_a_color_hex,
                tb.name AS team_b_name,
                tb.logo_url AS team_b_logo_url,
                tb.color_hex AS team_b_color_hex,
                tw.name AS winner_team_name,
                (
                    SELECT COUNT(*) FROM tournament_match_disputes d
                    WHERE d.match_id = m.id
                      AND d.status IN ('open', 'reviewing')
                ) AS open_disputes_count
            FROM tournament_matches m
            LEFT JOIN teams ta ON ta.id = m.team_a_id
            LEFT JOIN teams tb ON tb.id = m.team_b_id
            LEFT JOIN teams tw ON tw.id = m.winner_team_id
            WHERE m.tournament_id = ?
            ORDER BY m.round_number ASC, m.bracket_slot ASC
        ");
        $stmt->execute([$tournamentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function fetchPhaseTimelineRaw(int $tournamentId): array
    {
        $stmt = $this->db->prepare("
            SELECT
                round_number, phase_label,
                COUNT(*) AS total_matches,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
                SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress_count,
                SUM(CASE WHEN status = 'finalized' THEN 1 ELSE 0 END) AS finalized_count
            FROM tournament_matches
            WHERE tournament_id = ?
            GROUP BY round_number, phase_label
            ORDER BY round_number ASC
        ");
        $stmt->execute([$tournamentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function fetchMatchTimeline(int $matchId): array
    {
        $stmt = $this->db->prepare("
            SELECT e.id, e.event_type, e.payload_json, e.created_at, e.actor_user_id,
                   u.username AS actor_username
            FROM tournament_match_events e
            LEFT JOIN users u ON u.id = e.actor_user_id
            WHERE e.match_id = ?
            ORDER BY e.created_at ASC, e.id ASC
        ");
        $stmt->execute([$matchId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function fetchMatchDisputes(int $matchId): array
    {
        $stmt = $this->db->prepare("
            SELECT d.id, d.reason, d.status, d.resolution_note, d.created_at, d.updated_at,
                   d.resolved_at, d.created_by, d.resolved_by,
                   cu.username AS created_by_username,
                   ru.username AS resolved_by_username
            FROM tournament_match_disputes d
            LEFT JOIN users cu ON cu.id = d.created_by
            LEFT JOIN users ru ON ru.id = d.resolved_by
            WHERE d.match_id = ?
            ORDER BY d.created_at DESC, d.id DESC
        ");
        $stmt->execute([$matchId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countMatchesInTournament(int $tournamentId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM tournament_matches WHERE tournament_id = ?");
        $stmt->execute([$tournamentId]);
        return (int)$stmt->fetchColumn();
    }

    public function countTeamsInTournament(int $tournamentId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM teams WHERE tournament_id = ?");
        $stmt->execute([$tournamentId]);
        return (int)$stmt->fetchColumn();
    }

    public function fetchTeamsForBracket(int $tournamentId): array
    {
        $stmt = $this->db->prepare("
            SELECT id, name FROM teams
            WHERE tournament_id = ?
            ORDER BY registered_at ASC, id ASC
        ");
        $stmt->execute([$tournamentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function insertMatch(array $payload): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO tournament_matches (
                tournament_id, round_number, phase_label, bracket_slot,
                source_match_a_id, source_match_b_id,
                team_a_id, team_b_id,
                status, score_a, score_b, winner_team_id,
                captain_a_confirmed, captain_b_confirmed, finalized_at,
                created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $payload['tournament_id'],
            $payload['round_number'],
            $payload['phase_label'],
            $payload['bracket_slot'],
            $payload['source_match_a_id'],
            $payload['source_match_b_id'],
            $payload['team_a_id'],
            $payload['team_b_id'],
            $payload['status'],
            $payload['score_a'],
            $payload['score_b'],
            $payload['winner_team_id'],
            $payload['captain_a_confirmed'],
            $payload['captain_b_confirmed'],
            $payload['finalized_at'],
            $payload['created_by'],
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function updateMatchStatus(int $matchId, string $status, ?string $finalizedAt): void
    {
        $stmt = $this->db->prepare("
            UPDATE tournament_matches
            SET status = ?, finalized_at = ?
            WHERE id = ?
        ");
        $stmt->execute([$status, $finalizedAt, $matchId]);
    }

    public function updateMatchScore(
        int $matchId, int $scoreA, int $scoreB, ?int $winner,
        int $captainAConfirmed, int $captainBConfirmed,
        string $status, ?string $finalizedAt
    ): void {
        $stmt = $this->db->prepare("
            UPDATE tournament_matches
            SET score_a = ?, score_b = ?, winner_team_id = ?,
                captain_a_confirmed = ?, captain_b_confirmed = ?,
                status = ?, finalized_at = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $scoreA, $scoreB, $winner,
            $captainAConfirmed, $captainBConfirmed,
            $status, $finalizedAt, $matchId,
        ]);
    }

    public function updateMatchConfirmations(
        int $matchId, int $captainAConfirmed, int $captainBConfirmed,
        string $status, ?string $finalizedAt
    ): void {
        $stmt = $this->db->prepare("
            UPDATE tournament_matches
            SET captain_a_confirmed = ?, captain_b_confirmed = ?, status = ?, finalized_at = ?
            WHERE id = ?
        ");
        $stmt->execute([$captainAConfirmed, $captainBConfirmed, $status, $finalizedAt, $matchId]);
    }

    public function hasTeamRoleNew(int $teamId, int $userId, array $roles): bool
    {
        if (empty($roles)) return false;
        $placeholders = implode(', ', array_fill(0, count($roles), '?'));
        $stmt = $this->db->prepare("
            SELECT id FROM team_members
            WHERE team_id = ? AND user_id = ?
              AND pending_validation = 0
              AND role IN ($placeholders)
            LIMIT 1
        ");
        $stmt->execute(array_merge([$teamId, $userId], $roles));
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function teamCaptainIs(int $teamId, int $userId): bool
    {
        $stmt = $this->db->prepare("
            SELECT id FROM teams
            WHERE id = ? AND captain_id = ?
            LIMIT 1
        ");
        $stmt->execute([$teamId, $userId]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function fetchLeagueTeams(int $tournamentId): array
    {
        $stmt = $this->db->prepare("
            SELECT id, name FROM teams
            WHERE tournament_id = ?
            ORDER BY registered_at ASC, id ASC
        ");
        $stmt->execute([$tournamentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function fetchFinalizedMatchesForLeague(int $tournamentId): array
    {
        $stmt = $this->db->prepare("
            SELECT team_a_id, team_b_id, score_a, score_b
            FROM tournament_matches
            WHERE tournament_id = ?
              AND status = 'finalized'
              AND team_a_id IS NOT NULL
              AND team_b_id IS NOT NULL
        ");
        $stmt->execute([$tournamentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function logMatchEvent(int $matchId, string $eventType, ?int $actorUserId, array $payload = []): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO tournament_match_events (match_id, event_type, actor_user_id, payload_json)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([
            $matchId,
            $eventType,
            ($actorUserId && $actorUserId > 0) ? $actorUserId : null,
            !empty($payload) ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    public function findMatchForPropagation(int $matchId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, winner_team_id FROM tournament_matches
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$matchId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function fetchChildMatches(int $parentMatchId): array
    {
        $stmt = $this->db->prepare("
            SELECT id, source_match_a_id, source_match_b_id, team_a_id, team_b_id
            FROM tournament_matches
            WHERE source_match_a_id = ? OR source_match_b_id = ?
        ");
        $stmt->execute([$parentMatchId, $parentMatchId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function updateChildMatchTeams(int $childId, ?int $teamAId, ?int $teamBId): void
    {
        $stmt = $this->db->prepare("
            UPDATE tournament_matches
            SET team_a_id = ?, team_b_id = ?
            WHERE id = ?
        ");
        $stmt->execute([$teamAId, $teamBId, $childId]);
    }

    public function insertDispute(int $matchId, int $userId, string $reason): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO tournament_match_disputes (match_id, created_by, reason, status)
            VALUES (?, ?, ?, 'open')
        ");
        $stmt->execute([$matchId, $userId, $reason]);
        return (int)$this->db->lastInsertId();
    }

    public function findDisputeForUpdate(int $disputeId, int $matchId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, status FROM tournament_match_disputes
            WHERE id = ? AND match_id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([$disputeId, $matchId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function updateDispute(
        int $disputeId, string $status, ?string $resolutionNote,
        ?int $resolvedBy, ?string $resolvedAt
    ): void {
        $stmt = $this->db->prepare("
            UPDATE tournament_match_disputes
            SET status = ?, resolution_note = ?, resolved_by = ?, resolved_at = ?
            WHERE id = ?
        ");
        $stmt->execute([$status, $resolutionNote, $resolvedBy, $resolvedAt, $disputeId]);
    }
}