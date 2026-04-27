<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class TournamentController
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // Listado público (solo torneos public)
    public function getAll(Request $req, Response $res): Response
    {
        try {
            $stmt = $this->db->query("
                SELECT
                    id, name, description, game, type, max_teams, format,
                    start_date, start_time, prize,
                    location_name, location_address, location_lat, location_lng, is_online,
                    visibility, created_by, created_at
                FROM tournaments
                WHERE visibility = 'public'
                ORDER BY start_date ASC, start_time ASC, id DESC
            ");
            $tournaments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return $this->json($res, $tournaments);
        } catch (Throwable $e) {
            error_log('getAll tournaments error: ' . $e->getMessage());
            return $this->json($res, ['error' => 'No se pudieron cargar los torneos'], 500);
        }
    }

    // Detalle: si es privado, requiere code en query (?code=XXXX)
    public function getById(Request $req, Response $res, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);
        if ($id <= 0) {
            return $this->json($res, ['error' => 'ID de torneo no válido'], 400);
        }

        try {
            $stmt = $this->db->prepare("SELECT * FROM tournaments WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $tournament = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$tournament) {
                return $this->json($res, ['error' => 'Torneo no encontrado'], 404);
            }

            if (($tournament['visibility'] ?? 'public') === 'private') {
                $queryCode = $this->sanitizeAccessCode((string)($req->getQueryParams()['code'] ?? ''));
                $hash = (string)($tournament['access_code_hash'] ?? '');

                if (!$this->verifyAccessCode($queryCode, $hash)) {
                    return $this->json($res, [
                        'error' => 'Este torneo es privado. Introduce el código de acceso.',
                        'requires_access_code' => true
                    ], 403);
                }
            }

            $stmtTeams = $this->db->prepare("
                SELECT id, tournament_id, name, captain_id, registered_at
                FROM teams
                WHERE tournament_id = ?
                ORDER BY registered_at ASC
            ");
            $stmtTeams->execute([$id]);
            $tournament['teams'] = $stmtTeams->fetchAll(PDO::FETCH_ASSOC);

            // Nunca devolver hash
            unset($tournament['access_code_hash']);

            return $this->json($res, $tournament);
        } catch (Throwable $e) {
            error_log('getById tournament error: ' . $e->getMessage());
            return $this->json($res, ['error' => 'No se pudo cargar el detalle del torneo'], 500);
        }
    }

    public function create(Request $req, Response $res): Response
    {
        $user = $req->getAttribute('user');
        $data = (array)$req->getParsedBody();

        $name = trim((string)($data['name'] ?? ''));
        $description = trim((string)($data['description'] ?? ''));
        $game = trim((string)($data['game'] ?? ''));
        $type = trim((string)($data['type'] ?? '')); // sports | esports
        $maxTeams = (int)($data['max_teams'] ?? 0);
        $format = trim((string)($data['format'] ?? 'single_elim')); // league | single_elim
        $startDate = trim((string)($data['start_date'] ?? ''));
        $startTime = trim((string)($data['start_time'] ?? ''));
        $prize = trim((string)($data['prize'] ?? ''));

        $visibility = trim((string)($data['visibility'] ?? 'public')); // public | private
        $requestedAccessCode = trim((string)($data['access_code'] ?? ''));

        $locationName = trim((string)($data['location_name'] ?? ''));
        $locationAddress = trim((string)($data['location_address'] ?? ''));
        $locationLatRaw = $data['location_lat'] ?? null;
        $locationLngRaw = $data['location_lng'] ?? null;

        // Validaciones base
        if ($name === '' || $description === '' || $game === '' || $type === '') {
            return $this->json($res, ['error' => 'Campos requeridos: name, description, game, type'], 400);
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

        if (mb_strlen($name) > 100) {
            return $this->json($res, ['error' => 'El nombre excede 100 caracteres'], 400);
        }

        if (mb_strlen($description) < 10 || mb_strlen($description) > 2000) {
            return $this->json($res, ['error' => 'La descripción debe tener entre 10 y 2000 caracteres'], 400);
        }

        if (mb_strlen($game) > 50) {
            return $this->json($res, ['error' => 'El deporte/juego excede 50 caracteres'], 400);
        }

        if ($prize !== '' && mb_strlen($prize) > 255) {
            return $this->json($res, ['error' => 'El premio excede 255 caracteres'], 400);
        }

        if (!$this->isValidDateYmd($startDate)) {
            return $this->json($res, ['error' => 'start_date debe tener formato YYYY-MM-DD'], 400);
        }

        if ($startDate < date('Y-m-d')) {
            return $this->json($res, ['error' => 'La fecha de inicio no puede ser anterior a hoy'], 400);
        }

        if (!$this->isValidTimeHm($startTime)) {
            return $this->json($res, ['error' => 'start_time debe tener formato HH:MM'], 400);
        }

        $startTimeSql = $startTime . ':00';

        if (!in_array($visibility, ['public', 'private'], true)) {
            return $this->json($res, ['error' => 'visibility debe ser public o private'], 400);
        }

        // Ubicación por tipo
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

        // Privado: generar o validar código
        $accessCodePlain = null;
        $accessCodeHash = null;
        $accessCodeLast4 = null;

        if ($visibility === 'private') {
            $accessCodePlain = $this->sanitizeAccessCode($requestedAccessCode);

            if ($accessCodePlain === '') {
                $accessCodePlain = $this->generateAccessCode(8);
            }

            if (strlen($accessCodePlain) < 6 || strlen($accessCodePlain) > 16) {
                return $this->json($res, ['error' => 'El código privado debe tener entre 6 y 16 caracteres alfanuméricos'], 400);
            }

            $accessCodeHash = password_hash($accessCodePlain, PASSWORD_DEFAULT);
            $accessCodeLast4 = substr($accessCodePlain, -4);
        }

        try {
            $stmt = $this->db->prepare("
                INSERT INTO tournaments (
                    name, description, game, type, max_teams, format, start_date, start_time, prize, created_by,
                    location_name, location_address, location_lat, location_lng, is_online,
                    visibility, access_code_hash, access_code_last4
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $name,
                $description,
                $game,
                $type,
                $maxTeams,
                $format,
                $startDate,
                $startTimeSql,
                ($prize !== '' ? $prize : null),
                (int)$user['id'],
                ($locationName !== '' ? $locationName : null),
                ($locationAddress !== '' ? $locationAddress : null),
                $locationLat,
                $locationLng,
                $isOnline,
                $visibility,
                $accessCodeHash,
                $accessCodeLast4
            ]);

            $newId = (int)$this->db->lastInsertId();

            $payload = [
                'message' => 'Torneo creado correctamente',
                'id' => $newId
            ];

            if ($visibility === 'private') {
                $payload['private_access_code'] = $accessCodePlain;
                $payload['private_access_code_last4'] = $accessCodeLast4;
            }

            return $this->json($res, $payload, 201);
        } catch (PDOException $e) {
            error_log('create tournament sql error: ' . $e->getMessage());
            return $this->json($res, ['error' => 'No se pudo crear el torneo'], 500);
        } catch (Throwable $e) {
            error_log('create tournament error: ' . $e->getMessage());
            return $this->json($res, ['error' => 'Error interno al crear torneo'], 500);
        }
    }

    // Join: para privados exige access_code en body
    public function join(Request $req, Response $res, array $args): Response
    {
        $user = $req->getAttribute('user');
        $userId = (int)($user['id'] ?? 0);
        $tournamentId = (int)($args['id'] ?? 0);
        $data = (array)$req->getParsedBody();

        $teamName = trim((string)($data['team_name'] ?? ''));
        $accessCode = $this->sanitizeAccessCode((string)($data['access_code'] ?? ''));

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

            $stmtTournament = $this->db->prepare("
                SELECT id, max_teams, visibility, access_code_hash
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

            if (($tournament['visibility'] ?? 'public') === 'private') {
                if (!$this->verifyAccessCode($accessCode, (string)($tournament['access_code_hash'] ?? ''))) {
                    $this->db->rollBack();
                    return $this->json($res, ['error' => 'Código de acceso inválido para torneo privado'], 403);
                }
            }

            // No permitir 2 equipos del mismo capitán en el mismo torneo
            $stmtCaptain = $this->db->prepare("
                SELECT id FROM teams WHERE tournament_id = ? AND captain_id = ? LIMIT 1
            ");
            $stmtCaptain->execute([$tournamentId, $userId]);
            if ($stmtCaptain->fetch(PDO::FETCH_ASSOC)) {
                $this->db->rollBack();
                return $this->json($res, ['error' => 'Ya tienes un equipo inscrito en este torneo'], 409);
            }

            // Nombre de equipo único por torneo (case-insensitive)
            $stmtName = $this->db->prepare("
                SELECT id FROM teams WHERE tournament_id = ? AND LOWER(name) = LOWER(?) LIMIT 1
            ");
            $stmtName->execute([$tournamentId, $teamName]);
            if ($stmtName->fetch(PDO::FETCH_ASSOC)) {
                $this->db->rollBack();
                return $this->json($res, ['error' => 'Ya existe un equipo con ese nombre en este torneo'], 409);
            }

            $stmtCount = $this->db->prepare("SELECT COUNT(*) FROM teams WHERE tournament_id = ?");
            $stmtCount->execute([$tournamentId]);
            $currentTeams = (int)$stmtCount->fetchColumn();

            if ($currentTeams >= (int)$tournament['max_teams']) {
                $this->db->rollBack();
                return $this->json($res, ['error' => 'El torneo ya está lleno'], 409);
            }

            $stmtInsert = $this->db->prepare("
                INSERT INTO teams (tournament_id, name, captain_id)
                VALUES (?, ?, ?)
            ");
            $stmtInsert->execute([$tournamentId, $teamName, $userId]);

            $this->db->commit();
            return $this->json($res, ['message' => 'Equipo inscrito correctamente'], 201);
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            if ((int)$e->getCode() === 23000 || str_contains($e->getMessage(), 'Duplicate entry')) {
                return $this->json($res, ['error' => 'Datos duplicados al inscribir equipo'], 409);
            }

            error_log('join tournament sql error: ' . $e->getMessage());
            return $this->json($res, ['error' => 'No se pudo completar la inscripción'], 500);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            error_log('join tournament error: ' . $e->getMessage());
            return $this->json($res, ['error' => 'Error interno al inscribir equipo'], 500);
        }
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
        $clean = preg_replace('/[^a-zA-Z0-9]/', '', strtoupper(trim($code)));
        return (string)$clean;
    }

    private function generateAccessCode(int $length = 8): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $max = strlen($alphabet) - 1;
        $result = '';

        for ($i = 0; $i < $length; $i++) {
            $result .= $alphabet[random_int(0, $max)];
        }

        return $result;
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