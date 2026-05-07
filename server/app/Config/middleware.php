<?php

use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

function registerGlobalMiddleware(App $app, bool $appDebug, string $allowedOrigin): void
{
    // Limite de tamaño de body para evitar payloads gigantes
    $app->add(function (Request $request, $handler) use ($app) {
        $configuredMax = (int)($_ENV['MAX_REQUEST_BODY_BYTES'] ?? 3145728);
        $maxBytes = $configuredMax > 0 ? $configuredMax : 3145728;
        $contentLength = (int)$request->getHeaderLine('Content-Length');

        if ($contentLength > $maxBytes) {
            $response = $app->getResponseFactory()->createResponse(413);
            $response->getBody()->write(json_encode(['error' => 'Payload demasiado grande']));
            return $response->withHeader('Content-Type', 'application/json');
        }

        return $handler->handle($request);
    });

    $app->addBodyParsingMiddleware();
    $app->addRoutingMiddleware();

    // Servir avatares locales en /uploads/*
    $app->add(function (Request $request, $handler) use ($app) {
        $path = rawurldecode($request->getUri()->getPath());

        if (str_starts_with($path, '/uploads/')) {
            $baseUploadsDir = realpath(__DIR__ . '/../../public/uploads');
            if ($baseUploadsDir === false) {
                $response = $app->getResponseFactory()->createResponse(404);
                $response->getBody()->write('Not found');
                return $response->withHeader('Content-Type', 'text/plain');
            }

            $relative = ltrim(substr($path, strlen('/uploads/')), '/');
            $candidatePath = realpath($baseUploadsDir . DIRECTORY_SEPARATOR . $relative);

            if ($candidatePath === false || !str_starts_with($candidatePath, $baseUploadsDir) || !is_file($candidatePath)) {
                $response = $app->getResponseFactory()->createResponse(404);
                $response->getBody()->write('Not found');
                return $response->withHeader('Content-Type', 'text/plain');
            }

            $mime = mime_content_type($candidatePath) ?: 'application/octet-stream';
            $response = $app->getResponseFactory()->createResponse(200);
            $response->getBody()->write((string)file_get_contents($candidatePath));

            return $response
                ->withHeader('Content-Type', $mime)
                ->withHeader('Cache-Control', 'public, max-age=86400');
        }

        return $handler->handle($request);
    });

    $errorMiddleware = $app->addErrorMiddleware($appDebug, true, $appDebug);
    $errorMiddleware->getDefaultErrorHandler()->forceContentType('application/json');

    // CORS
    $app->add(function ($request, $handler) use ($allowedOrigin) {
        $response = $handler->handle($request);
        return $response
            ->withHeader('Access-Control-Allow-Origin', $allowedOrigin)
            ->withHeader('Vary', 'Origin')
            ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization, X-Tournament-Code')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS');
    });
}