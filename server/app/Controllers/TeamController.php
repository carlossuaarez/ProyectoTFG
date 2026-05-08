<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

require_once __DIR__ . '/../Core/Http.php';
require_once __DIR__ . '/../Services/TeamService.php';
require_once __DIR__ . '/../Services/AccessControlService.php';

class TeamController
{
    private TeamService $service;

    public function __construct(PDO $db)
    {
        $this->service = new TeamService($db);
    }

    public function getTournamentTeams(Request $req, Response $res, array $args): Response
    {
        $accessCode = AccessControlService::resolveAccessCodeFromRequest($req);
        $result = $this->service->getTournamentTeams(
            (array)$req->getAttribute('user'),
            (int)($args['id'] ?? 0),
            $accessCode
        );
        return Http::fromServiceResult($res, $result);
    }

    public function createTeamOrWaitlist(Request $req, Response $res, array $args): Response
    {
        $body = (array)$req->getParsedBody();
        $accessCode = AccessControlService::resolveAccessCodeFromRequest($req, $body);
        $result = $this->service->createTeamOrWaitlist(
            (array)$req->getAttribute('user'),
            (int)($args['id'] ?? 0),
            $body,
            $accessCode
        );
        return Http::fromServiceResult($res, $result);
    }

    public function createInvite(Request $req, Response $res, array $args): Response
    {
        $result = $this->service->createInvite(
            (array)$req->getAttribute('user'),
            (int)($args['id'] ?? 0),
            (array)$req->getParsedBody()
        );
        return Http::fromServiceResult($res, $result);
    }

    public function acceptInvite(Request $req, Response $res, array $args): Response
    {
        $user = (array)$req->getAttribute('user');
        $result = $this->service->acceptInvite(
            (int)($user['id'] ?? 0),
            (string)($args['code'] ?? '')
        );
        return Http::fromServiceResult($res, $result);
    }

    public function validateMember(Request $req, Response $res, array $args): Response
    {
        $user = (array)$req->getAttribute('user');
        $result = $this->service->validateMember(
            (int)($user['id'] ?? 0),
            (int)($args['teamId'] ?? 0),
            (int)($args['memberId'] ?? 0)
        );
        return Http::fromServiceResult($res, $result);
    }

    public function updateMemberRole(Request $req, Response $res, array $args): Response
    {
        $user = (array)$req->getAttribute('user');
        $data = (array)$req->getParsedBody();
        $result = $this->service->updateMemberRole(
            (int)($user['id'] ?? 0),
            (int)($args['teamId'] ?? 0),
            (int)($args['memberId'] ?? 0),
            trim((string)($data['role'] ?? ''))
        );
        return Http::fromServiceResult($res, $result);
    }
}