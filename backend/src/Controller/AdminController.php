<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AdminController
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // Panel admin: listar todos los torneos con campos ampliados
    public function getAllTournaments(Request $req, Response $res): Response
    {
        $user = (array)$req->getAttribute('user');
        if (($user['role'] ?? '') !== 'admin') {
            return $this->json($res, ['error' => 'No autorizado'], 403);
        }

        try {
            $stmt = $this->db->query("
                SELECT
                    t.id,
                    t.name,
                    t.description,
                    t.game,
                    t.type,
                    t.max_teams,
                    t.format,
                    t.start_date,
                    t.start_time,
                    t.prize,
                    t.location_name,
                    t.location_address,
                    t.location_lat,
                    t.location_lng,
                    t.is_online,
                    t.visibility,
                    t.access_code_last4,
                    t.created_by,
                    t.created_at
                FROM tournaments t
                ORDER BY t.start_date ASC, t.start_time ASC, t.created_at DESC
            ");

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Seguridad: nunca exponer access_code_hash en respuestas
            foreach ($rows as &$row) {
                unset($row['access_code_hash']);
            }

            return $this->json($res, $rows);
        } catch (Throwable $e) {
            error_log('admin getAllTournaments error: ' . $e->getMessage());
            return $this->json($res, ['error' => 'No se pudo cargar el panel de administración'], 500);
        }
    }

    public function deleteTournament(Request $req, Response $res, array $args): Response
    {
        $user = (array)$req->getAttribute('user');
        if (($user['role'] ?? '') !== 'admin') {
            return $this->json($res, ['error' => 'No autorizado'], 403);
        }

        $id = (int)($args['id'] ?? 0);
        if ($id <= 0) {
            return $this->json($res, ['error' => 'ID de torneo no válido'], 400);
        }

        try {
            $stmt = $this->db->prepare("DELETE FROM tournaments WHERE id = ?");
            $stmt->execute([$id]);

            if ($stmt->rowCount() === 0) {
                return $this->json($res, ['error' => 'Torneo no encontrado'], 404);
            }

            return $this->json($res, ['message' => 'Torneo eliminado']);
        } catch (Throwable $e) {
            error_log('admin deleteTournament error: ' . $e->getMessage());
            return $this->json($res, ['error' => 'No se pudo eliminar el torneo'], 500);
        }
    }

    private function json(Response $res, array $payload, int $status = 200): Response
    {
        $res->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $res->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}