<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class MyTournamentsController
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function getMine(Request $req, Response $res): Response
    {
        $user = (array)$req->getAttribute('user');
        $userId = (int)($user['id'] ?? 0);

        if ($userId <= 0) {
            return $this->json($res, ['error' => 'No autorizado'], 401);
        }

        try {
            $stmt = $this->db->prepare("
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
                    COALESCE(t.visibility, 'public') AS visibility,
                    t.created_by,
                    u.username AS created_by_username,
                    tm.name AS my_team_name,
                    tm.registered_at AS my_registered_at,
                    COALESCE(tc.teams_count, 0) AS teams_count,
                    CASE
                        WHEN COALESCE(tc.teams_count, 0) >= t.max_teams THEN 1
                        ELSE 0
                    END AS is_full
                FROM teams tm
                INNER JOIN tournaments t ON t.id = tm.tournament_id
                LEFT JOIN users u ON u.id = t.created_by
                LEFT JOIN (
                    SELECT tournament_id, COUNT(*) AS teams_count
                    FROM teams
                    GROUP BY tournament_id
                ) tc ON tc.tournament_id = t.id
                WHERE tm.captain_id = ?
                ORDER BY t.start_date ASC, t.start_time ASC, tm.registered_at DESC
            ");
            $stmt->execute([$userId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return $this->json($res, $rows);
        } catch (Throwable $e) {
            error_log('get my tournaments error: ' . $e->getMessage());
            return $this->json($res, ['error' => 'No se pudieron cargar tus torneos'], 500);
        }
    }

    private function json(Response $res, array $payload, int $status = 200): Response
    {
        $res->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $res->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}