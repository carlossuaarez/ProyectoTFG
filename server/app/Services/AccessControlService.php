<?php

use Psr\Http\Message\ServerRequestInterface as Request;

class AccessControlService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function isAdminOrOwner(array $user, array $tournament): bool
    {
        $role = (string)($user['role'] ?? '');
        if ($role === 'admin') {
            return true;
        }
        $userId = (int)($user['id'] ?? 0);
        return $userId > 0 && $userId === (int)($tournament['created_by'] ?? 0);
    }

    public function userBelongsToTournament(int $userId, int $tournamentId): bool
    {
        $stmt = $this->db->prepare("
            SELECT tm.id
            FROM team_members tm
            INNER JOIN teams t ON t.id = tm.team_id
            WHERE t.tournament_id = ?
              AND tm.user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$tournamentId, $userId]);
        if ($stmt->fetch(PDO::FETCH_ASSOC)) {
            return true;
        }

        $legacy = $this->db->prepare("
            SELECT id FROM teams
            WHERE tournament_id = ?
              AND captain_id = ?
            LIMIT 1
        ");
        $legacy->execute([$tournamentId, $userId]);
        return (bool)$legacy->fetch(PDO::FETCH_ASSOC);
    }

    public function canAccessTournament(array $user, array $tournament, string $accessCode = ''): bool
    {
        $visibility = (string)($tournament['visibility'] ?? 'public');
        if ($visibility !== 'private') {
            return true;
        }

        if ($this->isAdminOrOwner($user, $tournament)) {
            return true;
        }

        $userId = (int)($user['id'] ?? 0);
        if ($userId > 0 && $this->userBelongsToTournament($userId, (int)$tournament['id'])) {
            return true;
        }

        $hash = (string)($tournament['access_code_hash'] ?? '');
        if ($hash === '' && isset($tournament['tournament_access_code_hash'])) {
            $hash = (string)$tournament['tournament_access_code_hash'];
        }
        return self::verifyAccessCode($accessCode, $hash);
    }

    public static function sanitizeAccessCode(string $code): string
    {
        return (string)preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($code)));
    }

    public static function verifyAccessCode(string $candidate, string $hash): bool
    {
        if ($candidate === '' || $hash === '') {
            return false;
        }
        return password_verify($candidate, $hash);
    }

    public static function resolveAccessCodeFromRequest(Request $req, array $body = []): string
    {
        if (empty($body)) {
            $parsed = $req->getParsedBody();
            $body = is_array($parsed) ? $parsed : [];
        }

        $fromBody = self::sanitizeAccessCode((string)($body['access_code'] ?? ''));
        if ($fromBody !== '') {
            return $fromBody;
        }

        $fromHeader = self::sanitizeAccessCode($req->getHeaderLine('X-Tournament-Code'));
        if ($fromHeader !== '') {
            return $fromHeader;
        }

        return self::sanitizeAccessCode((string)($req->getQueryParams()['code'] ?? ''));
    }
}