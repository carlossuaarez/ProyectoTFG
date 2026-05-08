<?php

require_once __DIR__ . '/../Repositories/TeamRepository.php';
require_once __DIR__ . '/AccessControlService.php';

class TeamService
{
    private PDO $db;
    private TeamRepository $repo;
    private AccessControlService $access;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->repo = new TeamRepository($db);
        $this->access = new AccessControlService($db);
    }

    public function getTournamentTeams(array $user, int $tournamentId, string $accessCode): array
    {
        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0) return $this->result(401, ['error' => 'No autorizado']);
        if ($tournamentId <= 0) return $this->result(400, ['error' => 'ID de torneo no válido']);

        try {
            $tournament = $this->repo->findTournamentById($tournamentId);
            if (!$tournament) return $this->result(404, ['error' => 'Torneo no encontrado']);

            if (!$this->access->canAccessTournament($user, $tournament, $accessCode)) {
                return $this->result(403, [
                    'error' => 'Este torneo es privado. Introduce el código de acceso.',
                    'requires_access_code' => true,
                ]);
            }

            $this->seedAndRefreshAllTeams($tournamentId);

            $teams = $this->repo->fetchTeamsListWithStats($userId, $tournamentId);
            $members = $this->repo->fetchTeamMembersByTournament($tournamentId);

            $membersByTeam = [];
            foreach ($members as $m) {
                $tid = (int)$m['team_id'];
                if (!isset($membersByTeam[$tid])) $membersByTeam[$tid] = [];
                $membersByTeam[$tid][] = [
                    'id' => (int)$m['id'],
                    'user_id' => (int)$m['user_id'],
                    'username' => (string)$m['username'],
                    'avatar_url' => (string)($m['avatar_url'] ?? ''),
                    'role' => (string)$m['role'],
                    'pending_validation' => (int)$m['pending_validation'] === 1,
                    'joined_at' => $m['joined_at'],
                ];
            }

            $resultTeams = [];
            foreach ($teams as $team) {
                $teamId = (int)$team['id'];
                $capacity = max(1, (int)($team['capacity'] ?? 5));
                $validated = (int)($team['validated_members'] ?? 0);
                $pending = (int)($team['pending_members'] ?? 0);
                $status = (string)($team['computed_status'] ?? 'incomplete');
                if (!in_array($status, ['complete', 'incomplete', 'pending_validate'], true)) {
                    $status = 'incomplete';
                }
                $occupancyPercent = (int)round(min(100, ($validated / $capacity) * 100));

                $resultTeams[] = [
                    'id' => $teamId,
                    'tournament_id' => (int)$team['tournament_id'],
                    'name' => (string)$team['name'],
                    'logo_url' => (string)($team['logo_url'] ?? ''),
                    'color_hex' => (string)($team['color_hex'] ?? '#0EA5E9'),
                    'capacity' => $capacity,
                    'status' => $status,
                    'captain_id' => (int)$team['captain_id'],
                    'total_members' => (int)$team['total_members'],
                    'validated_members' => $validated,
                    'pending_members' => $pending,
                    'occupancy_percent' => $occupancyPercent,
                    'registered_at' => $team['registered_at'],
                    'current_user_role' => (string)($team['current_user_role'] ?? ''),
                    'current_user_pending_validation' => (int)$team['current_user_pending_validation'] === 1,
                    'members' => $membersByTeam[$teamId] ?? [],
                ];
            }

            return $this->result(200, [
                'tournament' => [
                    'id' => (int)$tournament['id'],
                    'name' => (string)$tournament['name'],
                    'max_teams' => (int)$tournament['max_teams'],
                    'visibility' => (string)($tournament['visibility'] ?? 'public'),
                    'created_by' => (int)$tournament['created_by'],
                ],
                'teams' => $resultTeams,
                'waitlist_pending' => $this->repo->countWaitlistPending($tournamentId),
            ]);
        } catch (Throwable $e) {
            error_log('getTournamentTeams error: ' . $e->getMessage());
            return $this->result(500, ['error' => 'No se pudieron cargar los equipos']);
        }
    }

    public function createTeamOrWaitlist(array $user, int $tournamentId, array $data, string $accessCode): array
    {
        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0) return $this->result(401, ['error' => 'No autorizado']);
        if ($tournamentId <= 0) return $this->result(400, ['error' => 'ID de torneo no válido']);

        $teamName = trim((string)($data['team_name'] ?? ''));
        $teamLogo = trim((string)($data['team_logo_url'] ?? ''));
        $teamColor = strtoupper(trim((string)($data['team_color'] ?? '#0EA5E9')));
        $capacity = (int)($data['capacity'] ?? 5);

        if ($teamName === '' || mb_strlen($teamName) < 2 || mb_strlen($teamName) > 100) {
            return $this->result(400, ['error' => 'El nombre del equipo debe tener entre 2 y 100 caracteres']);
        }
        if (!preg_match('/^#[0-9A-F]{6}$/', $teamColor)) {
            $teamColor = '#0EA5E9';
        }
        if ($capacity < 3 || $capacity > 15) {
            return $this->result(400, ['error' => 'La capacidad del equipo debe estar entre 3 y 15']);
        }
        if (!$this->isValidLogoUrl($teamLogo)) {
            return $this->result(400, ['error' => 'Logo de equipo no válido']);
        }

        try {
            $this->db->beginTransaction();
            $tournament = $this->repo->findTournamentForUpdate($tournamentId);
            if (!$tournament) { $this->db->rollBack(); return $this->result(404, ['error' => 'Torneo no encontrado']); }

            if (!$this->access->canAccessTournament($user, $tournament, $accessCode)) {
                $this->db->rollBack();
                return $this->result(403, [
                    'error' => 'Este torneo es privado. Introduce el código de acceso.',
                    'requires_access_code' => true,
                ]);
            }

            if ($this->repo->userIsAlreadyMember($tournamentId, $userId)) {
                $this->db->rollBack();
                return $this->result(409, ['error' => 'Ya perteneces a un equipo de este torneo']);
            }
            if ($this->repo->teamNameExistsInTournament($tournamentId, $teamName)) {
                $this->db->rollBack();
                return $this->result(409, ['error' => 'Ya existe un equipo con ese nombre en este torneo']);
            }

            $teamsCount = $this->repo->countTeamsInTournament($tournamentId);

            if ($teamsCount >= (int)$tournament['max_teams']) {
                $existing = $this->repo->findExistingPendingWaitlistForUpdate($tournamentId, $userId);
                if ($existing) {
                    $waitlistId = (int)$existing['id'];
                } else {
                    $waitlistId = $this->repo->insertWaitlistEntry($tournamentId, $userId, $teamName);
                }
                $position = $this->repo->computeWaitlistPosition($tournamentId, $waitlistId);
                $this->db->commit();
                return $this->result(202, [
                    'waitlisted' => true,
                    'message' => 'El torneo está completo. Se añadió tu equipo a la lista de espera.',
                    'waitlist_position' => $position,
                    'team_name' => $teamName,
                ]);
            }

            $teamId = $this->repo->insertTeam(
                $tournamentId, $teamName,
                ($teamLogo !== '' ? $teamLogo : null),
                $teamColor, $userId, $capacity
            );
            $this->repo->insertCaptainMember($teamId, $userId);
            $this->repo->cancelPendingWaitlistForUser($tournamentId, $userId);
            $this->refreshTeamStatus($teamId);

            $this->db->commit();
            return $this->result(201, [
                'waitlisted' => false,
                'message' => 'Equipo creado correctamente',
                'team' => [
                    'id' => $teamId,
                    'tournament_id' => $tournamentId,
                    'name' => $teamName,
                    'logo_url' => $teamLogo,
                    'color_hex' => $teamColor,
                    'capacity' => $capacity,
                    'status' => 'incomplete',
                ],
            ]);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log('createTeamOrWaitlist error: ' . $e->getMessage());
            return $this->result(500, ['error' => 'No se pudo crear el equipo']);
        }
    }

    public function createInvite(array $user, int $teamId, array $data): array
    {
        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0) return $this->result(401, ['error' => 'No autorizado']);
        if ($teamId <= 0) return $this->result(400, ['error' => 'ID de equipo no válido']);

        $maxUses = (int)($data['max_uses'] ?? 25);
        $expiresInDays = (int)($data['expires_in_days'] ?? 7);
        if ($maxUses < 1 || $maxUses > 500) return $this->result(400, ['error' => 'max_uses debe estar entre 1 y 500']);
        if ($expiresInDays < 1 || $expiresInDays > 30) return $this->result(400, ['error' => 'expires_in_days debe estar entre 1 y 30']);

        try {
            $membership = $this->repo->getMembership($teamId, $userId);
            if (!$membership || (int)$membership['pending_validation'] === 1) {
                return $this->result(403, ['error' => 'No perteneces a este equipo o estás pendiente de validar']);
            }
            if (!in_array((string)$membership['role'], ['captain', 'co_captain'], true)) {
                return $this->result(403, ['error' => 'Solo capitán o co-capitán pueden generar invitaciones']);
            }
            if (!$this->repo->teamExists($teamId)) {
                return $this->result(404, ['error' => 'Equipo no encontrado']);
            }

            $inviteCode = $this->generateUniqueInviteCode();
            $expiresAt = (new DateTimeImmutable('+' . $expiresInDays . ' days'))->format('Y-m-d H:i:s');
            $this->repo->insertInvite($teamId, $inviteCode, $userId, $expiresAt, $maxUses);

            $frontendBase = rtrim((string)($_ENV['FRONTEND_BASE_URL'] ?? 'http://localhost:5173'), '/');
            return $this->result(201, [
                'message' => 'Invitación generada',
                'invite_code' => $inviteCode,
                'join_url' => $frontendBase . '/team-invite/' . $inviteCode,
                'expires_at' => $expiresAt,
                'max_uses' => $maxUses,
            ]);
        } catch (Throwable $e) {
            error_log('createInvite error: ' . $e->getMessage());
            return $this->result(500, ['error' => 'No se pudo crear la invitación']);
        }
    }

    public function acceptInvite(int $userId, string $rawCode): array
    {
        if ($userId <= 0) return $this->result(401, ['error' => 'No autorizado']);
        $code = $this->sanitizeInviteCode($rawCode);
        if ($code === '' || strlen($code) < 6) {
            return $this->result(400, ['error' => 'Código de invitación no válido']);
        }

        try {
            $this->db->beginTransaction();
            $invite = $this->repo->findInviteForUpdate($code);
            if (!$invite) { $this->db->rollBack(); return $this->result(404, ['error' => 'Invitación no encontrada']); }
            if ((string)$invite['status'] !== 'active') { $this->db->rollBack(); return $this->result(410, ['error' => 'La invitación no está activa']); }
            if (strtotime((string)$invite['expires_at']) < time()) { $this->db->rollBack(); return $this->result(410, ['error' => 'La invitación ha expirado']); }
            if ((int)$invite['uses_count'] >= (int)$invite['max_uses']) { $this->db->rollBack(); return $this->result(410, ['error' => 'La invitación alcanzó su límite de usos']); }

            $teamId = (int)$invite['team_id'];

            if ($this->repo->userInTeam($teamId, $userId)) {
                $this->db->rollBack();
                return $this->result(409, ['error' => 'Ya perteneces a este equipo']);
            }
            if ($this->repo->countMembersInTeam($teamId) >= (int)$invite['capacity']) {
                $this->db->rollBack();
                return $this->result(409, ['error' => 'El equipo está completo']);
            }

            $this->repo->insertPlayerPending($teamId, $userId);
            $this->repo->incrementInviteUses((int)$invite['id']);
            $this->refreshTeamStatus($teamId);

            $this->db->commit();
            return $this->result(201, [
                'message' => 'Solicitud enviada. Tu entrada al equipo está pendiente de validación.',
                'team_id' => $teamId,
                'tournament_id' => (int)$invite['tournament_id'],
                'pending_validation' => true,
            ]);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log('acceptInvite error: ' . $e->getMessage());
            return $this->result(500, ['error' => 'No se pudo aceptar la invitación']);
        }
    }

    public function validateMember(int $userId, int $teamId, int $memberId): array
    {
        if ($userId <= 0) return $this->result(401, ['error' => 'No autorizado']);
        if ($teamId <= 0 || $memberId <= 0) return $this->result(400, ['error' => 'Parámetros no válidos']);

        try {
            $membership = $this->repo->getMembership($teamId, $userId);
            if (!$membership || (int)$membership['pending_validation'] === 1) {
                return $this->result(403, ['error' => 'No autorizado para validar miembros']);
            }
            if (!in_array((string)$membership['role'], ['captain', 'co_captain'], true)) {
                return $this->result(403, ['error' => 'Solo capitán o co-capitán pueden validar jugadores']);
            }

            $target = $this->repo->findMemberInTeam($memberId, $teamId);
            if (!$target) return $this->result(404, ['error' => 'Miembro no encontrado']);

            if ((int)$target['pending_validation'] === 0) {
                return $this->result(200, ['message' => 'El jugador ya estaba validado']);
            }

            $this->repo->markMemberValidated($memberId);
            $this->refreshTeamStatus($teamId);
            return $this->result(200, ['message' => 'Jugador validado correctamente']);
        } catch (Throwable $e) {
            error_log('validateMember error: ' . $e->getMessage());
            return $this->result(500, ['error' => 'No se pudo validar al jugador']);
        }
    }

    public function updateMemberRole(int $userId, int $teamId, int $memberId, string $newRole): array
    {
        if ($userId <= 0) return $this->result(401, ['error' => 'No autorizado']);
        if ($teamId <= 0 || $memberId <= 0) return $this->result(400, ['error' => 'Parámetros no válidos']);
        if (!in_array($newRole, ['co_captain', 'player'], true)) return $this->result(400, ['error' => 'Rol no válido']);

        try {
            $membership = $this->repo->getMembership($teamId, $userId);
            if (!$membership || (string)$membership['role'] !== 'captain' || (int)$membership['pending_validation'] === 1) {
                return $this->result(403, ['error' => 'Solo el capitán puede cambiar roles']);
            }
            $target = $this->repo->findMemberInTeam($memberId, $teamId);
            if (!$target) return $this->result(404, ['error' => 'Miembro no encontrado']);
            if ((string)$target['role'] === 'captain') {
                return $this->result(400, ['error' => 'No puedes cambiar el rol del capitán']);
            }
            if ((int)$target['pending_validation'] === 1) {
                return $this->result(400, ['error' => 'Primero valida al jugador para cambiar su rol']);
            }

            $this->repo->updateMemberRole($memberId, $newRole);
            return $this->result(200, ['message' => 'Rol actualizado correctamente']);
        } catch (Throwable $e) {
            error_log('updateMemberRole error: ' . $e->getMessage());
            return $this->result(500, ['error' => 'No se pudo actualizar el rol']);
        }
    }

    private function seedAndRefreshAllTeams(int $tournamentId): void
    {
        $this->repo->seedCaptainMembers($tournamentId);
        foreach ($this->repo->fetchTeamIdsByTournament($tournamentId) as $teamId) {
            $this->refreshTeamStatus($teamId);
        }
    }

    private function refreshTeamStatus(int $teamId): void
    {
        $row = $this->repo->fetchTeamCapacityCounts($teamId);
        if (!$row) return;
        $capacity = max(1, (int)$row['capacity']);
        $validated = (int)$row['validated_members'];
        $pending = (int)$row['pending_members'];

        $status = 'incomplete';
        if ($pending > 0) $status = 'pending_validate';
        elseif ($validated >= $capacity) $status = 'complete';

        $this->repo->updateTeamStatus($teamId, $status);
    }

    private function isValidLogoUrl(string $url): bool
    {
        if ($url === '') return true;
        if (mb_strlen($url) > 255) return false;
        if (str_starts_with($url, '/uploads/')) return true;
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    private function generateUniqueInviteCode(): string
    {
        for ($i = 0; $i < 50; $i++) {
            $candidate = $this->generateInviteCode(10);
            if (!$this->repo->inviteCodeExists($candidate)) return $candidate;
        }
        throw new RuntimeException('No se pudo generar un código de invitación único');
    }

    private function generateInviteCode(int $length = 10): string
    {
        $alphabet = 'BCDFGHJKLMNPQRSTVWXYZ23456789';
        $max = strlen($alphabet) - 1;
        $result = '';
        for ($i = 0; $i < $length; $i++) $result .= $alphabet[random_int(0, $max)];
        return $result;
    }

    private function sanitizeInviteCode(string $code): string
    {
        return (string)preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($code)));
    }

    private function result(int $status, array $payload): array
    {
        return ['status' => $status, 'payload' => $payload];
    }
}