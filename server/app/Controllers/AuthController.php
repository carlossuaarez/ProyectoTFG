<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

require_once __DIR__ . '/../Services/AuthService.php';

class AuthController
{
    private AuthService $authService;

    public function __construct(PDO $db, MailService $mailService)
    {
        $this->authService = new AuthService($db, $mailService);
    }

    public function register(Request $req, Response $res): Response
    {
        $result = $this->authService->register((array)$req->getParsedBody());
        return $this->json($res, $result['payload'], $result['status']);
    }

    public function login(Request $req, Response $res): Response
    {
        $result = $this->authService->login((array)$req->getParsedBody());
        return $this->json($res, $result['payload'], $result['status']);
    }

    public function googleLogin(Request $req, Response $res): Response
    {
        $result = $this->authService->googleLogin((array)$req->getParsedBody());
        return $this->json($res, $result['payload'], $result['status']);
    }

    public function verify2fa(Request $req, Response $res): Response
    {
        $result = $this->authService->verify2fa((array)$req->getParsedBody());
        return $this->json($res, $result['payload'], $result['status']);
    }

    public function me(Request $req, Response $res): Response
    {
        $authUser = (array)$req->getAttribute('user');
        $result = $this->authService->me((int)($authUser['id'] ?? 0));
        return $this->json($res, $result['payload'], $result['status']);
    }

    public function updateProfile(Request $req, Response $res): Response
    {
        $authUser = (array)$req->getAttribute('user');
        $result = $this->authService->updateProfile(
            (int)($authUser['id'] ?? 0),
            (array)$req->getParsedBody()
        );
        return $this->json($res, $result['payload'], $result['status']);
    }

    private function json(Response $res, array $payload, int $status = 200): Response
    {
        $res->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $res->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}