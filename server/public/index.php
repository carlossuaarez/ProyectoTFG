<?php
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

$appDebug = filter_var($_ENV['APP_DEBUG'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
$allowedOrigin = $_ENV['APP_CORS_ORIGIN'] ?? '*';

// Conectar a BD
try {
    $db = require __DIR__ . '/../app/Config/database.php';
} catch (Throwable $e) {
    error_log('Fatal bootstrap error: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Error interno del servidor']);
    exit;
}

$app = AppFactory::create();

require __DIR__ . '/../app/Config/middleware.php';
require __DIR__ . '/../app/Config/dependencies.php';
require __DIR__ . '/../app/Config/routes.php';

// Registrar middleware global
registerGlobalMiddleware($app, $appDebug, $allowedOrigin);

// Construir dependencias (controllers, middleware, rate-limiters...)
$deps = buildDependencies($db);

// Registrar rutas
registerRoutes($app, $deps);

$app->run();