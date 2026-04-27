<?php
use Slim\Factory\AppFactory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/Service/MailService.php';
require __DIR__ . '/../src/Controller/AuthController.php';
require __DIR__ . '/../src/Controller/TournamentController.php';
require __DIR__ . '/../src/Controller/AdminController.php';
require __DIR__ . '/../src/Middleware/AuthMiddleware.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Conectar a la BD
$db = require __DIR__ . '/../src/config/database.php';

$app = AppFactory::create();

// Middleware globales
$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();
$app->addErrorMiddleware(true, true, true);

// Preflight CORS
$app->options('/{routes:.+}', function (Request $req, Response $res) {
    return $res;
});

// Middleware CORS
$app->add(function ($request, $handler) {
    $response = $handler->handle($request);
    return $response
        ->withHeader('Access-Control-Allow-Origin', '*')
        ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS');
});

$app->get('/', function (Request $req, Response $res) {
    $res->getBody()->write('API TourneyHub funcionando');
    return $res->withHeader('Content-Type', 'text/plain');
});

// Controladores
$mailService = new MailService();
$authController = new AuthController($db, $mailService);
$tournamentController = new TournamentController($db);
$adminController = new AdminController($db);
$authMiddleware = new AuthMiddleware();

// Rutas públicas auth
$app->post('/api/register', [$authController, 'register']);
$app->post('/api/login', [$authController, 'login']);
$app->post('/api/auth/google', [$authController, 'googleLogin']);
$app->post('/api/2fa/verify', [$authController, 'verify2fa']);

// Rutas públicas de torneos
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