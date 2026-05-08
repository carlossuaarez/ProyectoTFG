<?php

class NotificationRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function bootstrapTables(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS user_notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                type VARCHAR(40) NOT NULL,
                title VARCHAR(140) NOT NULL,
                body VARCHAR(500) NOT NULL,
                link_url VARCHAR(255) NULL,
                meta_json JSON NULL,
                is_read TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                read_at DATETIME NULL,
                INDEX idx_user_created (user_id, created_at),
                INDEX idx_user_read (user_id, is_read),
                CONSTRAINT fk_user_notifications_user
                    FOREIGN KEY (user_id) REFERENCES users(id)
                    ON DELETE CASCADE
            )
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS notification_delivery_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                unique_key_hash CHAR(64) NOT NULL UNIQUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }

    public function listForUser(int $userId, int $limit): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->db->prepare("
            SELECT id, type, title, body, link_url, meta_json, is_read, created_at, read_at
            FROM user_notifications
            WHERE user_id = ?
            ORDER BY created_at DESC, id DESC
            LIMIT $limit
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countUnread(int $userId): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM user_notifications WHERE user_id = ? AND is_read = 0
        ");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    public function markRead(int $id, int $userId): int
    {
        $stmt = $this->db->prepare("
            UPDATE user_notifications
            SET is_read = 1, read_at = NOW()
            WHERE id = ? AND user_id = ?
        ");
        $stmt->execute([$id, $userId]);
        return $stmt->rowCount();
    }

    public function markAllReadForUser(int $userId): void
    {
        $stmt = $this->db->prepare("
            UPDATE user_notifications
            SET is_read = 1, read_at = NOW()
            WHERE user_id = ? AND is_read = 0
        ");
        $stmt->execute([$userId]);
    }

    public function findMatchSummary(int $matchId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT m.id, m.tournament_id, m.scheduled_at,
                   m.score_a, m.score_b, m.status,
                   ta.name AS team_a_name,
                   tb.name AS team_b_name,
                   t.name AS tournament_name
            FROM tournament_matches m
            INNER JOIN tournaments t ON t.id = m.tournament_id
            LEFT JOIN teams ta ON ta.id = m.team_a_id
            LEFT JOIN teams tb ON tb.id = m.team_b_id
            WHERE m.id = ?
            LIMIT 1
        ");
        $stmt->execute([$matchId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function fetchMatchParticipantUserIds(int $matchId): array
    {
        $stmt = $this->db->prepare("
            SELECT DISTINCT tm.user_id
            FROM tournament_matches m
            INNER JOIN team_members tm ON tm.team_id IN (m.team_a_id, m.team_b_id)
            WHERE m.id = ? AND tm.pending_validation = 0
        ");
        $stmt->execute([$matchId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $ids = [];
        foreach ($rows as $row) {
            $id = (int)($row['user_id'] ?? 0);
            if ($id > 0) $ids[] = $id;
        }
        return array_values(array_unique($ids));
    }

    public function insertNotification(int $userId, string $type, string $title, string $body, ?string $linkUrl, ?string $metaJson): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO user_notifications (user_id, type, title, body, link_url, meta_json, is_read)
            VALUES (?, ?, ?, ?, ?, ?, 0)
        ");
        $stmt->execute([$userId, $type, $title, $body, $linkUrl, $metaJson]);
    }

    public function alreadySent(string $key): bool
    {
        $stmt = $this->db->prepare("SELECT id FROM notification_delivery_log WHERE unique_key_hash = ? LIMIT 1");
        $stmt->execute([$key]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function markSent(string $key): void
    {
        $stmt = $this->db->prepare("INSERT IGNORE INTO notification_delivery_log (unique_key_hash) VALUES (?)");
        $stmt->execute([$key]);
    }

    public function fetchUpcomingMatches(): array
    {
        $stmt = $this->db->query("
            SELECT m.id, m.scheduled_at,
                   ta.name AS team_a_name,
                   tb.name AS team_b_name,
                   t.name AS tournament_name
            FROM tournament_matches m
            INNER JOIN tournaments t ON t.id = m.tournament_id
            LEFT JOIN teams ta ON ta.id = m.team_a_id
            LEFT JOIN teams tb ON tb.id = m.team_b_id
            WHERE m.scheduled_at IS NOT NULL
              AND m.status IN ('pending', 'in_progress')
              AND m.team_a_id IS NOT NULL
              AND m.team_b_id IS NOT NULL
              AND m.scheduled_at BETWEEN DATE_SUB(NOW(), INTERVAL 2 HOUR) AND DATE_ADD(NOW(), INTERVAL 30 HOUR)
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function fetchRecentScheduleChanges(): array
    {
        $stmt = $this->db->query("
            SELECT m.id, m.scheduled_at, m.updated_at,
                   ta.name AS team_a_name,
                   tb.name AS team_b_name,
                   t.name AS tournament_name
            FROM tournament_matches m
            INNER JOIN tournaments t ON t.id = m.tournament_id
            LEFT JOIN teams ta ON ta.id = m.team_a_id
            LEFT JOIN teams tb ON tb.id = m.team_b_id
            WHERE m.scheduled_at IS NOT NULL
              AND m.team_a_id IS NOT NULL
              AND m.team_b_id IS NOT NULL
              AND m.status IN ('pending', 'in_progress')
              AND m.updated_at >= DATE_SUB(NOW(), INTERVAL 72 HOUR)
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function fetchRecentResultEvents(): array
    {
        $stmt = $this->db->query("
            SELECT e.id AS event_id, e.match_id, e.event_type, e.created_at,
                   m.score_a, m.score_b, m.status,
                   ta.name AS team_a_name,
                   tb.name AS team_b_name,
                   t.name AS tournament_name
            FROM tournament_match_events e
            INNER JOIN tournament_matches m ON m.id = e.match_id
            INNER JOIN tournaments t ON t.id = m.tournament_id
            LEFT JOIN teams ta ON ta.id = m.team_a_id
            LEFT JOIN teams tb ON tb.id = m.team_b_id
            WHERE e.event_type IN ('score_submitted', 'score_overridden', 'finalized')
              AND e.created_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function fetchTournamentsWithFreeSlots(): array
    {
        $stmt = $this->db->query("
            SELECT t.id, t.name, t.max_teams,
                   COALESCE(tc.teams_count, 0) AS teams_count
            FROM tournaments t
            LEFT JOIN (
                SELECT tournament_id, COUNT(*) AS teams_count
                FROM teams GROUP BY tournament_id
            ) tc ON tc.tournament_id = t.id
            WHERE COALESCE(tc.teams_count, 0) < t.max_teams
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function fetchPendingWaitlistByTournament(int $tournamentId, int $limit = 50): array
    {
        $limit = max(1, $limit);
        $stmt = $this->db->prepare("
            SELECT user_id FROM waitlist_entries
            WHERE tournament_id = ? AND status = 'pending'
            ORDER BY created_at ASC, id ASC
            LIMIT $limit
        ");
        $stmt->execute([$tournamentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findTournamentSummary(int $tournamentId): ?array
    {
        $stmt = $this->db->prepare("SELECT id, name FROM tournaments WHERE id = ? LIMIT 1");
        $stmt->execute([$tournamentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}