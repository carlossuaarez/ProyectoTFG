<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

require_once __DIR__ . '/../Core/Http.php';
require_once __DIR__ . '/../Services/TournamentService.php';
require_once __DIR__ . '/../Services/AccessControlService.php';

class TournamentController
{
    private TournamentService $service;

    public function __construct(PDO $db)
    {
        $this->service = new TournamentService($db);
    }

    public function getAll(Request $req, Response $res): Response
    {
        return Http::fromServiceResult($res, $this->service->getAll());
    }

    public function getById(Request $req, Response $res, array $args): Response
    {
        $accessCode = AccessControlService::resolveAccessCodeFromRequest($req);
        $requestUser = $this->getRequestUserFromToken($req);
        $result = $this->service->getById((int)($args['id'] ?? 0), $requestUser, $accessCode);
        return Http::fromServiceResult($res, $result);
    }

    public function create(Request $req, Response $res): Response
    {
        $user = (array)$req->getAttribute('user');
        $result = $this->service->create((int)($user['id'] ?? 0), (array)$req->getParsedBody());
        return Http::fromServiceResult($res, $result);
    }

    public function update(Request $req, Response $res, array $args): Response
    {
        $user = (array)$req->getAttribute('user');
        $result = $this->service->update(
            (int)($user['id'] ?? 0),
            (int)($args['id'] ?? 0),
            (array)$req->getParsedBody()
        );
        return Http::fromServiceResult($res, $result);
    }

    public function resolvePrivateByCode(Request $req, Response $res): Response
    {
        $data = (array)$req->getParsedBody();
        return Http::fromServiceResult($res, $this->service->resolvePrivateByCode((string)($data['access_code'] ?? '')));
    }

    public function join(Request $req, Response $res, array $args): Response
    {
        $user = (array)$req->getAttribute('user');
        $body = (array)$req->getParsedBody();
        $accessCode = AccessControlService::resolveAccessCodeFromRequest($req, $body);
        $result = $this->service->join(
            (int)($user['id'] ?? 0),
            (int)($args['id'] ?? 0),
            $body,
            $accessCode
        );
        return Http::fromServiceResult($res, $result);
    }

    private function getRequestUserFromToken(Request $req): array
    {
        $authHeader = $req->getHeaderLine('Authorization');
        if ($authHeader === '' || stripos($authHeader, 'Bearer ') !== 0) {
            return [];
        }
        $token = trim(substr($authHeader, 7));
        if ($token === '') return [];

        try {
            $secret = (string)($_ENV['JWT_SECRET'] ?? '');
            if ($secret === '') return [];
            $decoded = JWT::decode($token, new Key($secret, 'HS256'));
            $payload = (array)$decoded;
            return [
                'id' => (int)($payload['id'] ?? 0),
                'role' => (string)($payload['role'] ?? ''),
            ];
        } catch (Throwable $e) {
            return [];
        }
    }
}