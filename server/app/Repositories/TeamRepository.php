<?php

class TeamRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function findTournamentById(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, name, max_teams, COALESCE(visibility, 'public') AS visibility,
                   access_code_hash, created_by
            FROM tournaments
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findTournamentForUpdate(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, name, max_teams, COALESCE(visibility, 'public') AS visibility,
                   access_code_hash, created_by
            FROM tournaments
            WHERE id = ?
            FOR UPDATE
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function fetchTeamsListWithStats(int $userId, int $tournamentId): array
    {
        $stmt = $this->db->prepare("
            SELECT
                t.id, t.tournament_id, t.name, t.logo_url, t.color_hex,
                t.capacity, t.team_status, t.captain_id, t.registered_at,
                COALESCE(m.total_members, 0) AS total_members,
                COALESCE(m.validated_members, 0) AS validated_members,
                COALESCE(m.pending_members, 0) AS pending_members,
                CASE
                    WHEN COALESCE(m.pending_members, 0) > 0 THEN 'pending_validate'
                    WHEN COALESCE(m.validated_members, 0) >= t.capacity THEN 'complete'
                    ELSE 'incomplete'
                END AS computed_status,
                my.role AS current_user_role,
                COALESCE(my.pending_validation, 0) AS current_user_pending_validation
            FROM teams t
            LEFT JOIN (
                SELECT team_id, COUNT(*) AS total_members,
                       SUM(CASE WHEN pending_validation = 0 THEN 1 ELSE 0 END) AS validated_members,
                       SUM(CASE WHEN pending_validation = 1 THEN 1 ELSE 0 END) AS pending_members
                FROM team_members
                GROUP BY team_id
            ) m ON m.team_id = t.id
            LEFT JOIN team_members my
                ON my.team_id = t.id AND my.user_id = ?
            WHERE t.tournament_id = ?
            ORDER BY t.registered_at ASC, t.id ASC
        ");
        $stmt->execute([$userId, $tournamentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function fetchTeamMembersByTournament(int $tournamentId): array
    {
        $stmt = $this->db->prepare("
            SELECT tm.id, tm.team_id, tm.user_id, tm.role, tm.pending_validation,
                   tm.joined_at, u.username, u.avatar_url
            FROM team_members tm
            INNER JOIN teams t ON t.id = tm.team_id
            INNER JOIN users u ON u.id = tm.user_id
            WHERE t.tournament_id = ?
            ORDER BY tm.team_id ASC,
                CASE tm.role
                    WHEN 'captain' THEN 1
                    WHEN 'co_captain' THEN 2
                    ELSE 3
                END ASC,
                tm.joined_at ASC
        ");
        $stmt->execute([$tournamentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countWaitlistPending(int $tournamentId): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM waitlist_entries
            WHERE tournament_id = ? AND status = 'pending'
        ");
        $stmt->execute([$tournamentId]);
        return (int)$stmt->fetchColumn();
    }

    public function userIsAlreadyMember(int $tournamentId, int $userId): bool
    {
        $stmt = $this->db->prepare("
            SELECT tm.id FROM team_members tm
            INNER JOIN teams t ON t.id = tm.team_id
            WHERE t.tournament_id = ? AND tm.user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$tournamentId, $userId]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function teamNameExistsInTournament(int $tournamentId, string $teamName): bool
    {
        $stmt = $this->db->prepare("
            SELECT id FROM teams
            WHERE tournament_id = ? AND LOWER(name) = LOWER(?)
            LIMIT 1
        ");
        $stmt->execute([$tournamentId, $teamName]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function countTeamsInTournament(int $tournamentId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM teams WHERE tournament_id = ?");
        $stmt->execute([$tournamentId]);
        return (int)$stmt->fetchColumn();
    }

    public function findExistingPendingWaitlistForUpdate(int $tournamentId, int $userId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id FROM waitlist_entries
            WHERE tournament_id = ? AND user_id = ? AND status = 'pending'
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([$tournamentId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function insertWaitlistEntry(int $tournamentId, int $userId, string $teamName): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO waitlist_entries (tournament_id, user_id, team_name, status)
            VALUES (?, ?, ?, 'pending')
        ");
        $stmt->execute([$tournamentId, $userId, $teamName]);
        return (int)$this->db->lastInsertId();
    }

    public function computeWaitlistPosition(int $tournamentId, int $waitlistId): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) AS pos FROM waitlist_entries
            WHERE tournament_id = ? AND status = 'pending' AND id <= ?
        ");
        $stmt->execute([$tournamentId, $waitlistId]);
        return (int)$stmt->fetchColumn();
    }

    public function insertTeam(int $tournamentId, string $name, ?string $logoUrl, string $colorHex, int $captainId, int $capacity): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO teams (tournament_id, name, logo_url, color_hex, captain_id, capacity, team_status)
            VALUES (?, ?, ?, ?, ?, ?, 'incomplete')
        ");
        $stmt->execute([$tournamentId, $name, $logoUrl, $colorHex, $captainId, $capacity]);
        return (int)$this->db->lastInsertId();
    }

    public function insertCaptainMember(int $teamId, int $userId): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO team_members (team_id, user_id, role, pending_validation)
            VALUES (?, ?, 'captain', 0)
        ");
        $stmt->execute([$teamId, $userId]);
    }

    public function cancelPendingWaitlistForUser(int $tournamentId, int $userId): void
    {
        $stmt = $this->db->prepare("
            UPDATE waitlist_entries
            SET status = 'cancelled'
            WHERE tournament_id = ? AND user_id = ? AND status = 'pending'
        ");
        $stmt->execute([$tournamentId, $userId]);
    }

    public function seedCaptainMembers(int $tournamentId): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO team_members (team_id, user_id, role, pending_validation)
            SELECT t.id, t.captain_id, 'captain', 0
            FROM teams t
            LEFT JOIN team_members tm ON tm.team_id = t.id AND tm.user_id = t.captain_id
            WHERE t.tournament_id = ? AND tm.id IS NULL
        ");
        $stmt->execute([$tournamentId]);
    }

    public function fetchTeamIdsByTournament(int $tournamentId): array
    {
        $stmt = $this->db->prepare("SELECT id FROM teams WHERE tournament_id = ?");
        $stmt->execute([$tournamentId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function fetchTeamCapacityCounts(int $teamId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT t.capacity,
                   COALESCE(SUM(CASE WHEN tm.pending_validation = 0 THEN 1 ELSE 0 END), 0) AS validated_members,
                   COALESCE(SUM(CASE WHEN tm.pending_validation = 1 THEN 1 ELSE 0 END), 0) AS pending_members
            FROM teams t
            LEFT JOIN team_members tm ON tm.team_id = t.id
            WHERE t.id = ?
            GROUP BY t.id, t.capacity
            LIMIT 1
        ");
        $stmt->execute([$teamId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function updateTeamStatus(int $teamId, string $status): void
    {
        $stmt = $this->db->prepare("UPDATE teams SET team_status = ? WHERE id = ?");
        $stmt->execute([$status, $teamId]);
    }

    public function getMembership(int $teamId, int $userId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, team_id, user_id, role, pending_validation
            FROM team_members
            WHERE team_id = ? AND user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$teamId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function teamExists(int $teamId): bool
    {
        $stmt = $this->db->prepare("SELECT id FROM teams WHERE id = ? LIMIT 1");
        $stmt->execute([$teamId]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function inviteCodeExists(string $code): bool
    {
        $stmt = $this->db->prepare("SELECT id FROM team_invites WHERE invite_code = ? LIMIT 1");
        $stmt->execute([$code]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function insertInvite(int $teamId, string $code, int $createdBy, string $expiresAt, int $maxUses): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO team_invites (team_id, invite_code, created_by, status, expires_at, max_uses, uses_count)
            VALUES (?, ?, ?, 'active', ?, ?, 0)
        ");
        $stmt->execute([$teamId, $code, $createdBy, $expiresAt, $maxUses]);
        return (int)$this->db->lastInsertId();
    }

    public function findInviteForUpdate(string $code): ?array
    {
        $stmt = $this->db->prepare("
            SELECT i.id, i.team_id, i.invite_code, i.status, i.expires_at,
                   i.max_uses, i.uses_count, t.tournament_id, t.capacity
            FROM team_invites i
            INNER JOIN teams t ON t.id = i.team_id
            WHERE i.invite_code = ?
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([$code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function userInTeam(int $teamId, int $userId): bool
    {
        $stmt = $this->db->prepare("
            SELECT id FROM team_members
            WHERE team_id = ? AND user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$teamId, $userId]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function countMembersInTeam(int $teamId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM team_members WHERE team_id = ?");
        $stmt->execute([$teamId]);
        return (int)$stmt->fetchColumn();
    }

    public function insertPlayerPending(int $teamId, int $userId): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO team_members (team_id, user_id, role, pending_validation)
            VALUES (?, ?, 'player', 1)
        ");
        $stmt->execute([$teamId, $userId]);
    }

    public function incrementInviteUses(int $inviteId): void
    {
        $stmt = $this->db->prepare("UPDATE team_invites SET uses_count = uses_count + 1 WHERE id = ?");
        $stmt->execute([$inviteId]);
    }

    public function findMemberInTeam(int $memberId, int $teamId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, team_id, user_id, role, pending_validation
            FROM team_members
            WHERE id = ? AND team_id = ?
            LIMIT 1
        ");
        $stmt->execute([$memberId, $teamId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function markMemberValidated(int $memberId): void
    {
        $stmt = $this->db->prepare("UPDATE team_members SET pending_validation = 0 WHERE id = ?");
        $stmt->execute([$memberId]);
    }

    public function updateMemberRole(int $memberId, string $role): void
    {
        $stmt = $this->db->prepare("UPDATE team_members SET role = ? WHERE id = ?");
        $stmt->execute([$role, $memberId]);
    }
}