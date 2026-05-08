<?php

class UserDashboardRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT
                id, username, full_name, email, avatar_url, role, created_at,
                COALESCE(show_full_name, 0) AS show_full_name,
                COALESCE(show_contact, 0) AS show_contact
            FROM users
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findByUsername(string $username): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, username, full_name, email, avatar_url, role, created_at,
                   show_full_name, show_contact
            FROM users
            WHERE LOWER(username) = LOWER(?)
            LIMIT 1
        ");
        $stmt->execute([$username]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function updatePrivacy(int $userId, int $showFullName, int $showContact): void
    {
        $stmt = $this->db->prepare("
            UPDATE users
            SET show_full_name = ?, show_contact = ?
            WHERE id = ?
        ");
        $stmt->execute([$showFullName, $showContact, $userId]);
    }

    public function countDistinctTeams(int $userId): int
    {
        return $this->scalar("
            SELECT COUNT(DISTINCT tm.team_id)
            FROM team_members tm
            WHERE tm.user_id = ?
        ", [$userId]);
    }

    public function countTournamentsPlayed(int $userId): int
    {
        return $this->scalar("
            SELECT COUNT(DISTINCT t.id)
            FROM team_members tm
            INNER JOIN teams te ON te.id = tm.team_id
            INNER JOIN tournaments t ON t.id = te.tournament_id
            WHERE tm.user_id = ?
              AND t.start_date <= CURDATE()
        ", [$userId]);
    }

    public function countTournamentsWon(int $userId): int
    {
        return $this->scalar("
            SELECT COUNT(*)
            FROM tournament_user_results r
            WHERE r.user_id = ?
              AND (r.result = 'winner' OR r.final_position = 1)
        ", [$userId]);
    }

    public function fetchAttendanceCounts(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END), 0) AS present_count,
                COALESCE(SUM(CASE WHEN status = 'no_show' THEN 1 ELSE 0 END), 0) AS no_show_count
            FROM user_attendance_records
            WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['present_count' => 0, 'no_show_count' => 0];
        return [
            'present_count' => (int)($row['present_count'] ?? 0),
            'no_show_count' => (int)($row['no_show_count'] ?? 0),
        ];
    }

    public function fetchSanctionCounts(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END), 0) AS active_count,
                COUNT(*) AS total_count
            FROM user_sanctions
            WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['active_count' => 0, 'total_count' => 0];
        return [
            'active_count' => (int)($row['active_count'] ?? 0),
            'total_count' => (int)($row['total_count'] ?? 0),
        ];
    }

    public function countCreatedTournaments(int $userId): int
    {
        return $this->scalar("SELECT COUNT(*) FROM tournaments WHERE created_by = ?", [$userId]);
    }

    public function countCaptainTeams(int $userId): int
    {
        return $this->scalar("
            SELECT COUNT(*)
            FROM team_members
            WHERE user_id = ?
              AND role = 'captain'
              AND pending_validation = 0
        ", [$userId]);
    }

    public function countTop8Finishes(int $userId): int
    {
        return $this->scalar("
            SELECT COUNT(*)
            FROM tournament_user_results r
            WHERE r.user_id = ?
              AND (
                (r.final_position IS NOT NULL AND r.final_position <= 8)
                OR r.result IN ('top8','winner')
              )
        ", [$userId]);
    }

    public function fetchRecentTournaments(int $userId, int $limit = 8): array
    {
        $limit = max(1, $limit);
        $stmt = $this->db->prepare("
            SELECT
                t.id, t.name, t.game, t.start_date, t.start_time,
                tm.role AS team_role,
                te.name AS team_name,
                COALESCE(t.visibility, 'public') AS visibility
            FROM team_members tm
            INNER JOIN teams te ON te.id = tm.team_id
            INNER JOIN tournaments t ON t.id = te.tournament_id
            WHERE tm.user_id = ?
            ORDER BY t.start_date DESC, t.start_time DESC, t.id DESC
            LIMIT $limit
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function fetchWonTournaments(int $userId, int $limit = 8): array
    {
        $limit = max(1, $limit);
        $stmt = $this->db->prepare("
            SELECT
                t.id, t.name, t.game, t.start_date, t.start_time,
                r.final_position, r.result
            FROM tournament_user_results r
            INNER JOIN tournaments t ON t.id = r.tournament_id
            WHERE r.user_id = ?
              AND (r.result = 'winner' OR r.final_position = 1)
            ORDER BY t.start_date DESC, t.start_time DESC, t.id DESC
            LIMIT $limit
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function fetchUserTeams(int $userId, int $limit = 12): array
    {
        $limit = max(1, $limit);
        $stmt = $this->db->prepare("
            SELECT
                te.id, te.name, te.logo_url, te.color_hex, te.team_status, te.capacity,
                tm.role, tm.pending_validation,
                t.id AS tournament_id, t.name AS tournament_name
            FROM team_members tm
            INNER JOIN teams te ON te.id = tm.team_id
            INNER JOIN tournaments t ON t.id = te.tournament_id
            WHERE tm.user_id = ?
            ORDER BY tm.joined_at DESC, te.id DESC
            LIMIT $limit
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function scalar(string $sql, array $params = []): int
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)($stmt->fetchColumn() ?: 0);
    }
}