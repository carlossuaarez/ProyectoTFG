<?php

class MyTournamentsRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function fetchJoinedAsCaptain(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT
                t.id, t.name, t.description, t.game, t.type, t.max_teams, t.format,
                t.start_date, t.start_time, t.prize,
                t.location_name, t.location_address, t.location_lat, t.location_lng, t.is_online,
                COALESCE(t.visibility, 'public') AS visibility,
                t.created_by,
                u.username AS created_by_username,
                tm.name AS my_team_name,
                tm.registered_at AS my_registered_at,
                COALESCE(tc.teams_count, 0) AS teams_count,
                CASE
                    WHEN COALESCE(tc.teams_count, 0) >= t.max_teams THEN 1
                    ELSE 0
                END AS is_full
            FROM teams tm
            INNER JOIN tournaments t ON t.id = tm.tournament_id
            LEFT JOIN users u ON u.id = t.created_by
            LEFT JOIN (
                SELECT tournament_id, COUNT(*) AS teams_count
                FROM teams GROUP BY tournament_id
            ) tc ON tc.tournament_id = t.id
            WHERE tm.captain_id = ?
            ORDER BY t.start_date ASC, t.start_time ASC, tm.registered_at DESC
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function fetchCreatedByUser(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT
                t.id, t.name, t.description, t.game, t.type, t.max_teams, t.format,
                t.start_date, t.start_time, t.prize,
                t.location_name, t.location_address, t.location_lat, t.location_lng, t.is_online,
                COALESCE(t.visibility, 'public') AS visibility,
                t.created_by,
                u.username AS created_by_username,
                COALESCE(tc.teams_count, 0) AS teams_count,
                CASE
                    WHEN COALESCE(tc.teams_count, 0) >= t.max_teams THEN 1
                    ELSE 0
                END AS is_full
            FROM tournaments t
            LEFT JOIN users u ON u.id = t.created_by
            LEFT JOIN (
                SELECT tournament_id, COUNT(*) AS teams_count
                FROM teams GROUP BY tournament_id
            ) tc ON tc.tournament_id = t.id
            WHERE t.created_by = ?
            ORDER BY t.start_date ASC, t.start_time ASC, t.id DESC
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}