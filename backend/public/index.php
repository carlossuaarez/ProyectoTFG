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

$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();

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

// ---- Rate limiters torneos privados (anti brute-force código) ----
$tournamentDetailLimiter = new RateLimitMiddleware(
    $db,
    'tournament_detail_ip_id',
    60,  // 60 req/10m por IP+torneo
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
    30,  // 30 req/10m por IP+torneo
    600,
    function (Request $r): string {
        $ip = RateLimitMiddleware::extractClientIp($r);
        $route = RouteContext::fromRequest($r)->getRoute();
        $id = $route ? (string)$route->getArgument('id') : '0';
        return $ip . ':' . $id;
    }
);

// Rutas auth públicas
$app->post('/api/register', [$authController, 'register']);
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

// Rutas protegidas
$app->group('/api', function ($group) use ($tournamentController, $adminController) {
    $group->post('/tournaments', [$tournamentController, 'create']);
    $group->post('/tournaments/{id}/join', [$tournamentController, 'join']);
    $group->get('/admin/tournaments', [$adminController, 'getAllTournaments']);
    $group->delete('/admin/tournaments/{id}', [$adminController, 'deleteTournament']);
})->add($authMiddleware)->add($tournamentJoinLimiter);

$app->run();