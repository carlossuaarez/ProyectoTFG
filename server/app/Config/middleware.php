<?php

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\App;
use Slim\Exception\HttpNotFoundException;
use Slim\Exception\HttpMethodNotAllowedException;

function registerGlobalMiddleware(App $app, bool $appDebug, string $allowedOrigin): void
{
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

    // Handler global de errores: SIEMPRE devuelve JSON con la misma forma { "error": "..." }
    $errorMiddleware = $app->addErrorMiddleware($appDebug, true, $appDebug);

    $errorMiddleware->setDefaultErrorHandler(function (
        Request $request,
        Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails
    ) use ($app): Response {
        $status = 500;
        $message = 'Error interno del servidor';

        if ($exception instanceof HttpNotFoundException) {
            $status = 404;
            $message = 'Ruta no encontrada';
        } elseif ($exception instanceof HttpMethodNotAllowedException) {
            $status = 405;
            $message = 'Método HTTP no permitido';
        }

        if ($displayErrorDetails) {
            $message = $exception->getMessage() ?: $message;
        }

        if ($logErrors) {
            error_log('Unhandled exception: ' . $exception->getMessage()
                . ' @ ' . $exception->getFile() . ':' . $exception->getLine());
        }

        $payload = ['error' => $message];
        if ($displayErrorDetails) {
            $payload['debug'] = [
                'type' => get_class($exception),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ];
        }

        $response = $app->getResponseFactory()->createResponse($status);
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // CORS
    $app->add(function ($request, $handler) use ($allowedOrigin) {
        $response = $handler->handle($request);
        return $response
            ->withHeader('Access-Control-Allow-Origin', $allowedOrigin)
            ->withHeader('Vary', 'Origin')
            ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization, X-Tournament-Code, X-Internal-Scheduler-Token')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS');
    });
}