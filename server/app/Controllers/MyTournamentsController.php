<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

require_once __DIR__ . '/../Core/Http.php';
require_once __DIR__ . '/../Services/MyTournamentsService.php';

class MyTournamentsController
{
    private MyTournamentsService $service;

    public function __construct(PDO $db)
    {
        $this->service = new MyTournamentsService($db);
    }

    public function getMine(Request $req, Response $res): Response
    {
        $user = (array)$req->getAttribute('user');
        $result = $this->service->getMine((int)($user['id'] ?? 0));
        return Http::fromServiceResult($res, $result);
    }
}