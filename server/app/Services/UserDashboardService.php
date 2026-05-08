<?php

require_once __DIR__ . '/../Repositories/UserDashboardRepository.php';

class UserDashboardService
{
    private UserDashboardRepository $repo;

    public function __construct(PDO $db)
    {
        $this->repo = new UserDashboardRepository($db);
    }

    public function getMyDashboard(int $userId): array
    {
        if ($userId <= 0) {
            return $this->result(401, ['error' => 'No autorizado']);
        }

        $user = $this->repo->findById($userId);
        if (!$user) {
            return $this->result(404, ['error' => 'Usuario no encontrado']);
        }

        try {
            $quickStats = $this->buildQuickStats($userId);
            return $this->result(200, [
                'profile' => $this->buildProfile($user),
                'quick_stats' => $quickStats,
                'badges' => $this->buildBadges($quickStats),
                'history' => $this->buildHistory($userId),
            ]);
        } catch (Throwable $e) {
            error_log('getMyDashboard error: ' . $e->getMessage());
            return $this->result(500, ['error' => 'No se pudo cargar el dashboard de usuario']);
        }
    }

    public function updateMyPrivacy(int $userId, array $data): array
    {
        if ($userId <= 0) return $this->result(401, ['error' => 'No autorizado']);

        $user = $this->repo->findById($userId);
        if (!$user) return $this->result(404, ['error' => 'Usuario no encontrado']);

        $hasShowFullName = array_key_exists('show_full_name', $data);
        $hasShowContact = array_key_exists('show_contact', $data);

        if (!$hasShowFullName && !$hasShowContact) {
            return $this->result(400, ['error' => 'No se recibieron cambios de privacidad']);
        }

        $showFullName = $hasShowFullName
            ? $this->toBoolInt($data['show_full_name'])
            : (int)($user['show_full_name'] ?? 0);
        $showContact = $hasShowContact
            ? $this->toBoolInt($data['show_contact'])
            : (int)($user['show_contact'] ?? 0);

        try {
            $this->repo->updatePrivacy($userId, $showFullName, $showContact);
            $updated = $this->repo->findById($userId);

            return $this->result(200, [
                'message' => 'Privacidad actualizada correctamente',
                'privacy' => [
                    'show_full_name' => (int)($updated['show_full_name'] ?? 0) === 1,
                    'show_contact' => (int)($updated['show_contact'] ?? 0) === 1,
                ],
                'public_preview' => $this->buildPublicPreview($updated),
            ]);
        } catch (Throwable $e) {
            error_log('updateMyPrivacy error: ' . $e->getMessage());
            return $this->result(500, ['error' => 'No se pudo actualizar la privacidad']);
        }
    }

    public function getPublicProfile(string $username): array
    {
        $username = trim($username);
        if ($username === '' || !preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
            return $this->result(400, ['error' => 'Username no válido']);
        }

        $user = $this->repo->findByUsername($username);
        if (!$user) return $this->result(404, ['error' => 'Usuario no encontrado']);

        try {
            $stats = $this->buildQuickStats((int)$user['id']);
            return $this->result(200, [
                'user' => [
                    'username' => (string)$user['username'],
                    'display_name' => $this->displayNameFor($user),
                    'contact' => ((int)($user['show_contact'] ?? 0) === 1) ? (string)$user['email'] : null,
                    'avatar_url' => (string)($user['avatar_url'] ?? ''),
                    'role' => (string)($user['role'] ?? 'user'),
                    'created_at' => $user['created_at'] ?? null,
                ],
                'quick_stats' => [
                    'teams_count' => (int)$stats['teams_count'],
                    'tournaments_played' => (int)$stats['tournaments_played'],
                    'tournaments_won' => (int)$stats['tournaments_won'],
                    'attendance_pct' => (int)$stats['attendance_pct'],
                    'no_shows' => (int)$stats['no_shows'],
                    'active_sanctions' => (int)$stats['active_sanctions'],
                ],
                'badges' => $this->buildBadges($stats),
            ]);
        } catch (Throwable $e) {
            error_log('getPublicProfile error: ' . $e->getMessage());
            return $this->result(500, ['error' => 'No se pudo cargar el perfil público']);
        }
    }

    private function buildProfile(array $user): array
    {
        return [
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
            'public_preview' => $this->buildPublicPreview($user),
        ];
    }

    private function buildPublicPreview(array $user): array
    {
        return [
            'display_name' => $this->displayNameFor($user),
            'contact' => ((int)($user['show_contact'] ?? 0) === 1)
                ? (string)$user['email']
                : null,
        ];
    }

    private function displayNameFor(array $user): string
    {
        if ((int)($user['show_full_name'] ?? 0) === 1
            && trim((string)($user['full_name'] ?? '')) !== '') {
            return (string)$user['full_name'];
        }
        return '@' . (string)$user['username'];
    }

    private function buildQuickStats(int $userId): array
    {
        $attendance = $this->repo->fetchAttendanceCounts($userId);
        $present = $attendance['present_count'];
        $noShow = $attendance['no_show_count'];
        $attTotal = $present + $noShow;
        $attPct = $attTotal > 0 ? (int)round(($present / $attTotal) * 100) : 100;

        $sanctions = $this->repo->fetchSanctionCounts($userId);

        return [
            'teams_count' => $this->repo->countDistinctTeams($userId),
            'tournaments_played' => $this->repo->countTournamentsPlayed($userId),
            'tournaments_won' => $this->repo->countTournamentsWon($userId),
            'attendance_pct' => max(0, min(100, $attPct)),
            'attendances' => $present,
            'no_shows' => $noShow,
            'active_sanctions' => $sanctions['active_count'],
            'total_sanctions' => $sanctions['total_count'],
            'created_tournaments' => $this->repo->countCreatedTournaments($userId),
            'captain_teams' => $this->repo->countCaptainTeams($userId),
            'top8_finishes' => $this->repo->countTop8Finishes($userId),
        ];
    }

    private function buildBadges(array $stats): array
    {
        $badges = [];
        if ((int)($stats['captain_teams'] ?? 0) >= 2) {
            $badges[] = ['key' => 'captain-active', 'label' => 'Capitán activo', 'tone' => 'blue'];
        }
        if ((int)($stats['created_tournaments'] ?? 0) >= 1) {
            $badges[] = ['key' => 'organizer', 'label' => 'Organizador', 'tone' => 'violet'];
        }
        if ((int)($stats['top8_finishes'] ?? 0) >= 1) {
            $badges[] = ['key' => 'top8', 'label' => 'Top 8', 'tone' => 'amber'];
        }
        return $badges;
    }

    private function buildHistory(int $userId): array
    {
        return [
            'recent_tournaments' => $this->repo->fetchRecentTournaments($userId, 8),
            'won_tournaments' => $this->repo->fetchWonTournaments($userId, 8),
            'teams' => $this->repo->fetchUserTeams($userId, 12),
        ];
    }

    private function toBoolInt($value): int
    {
        if (is_bool($value)) return $value ? 1 : 0;
        if (is_int($value) || is_float($value)) return ((int)$value) === 1 ? 1 : 0;

        $s = strtolower(trim((string)$value));
        if (in_array($s, ['1', 'true', 'yes', 'on'], true)) return 1;
        if (in_array($s, ['0', 'false', 'no', 'off'], true)) return 0;
        return 0;
    }

    private function result(int $status, array $payload): array
    {
        return ['status' => $status, 'payload' => $payload];
    }
}