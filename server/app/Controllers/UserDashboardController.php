<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

require_once __DIR__ . '/../Core/Http.php';
require_once __DIR__ . '/../Services/UserDashboardService.php';

class UserDashboardController
{
    private UserDashboardService $service;

    public function __construct(PDO $db)
    {
        $this->service = new UserDashboardService($db);
    }

    public function getMyDashboard(Request $req, Response $res): Response
    {
        $authUser = (array)$req->getAttribute('user');
        $result = $this->service->getMyDashboard((int)($authUser['id'] ?? 0));
        return Http::fromServiceResult($res, $result);
    }

    public function updateMyPrivacy(Request $req, Response $res): Response
    {
        $authUser = (array)$req->getAttribute('user');
        $result = $this->service->updateMyPrivacy(
            (int)($authUser['id'] ?? 0),
            (array)$req->getParsedBody()
        );
        return Http::fromServiceResult($res, $result);
    }

    public function getPublicProfile(Request $req, Response $res, array $args): Response
    {
        $result = $this->service->getPublicProfile((string)($args['username'] ?? ''));
        return Http::fromServiceResult($res, $result);
    }
}