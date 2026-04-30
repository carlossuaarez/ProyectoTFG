<?php
use Slim\Factory\AppFactory;
use Slim\Routing\RouteContext;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/Service/MailService.php';
require __DIR__ . '/../src/Controller/AuthController.php';
require __DIR__ . '/../src/Controller/TournamentController.php';
require __DIR__ . '/../src/Controller/AdminController.php';
require __DIR__ . '/../src/Middleware/AuthMiddleware.php';
require __DIR__ . '/../src/Middleware/RateLimitMiddleware.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

$appDebug = filter_var($_ENV['APP_DEBUG'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
$allowedOrigin = $_ENV['APP_CORS_ORIGIN'] ?? '*';

// Conectar a BD
try {
    $db = require __DIR__ . '/../src/config/database.php';
} catch (Throwable $e) {
    error_log('Fatal bootstrap error: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Error interno del servidor']);
    exit;
}

$app = AppFactory::create();

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
        $baseUploadsDir = realpath(__DIR__ . '/uploads');
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

// Preflight CORS
$app->options('/{routes:.+}', function (Request $req, Response $res) {
    return $res;
});

// CORS
$app->add(function ($request, $handler) use ($allowedOrigin) {
    $response = $handler->handle($request);
    return $response
        ->withHeader('Access-Control-Allow-Origin', $allowedOrigin)
        ->withHeader('Vary', 'Origin')
        ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization, X-Tournament-Code')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS');
});

$app->get('/', function (Request $req, Response $res) {
    $res->getBody()->write('API TourneyHub funcionando');
    return $res->withHeader('Content-Type', 'text/plain');
});

$mailService = new MailService();
$authController = new AuthController($db, $mailService);
$tournamentController = new TournamentController($db);
$adminController = new AdminController($db);
$authMiddleware = new AuthMiddleware();

// ---- Rate limiters auth ----
$loginByIpLimiter = new RateLimitMiddleware(
    $db,
    'login_ip',
    20,
    900,
    fn(Request $r) => RateLimitMiddleware::extractClientIp($r)
);

$loginByEmailLimiter = new RateLimitMiddleware(
    $db,
    'login_email',
    8,
    900,
    function (Request $r): string {
        $body = (array)$r->getParsedBody();
        return strtolower(trim((string)($body['email'] ?? '')));
    }
);

$googleByIpLimiter = new RateLimitMiddleware(
    $db,
    'google_ip',
    20,
    900,
    fn(Request $r) => RateLimitMiddleware::extractClientIp($r)
);

$twoFaByIpLimiter = new RateLimitMiddleware(
    $db,
    'twofa_ip',
    20,
    600,
    fn(Request $r) => RateLimitMiddleware::extractClientIp($r)
);

$twoFaByChallengeLimiter = new RateLimitMiddleware(
    $db,
    'twofa_challenge',
    10,
    600,
    function (Request $r): string {
        $body = (array)$r->getParsedBody();
        return trim((string)($body['challenge_id'] ?? ''));
    }
);

$registerByIpLimiter = new RateLimitMiddleware(
    $db,
    'register_ip',
    10,
    900,
    fn(Request $r) => RateLimitMiddleware::extractClientIp($r)
);

// ---- Rate limiters torneos privados ----
$tournamentDetailLimiter = new RateLimitMiddleware(
    $db,
    'tournament_detail_ip_id',
    60,
    600,
    function (Request $r): string {
        $ip = RateLimitMiddleware::extractClientIp($r);
        $route = RouteContext::fromRequest($r)->getRoute();
        $id = $route ? (string)$route->getArgument('id') : '0';
        return $ip . ':' . $id;
    }
);

$tournamentJoinLimiter = new RateLimitMiddleware(
    $db,
    'tournament_join_ip_id',
    30,
    600,
    function (Request $r): string {
        $ip = RateLimitMiddleware::extractClientIp($r);
        $route = RouteContext::fromRequest($r)->getRoute();
        $id = $route ? (string)$route->getArgument('id') : '0';
        return $ip . ':' . $id;
    }
);

$privateCodeResolveLimiter = new RateLimitMiddleware(
    $db,
    'tournament_private_resolve_ip',
    30,
    600,
    fn(Request $r) => RateLimitMiddleware::extractClientIp($r)
);

// Rutas auth públicas
$app->post('/api/register', [$authController, 'register'])
    ->add($registerByIpLimiter);

$app->post('/api/login', [$authController, 'login'])
    ->add($loginByEmailLimiter)
    ->add($loginByIpLimiter);

$app->post('/api/auth/google', [$authController, 'googleLogin'])
    ->add($googleByIpLimiter);

$app->post('/api/2fa/verify', [$authController, 'verify2fa'])
    ->add($twoFaByChallengeLimiter)
    ->add($twoFaByIpLimiter);

// Rutas públicas torneos
$app->get('/api/tournaments', [$tournamentController, 'getAll']);

$app->get('/api/tournaments/{id}', [$tournamentController, 'getById'])
    ->add($tournamentDetailLimiter);

$app->post('/api/tournaments/private/resolve', [$tournamentController, 'resolvePrivateByCode'])
    ->add($privateCodeResolveLimiter);

// Rutas protegidas
$app->group('/api', function ($group) use ($authController, $tournamentController, $adminController, $tournamentJoinLimiter) {
    // Perfil
    $group->get('/me', [$authController, 'me']);
    $group->put('/me', [$authController, 'updateProfile']);

    // Torneos
    $group->post('/tournaments', [$tournamentController, 'create']);
    $group->put('/tournaments/{id}', [$tournamentController, 'update']);
    $group->post('/tournaments/{id}/join', [$tournamentController, 'join'])->add($tournamentJoinLimiter);

    // Admin
    $group->get('/admin/tournaments', [$adminController, 'getAllTournaments']);
    $group->delete('/admin/tournaments/{id}', [$adminController, 'deleteTournament']);
})->add($authMiddleware);

$app->run();