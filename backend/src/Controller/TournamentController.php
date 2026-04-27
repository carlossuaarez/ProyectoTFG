<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class TournamentController {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    // Listar todos los torneos
    public function getAll(Request $req, Response $res) {
        $stmt = $this->db->query("SELECT * FROM tournaments ORDER BY start_date DESC");
        $tournaments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $res->getBody()->write(json_encode($tournaments));
        return $res->withHeader('Content-Type', 'application/json');
    }

    // Obtener un torneo por ID
    public function getById(Request $req, Response $res, array $args) {
        $id = $args['id'];
        $stmt = $this->db->prepare("SELECT * FROM tournaments WHERE id = ?");
        $stmt->execute([$id]);
        $tournament = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$tournament) {
            $res->getBody()->write(json_encode(['error' => 'Torneo no encontrado']));
            return $res->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        // También obtener los equipos inscritos
        $stmtTeams = $this->db->prepare("SELECT * FROM teams WHERE tournament_id = ?");
        $stmtTeams->execute([$id]);
        $tournament['teams'] = $stmtTeams->fetchAll(PDO::FETCH_ASSOC);

        $res->getBody()->write(json_encode($tournament));
        return $res->withHeader('Content-Type', 'application/json');
    }

    // Crear un torneo (requiere token)
    public function create(Request $req, Response $res) {
        $user = $req->getAttribute('user');
        $data = $req->getParsedBody();

        $name = $data['name'] ?? '';
        $game = $data['game'] ?? '';
        $type = $data['type'] ?? '';
        $max_teams = $data['max_teams'] ?? 0;
        $format = $data['format'] ?? 'single_elim';
        $start_date = $data['start_date'] ?? date('Y-m-d');
        $prize = $data['prize'] ?? '';

        if (empty($name) || empty($game) || empty($type)) {
            $res->getBody()->write(json_encode(['error' => 'Campos requeridos: name, game, type']));
            return $res->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $stmt = $this->db->prepare(
            "INSERT INTO tournaments (name, game, type, max_teams, format, start_date, prize, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([$name, $game, $type, $max_teams, $format, $start_date, $prize, $user['id']]);

        $newId = $this->db->lastInsertId();
        $res->getBody()->write(json_encode(['message' => 'Torneo creado', 'id' => $newId]));
        return $res->withStatus(201)->withHeader('Content-Type', 'application/json');
    }

    // Inscribir un equipo a un torneo
    public function join(Request $req, Response $res, array $args) {
        $user = $req->getAttribute('user');
        $tournamentId = $args['id'];
        $data = $req->getParsedBody();
        $teamName = $data['team_name'] ?? '';

        if (empty($teamName)) {
            $res->getBody()->write(json_encode(['error' => 'El nombre del equipo es obligatorio']));
            return $res->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        try {
            $this->db->beginTransaction();

            // Bloquea el torneo durante la comprobación para evitar sobreinscripciones concurrentes.
            $stmtTournament = $this->db->prepare("SELECT * FROM tournaments WHERE id = ? FOR UPDATE");
            $stmtTournament->execute([$tournamentId]);
            $tournament = $stmtTournament->fetch();
            if (!$tournament) {
                $this->db->rollBack();
                $res->getBody()->write(json_encode(['error' => 'Torneo no encontrado']));
                return $res->withStatus(404)->withHeader('Content-Type', 'application/json');
            }

            $stmtCount = $this->db->prepare("SELECT COUNT(*) FROM teams WHERE tournament_id = ?");
            $stmtCount->execute([$tournamentId]);
            $currentTeams = (int) $stmtCount->fetchColumn();

            if ($currentTeams >= (int) $tournament['max_teams']) {
                $this->db->rollBack();
                $res->getBody()->write(json_encode(['error' => 'El torneo ya está lleno']));
                return $res->withStatus(409)->withHeader('Content-Type', 'application/json');
            }

            $stmtInsert = $this->db->prepare(
                "INSERT INTO teams (tournament_id, name, captain_id) VALUES (?, ?, ?)"
            );
            $stmtInsert->execute([$tournamentId, $teamName, $user['id']]);

            $this->db->commit();
            $res->getBody()->write(json_encode(['message' => 'Equipo inscrito correctamente']));
            return $res->withStatus(201)->withHeader('Content-Type', 'application/json');
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $res->getBody()->write(json_encode(['error' => 'No se pudo inscribir el equipo']));
            return $res->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }
}