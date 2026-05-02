<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class UserDashboardController
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Dashboard completo del usuario autenticado:
     * - perfil + privacidad
     * - stats rápidas
     * - fiabilidad
     * - badges
     * - historial
     */
    public function getMyDashboard(Request $req, Response $res): Response
    {
        $authUser = (array)$req->getAttribute('user');
        $userId = (int)($authUser['id'] ?? 0);

        if ($userId <= 0) {
            return $this->json($res, ['error' => 'No autorizado'], 401);
        }

        $user = $this->getUserById($userId);
        if (!$user) {
            return $this->json($res, ['error' => 'Usuario no encontrado'], 404);
        }

        try {
            $quickStats = $this->buildQuickStats($userId);
            $badges = $this->buildBadges($quickStats);
            $history = $this->buildHistory($userId);

            return $this->json($res, [
                'profile' => [
                    'id' => (int)$user['id'],
                    'username' => (string)$user['username'],
                    'full_name' => (string)($user['full_name'] ?? ''),
                    'email' => (string)$user['email'],
                    'avatar_url' => (string)($user['avatar_url'] ?? ''),
                    'role' => (string)($user['role'] ?? 'user'),
                    'created_at' => $user['created_at'] ?? null,
                    'privacy' => [
                        'show_full_name' => (int)($user['show_full_name'] ?? 0) === 1,
                        'show_contact' => (int)($user['show_contact'] ?? 0) === 1,
                    ],
                    'public_preview' => [
                        'display_name' => ((int)($user['show_full_name'] ?? 0) === 1 && trim((string)($user['full_name'] ?? '')) !== '')
                            ? (string)$user['full_name']
                            : '@' . (string)$user['username'],
                        'contact' => ((int)($user['show_contact'] ?? 0) === 1)
                            ? (string)$user['email']
                            : null
                    ]
                ],
                'quick_stats' => $quickStats,
                'badges' => $badges,
                'history' => $history
            ]);
        } catch (Throwable $e) {
            error_log('getMyDashboard error: ' . $e->getMessage());
            return $this->json($res, ['error' => 'No se pudo cargar el dashboard de usuario'], 500);
        }
    }

    /**
     * Actualizar privacidad simple del usuario autenticado.
     */
    public function updateMyPrivacy(Request $req, Response $res): Response
    {
        $authUser = (array)$req->getAttribute('user');
        $userId = (int)($authUser['id'] ?? 0);

        if ($userId <= 0) {
            return $this->json($res, ['error' => 'No autorizado'], 401);
        }

        $user = $this->getUserById($userId);
        if (!$user) {
            return $this->json($res, ['error' => 'Usuario no encontrado'], 404);
        }

        $data = (array)$req->getParsedBody();

        $hasShowFullName = array_key_exists('show_full_name', $data);
        $hasShowContact = array_key_exists('show_contact', $data);

        if (!$hasShowFullName && !$hasShowContact) {
            return $this->json($res, ['error' => 'No se recibieron cambios de privacidad'], 400);
        }

        $showFullName = $hasShowFullName
            ? $this->toBoolInt($data['show_full_name'])
            : (int)($user['show_full_name'] ?? 0);

        $showContact = $hasShowContact
            ? $this->toBoolInt($data['show_contact'])
            : (int)($user['show_contact'] ?? 0);

        try {
            $stmt = $this->db->prepare("
                UPDATE users
                SET show_full_name = ?, show_contact = ?
                WHERE id = ?
            ");
            $stmt->execute([$showFullName, $showContact, $userId]);

            $updated = $this->getUserById($userId);

            return $this->json($res, [
                'message' => 'Privacidad actualizada correctamente',
                'privacy' => [
                    'show_full_name' => (int)($updated['show_full_name'] ?? 0) === 1,
                    'show_contact' => (int)($updated['show_contact'] ?? 0) === 1,
                ],
                'public_preview' => [
                    'display_name' => ((int)($updated['show_full_name'] ?? 0) === 1 && trim((string)($updated['full_name'] ?? '')) !== '')
                        ? (string)$updated['full_name']
                        : '@' . (string)$updated['username'],
                    'contact' => ((int)($updated['show_contact'] ?? 0) === 1)
                        ? (string)$updated['email']
                        : null
                ]
            ]);
        } catch (Throwable $e) {
            error_log('updateMyPrivacy error: ' . $e->getMessage());
            return $this->json($res, ['error' => 'No se pudo actualizar la privacidad'], 500);
        }
    }

    /**
     * Perfil público por username (respeta privacidad simple).
     * Útil para futuras vistas públicas.
     */
    public function getPublicProfile(Request $req, Response $res, array $args): Response
    {
        $username = trim((string)($args['username'] ?? ''));
        if ($username === '' || !preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
            return $this->json($res, ['error' => 'Username no válido'], 400);
        }

        $stmt = $this->db->prepare("
            SELECT id, username, full_name, email, avatar_url, role, created_at, show_full_name, show_contact
            FROM users
            WHERE LOWER(username) = LOWER(?)
            LIMIT 1
        ");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return $this->json($res, ['error' => 'Usuario no encontrado'], 404);
        }

        try {
            $stats = $this->buildQuickStats((int)$user['id']);
            $badges = $this->buildBadges($stats);

            return $this->json($res, [
                'user' => [
                    'username' => (string)$user['username'],
                    'display_name' => ((int)($user['show_full_name'] ?? 0) === 1 && trim((string)($user['full_name'] ?? '')) !== '')
                        ? (string)$user['full_name']
                        : '@' . (string)$user['username'],
                    'contact' => ((int)($user['show_contact'] ?? 0) === 1)
                        ? (string)$user['email']
                        : null,
                    'avatar_url' => (string)($user['avatar_url'] ?? ''),
                    'role' => (string)($user['role'] ?? 'user'),
                    'created_at' => $user['created_at'] ?? null
                ],
                'quick_stats' => [
                    'teams_count' => (int)$stats['teams_count'],
                    'tournaments_played' => (int)$stats['tournaments_played'],
                    'tournaments_won' => (int)$stats['tournaments_won'],
                    'attendance_pct' => (int)$stats['attendance_pct'],
                    'no_shows' => (int)$stats['no_shows'],
                    'active_sanctions' => (int)$stats['active_sanctions']
                ],
                'badges' => $badges
            ]);
        } catch (Throwable $e) {
            error_log('getPublicProfile error: ' . $e->getMessage());
            return $this->json($res, ['error' => 'No se pudo cargar el perfil público'], 500);
        }
    }

    // -------------------
    // Internal builders
    // -------------------

    private function buildQuickStats(int $userId): array
    {
        $teamsCount = $this->scalar("
            SELECT COUNT(DISTINCT tm.team_id)
            FROM team_members tm
            WHERE tm.user_id = ?
        ", [$userId]);

        $tournamentsPlayed = $this->scalar("
            SELECT COUNT(DISTINCT t.id)
            FROM team_members tm
            INNER JOIN teams te ON te.id = tm.team_id
            INNER JOIN tournaments t ON t.id = te.tournament_id
            WHERE tm.user_id = ?
              AND t.start_date <= CURDATE()
        ", [$userId]);

        $tournamentsWon = $this->scalar("
            SELECT COUNT(*)
            FROM tournament_user_results r
            WHERE r.user_id = ?
              AND (r.result = 'winner' OR r.final_position = 1)
        ", [$userId]);

        $attendanceStmt = $this->db->prepare("
            SELECT
              COALESCE(SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END), 0) AS present_count,
              COALESCE(SUM(CASE WHEN status = 'no_show' THEN 1 ELSE 0 END), 0) AS no_show_count
            FROM user_attendance_records
            WHERE user_id = ?
        ");
        $attendanceStmt->execute([$userId]);
        $attendance = $attendanceStmt->fetch(PDO::FETCH_ASSOC) ?: ['present_count' => 0, 'no_show_count' => 0];

        $presentCount = (int)$attendance['present_count'];
        $noShowCount = (int)$attendance['no_show_count'];
        $attendanceTotal = $presentCount + $noShowCount;
        $attendancePct = $attendanceTotal > 0
            ? (int)round(($presentCount / $attendanceTotal) * 100)
            : 100;

        $sanctionsStmt = $this->db->prepare("
            SELECT
              COALESCE(SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END), 0) AS active_count,
              COUNT(*) AS total_count
            FROM user_sanctions
            WHERE user_id = ?
        ");
        $sanctionsStmt->execute([$userId]);
        $sanctions = $sanctionsStmt->fetch(PDO::FETCH_ASSOC) ?: ['active_count' => 0, 'total_count' => 0];

        $createdTournaments = $this->scalar("
            SELECT COUNT(*) FROM tournaments WHERE created_by = ?
        ", [$userId]);

        $captainTeams = $this->scalar("
            SELECT COUNT(*)
            FROM team_members
            WHERE user_id = ?
              AND role = 'captain'
              AND pending_validation = 0
        ", [$userId]);

        $top8Count = $this->scalar("
            SELECT COUNT(*)
            FROM tournament_user_results r
            WHERE r.user_id = ?
              AND (
                (r.final_position IS NOT NULL AND r.final_position <= 8)
                OR r.result IN ('top8','winner')
              )
        ", [$userId]);

        return [
            'teams_count' => $teamsCount,
            'tournaments_played' => $tournamentsPlayed,
            'tournaments_won' => $tournamentsWon,
            'attendance_pct' => max(0, min(100, $attendancePct)),
            'attendances' => $presentCount,
            'no_shows' => $noShowCount,
            'active_sanctions' => (int)$sanctions['active_count'],
            'total_sanctions' => (int)$sanctions['total_count'],
            'created_tournaments' => $createdTournaments,
            'captain_teams' => $captainTeams,
            'top8_finishes' => $top8Count
        ];
    }

    private function buildBadges(array $quickStats): array
    {
        $badges = [];

        if ((int)($quickStats['captain_teams'] ?? 0) >= 2) {
            $badges[] = [
                'key' => 'captain-active',
                'label' => 'Capitán activo',
                'tone' => 'blue'
            ];
        }

        if ((int)($quickStats['created_tournaments'] ?? 0) >= 1) {
            $badges[] = [
                'key' => 'organizer',
                'label' => 'Organizador',
                'tone' => 'violet'
            ];
        }

        if ((int)($quickStats['top8_finishes'] ?? 0) >= 1) {
            $badges[] = [
                'key' => 'top8',
                'label' => 'Top 8',
                'tone' => 'amber'
            ];
        }

        return $badges;
    }

    private function buildHistory(int $userId): array
    {
        $stmtRecent = $this->db->prepare("
            SELECT
                t.id,
                t.name,
                t.game,
                t.start_date,
                t.start_time,
                tm.role AS team_role,
                te.name AS team_name,
                COALESCE(t.visibility, 'public') AS visibility
            FROM team_members tm
            INNER JOIN teams te ON te.id = tm.team_id
            INNER JOIN tournaments t ON t.id = te.tournament_id
            WHERE tm.user_id = ?
            ORDER BY t.start_date DESC, t.start_time DESC, t.id DESC
            LIMIT 8
        ");
        $stmtRecent->execute([$userId]);
        $recentTournaments = $stmtRecent->fetchAll(PDO::FETCH_ASSOC);

        $stmtWon = $this->db->prepare("
            SELECT
                t.id,
                t.name,
                t.game,
                t.start_date,
                t.start_time,
                r.final_position,
                r.result
            FROM tournament_user_results r
            INNER JOIN tournaments t ON t.id = r.tournament_id
            WHERE r.user_id = ?
              AND (r.result = 'winner' OR r.final_position = 1)
            ORDER BY t.start_date DESC, t.start_time DESC, t.id DESC
            LIMIT 8
        ");
        $stmtWon->execute([$userId]);
        $wonTournaments = $stmtWon->fetchAll(PDO::FETCH_ASSOC);

        $stmtTeams = $this->db->prepare("
            SELECT
                te.id,
                te.name,
                te.logo_url,
                te.color_hex,
                te.team_status,
                te.capacity,
                tm.role,
                tm.pending_validation,
                t.id AS tournament_id,
                t.name AS tournament_name
            FROM team_members tm
            INNER JOIN teams te ON te.id = tm.team_id
            INNER JOIN tournaments t ON t.id = te.tournament_id
            WHERE tm.user_id = ?
            ORDER BY tm.joined_at DESC, te.id DESC
            LIMIT 12
        ");
        $stmtTeams->execute([$userId]);
        $teams = $stmtTeams->fetchAll(PDO::FETCH_ASSOC);

        return [
            'recent_tournaments' => $recentTournaments,
            'won_tournaments' => $wonTournaments,
            'teams' => $teams
        ];
    }

    private function getUserById(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT
                id,
                username,
                full_name,
                email,
                avatar_url,
                role,
                created_at,
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

    private function scalar(string $sql, array $params = []): int
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)($stmt->fetchColumn() ?: 0);
    }

    private function toBoolInt($value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_int($value) || is_float($value)) {
            return ((int)$value) === 1 ? 1 : 0;
        }

        $s = strtolower(trim((string)$value));
        if (in_array($s, ['1', 'true', 'yes', 'on'], true)) return 1;
        if (in_array($s, ['0', 'false', 'no', 'off'], true)) return 0;

        return 0;
    }

    private function json(Response $res, array $payload, int $status = 200): Response
    {
        $res->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $res->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}