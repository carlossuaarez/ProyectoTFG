<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class TeamController
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Listado de equipos de un torneo con:
     * - miembros y roles
     * - estado complete/incomplete/pending_validate
     * - plazas ocupadas
     */
    public function getTournamentTeams(Request $req, Response $res, array $args): Response
    {
        $user = (array)$req->getAttribute('user');
        $userId = (int)($user['id'] ?? 0);
        $tournamentId = (int)($args['id'] ?? 0);

        if ($userId <= 0) {
            return $this->json($res, ['error' => 'No autorizado'], 401);
        }

        if ($tournamentId <= 0) {
            return $this->json($res, ['error' => 'ID de torneo no válido'], 400);
        }

        try {
            $tournament = $this->getTournamentById($tournamentId);
            if (!$tournament) {
                return $this->json($res, ['error' => 'Torneo no encontrado'], 404);
            }

            // Privados: admin/owner/miembro o código válido
            $accessCode = $this->resolveAccessCodeFromRequest($req);
            if (!$this->canAccessTournament($user, $tournament, $accessCode)) {
                return $this->json($res, [
                    'error' => 'Este torneo es privado. Introduce el código de acceso.',
                    'requires_access_code' => true
                ], 403);
            }

            $this->seedCaptainMembers($tournamentId);

            $stmtTeams = $this->db->prepare("
                SELECT
                    t.id,
                    t.tournament_id,
                    t.name,
                    t.logo_url,
                    t.color_hex,
                    t.capacity,
                    t.team_status,
                    t.captain_id,
                    t.registered_at,
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
                    SELECT
                        team_id,
                        COUNT(*) AS total_members,
                        SUM(CASE WHEN pending_validation = 0 THEN 1 ELSE 0 END) AS validated_members,
                        SUM(CASE WHEN pending_validation = 1 THEN 1 ELSE 0 END) AS pending_members
                    FROM team_members
                    GROUP BY team_id
                ) m ON m.team_id = t.id
                LEFT JOIN team_members my
                    ON my.team_id = t.id
                    AND my.user_id = ?
                WHERE t.tournament_id = ?
                ORDER BY t.registered_at ASC, t.id ASC
            ");
            $stmtTeams->execute([$userId, $tournamentId]);
            $teams = $stmtTeams->fetchAll(PDO::FETCH_ASSOC);

            $stmtMembers = $this->db->prepare("
                SELECT
                    tm.id,
                    tm.team_id,
                    tm.user_id,
                    tm.role,
                    tm.pending_validation,
                    tm.joined_at,
                    u.username,
                    u.avatar_url
                FROM team_members tm
                INNER JOIN teams t ON t.id = tm.team_id
                INNER JOIN users u ON u.id = tm.user_id
                WHERE t.tournament_id = ?
                ORDER BY
                    tm.team_id ASC,
                    CASE tm.role
                        WHEN 'captain' THEN 1
                        WHEN 'co_captain' THEN 2
                        ELSE 3
                    END ASC,
                    tm.joined_at ASC
            ");
            $stmtMembers->execute([$tournamentId]);
            $members = $stmtMembers->fetchAll(PDO::FETCH_ASSOC);

            $membersByTeam = [];
            foreach ($members as $member) {
                $tid = (int)$member['team_id'];
                if (!isset($membersByTeam[$tid])) {
                    $membersByTeam[$tid] = [];
                }
                $membersByTeam[$tid][] = [
                    'id' => (int)$member['id'],
                    'user_id' => (int)$member['user_id'],
                    'username' => (string)$member['username'],
                    'avatar_url' => (string)($member['avatar_url'] ?? ''),
                    'role' => (string)$member['role'],
                    'pending_validation' => (int)$member['pending_validation'] === 1,
                    'joined_at' => $member['joined_at'],
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

            $stmtWaitlist = $this->db->prepare("
                SELECT COUNT(*) FROM waitlist_entries
                WHERE tournament_id = ? AND status = 'pending'
            ");
            $stmtWaitlist->execute([$tournamentId]);
            $waitlistPending = (int)$stmtWaitlist->fetchColumn();

            return $this->json($res, [
                'tournament' => [
                    'id' => (int)$tournament['id'],
                    'name' => (string)$tournament['name'],
                    'max_teams' => (int)$tournament['max_teams'],
                    'visibility' => (string)($tournament['visibility'] ?? 'public'),
                    'created_by' => (int)$tournament['created_by'],
                ],
                'teams' => $resultTeams,
                'waitlist_pending' => $waitlistPending
            ]);
        } catch (Throwable $e) {
            error_log('getTournamentTeams error: ' . $e->getMessage());
            return $this->json($res, ['error' => 'No se pudieron cargar los equipos'], 500);
        }
    }

    /**
     * Crear equipo en torneo.
     * Si está lleno -> entra automáticamente a lista de espera.
     */
    public function createTeamOrWaitlist(Request $req, Response $res, array $args): Response
    {
        $user = (array)$req->getAttribute('user');
        $userId = (int)($user['id'] ?? 0);
        $tournamentId = (int)($args['id'] ?? 0);
        $data = (array)$req->getParsedBody();

        if ($userId <= 0) {
            return $this->json($res, ['error' => 'No autorizado'], 401);
        }

        if ($tournamentId <= 0) {
            return $this->json($res, ['error' => 'ID de torneo no válido'], 400);
        }

        $teamName = trim((string)($data['team_name'] ?? ''));
        $teamLogo = trim((string)($data['team_logo_url'] ?? ''));
        $teamColor = strtoupper(trim((string)($data['team_color'] ?? '#0EA5E9')));
        $capacity = (int)($data['capacity'] ?? 5);

        if ($teamName === '' || mb_strlen($teamName) < 2 || mb_strlen($teamName) > 100) {
            return $this->json($res, ['error' => 'El nombre del equipo debe tener entre 2 y 100 caracteres'], 400);
        }

        if (!preg_match('/^#[0-9A-F]{6}$/', $teamColor)) {
            $teamColor = '#0EA5E9';
        }

        if ($capacity < 3 || $capacity > 15) {
            return $this->json($res, ['error' => 'La capacidad del equipo debe estar entre 3 y 15'], 400);
        }

        if (!$this->isValidLogoUrl($teamLogo)) {
            return $this->json($res, ['error' => 'Logo de equipo no válido'], 400);
        }

        try {
            $this->db->beginTransaction();

            $stmtTournament = $this->db->prepare("
                SELECT id, name, max_teams, COALESCE(visibility, 'public') AS visibility, access_code_hash, created_by
                FROM tournaments
                WHERE id = ?
                FOR UPDATE
            ");
            $stmtTournament->execute([$tournamentId]);
            $tournament = $stmtTournament->fetch(PDO::FETCH_ASSOC);

            if (!$tournament) {
                $this->db->rollBack();
                return $this->json($res, ['error' => 'Torneo no encontrado'], 404);
            }

            $accessCode = $this->resolveAccessCodeFromRequest($req, $data);
            if (!$this->canAccessTournament($user, $tournament, $accessCode)) {
                $this->db->rollBack();
                return $this->json($res, [
                    'error' => 'Este torneo es privado. Introduce el código de acceso.',
                    'requires_access_code' => true
                ], 403);
            }

            $stmtAlreadyMember = $this->db->prepare("
                SELECT tm.id
                FROM team_members tm
                INNER JOIN teams t ON t.id = tm.team_id
                WHERE t.tournament_id = ?
                  AND tm.user_id = ?
                LIMIT 1
            ");
            $stmtAlreadyMember->execute([$tournamentId, $userId]);
            if ($stmtAlreadyMember->fetch(PDO::FETCH_ASSOC)) {
                $this->db->rollBack();
                return $this->json($res, ['error' => 'Ya perteneces a un equipo de este torneo'], 409);
            }

            $stmtTeamName = $this->db->prepare("
                SELECT id
                FROM teams
                WHERE tournament_id = ?
                  AND LOWER(name) = LOWER(?)
                LIMIT 1
            ");
            $stmtTeamName->execute([$tournamentId, $teamName]);
            if ($stmtTeamName->fetch(PDO::FETCH_ASSOC)) {
                $this->db->rollBack();
                return $this->json($res, ['error' => 'Ya existe un equipo con ese nombre en este torneo'], 409);
            }

            $stmtCountTeams = $this->db->prepare("SELECT COUNT(*) FROM teams WHERE tournament_id = ?");
            $stmtCountTeams->execute([$tournamentId]);
            $teamsCount = (int)$stmtCountTeams->fetchColumn();

            if ($teamsCount >= (int)$tournament['max_teams']) {
                $stmtExistingWait = $this->db->prepare("
                    SELECT id
                    FROM waitlist_entries
                    WHERE tournament_id = ?
                      AND user_id = ?
                      AND status = 'pending'
                    LIMIT 1
                    FOR UPDATE
                ");
                $stmtExistingWait->execute([$tournamentId, $userId]);
                $existingWait = $stmtExistingWait->fetch(PDO::FETCH_ASSOC);

                if ($existingWait) {
                    $waitlistId = (int)$existingWait['id'];
                } else {
                    $stmtInsertWait = $this->db->prepare("
                        INSERT INTO waitlist_entries (tournament_id, user_id, team_name, status)
                        VALUES (?, ?, ?, 'pending')
                    ");
                    $stmtInsertWait->execute([$tournamentId, $userId, $teamName]);
                    $waitlistId = (int)$this->db->lastInsertId();
                }

                $stmtPosition = $this->db->prepare("
                    SELECT COUNT(*) AS pos
                    FROM waitlist_entries
                    WHERE tournament_id = ?
                      AND status = 'pending'
                      AND id <= ?
                ");
                $stmtPosition->execute([$tournamentId, $waitlistId]);
                $position = (int)$stmtPosition->fetchColumn();

                $this->db->commit();

                return $this->json($res, [
                    'waitlisted' => true,
                    'message' => 'El torneo está completo. Se añadió tu equipo a la lista de espera.',
                    'waitlist_position' => $position,
                    'team_name' => $teamName
                ], 202);
            }

            $stmtInsertTeam = $this->db->prepare("
                INSERT INTO teams (tournament_id, name, logo_url, color_hex, captain_id, capacity, team_status)
                VALUES (?, ?, ?, ?, ?, ?, 'incomplete')
            ");
            $stmtInsertTeam->execute([
                $tournamentId,
                $teamName,
                ($teamLogo !== '' ? $teamLogo : null),
                $teamColor,
                $userId,
                $capacity
            ]);

            $teamId = (int)$this->db->lastInsertId();

            $stmtInsertCaptain = $this->db->prepare("
                INSERT INTO team_members (team_id, user_id, role, pending_validation)
                VALUES (?, ?, 'captain', 0)
            ");
            $stmtInsertCaptain->execute([$teamId, $userId]);

            // Si estaba en lista de espera pendiente para este torneo, se cancela
            $stmtCancelWait = $this->db->prepare("
                UPDATE waitlist_entries
                SET status = 'cancelled'
                WHERE tournament_id = ?
                  AND user_id = ?
                  AND status = 'pending'
            ");
            $stmtCancelWait->execute([$tournamentId, $userId]);

            $this->refreshTeamStatus($teamId);

            $this->db->commit();

            return $this->json($res, [
                'waitlisted' => false,
                'message' => 'Equipo creado correctamente',
                'team' => [
                    'id' => $teamId,
                    'tournament_id' => $tournamentId,
                    'name' => $teamName,
                    'logo_url' => $teamLogo,
                    'color_hex' => $teamColor,
                    'capacity' => $capacity,
                    'status' => 'incomplete'
                ]
            ], 201);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('createTeamOrWaitlist error: ' . $e->getMessage());
            return $this->json($res, ['error' => 'No se pudo crear el equipo'], 500);
        }
    }

    /**
     * Crear invitación de equipo (capitán/co-capitán)
     */
    public function createInvite(Request $req, Response $res, array $args): Response
    {
        $user = (array)$req->getAttribute('user');
        $userId = (int)($user['id'] ?? 0);
        $teamId = (int)($args['id'] ?? 0);
        $data = (array)$req->getParsedBody();

        if ($userId <= 0) {
            return $this->json($res, ['error' => 'No autorizado'], 401);
        }

        if ($teamId <= 0) {
            return $this->json($res, ['error' => 'ID de equipo no válido'], 400);
        }

        $maxUses = (int)($data['max_uses'] ?? 25);
        $expiresInDays = (int)($data['expires_in_days'] ?? 7);

        if ($maxUses < 1 || $maxUses > 500) {
            return $this->json($res, ['error' => 'max_uses debe estar entre 1 y 500'], 400);
        }

        if ($expiresInDays < 1 || $expiresInDays > 30) {
            return $this->json($res, ['error' => 'expires_in_days debe estar entre 1 y 30'], 400);
        }

        try {
            $membership = $this->getMembership($teamId, $userId);
            if (!$membership || (int)$membership['pending_validation'] === 1) {
                return $this->json($res, ['error' => 'No perteneces a este equipo o estás pendiente de validar'], 403);
            }

            $role = (string)$membership['role'];
            if (!in_array($role, ['captain', 'co_captain'], true)) {
                return $this->json($res, ['error' => 'Solo capitán o co-capitán pueden generar invitaciones'], 403);
            }

            $stmtTeam = $this->db->prepare("SELECT id FROM teams WHERE id = ? LIMIT 1");
            $stmtTeam->execute([$teamId]);
            if (!$stmtTeam->fetch(PDO::FETCH_ASSOC)) {
                return $this->json($res, ['error' => 'Equipo no encontrado'], 404);
            }

            $inviteCode = $this->generateUniqueInviteCode();
            $expiresAt = (new DateTimeImmutable('+' . $expiresInDays . ' days'))->format('Y-m-d H:i:s');

            $stmt = $this->db->prepare("
                INSERT INTO team_invites (team_id, invite_code, created_by, status, expires_at, max_uses, uses_count)
                VALUES (?, ?, ?, 'active', ?, ?, 0)
            ");
            $stmt->execute([$teamId, $inviteCode, $userId, $expiresAt, $maxUses]);

            $frontendBase = rtrim((string)($_ENV['FRONTEND_BASE_URL'] ?? 'http://localhost:5173'), '/');
            $joinUrl = $frontendBase . '/team-invite/' . $inviteCode;

            return $this->json($res, [
                'message' => 'Invitación generada',
                'invite_code' => $inviteCode,
                'join_url' => $joinUrl,
                'expires_at' => $expiresAt,
                'max_uses' => $maxUses
            ], 201);
        } catch (Throwable $e) {
            error_log('createInvite error: ' . $e->getMessage());
            return $this->json($res, ['error' => 'No se pudo crear la invitación'], 500);
        }
    }

    /**
     * Aceptar invitación por código (queda pendiente de validar)
     */
    public function acceptInvite(Request $req, Response $res, array $args): Response
    {
        $user = (array)$req->getAttribute('user');
        $userId = (int)($user['id'] ?? 0);
        $inviteCode = $this->sanitizeInviteCode((string)($args['code'] ?? ''));

        if ($userId <= 0) {
            return $this->json($res, ['error' => 'No autorizado'], 401);
        }

        if ($inviteCode === '' || strlen($inviteCode) < 6) {
            return $this->json($res, ['error' => 'Código de invitación no válido'], 400);
        }

        try {
            $this->db->beginTransaction();

            $stmtInvite = $this->db->prepare("
                SELECT
                    i.id,
                    i.team_id,
                    i.invite_code,
                    i.status,
                    i.expires_at,
                    i.max_uses,
                    i.uses_count,
                    t.tournament_id,
                    t.capacity
                FROM team_invites i
                INNER JOIN teams t ON t.id = i.team_id
                WHERE i.invite_code = ?
                LIMIT 1
                FOR UPDATE
            ");
            $stmtInvite->execute([$inviteCode]);
            $invite = $stmtInvite->fetch(PDO::FETCH_ASSOC);

            if (!$invite) {
                $this->db->rollBack();
                return $this->json($res, ['error' => 'Invitación no encontrada'], 404);
            }

            if ((string)$invite['status'] !== 'active') {
                $this->db->rollBack();
                return $this->json($res, ['error' => 'La invitación no está activa'], 410);
            }

            if (strtotime((string)$invite['expires_at']) < time()) {
                $this->db->rollBack();
                return $this->json($res, ['error' => 'La invitación ha expirado'], 410);
            }

            if ((int)$invite['uses_count'] >= (int)$invite['max_uses']) {
                $this->db->rollBack();
                return $this->json($res, ['error' => 'La invitación alcanzó su límite de usos'], 410);
            }

            $teamId = (int)$invite['team_id'];

            $stmtAlreadyMember = $this->db->prepare("
                SELECT id
                FROM team_members
                WHERE team_id = ?
                  AND user_id = ?
                LIMIT 1
            ");
            $stmtAlreadyMember->execute([$teamId, $userId]);
            if ($stmtAlreadyMember->fetch(PDO::FETCH_ASSOC)) {
                $this->db->rollBack();
                return $this->json($res, ['error' => 'Ya perteneces a este equipo'], 409);
            }

            $stmtCountMembers = $this->db->prepare("
                SELECT COUNT(*) FROM team_members WHERE team_id = ?
            ");
            $stmtCountMembers->execute([$teamId]);
            $memberCount = (int)$stmtCountMembers->fetchColumn();

            if ($memberCount >= (int)$invite['capacity']) {
                $this->db->rollBack();
                return $this->json($res, ['error' => 'El equipo está completo'], 409);
            }

            $stmtInsertMember = $this->db->prepare("
                INSERT INTO team_members (team_id, user_id, role, pending_validation)
                VALUES (?, ?, 'player', 1)
            ");
            $stmtInsertMember->execute([$teamId, $userId]);

            $stmtUseInvite = $this->db->prepare("
                UPDATE team_invites
                SET uses_count = uses_count + 1
                WHERE id = ?
            ");
            $stmtUseInvite->execute([(int)$invite['id']]);

            $this->refreshTeamStatus($teamId);

            $this->db->commit();

            return $this->json($res, [
                'message' => 'Solicitud enviada. Tu entrada al equipo está pendiente de validación.',
                'team_id' => $teamId,
                'tournament_id' => (int)$invite['tournament_id'],
                'pending_validation' => true
            ], 201);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('acceptInvite error: ' . $e->getMessage());
            return $this->json($res, ['error' => 'No se pudo aceptar la invitación'], 500);
        }
    }

    /**
     * Validar jugador pendiente (capitán/co-capitán)
     */
    public function validateMember(Request $req, Response $res, array $args): Response
    {
        $user = (array)$req->getAttribute('user');
        $userId = (int)($user['id'] ?? 0);
        $teamId = (int)($args['teamId'] ?? 0);
        $memberId = (int)($args['memberId'] ?? 0);

        if ($userId <= 0) {
            return $this->json($res, ['error' => 'No autorizado'], 401);
        }

        if ($teamId <= 0 || $memberId <= 0) {
            return $this->json($res, ['error' => 'Parámetros no válidos'], 400);
        }

        try {
            $membership = $this->getMembership($teamId, $userId);
            if (!$membership || (int)$membership['pending_validation'] === 1) {
                return $this->json($res, ['error' => 'No autorizado para validar miembros'], 403);
            }

            $role = (string)$membership['role'];
            if (!in_array($role, ['captain', 'co_captain'], true)) {
                return $this->json($res, ['error' => 'Solo capitán o co-capitán pueden validar jugadores'], 403);
            }

            $stmtTarget = $this->db->prepare("
                SELECT id, team_id, role, pending_validation
                FROM team_members
                WHERE id = ? AND team_id = ?
                LIMIT 1
            ");
            $stmtTarget->execute([$memberId, $teamId]);
            $target = $stmtTarget->fetch(PDO::FETCH_ASSOC);

            if (!$target) {
                return $this->json($res, ['error' => 'Miembro no encontrado'], 404);
            }

            if ((int)$target['pending_validation'] === 0) {
                return $this->json($res, ['message' => 'El jugador ya estaba validado']);
            }

            $stmtUpdate = $this->db->prepare("
                UPDATE team_members
                SET pending_validation = 0
                WHERE id = ?
            ");
            $stmtUpdate->execute([$memberId]);

            $this->refreshTeamStatus($teamId);

            return $this->json($res, ['message' => 'Jugador validado correctamente']);
        } catch (Throwable $e) {
            error_log('validateMember error: ' . $e->getMessage());
            return $this->json($res, ['error' => 'No se pudo validar al jugador'], 500);
        }
    }

    /**
     * Cambiar rol (solo capitán): co_captain <-> player
     */
    public function updateMemberRole(Request $req, Response $res, array $args): Response
    {
        $user = (array)$req->getAttribute('user');
        $userId = (int)($user['id'] ?? 0);
        $teamId = (int)($args['teamId'] ?? 0);
        $memberId = (int)($args['memberId'] ?? 0);
        $data = (array)$req->getParsedBody();

        $newRole = trim((string)($data['role'] ?? ''));

        if ($userId <= 0) {
            return $this->json($res, ['error' => 'No autorizado'], 401);
        }

        if ($teamId <= 0 || $memberId <= 0) {
            return $this->json($res, ['error' => 'Parámetros no válidos'], 400);
        }

        if (!in_array($newRole, ['co_captain', 'player'], true)) {
            return $this->json($res, ['error' => 'Rol no válido'], 400);
        }

        try {
            $membership = $this->getMembership($teamId, $userId);
            if (!$membership || (string)$membership['role'] !== 'captain' || (int)$membership['pending_validation'] === 1) {
                return $this->json($res, ['error' => 'Solo el capitán puede cambiar roles'], 403);
            }

            $stmtTarget = $this->db->prepare("
                SELECT id, role, pending_validation, user_id
                FROM team_members
                WHERE id = ? AND team_id = ?
                LIMIT 1
            ");
            $stmtTarget->execute([$memberId, $teamId]);
            $target = $stmtTarget->fetch(PDO::FETCH_ASSOC);

            if (!$target) {
                return $this->json($res, ['error' => 'Miembro no encontrado'], 404);
            }

            if ((string)$target['role'] === 'captain') {
                return $this->json($res, ['error' => 'No puedes cambiar el rol del capitán'], 400);
            }

            if ((int)$target['pending_validation'] === 1) {
                return $this->json($res, ['error' => 'Primero valida al jugador para cambiar su rol'], 400);
            }

            $stmtUpdate = $this->db->prepare("
                UPDATE team_members
                SET role = ?
                WHERE id = ?
            ");
            $stmtUpdate->execute([$newRole, $memberId]);

            return $this->json($res, ['message' => 'Rol actualizado correctamente']);
        } catch (Throwable $e) {
            error_log('updateMemberRole error: ' . $e->getMessage());
            return $this->json($res, ['error' => 'No se pudo actualizar el rol'], 500);
        }
    }

    // ------------------------
    // Helpers
    // ------------------------

    private function getTournamentById(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, name, max_teams, COALESCE(visibility, 'public') AS visibility, access_code_hash, created_by
            FROM tournaments
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function canAccessTournament(array $user, array $tournament, string $accessCode = ''): bool
    {
        $visibility = (string)($tournament['visibility'] ?? 'public');
        if ($visibility !== 'private') {
            return true;
        }

        $userId = (int)($user['id'] ?? 0);
        $role = (string)($user['role'] ?? '');
        if ($role === 'admin') {
            return true;
        }

        if ($userId > 0 && $userId === (int)$tournament['created_by']) {
            return true;
        }

        if ($userId > 0 && $this->userBelongsToTournament($userId, (int)$tournament['id'])) {
            return true;
        }

        $hash = (string)($tournament['access_code_hash'] ?? '');
        return $this->verifyAccessCode($accessCode, $hash);
    }

    private function userBelongsToTournament(int $userId, int $tournamentId): bool
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

        // Fallback legacy por si no se ha hecho seed aún
        $stmtLegacy = $this->db->prepare("
            SELECT id FROM teams
            WHERE tournament_id = ?
              AND captain_id = ?
            LIMIT 1
        ");
        $stmtLegacy->execute([$tournamentId, $userId]);
        return (bool)$stmtLegacy->fetch(PDO::FETCH_ASSOC);
    }

    private function seedCaptainMembers(int $tournamentId): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO team_members (team_id, user_id, role, pending_validation)
            SELECT t.id, t.captain_id, 'captain', 0
            FROM teams t
            LEFT JOIN team_members tm
                ON tm.team_id = t.id
               AND tm.user_id = t.captain_id
            WHERE t.tournament_id = ?
              AND tm.id IS NULL
        ");
        $stmt->execute([$tournamentId]);

        $stmtTeamIds = $this->db->prepare("SELECT id FROM teams WHERE tournament_id = ?");
        $stmtTeamIds->execute([$tournamentId]);
        $teamIds = $stmtTeamIds->fetchAll(PDO::FETCH_COLUMN);

        foreach ($teamIds as $tid) {
            $this->refreshTeamStatus((int)$tid);
        }
    }

    private function refreshTeamStatus(int $teamId): void
    {
        $stmt = $this->db->prepare("
            SELECT
                t.capacity,
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

        if (!$row) {
            return;
        }

        $capacity = max(1, (int)$row['capacity']);
        $validated = (int)$row['validated_members'];
        $pending = (int)$row['pending_members'];

        $status = 'incomplete';
        if ($pending > 0) {
            $status = 'pending_validate';
        } elseif ($validated >= $capacity) {
            $status = 'complete';
        }

        $stmtUpdate = $this->db->prepare("
            UPDATE teams
            SET team_status = ?
            WHERE id = ?
        ");
        $stmtUpdate->execute([$status, $teamId]);
    }

    private function getMembership(int $teamId, int $userId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, team_id, user_id, role, pending_validation
            FROM team_members
            WHERE team_id = ?
              AND user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$teamId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function isValidLogoUrl(string $url): bool
    {
        if ($url === '') {
            return true;
        }

        if (mb_strlen($url) > 255) {
            return false;
        }

        if (str_starts_with($url, '/uploads/')) {
            return true;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    private function generateUniqueInviteCode(): string
    {
        $maxAttempts = 50;
        for ($i = 0; $i < $maxAttempts; $i++) {
            $candidate = $this->generateInviteCode(10);
            $stmt = $this->db->prepare("SELECT id FROM team_invites WHERE invite_code = ? LIMIT 1");
            $stmt->execute([$candidate]);
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                return $candidate;
            }
        }

        throw new RuntimeException('No se pudo generar un código de invitación único');
    }

    private function generateInviteCode(int $length = 10): string
    {
        $alphabet = 'BCDFGHJKLMNPQRSTVWXYZ23456789';
        $max = strlen($alphabet) - 1;
        $result = '';

        for ($i = 0; $i < $length; $i++) {
            $result .= $alphabet[random_int(0, $max)];
        }

        return $result;
    }

    private function sanitizeInviteCode(string $code): string
    {
        return (string)preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($code)));
    }

    private function resolveAccessCodeFromRequest(Request $req, array $body = []): string
    {
        $fromBody = $this->sanitizeAccessCode((string)($body['access_code'] ?? ''));
        if ($fromBody !== '') {
            return $fromBody;
        }

        $fromHeader = $this->sanitizeAccessCode($req->getHeaderLine('X-Tournament-Code'));
        if ($fromHeader !== '') {
            return $fromHeader;
        }

        return $this->sanitizeAccessCode((string)($req->getQueryParams()['code'] ?? ''));
    }

    private function sanitizeAccessCode(string $code): string
    {
        return (string)preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($code)));
    }

    private function verifyAccessCode(string $candidate, string $hash): bool
    {
        if ($candidate === '' || $hash === '') {
            return false;
        }
        return password_verify($candidate, $hash);
    }

    private function json(Response $res, array $payload, int $status = 200): Response
    {
        $res->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $res->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}