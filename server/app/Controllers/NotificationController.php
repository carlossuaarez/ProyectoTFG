<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

require_once __DIR__ . '/../Core/Http.php';
require_once __DIR__ . '/../Services/NotificationService.php';

class NotificationController
{
    private NotificationService $service;

    public function __construct(PDO $db)
    {
        $this->service = new NotificationService($db);
    }

    public function bootstrapTables(): void
    {
        $this->service->bootstrapTables();
    }

    public function listMine(Request $req, Response $res): Response
    {
        $user = (array)$req->getAttribute('user');
        $limit = (int)($req->getQueryParams()['limit'] ?? 30);
        $result = $this->service->listMine((int)($user['id'] ?? 0), $limit);
        return Http::fromServiceResult($res, $result);
    }

    public function markRead(Request $req, Response $res, array $args): Response
    {
        $user = (array)$req->getAttribute('user');
        $result = $this->service->markRead((int)($user['id'] ?? 0), (int)($args['id'] ?? 0));
        return Http::fromServiceResult($res, $result);
    }

    public function markAllRead(Request $req, Response $res): Response
    {
        $user = (array)$req->getAttribute('user');
        $result = $this->service->markAllRead((int)($user['id'] ?? 0));
        return Http::fromServiceResult($res, $result);
    }

    public function runScheduler(Request $req, Response $res): Response
    {
        $headerToken = trim((string)$req->getHeaderLine('X-Internal-Scheduler-Token'));
        $result = $this->service->runScheduler($headerToken);
        return Http::fromServiceResult($res, $result);
    }
}