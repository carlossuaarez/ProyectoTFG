<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

require_once __DIR__ . '/../Core/Http.php';
require_once __DIR__ . '/../Services/MatchService.php';
require_once __DIR__ . '/../Services/AccessControlService.php';

class MatchController
{
    private MatchService $service;

    public function __construct(PDO $db)
    {
        $this->service = new MatchService($db);
    }

    public function getTournamentMatches(Request $req, Response $res, array $args): Response
    {
        $accessCode = AccessControlService::resolveAccessCodeFromRequest($req);
        $result = $this->service->getTournamentMatches(
            (array)$req->getAttribute('user'),
            (int)($args['id'] ?? 0),
            $accessCode
        );
        return Http::fromServiceResult($res, $result);
    }

    public function bootstrapTournamentBracket(Request $req, Response $res, array $args): Response
    {
        $result = $this->service->bootstrapTournamentBracket(
            (array)$req->getAttribute('user'),
            (int)($args['id'] ?? 0)
        );
        return Http::fromServiceResult($res, $result);
    }

    public function getMatchCenter(Request $req, Response $res, array $args): Response
    {
        $accessCode = AccessControlService::resolveAccessCodeFromRequest($req);
        $result = $this->service->getMatchCenter(
            (array)$req->getAttribute('user'),
            (int)($args['id'] ?? 0),
            $accessCode
        );
        return Http::fromServiceResult($res, $result);
    }

    public function updateMatchStatus(Request $req, Response $res, array $args): Response
    {
        $data = (array)$req->getParsedBody();
        $result = $this->service->updateMatchStatus(
            (array)$req->getAttribute('user'),
            (int)($args['id'] ?? 0),
            trim((string)($data['status'] ?? ''))
        );
        return Http::fromServiceResult($res, $result);
    }

    public function submitMatchScore(Request $req, Response $res, array $args): Response
    {
        $result = $this->service->submitMatchScore(
            (array)$req->getAttribute('user'),
            (int)($args['id'] ?? 0),
            (array)$req->getParsedBody()
        );
        return Http::fromServiceResult($res, $result);
    }

    public function overrideMatchScore(Request $req, Response $res, array $args): Response
    {
        $result = $this->service->overrideMatchScore(
            (array)$req->getAttribute('user'),
            (int)($args['id'] ?? 0),
            (array)$req->getParsedBody()
        );
        return Http::fromServiceResult($res, $result);
    }

    public function confirmMatchResult(Request $req, Response $res, array $args): Response
    {
        $result = $this->service->confirmMatchResult(
            (array)$req->getAttribute('user'),
            (int)($args['id'] ?? 0)
        );
        return Http::fromServiceResult($res, $result);
    }

    public function openDispute(Request $req, Response $res, array $args): Response
    {
        $result = $this->service->openDispute(
            (array)$req->getAttribute('user'),
            (int)($args['id'] ?? 0),
            (array)$req->getParsedBody()
        );
        return Http::fromServiceResult($res, $result);
    }

    public function updateDispute(Request $req, Response $res, array $args): Response
    {
        $result = $this->service->updateDispute(
            (array)$req->getAttribute('user'),
            (int)($args['id'] ?? 0),
            (int)($args['disputeId'] ?? 0),
            (array)$req->getParsedBody()
        );
        return Http::fromServiceResult($res, $result);
    }
}