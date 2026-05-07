<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

require_once __DIR__ . '/../Repositories/TournamentRepository.php';

class TournamentController
{
    private PDO $db;
    private TournamentRepository $tournamentRepository;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->tournamentRepository = new TournamentRepository($db);
    }

    // Listado público compatible con legacy
    public function getAll(Request $req, Response $res): Response
    {
        try {
            $rows = $this->tournamentRepository->fetchPublicList();
            return $this->json($res, $rows);
        } catch (Throwable $e) {
            error_log('getAll tournaments error: ' . $e->getMessage());
            return $this->json($res, ['error' => 'No se pudieron cargar los torneos'], 500);
        }
    }

    // Soporta código por Header (preferido), body y query (retrocompatibilidad)
    public function getById(Request $req, Response $res, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);
        if ($id <= 0) {
            return $this->json($res, ['error' => 'ID de torneo no válido'], 400);
        }

        try {
            $tournament = $this->tournamentRepository->findByIdWithCreator($id);

            if (!$tournament) {
                return $this->json($res, ['error' => 'Torneo no encontrado'], 404);
            }

            $visibility = $this->normalizeVisibility((string)($tournament['visibility'] ?? 'public'));
            $tournament['visibility'] = $visibility;

            $requestUser = $this->getRequestUserFromToken($req);
            $isAdminPreview = (($requestUser['role'] ?? '') === 'admin');
            $isOwnerPreview = ((int)($requestUser['id'] ?? 0) > 0)
                && ((int)($requestUser['id'] ?? 0) === (int)($tournament['created_by'] ?? 0));

            if ($visibility === 'private') {
                $code = $this->resolveAccessCodeFromRequest($req);
                $hash = (string)($tournament['access_code_hash'] ?? '');

                if (!$isAdminPreview && !$isOwnerPreview && !$this->verifyAccessCode($code, $hash)) {
                    return $this->json($res, [
                        'error' => 'Este torneo es privado. Introduce el código de acceso.',
                        'requires_access_code' => true
                    ], 403);
                }
            }

            $tournament['teams'] = $this->tournamentRepository->fetchTeamsByTournamentId($id);
            $teamsCount = count($tournament['teams']);
            $tournament['teams_count'] = $teamsCount;
            $tournament['is_full'] = ($teamsCount >= (int)($tournament['max_teams'] ?? 0)) ? 1 : 0;

            // Nunca devolver hash
            unset($tournament['access_code_hash']);

            // Flags para frontend
            $tournament['admin_preview'] = $isAdminPreview;
            $tournament['owner_preview'] = $isOwnerPreview;

            return $this->json($res, $tournament);
        } catch (Throwable $e) {
            error_log('getById tournament error: ' . $e->getMessage());
            return $this->json($res, ['error' => 'No se pudo cargar el detalle del torneo'], 500);
        }
    }

    public function create(Request $req, Response $res): Response
    {
        $user = (array)$req->getAttribute('user');
        $data = (array)$req->getParsedBody();

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

        // Validaciones base
        if ($name === '' || $game === '' || $type === '') {
            return $this->json($res, ['error' => 'Campos requeridos: name, game, type'], 400);
        }

        if (!in_array($type, ['sports', 'esports'], true)) {
            return $this->json($res, ['error' => 'Categoría no válida (sports | esports)'], 400);
        }

        if (!in_array($format, ['league', 'single_elim'], true)) {
            return $this->json($res, ['error' => 'Formato no válido (league | single_elim)'], 400);
        }

        if ($maxTeams < 2 || $maxTeams > 128) {
            return $this->json($res, ['error' => 'max_teams debe estar entre 2 y 128'], 400);
        }

        if (!$this->isValidDateYmd($startDate) || $startDate < date('Y-m-d')) {
            return $this->json($res, ['error' => 'La fecha de inicio no es válida'], 400);
        }

        if (!$this->isValidTimeHm($startTime)) {
            return $this->json($res, ['error' => 'start_time debe tener formato HH:MM'], 400);
        }

        $startTimeSql = $startTime . ':00';

        $isOnline = 0;
        $locationLat = null;
        $locationLng = null;

        if ($type === 'esports') {
            $isOnline = 1;
            $locationName = 'Online';
            $locationAddress = 'Online';
        } else {
            if ($locationName === '') {
                return $this->json($res, ['error' => 'Para deportes debes indicar location_name'], 400);
            }

            if (!is_numeric($locationLatRaw) || !is_numeric($locationLngRaw)) {
                return $this->json($res, ['error' => 'Para deportes debes indicar latitud y longitud'], 400);
            }

            $locationLat = (float)$locationLatRaw;
            $locationLng = (float)$locationLngRaw;

            if ($locationLat < -90 || $locationLat > 90 || $locationLng < -180 || $locationLng > 180) {
                return $this->json($res, ['error' => 'Coordenadas de ubicación no válidas'], 400);
            }
        }

        // Privado: SIEMPRE generar código automático único
        $accessCodePlain = null;
        $accessCodeHash = null;
        $accessCodeLast4 = null;

        if ($visibility === 'private') {
            try {
                $accessCodePlain = $this->generateUniqueAccessCode(8);
            } catch (Throwable $e) {
                error_log('private code generation error: ' . $e->getMessage());
                return $this->json($res, ['error' => 'No se pudo generar el código privado'], 500);
            }

            $accessCodeHash = password_hash($accessCodePlain, PASSWORD_DEFAULT);
            $accessCodeLast4 = substr($accessCodePlain, -4);
        }

        try {
            $newId = $this->tournamentRepository->insertTournament([
                'name' => $name,
                'description' => ($description !== '' ? $description : null),
                'game' => $game,
                'type' => $type,
                'max_teams' => $maxTeams,
                'format' => $format,
                'start_date' => $startDate,
                'start_time' => $startTimeSql,
                'prize' => ($prize !== '' ? $prize : null),
                'created_by' => (int)$user['id'],
                'location_name' => ($locationName !== '' ? $locationName : null),
                'location_address' => ($locationAddress !== '' ? $locationAddress : null),
                'location_lat' => $locationLat,
                'location_lng' => $locationLng,
                'is_online' => $isOnline,
                'visibility' => $visibility,
                'access_code_hash' => $accessCodeHash,
                'access_code_last4' => $accessCodeLast4,
            ]);

            $payload = [
                'message' => 'Torneo creado correctamente',
                'id' => $newId
            ];

            if ($visibility === 'private') {
                $payload['private_access_code'] = $accessCodePlain;
                $payload['private_access_code_last4'] = $accessCodeLast4;
            }

            return $this->json($res, $payload, 201);
        } catch (Throwable $e) {
            error_log('create tournament error: ' . $e->getMessage());
            return $this->json($res, ['error' => 'No se pudo crear el torneo'], 500);
        }
    }

    // Solo el creador del torneo puede editar (max_teams es inmutable)
    public function update(Request $req, Response $res, array $args): Response
    {
        $user = (array)$req->getAttribute('user');
        $userId = (int)($user['id'] ?? 0);
        $tournamentId = (int)($args['id'] ?? 0);

        if ($tournamentId <= 0) {
            return $this->json($res, ['error' => 'ID de torneo no válido'], 400);
        }

        try {
            $current = $this->tournamentRepository->findByIdWithCreator($tournamentId);

            if (!$current) {
                return $this->json($res, ['error' => 'Torneo no encontrado'], 404);
            }

            if ((int)$current['created_by'] !== $userId) {
                return $this->json($res, ['error' => 'Solo el creador del torneo puede editarlo'], 403);
            }

            $data = (array)$req->getParsedBody();

            // max_teams no editable
            if (array_key_exists('max_teams', $data)) {
                $incomingMaxTeams = (int)$data['max_teams'];
                if ($incomingMaxTeams !== (int)$current['max_teams']) {
                    return $this->json($res, ['error' => 'No se puede modificar el número máximo de equipos'], 400);
                }
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

            // Validaciones base
            if ($name === '' || $game === '' || $type === '') {
                return $this->json($res, ['error' => 'Campos requeridos: name, game, type'], 400);
            }

            if (!in_array($type, ['sports', 'esports'], true)) {
                return $this->json($res, ['error' => 'Categoría no válida (sports | esports)'], 400);
            }

            if (!in_array($format, ['league', 'single_elim'], true)) {
                return $this->json($res, ['error' => 'Formato no válido (league | single_elim)'], 400);
            }

            if (!$this->isValidDateYmd($startDate) || $startDate < date('Y-m-d')) {
                return $this->json($res, ['error' => 'La fecha de inicio no es válida'], 400);
            }

            if (!$this->isValidTimeHm($startTime)) {
                return $this->json($res, ['error' => 'start_time debe tener formato HH:MM'], 400);
            }

            $startTimeSql = $startTime . ':00';

            $isOnline = 0;
            $locationLat = null;
            $locationLng = null;

            if ($type === 'esports') {
                $isOnline = 1;
                $locationName = 'Online';
                $locationAddress = 'Online';
            } else {
                if ($locationName === '') {
                    return $this->json($res, ['error' => 'Para deportes debes indicar location_name'], 400);
                }

                if (!is_numeric($locationLatRaw) || !is_numeric($locationLngRaw)) {
                    return $this->json($res, ['error' => 'Para deportes debes indicar latitud y longitud'], 400);
                }

                $locationLat = (float)$locationLatRaw;
                $locationLng = (float)$locationLngRaw;

                if ($locationLat < -90 || $locationLat > 90 || $locationLng < -180 || $locationLng > 180) {
                    return $this->json($res, ['error' => 'Coordenadas de ubicación no válidas'], 400);
                }
            }

            $currentVisibility = $this->normalizeVisibility((string)($current['visibility'] ?? 'public'));
            $accessCodeHash = null;
            $accessCodeLast4 = null;
            $newPrivateCode = null;

            if ($visibility === 'private') {
                // Si ya era privado, conserva código.
                if ($currentVisibility === 'private' && (string)($current['access_code_hash'] ?? '') !== '') {
                    $accessCodeHash = (string)$current['access_code_hash'];
                    $accessCodeLast4 = (string)($current['access_code_last4'] ?? '');
                } else {
                    // Si pasa de público a privado, genera código nuevo automático.
                    try {
                        $newPrivateCode = $this->generateUniqueAccessCode(8);
                    } catch (Throwable $e) {
                        error_log('private code generation on update error: ' . $e->getMessage());
                        return $this->json($res, ['error' => 'No se pudo generar el código privado'], 500);
                    }
                    $accessCodeHash = password_hash($newPrivateCode, PASSWORD_DEFAULT);
                    $accessCodeLast4 = substr($newPrivateCode, -4);
                }
            }

            $this->tournamentRepository->updateTournamentById($tournamentId, [
                'name' => $name,
                'description' => ($description !== '' ? $description : null),
                'game' => $game,
                'type' => $type,
                'format' => $format,
                'start_date' => $startDate,
                'start_time' => $startTimeSql,
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

            return $this->json($res, $payload);
        } catch (Throwable $e) {
            error_log('update tournament error: ' . $e->getMessage());
            return $this->json($res, ['error' => 'No se pudo actualizar el torneo'], 500);
        }
    }

    // Resolver código privado -> id de torneo para acceso rápido desde Home
    public function resolvePrivateByCode(Request $req, Response $res): Response
    {
        $data = (array)$req->getParsedBody();
        $code = $this->sanitizeAccessCode((string)($data['access_code'] ?? ''));

        if ($code === '' || strlen($code) < 6 || strlen($code) > 16) {
            return $this->json($res, ['error' => 'Código privado no válido'], 400);
        }

        try {
            $tournament = $this->findPrivateTournamentByCode($code);

            if (!$tournament) {
                return $this->json($res, ['error' => 'Código privado incorrecto o torneo no encontrado'], 404);
            }

            return $this->json($res, [
                'tournament_id' => (int)$tournament['id']
            ]);
        } catch (Throwable $e) {
            error_log('resolve private code error: ' . $e->getMessage());
            return $this->json($res, ['error' => 'No se pudo resolver el código privado'], 500);
        }
    }

    // Join: para privados exige access_code en body/header/query
    public function join(Request $req, Response $res, array $args): Response
    {
        $user = (array)$req->getAttribute('user');
        $userId = (int)($user['id'] ?? 0);
        $tournamentId = (int)($args['id'] ?? 0);
        $data = (array)$req->getParsedBody();

        $teamName = trim((string)($data['team_name'] ?? ''));

        if ($tournamentId <= 0) {
            return $this->json($res, ['error' => 'ID de torneo no válido'], 400);
        }

        if ($teamName === '') {
            return $this->json($res, ['error' => 'El nombre del equipo es obligatorio'], 400);
        }

        if (mb_strlen($teamName) < 2 || mb_strlen($teamName) > 100) {
            return $this->json($res, ['error' => 'El nombre del equipo debe tener entre 2 y 100 caracteres'], 400);
        }

        try {
            $this->db->beginTransaction();

            $tournament = $this->tournamentRepository->findByIdForUpdate($tournamentId);

            if (!$tournament) {
                $this->db->rollBack();
                return $this->json($res, ['error' => 'Torneo no encontrado'], 404);
            }

            if (($tournament['visibility'] ?? 'public') === 'private') {
                $code = $this->resolveAccessCodeFromRequest($req, $data);
                if (!$this->verifyAccessCode($code, (string)($tournament['access_code_hash'] ?? ''))) {
                    $this->db->rollBack();
                    return $this->json($res, ['error' => 'Código de acceso inválido para torneo privado'], 403);
                }
            }

            // No permitir 2 equipos del mismo capitán en el mismo torneo
            if ($this->tournamentRepository->captainHasTeamInTournament($tournamentId, $userId)) {
                $this->db->rollBack();
                return $this->json($res, ['error' => 'Ya tienes un equipo inscrito en este torneo'], 409);
            }

            // Nombre de equipo único por torneo (case-insensitive)
            if ($this->tournamentRepository->teamNameExistsInTournament($tournamentId, $teamName)) {
                $this->db->rollBack();
                return $this->json($res, ['error' => 'Ya existe un equipo con ese nombre en este torneo'], 409);
            }

            $currentTeams = $this->tournamentRepository->countTeams($tournamentId);

            if ($currentTeams >= (int)$tournament['max_teams']) {
                $this->db->rollBack();
                return $this->json($res, ['error' => 'El torneo ya está lleno'], 409);
            }

            $this->tournamentRepository->insertTeam($tournamentId, $teamName, $userId);

            $this->db->commit();
            return $this->json($res, ['message' => 'Equipo inscrito correctamente'], 201);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            error_log('join tournament error: ' . $e->getMessage());
            return $this->json($res, ['error' => 'No se pudo completar la inscripción'], 500);
        }
    }

    private function findPrivateTournamentByCode(string $code): ?array
    {
        $last4 = substr($code, -4);

        // Búsqueda rápida por últimos 4 caracteres
        $candidates = $this->tournamentRepository->findPrivateCandidatesByLast4($last4);

        foreach ($candidates as $row) {
            if ($this->verifyAccessCode($code, (string)($row['access_code_hash'] ?? ''))) {
                return $row;
            }
        }

        // Fallback para torneos legacy sin access_code_last4 rellenado
        $legacyCandidates = $this->tournamentRepository->findLegacyPrivateCandidates();

        foreach ($legacyCandidates as $row) {
            if ($this->verifyAccessCode($code, (string)($row['access_code_hash'] ?? ''))) {
                return $row;
            }
        }

        return null;
    }

    private function getRequestUserFromToken(Request $req): array
    {
        $authHeader = $req->getHeaderLine('Authorization');
        if ($authHeader === '' || stripos($authHeader, 'Bearer ') !== 0) {
            return [];
        }

        $token = trim(substr($authHeader, 7));
        if ($token === '') {
            return [];
        }

        try {
            $secret = (string)($_ENV['JWT_SECRET'] ?? '');
            if ($secret === '') {
                return [];
            }

            $decoded = JWT::decode($token, new Key($secret, 'HS256'));
            $payload = (array)$decoded;

            return [
                'id' => (int)($payload['id'] ?? 0),
                'role' => (string)($payload['role'] ?? ''),
            ];
        } catch (Throwable $e) {
            return [];
        }
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

        // Retrocompatibilidad temporal con enlaces ?code=
        return $this->sanitizeAccessCode((string)($req->getQueryParams()['code'] ?? ''));
    }

    private function normalizeVisibility(string $value): string
    {
        $v = strtolower(trim($value));
        return $v === 'private' ? 'private' : 'public';
    }

    private function verifyAccessCode(string $candidate, string $hash): bool
    {
        if ($candidate === '' || $hash === '') {
            return false;
        }
        return password_verify($candidate, $hash);
    }

    private function sanitizeAccessCode(string $code): string
    {
        return (string)preg_replace('/[^a-zA-Z0-9]/', '', strtoupper(trim($code)));
    }

    // Evita vocales para reducir riesgo de palabras obscenas
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

    // Garantiza que no se repite contra códigos privados ya existentes
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
        // optimización: comparar solo candidatos con mismo last4
        $last4 = substr($candidate, -4);
        $rows = $this->tournamentRepository->candidatesByLast4ForUniqueness($last4);

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

    private function json(Response $res, array $payload, int $status = 200): Response
    {
        $res->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $res->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}