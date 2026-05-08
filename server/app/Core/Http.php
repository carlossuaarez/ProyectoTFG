<?php

use Psr\Http\Message\ResponseInterface as Response;

final class Http
{
    private function __construct() {}

    public static function json(Response $res, array $payload, int $status = 200): Response
    {
        $res->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $res->withStatus($status)->withHeader('Content-Type', 'application/json');
    }

    public static function fromServiceResult(Response $res, array $result): Response
    {
        $status = (int)($result['status'] ?? 200);
        $payload = (array)($result['payload'] ?? []);
        return self::json($res, $payload, $status);
    }
}