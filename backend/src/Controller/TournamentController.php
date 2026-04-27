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

    public function getAll(Request $req, Response $res): Response
    {
        try {
            $stmt = $this->db->query("SELECT * FROM tournaments ORDER BY start_date DESC");
            $tournaments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return $this->json($res, $tournaments);
        } catch (Throwable $e) {
            error_log('getAll tournaments error: ' . $e->getMessage());
            return $this->json($res, ['error' => 'No se pudieron cargar los torneos'], 500);
        }
    }

    public function getById(Request $req, Response $res, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);
        if ($id <= 0) {
            return $this->json($res, ['error' => 'ID de torneo no válido'], 400);
        }

        try {
            $stmt = $this->db->prepare("SELECT * FROM tournaments WHERE id = ?");
            $stmt->execute([$id]);
            $tournament = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$tournament) {
                return $this->json($res, ['error' => 'Torneo no encontrado'], 404);
            }

            $stmtTeams = $this->db->prepare("
                SELECT id, tournament_id, name, captain_id, registered_at
                FROM teams
                WHERE tournament_id = ?
                ORDER BY registered_at ASC
            ");
            $stmtTeams->execute([$id]);
            $tournament['teams'] = $stmtTeams->fetchAll(PDO::FETCH_ASSOC);

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
        $game = trim((string)($data['game'] ?? ''));
        $type = trim((string)($data['type'] ?? ''));
        $maxTeams = (int)($data['max_teams'] ?? 0);
        $format = trim((string)($data['format'] ?? ''));
        $startDate = trim((string)($data['start_date'] ?? ''));
        $prize = trim((string)($data['prize'] ?? ''));

        $validationError = $this->validateTournamentCreateInput([
            'name' => $name,
            'game' => $game,
            'type' => $type,
            'max_teams' => $maxTeams,
            'format' => $format,
            'start_date' => $startDate,
            'prize' => $prize,
        ]);

        if ($validationError !== null) {
            return $this->json($res, ['error' => $validationError], 400);
        }

        try {
            $stmt = $this->db->prepare(
                "INSERT INTO tournaments (name, game, type, max_teams, format, start_date, prize, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $name,
                $game,
                $type,
                $maxTeams,
                $format,
                $startDate,
                ($prize !== '' ? $prize : null),
                (int)$user['id']
            ]);

            return $this->json($res, [
                'message' => 'Torneo creado',
                'id' => (int)$this->db->lastInsertId()
            ], 201);
        } catch (PDOException $e) {
            error_log('create tournament sql error: ' . $e->getMessage());
            return $this->json($res, ['error' => 'No se pudo crear el torneo'], 500);
        } catch (Throwable $e) {
            error_log('create tournament error: ' . $e->getMessage());
            return $this->json($res, ['error' => 'Error interno al crear torneo'], 500);
        }
    }

    public function join(Request $req, Response $res, array $args): Response
    {
        $user = $req->getAttribute('user');
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

            $stmtTournament = $this->db->prepare("
                SELECT id, max_teams
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

            // Regla 1: mismo capitán no puede inscribir más de un equipo en el mismo torneo
            $stmtCaptain = $this->db->prepare("
                SELECT id
                FROM teams
                WHERE tournament_id = ? AND captain_id = ?
                LIMIT 1
            ");
            $stmtCaptain->execute([$tournamentId, $userId]);
            if ($stmtCaptain->fetch(PDO::FETCH_ASSOC)) {
                $this->db->rollBack();
                return $this->json($res, ['error' => 'Ya tienes un equipo inscrito en este torneo'], 409);
            }

            // Regla 2: nombre único por torneo
            $stmtName = $this->db->prepare("
                SELECT id
                FROM teams
                WHERE tournament_id = ? AND LOWER(name) = LOWER(?)
                LIMIT 1
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

            $stmtInsert = $this->db->prepare(
                "INSERT INTO teams (tournament_id, name, captain_id) VALUES (?, ?, ?)"
            );
            $stmtInsert->execute([$tournamentId, $teamName, $userId]);

            $this->db->commit();
            return $this->json($res, ['message' => 'Equipo inscrito correctamente'], 201);
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            // Respaldo por si cae en constraint UNIQUE de BD
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

    private function validateTournamentCreateInput(array $data): ?string
    {
        $allowedTypes = ['sports', 'esports'];
        $allowedFormats = ['league', 'single_elim'];

        if ($data['name'] === '' || $data['game'] === '' || $data['type'] === '') {
            return 'Campos requeridos: name, game, type';
        }

        if (!in_array($data['type'], $allowedTypes, true)) {
            return 'Tipo de torneo no válido';
        }

        if (!in_array($data['format'], $allowedFormats, true)) {
            return 'Formato de torneo no válido';
        }

        if (!is_int($data['max_teams']) || $data['max_teams'] < 2 || $data['max_teams'] > 128) {
            return 'max_teams debe estar entre 2 y 128';
        }

        if (!$this->isValidDateYmd($data['start_date'])) {
            return 'start_date debe tener formato YYYY-MM-DD';
        }

        // Opcional: no permitir fecha anterior a hoy
        if ($data['start_date'] < date('Y-m-d')) {
            return 'La fecha de inicio no puede ser anterior a hoy';
        }

        if (mb_strlen($data['name']) > 100 || mb_strlen($data['game']) > 50) {
            return 'name o game exceden la longitud máxima';
        }

        if ($data['prize'] !== '' && mb_strlen($data['prize']) > 255) {
            return 'El premio excede la longitud máxima';
        }

        return null;
    }

    private function isValidDateYmd(string $date): bool
    {
        $dt = DateTime::createFromFormat('Y-m-d', $date);
        return $dt instanceof DateTime && $dt->format('Y-m-d') === $date;
    }

    private function json(Response $res, array $payload, int $status = 200): Response
    {
        $res->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $res->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}