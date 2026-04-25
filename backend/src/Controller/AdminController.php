<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AdminController {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    // Obtener todos los torneos (para el panel, incluyendo los no empezados)
    public function getAllTournaments(Request $req, Response $res) {
        $user = $req->getAttribute('user');
        if ($user['role'] !== 'admin') {
            $res->getBody()->write(json_encode(['error' => 'No autorizado']));
            return $res->withStatus(403)->withHeader('Content-Type', 'application/json');
        }

        $stmt = $this->db->query("SELECT * FROM tournaments ORDER BY created_at DESC");
        $tournaments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $res->getBody()->write(json_encode($tournaments));
        return $res->withHeader('Content-Type', 'application/json');
    }

    // Eliminar un torneo
    public function deleteTournament(Request $req, Response $res, array $args) {
        $user = $req->getAttribute('user');
        if ($user['role'] !== 'admin') {
            $res->getBody()->write(json_encode(['error' => 'No autorizado']));
            return $res->withStatus(403)->withHeader('Content-Type', 'application/json');
        }

        $id = $args['id'];
        $stmt = $this->db->prepare("DELETE FROM tournaments WHERE id = ?");
        $stmt->execute([$id]);
        $res->getBody()->write(json_encode(['message' => 'Torneo eliminado']));
        return $res->withHeader('Content-Type', 'application/json');
    }
}