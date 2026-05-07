<?php

require_once __DIR__ . '/../Models/Tournament.php';

class TournamentRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function fetchPublicList(): array
    {
        $stmt = $this->db->query("
            SELECT
                t.id, t.name, t.description, t.game, t.type, t.max_teams, t.format,
                t.start_date, t.start_time, t.prize,
                t.location_name, t.location_address, t.location_lat, t.location_lng, t.is_online,
                COALESCE(t.visibility, 'public') AS visibility,
                COALESCE(tc.teams_count, 0) AS teams_count,
                CASE
                    WHEN COALESCE(tc.teams_count, 0) >= t.max_teams THEN 1
                    ELSE 0
                END AS is_full,
                t.created_by,
                u.username AS created_by_username,
                t.created_at
            FROM tournaments t
            LEFT JOIN users u ON u.id = t.created_by
            LEFT JOIN (
                SELECT tournament_id, COUNT(*) AS teams_count
                FROM teams
                GROUP BY tournament_id
            ) tc ON tc.tournament_id = t.id
            WHERE COALESCE(t.visibility, 'public') = 'public'
            ORDER BY t.start_date ASC, t.start_time ASC, t.id DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findByIdWithCreator(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT t.*, u.username AS created_by_username
            FROM tournaments t
            LEFT JOIN users u ON u.id = t.created_by
            WHERE t.id = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findByIdForUpdate(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, max_teams, COALESCE(visibility, 'public') AS visibility, access_code_hash, access_code_last4, created_by
            FROM tournaments
            WHERE id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function fetchTeamsByTournamentId(int $tournamentId): array
    {
        $stmt = $this->db->prepare("
            SELECT id, tournament_id, name, captain_id, registered_at
            FROM teams
            WHERE tournament_id = ?
            ORDER BY registered_at ASC
        ");
        $stmt->execute([$tournamentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countTeams(int $tournamentId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM teams WHERE tournament_id = ?");
        $stmt->execute([$tournamentId]);
        return (int)$stmt->fetchColumn();
    }

    public function captainHasTeamInTournament(int $tournamentId, int $captainId): bool
    {
        $stmt = $this->db->prepare("
            SELECT id FROM teams WHERE tournament_id = ? AND captain_id = ? LIMIT 1
        ");
        $stmt->execute([$tournamentId, $captainId]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function teamNameExistsInTournament(int $tournamentId, string $teamName): bool
    {
        $stmt = $this->db->prepare("
            SELECT id FROM teams WHERE tournament_id = ? AND LOWER(name) = LOWER(?) LIMIT 1
        ");
        $stmt->execute([$tournamentId, $teamName]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function insertTeam(int $tournamentId, string $teamName, int $captainId): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO teams (tournament_id, name, captain_id)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$tournamentId, $teamName, $captainId]);
        return (int)$this->db->lastInsertId();
    }

    public function insertTournament(array $payload): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO tournaments (
                name, description, game, type, max_teams, format, start_date, start_time, prize, created_by,
                location_name, location_address, location_lat, location_lng, is_online,
                visibility, access_code_hash, access_code_last4
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $payload['name'],
            $payload['description'],
            $payload['game'],
            $payload['type'],
            $payload['max_teams'],
            $payload['format'],
            $payload['start_date'],
            $payload['start_time'],
            $payload['prize'],
            $payload['created_by'],
            $payload['location_name'],
            $payload['location_address'],
            $payload['location_lat'],
            $payload['location_lng'],
            $payload['is_online'],
            $payload['visibility'],
            $payload['access_code_hash'],
            $payload['access_code_last4'],
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function updateTournamentById(int $tournamentId, array $payload): void
    {
        $stmt = $this->db->prepare("
            UPDATE tournaments
            SET
                name = ?,
                description = ?,
                game = ?,
                type = ?,
                format = ?,
                start_date = ?,
                start_time = ?,
                prize = ?,
                location_name = ?,
                location_address = ?,
                location_lat = ?,
                location_lng = ?,
                is_online = ?,
                visibility = ?,
                access_code_hash = ?,
                access_code_last4 = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $payload['name'],
            $payload['description'],
            $payload['game'],
            $payload['type'],
            $payload['format'],
            $payload['start_date'],
            $payload['start_time'],
            $payload['prize'],
            $payload['location_name'],
            $payload['location_address'],
            $payload['location_lat'],
            $payload['location_lng'],
            $payload['is_online'],
            $payload['visibility'],
            $payload['access_code_hash'],
            $payload['access_code_last4'],
            $tournamentId,
        ]);
    }

    public function findPrivateCandidatesByLast4(string $last4): array
    {
        $stmt = $this->db->prepare("
            SELECT id, access_code_hash
            FROM tournaments
            WHERE COALESCE(visibility, 'public') = 'private'
              AND access_code_hash IS NOT NULL
              AND access_code_last4 = ?
            ORDER BY id DESC
        ");
        $stmt->execute([$last4]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findLegacyPrivateCandidates(): array
    {
        $stmt = $this->db->query("
            SELECT id, access_code_hash
            FROM tournaments
            WHERE COALESCE(visibility, 'public') = 'private'
              AND access_code_hash IS NOT NULL
              AND (access_code_last4 IS NULL OR access_code_last4 = '')
            ORDER BY id DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function candidatesByLast4ForUniqueness(string $last4): array
    {
        $stmt = $this->db->prepare("
            SELECT access_code_hash
            FROM tournaments
            WHERE COALESCE(visibility, 'public') = 'private'
              AND access_code_hash IS NOT NULL
              AND access_code_last4 = ?
        ");
        $stmt->execute([$last4]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getPdo(): PDO
    {
        return $this->db;
    }
}