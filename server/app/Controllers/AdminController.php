<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

require_once __DIR__ . '/../Core/Http.php';
require_once __DIR__ . '/../Services/AdminService.php';

class AdminController
{
    private AdminService $service;

    public function __construct(PDO $db)
    {
        $this->service = new AdminService($db);
    }

    public function getAllTournaments(Request $req, Response $res): Response
    {
        $result = $this->service->getAllTournaments((array)$req->getAttribute('user'));
        return Http::fromServiceResult($res, $result);
    }

    public function deleteTournament(Request $req, Response $res, array $args): Response
    {
        $result = $this->service->deleteTournament(
            (array)$req->getAttribute('user'),
            (int)($args['id'] ?? 0)
        );
        return Http::fromServiceResult($res, $result);
    }
}