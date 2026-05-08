<?php

class AdminRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function fetchAllTournamentsForAdmin(): array
    {
        $stmt = $this->db->query("
            SELECT
                t.id, t.name, t.description, t.game, t.type, t.max_teams,
                COALESCE(tc.teams_count, 0) AS teams_count,
                CASE
                    WHEN COALESCE(tc.teams_count, 0) >= t.max_teams THEN 1
                    ELSE 0
                END AS is_full,
                t.format, t.start_date, t.start_time, t.prize,
                t.location_name, t.location_address, t.location_lat, t.location_lng, t.is_online,
                t.visibility, t.access_code_last4,
                t.created_by,
                u.username AS created_by_username,
                t.created_at
            FROM tournaments t
            LEFT JOIN users u ON u.id = t.created_by
            LEFT JOIN (
                SELECT tournament_id, COUNT(*) AS teams_count
                FROM teams GROUP BY tournament_id
            ) tc ON tc.tournament_id = t.id
            ORDER BY t.start_date ASC, t.start_time ASC, t.created_at DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function deleteTournament(int $tournamentId): int
    {
        $stmt = $this->db->prepare("DELETE FROM tournaments WHERE id = ?");
        $stmt->execute([$tournamentId]);
        return $stmt->rowCount();
    }
}