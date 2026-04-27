<?php
use Slim\Factory\AppFactory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/Controller/AuthController.php';
require __DIR__ . '/../src/Controller/TournamentController.php';
require __DIR__ . '/../src/Controller/AdminController.php';
require __DIR__ . '/../src/Middleware/AuthMiddleware.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

// Conectar a la BD
$db = require __DIR__ . '/../src/config/database.php';

$app = AppFactory::create();

// Middleware globales
$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();
$app->addErrorMiddleware(true, true, true);

// Middleware CORS
$app->add(function ($request, $handler) {
    if ($request->getMethod() === 'OPTIONS') {
        $response = (new \Slim\Psr7\Response())->withStatus(204);
    } else {
        $response = $handler->handle($request);
    }

    $origin = $_ENV['CORS_ORIGIN'] ?? '*';

    return $response
        ->withHeader('Access-Control-Allow-Origin', $origin)
        ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS')
        ->withHeader('Access-Control-Max-Age', '86400');
});

$app->get('/', function (Request $req, Response $res) {
    $res->getBody()->write('API TourneyHub funcionando');
    return $res->withHeader('Content-Type', 'text/plain');
});

$app->options('/{routes:.+}', function (Request $req, Response $res) {
    return $res->withStatus(204);
});

// Controladores
$authController = new AuthController($db);
$tournamentController = new TournamentController($db);
$adminController = new AdminController($db);
$authMiddleware = new AuthMiddleware();

// Rutas públicas
$app->post('/api/register', [$authController, 'register']);
$app->post('/api/login', [$authController, 'login']);
$app->get('/api/tournaments', [$tournamentController, 'getAll']);
$app->get('/api/tournaments/{id}', [$tournamentController, 'getById']);

// Rutas protegidas (requieren token)
$app->group('/api', function ($group) use ($tournamentController, $adminController) {
    $group->post('/tournaments', [$tournamentController, 'create']);
    $group->post('/tournaments/{id}/join', [$tournamentController, 'join']);
    $group->get('/admin/tournaments', [$adminController, 'getAllTournaments']);
    $group->delete('/admin/tournaments/{id}', [$adminController, 'deleteTournament']);
})->add($authMiddleware);

$app->run();