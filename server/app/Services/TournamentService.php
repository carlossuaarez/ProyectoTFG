<?php

require_once __DIR__ . '/../Repositories/TournamentRepository.php';
require_once __DIR__ . '/AccessControlService.php';

class TournamentService
{
    private PDO $db;
    private TournamentRepository $repo;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->repo = new TournamentRepository($db);
    }

    public function getAll(): array
    {
        try {
            $rows = $this->repo->fetchPublicList();
            return $this->result(200, $rows);
        } catch (Throwable $e) {
            error_log('getAll tournaments error: ' . $e->getMessage());
            return $this->result(500, ['error' => 'No se pudieron cargar los torneos']);
        }
    }

    public function getById(int $id, array $requestUser, string $accessCode): array
    {
        if ($id <= 0) {
            return $this->result(400, ['error' => 'ID de torneo no válido']);
        }

        try {
            $tournament = $this->repo->findByIdWithCreator($id);
            if (!$tournament) {
                return $this->result(404, ['error' => 'Torneo no encontrado']);
            }

            $visibility = $this->normalizeVisibility((string)($tournament['visibility'] ?? 'public'));
            $tournament['visibility'] = $visibility;

            $isAdminPreview = (($requestUser['role'] ?? '') === 'admin');
            $isOwnerPreview = ((int)($requestUser['id'] ?? 0) > 0)
                && ((int)$requestUser['id'] === (int)$tournament['created_by']);

            if ($visibility === 'private') {
                $hash = (string)($tournament['access_code_hash'] ?? '');
                if (!$isAdminPreview && !$isOwnerPreview
                    && !AccessControlService::verifyAccessCode($accessCode, $hash)) {
                    return $this->result(403, [
                        'error' => 'Este torneo es privado. Introduce el código de acceso.',
                        'requires_access_code' => true,
                    ]);
                }
            }

            $tournament['teams'] = $this->repo->fetchTeamsByTournamentId($id);
            $teamsCount = count($tournament['teams']);
            $tournament['teams_count'] = $teamsCount;
            $tournament['is_full'] = ($teamsCount >= (int)$tournament['max_teams']) ? 1 : 0;

            unset($tournament['access_code_hash']);
            $tournament['admin_preview'] = $isAdminPreview;
            $tournament['owner_preview'] = $isOwnerPreview;

            return $this->result(200, $tournament);
        } catch (Throwable $e) {
            error_log('getById tournament error: ' . $e->getMessage());
            return $this->result(500, ['error' => 'No se pudo cargar el detalle del torneo']);
        }
    }

    public function create(int $userId, array $data): array
    {
        $name = trim((string)($data['name'] ?? ''));
        $description = trim((string)($data['description'] ?? ''));
        $game = trim((string)($data['game'] ?? ''));
        $type = trim((string)($data['type'] ?? ''));
        $maxTeams = (int)($data['max_teams'] ?? 0);
        $format = trim((string)($data['format'] ?? 'single_elim'));
        $startDate = trim((string)($data['start_date'] ?? ''));
        $startTime = trim((string)($data['start_time'] ?? ''));
        $prize = trim((string)($data['prize'] ?? ''));
        $visibility = $this->normalizeVisibility((string)($data['visibility'] ?? 'public'));
        $locationName = trim((string)($data['location_name'] ?? ''));
        $locationAddress = trim((string)($data['location_address'] ?? ''));
        $locationLatRaw = $data['location_lat'] ?? null;
        $locationLngRaw = $data['location_lng'] ?? null;

        $err = $this->validateCommon($name, $game, $type, $format, $startDate, $startTime, $maxTeams, true);
        if ($err !== null) return $err;

        $isOnline = 0;
        $locationLat = null;
        $locationLng = null;

        if ($type === 'esports') {
            $isOnline = 1;
            $locationName = 'Online';
            $locationAddress = 'Online';
        } else {
            if ($locationName === '') {
                return $this->result(400, ['error' => 'Para deportes debes indicar location_name']);
            }
            if (!is_numeric($locationLatRaw) || !is_numeric($locationLngRaw)) {
                return $this->result(400, ['error' => 'Para deportes debes indicar latitud y longitud']);
            }
            $locationLat = (float)$locationLatRaw;
            $locationLng = (float)$locationLngRaw;
            if ($locationLat < -90 || $locationLat > 90 || $locationLng < -180 || $locationLng > 180) {
                return $this->result(400, ['error' => 'Coordenadas de ubicación no válidas']);
            }
        }

        $accessCodePlain = null;
        $accessCodeHash = null;
        $accessCodeLast4 = null;

        if ($visibility === 'private') {
            try {
                $accessCodePlain = $this->generateUniqueAccessCode(8);
            } catch (Throwable $e) {
                error_log('private code generation error: ' . $e->getMessage());
                return $this->result(500, ['error' => 'No se pudo generar el código privado']);
            }
            $accessCodeHash = password_hash($accessCodePlain, PASSWORD_DEFAULT);
            $accessCodeLast4 = substr($accessCodePlain, -4);
        }

        try {
            $newId = $this->repo->insertTournament([
                'name' => $name,
                'description' => ($description !== '' ? $description : null),
                'game' => $game,
                'type' => $type,
                'max_teams' => $maxTeams,
                'format' => $format,
                'start_date' => $startDate,
                'start_time' => $startTime . ':00',
                'prize' => ($prize !== '' ? $prize : null),
                'created_by' => $userId,
                'location_name' => ($locationName !== '' ? $locationName : null),
                'location_address' => ($locationAddress !== '' ? $locationAddress : null),
                'location_lat' => $locationLat,
                'location_lng' => $locationLng,
                'is_online' => $isOnline,
                'visibility' => $visibility,
                'access_code_hash' => $accessCodeHash,
                'access_code_last4' => $accessCodeLast4,
            ]);

            $payload = ['message' => 'Torneo creado correctamente', 'id' => $newId];
            if ($visibility === 'private') {
                $payload['private_access_code'] = $accessCodePlain;
                $payload['private_access_code_last4'] = $accessCodeLast4;
            }
            return $this->result(201, $payload);
        } catch (Throwable $e) {
            error_log('create tournament error: ' . $e->getMessage());
            return $this->result(500, ['error' => 'No se pudo crear el torneo']);
        }
    }

    public function update(int $userId, int $tournamentId, array $data): array
    {
        if ($tournamentId <= 0) {
            return $this->result(400, ['error' => 'ID de torneo no válido']);
        }

        try {
            $current = $this->repo->findByIdWithCreator($tournamentId);
            if (!$current) {
                return $this->result(404, ['error' => 'Torneo no encontrado']);
            }
            if ((int)$current['created_by'] !== $userId) {
                return $this->result(403, ['error' => 'Solo el creador del torneo puede editarlo']);
            }

            if (array_key_exists('max_teams', $data)
                && (int)$data['max_teams'] !== (int)$current['max_teams']) {
                return $this->result(400, ['error' => 'No se puede modificar el número máximo de equipos']);
            }

            $name = trim((string)($data['name'] ?? ''));
            $description = trim((string)($data['description'] ?? ''));
            $game = trim((string)($data['game'] ?? ''));
            $type = trim((string)($data['type'] ?? ''));
            $format = trim((string)($data['format'] ?? 'single_elim'));
            $startDate = trim((string)($data['start_date'] ?? ''));
            $startTime = trim((string)($data['start_time'] ?? ''));
            $prize = trim((string)($data['prize'] ?? ''));
            $visibility = $this->normalizeVisibility((string)($data['visibility'] ?? $current['visibility'] ?? 'public'));
            $locationName = trim((string)($data['location_name'] ?? ''));
            $locationAddress = trim((string)($data['location_address'] ?? ''));
            $locationLatRaw = $data['location_lat'] ?? null;
            $locationLngRaw = $data['location_lng'] ?? null;

            $err = $this->validateCommon($name, $game, $type, $format, $startDate, $startTime, 0, false);
            if ($err !== null) return $err;

            $isOnline = 0;
            $locationLat = null;
            $locationLng = null;

            if ($type === 'esports') {
                $isOnline = 1;
                $locationName = 'Online';
                $locationAddress = 'Online';
            } else {
                if ($locationName === '') {
                    return $this->result(400, ['error' => 'Para deportes debes indicar location_name']);
                }
                if (!is_numeric($locationLatRaw) || !is_numeric($locationLngRaw)) {
                    return $this->result(400, ['error' => 'Para deportes debes indicar latitud y longitud']);
                }
                $locationLat = (float)$locationLatRaw;
                $locationLng = (float)$locationLngRaw;
                if ($locationLat < -90 || $locationLat > 90 || $locationLng < -180 || $locationLng > 180) {
                    return $this->result(400, ['error' => 'Coordenadas de ubicación no válidas']);
                }
            }

            $currentVisibility = $this->normalizeVisibility((string)($current['visibility'] ?? 'public'));
            $accessCodeHash = null;
            $accessCodeLast4 = null;
            $newPrivateCode = null;

            if ($visibility === 'private') {
                if ($currentVisibility === 'private' && (string)($current['access_code_hash'] ?? '') !== '') {
                    $accessCodeHash = (string)$current['access_code_hash'];
                    $accessCodeLast4 = (string)($current['access_code_last4'] ?? '');
                } else {
                    try {
                        $newPrivateCode = $this->generateUniqueAccessCode(8);
                    } catch (Throwable $e) {
                        error_log('private code generation on update error: ' . $e->getMessage());
                        return $this->result(500, ['error' => 'No se pudo generar el código privado']);
                    }
                    $accessCodeHash = password_hash($newPrivateCode, PASSWORD_DEFAULT);
                    $accessCodeLast4 = substr($newPrivateCode, -4);
                }
            }

            $this->repo->updateTournamentById($tournamentId, [
                'name' => $name,
                'description' => ($description !== '' ? $description : null),
                'game' => $game,
                'type' => $type,
                'format' => $format,
                'start_date' => $startDate,
                'start_time' => $startTime . ':00',
                'prize' => ($prize !== '' ? $prize : null),
                'location_name' => ($locationName !== '' ? $locationName : null),
                'location_address' => ($locationAddress !== '' ? $locationAddress : null),
                'location_lat' => $locationLat,
                'location_lng' => $locationLng,
                'is_online' => $isOnline,
                'visibility' => $visibility,
                'access_code_hash' => $accessCodeHash,
                'access_code_last4' => $accessCodeLast4,
            ]);

            $payload = ['message' => 'Torneo actualizado correctamente'];
            if ($newPrivateCode !== null) {
                $payload['private_access_code'] = $newPrivateCode;
                $payload['private_access_code_last4'] = $accessCodeLast4;
            }
            return $this->result(200, $payload);
        } catch (Throwable $e) {
            error_log('update tournament error: ' . $e->getMessage());
            return $this->result(500, ['error' => 'No se pudo actualizar el torneo']);
        }
    }

    public function resolvePrivateByCode(string $rawCode): array
    {
        $code = AccessControlService::sanitizeAccessCode($rawCode);
        if ($code === '' || strlen($code) < 6 || strlen($code) > 16) {
            return $this->result(400, ['error' => 'Código privado no válido']);
        }

        try {
            $t = $this->findPrivateTournamentByCode($code);
            if (!$t) {
                return $this->result(404, ['error' => 'Código privado incorrecto o torneo no encontrado']);
            }
            return $this->result(200, ['tournament_id' => (int)$t['id']]);
        } catch (Throwable $e) {
            error_log('resolve private code error: ' . $e->getMessage());
            return $this->result(500, ['error' => 'No se pudo resolver el código privado']);
        }
    }

    public function join(int $userId, int $tournamentId, array $data, string $accessCode): array
    {
        $teamName = trim((string)($data['team_name'] ?? ''));
        if ($tournamentId <= 0) {
            return $this->result(400, ['error' => 'ID de torneo no válido']);
        }
        if ($teamName === '') {
            return $this->result(400, ['error' => 'El nombre del equipo es obligatorio']);
        }
        if (mb_strlen($teamName) < 2 || mb_strlen($teamName) > 100) {
            return $this->result(400, ['error' => 'El nombre del equipo debe tener entre 2 y 100 caracteres']);
        }

        try {
            $this->db->beginTransaction();
            $tournament = $this->repo->findByIdForUpdate($tournamentId);
            if (!$tournament) {
                $this->db->rollBack();
                return $this->result(404, ['error' => 'Torneo no encontrado']);
            }
            if (($tournament['visibility'] ?? 'public') === 'private') {
                if (!AccessControlService::verifyAccessCode($accessCode, (string)($tournament['access_code_hash'] ?? ''))) {
                    $this->db->rollBack();
                    return $this->result(403, ['error' => 'Código de acceso inválido para torneo privado']);
                }
            }
            if ($this->repo->captainHasTeamInTournament($tournamentId, $userId)) {
                $this->db->rollBack();
                return $this->result(409, ['error' => 'Ya tienes un equipo inscrito en este torneo']);
            }
            if ($this->repo->teamNameExistsInTournament($tournamentId, $teamName)) {
                $this->db->rollBack();
                return $this->result(409, ['error' => 'Ya existe un equipo con ese nombre en este torneo']);
            }
            if ($this->repo->countTeams($tournamentId) >= (int)$tournament['max_teams']) {
                $this->db->rollBack();
                return $this->result(409, ['error' => 'El torneo ya está lleno']);
            }
            $this->repo->insertTeam($tournamentId, $teamName, $userId);
            $this->db->commit();
            return $this->result(201, ['message' => 'Equipo inscrito correctamente']);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log('join tournament error: ' . $e->getMessage());
            return $this->result(500, ['error' => 'No se pudo completar la inscripción']);
        }
    }

    private function validateCommon(
        string $name, string $game, string $type, string $format,
        string $startDate, string $startTime, int $maxTeams, bool $checkMaxTeams
    ): ?array {
        if ($name === '' || $game === '' || $type === '') {
            return $this->result(400, ['error' => 'Campos requeridos: name, game, type']);
        }
        if (!in_array($type, ['sports', 'esports'], true)) {
            return $this->result(400, ['error' => 'Categoría no válida (sports | esports)']);
        }
        if (!in_array($format, ['league', 'single_elim'], true)) {
            return $this->result(400, ['error' => 'Formato no válido (league | single_elim)']);
        }
        if ($checkMaxTeams && ($maxTeams < 2 || $maxTeams > 128)) {
            return $this->result(400, ['error' => 'max_teams debe estar entre 2 y 128']);
        }
        if (!$this->isValidDateYmd($startDate) || $startDate < date('Y-m-d')) {
            return $this->result(400, ['error' => 'La fecha de inicio no es válida']);
        }
        if (!$this->isValidTimeHm($startTime)) {
            return $this->result(400, ['error' => 'start_time debe tener formato HH:MM']);
        }
        return null;
    }

    private function findPrivateTournamentByCode(string $code): ?array
    {
        $last4 = substr($code, -4);
        foreach ($this->repo->findPrivateCandidatesByLast4($last4) as $row) {
            if (AccessControlService::verifyAccessCode($code, (string)($row['access_code_hash'] ?? ''))) {
                return $row;
            }
        }
        foreach ($this->repo->findLegacyPrivateCandidates() as $row) {
            if (AccessControlService::verifyAccessCode($code, (string)($row['access_code_hash'] ?? ''))) {
                return $row;
            }
        }
        return null;
    }

    private function normalizeVisibility(string $value): string
    {
        $v = strtolower(trim($value));
        return $v === 'private' ? 'private' : 'public';
    }

    private function generateAccessCode(int $length = 8): string
    {
        $alphabet = 'BCDFGHJKLMNPQRSTVWXYZ23456789';
        $max = strlen($alphabet) - 1;
        $result = '';
        for ($i = 0; $i < $length; $i++) {
            $result .= $alphabet[random_int(0, $max)];
        }
        return $result;
    }

    private function generateUniqueAccessCode(int $length = 8, int $maxAttempts = 40): string
    {
        for ($i = 0; $i < $maxAttempts; $i++) {
            $candidate = $this->generateAccessCode($length);
            if ($this->isAccessCodeUnique($candidate)) {
                return $candidate;
            }
        }
        throw new RuntimeException('No se pudo generar un código privado único');
    }

    private function isAccessCodeUnique(string $candidate): bool
    {
        $last4 = substr($candidate, -4);
        $rows = $this->repo->candidatesByLast4ForUniqueness($last4);
        foreach ($rows as $row) {
            $hash = (string)($row['access_code_hash'] ?? '');
            if ($hash !== '' && password_verify($candidate, $hash)) {
                return false;
            }
        }
        return true;
    }

    private function isValidDateYmd(string $date): bool
    {
        $dt = DateTime::createFromFormat('Y-m-d', $date);
        return $dt instanceof DateTime && $dt->format('Y-m-d') === $date;
    }

    private function isValidTimeHm(string $time): bool
    {
        return (bool)preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time);
    }

    private function result(int $status, array $payload): array
    {
        return ['status' => $status, 'payload' => $payload];
    }
}