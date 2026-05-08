<?php

use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteContext;

require_once __DIR__ . '/../Services/MailService.php';

require_once __DIR__ . '/../Controllers/AuthController.php';
require_once __DIR__ . '/../Controllers/TournamentController.php';
require_once __DIR__ . '/../Controllers/AdminController.php';
require_once __DIR__ . '/../Controllers/MyTournamentsController.php';
require_once __DIR__ . '/../Controllers/TeamController.php';
require_once __DIR__ . '/../Controllers/UserDashboardController.php';
require_once __DIR__ . '/../Controllers/MatchController.php';
require_once __DIR__ . '/../Controllers/NotificationController.php';

require_once __DIR__ . '/../Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../Middleware/RateLimitMiddleware.php';

function buildDependencies(PDO $db): array
{
    $mailService = new MailService();

    $authController = new AuthController($db, $mailService);
    $tournamentController = new TournamentController($db);
    $adminController = new AdminController($db);
    $myTournamentsController = new MyTournamentsController($db);
    $teamController = new TeamController($db);
    $userDashboardController = new UserDashboardController($db);
    $matchController = new MatchController($db);
    $notificationController = new NotificationController($db);

    // Auto-creación de tablas de notificaciones (idempotente)
    $notificationController->bootstrapTables();

    $authMiddleware = new AuthMiddleware();

    // ---- Rate limiters auth ----
    $loginByIpLimiter = new RateLimitMiddleware($db, 'login_ip', 20, 900,
        fn(Request $r) => RateLimitMiddleware::extractClientIp($r));

    $loginByEmailLimiter = new RateLimitMiddleware($db, 'login_email', 8, 900,
        function (Request $r): string {
            $body = (array)$r->getParsedBody();
            return strtolower(trim((string)($body['email'] ?? '')));
        });

    $googleByIpLimiter = new RateLimitMiddleware($db, 'google_ip', 20, 900,
        fn(Request $r) => RateLimitMiddleware::extractClientIp($r));

    $twoFaByIpLimiter = new RateLimitMiddleware($db, 'twofa_ip', 20, 600,
        fn(Request $r) => RateLimitMiddleware::extractClientIp($r));

    $twoFaByChallengeLimiter = new RateLimitMiddleware($db, 'twofa_challenge', 10, 600,
        function (Request $r): string {
            $body = (array)$r->getParsedBody();
            return trim((string)($body['challenge_id'] ?? ''));
        });

    $registerByIpLimiter = new RateLimitMiddleware($db, 'register_ip', 10, 900,
        fn(Request $r) => RateLimitMiddleware::extractClientIp($r));

    // ---- Rate limiters torneos privados ----
    $tournamentDetailLimiter = new RateLimitMiddleware($db, 'tournament_detail_ip_id', 60, 600,
        function (Request $r): string {
            $ip = RateLimitMiddleware::extractClientIp($r);
            $route = RouteContext::fromRequest($r)->getRoute();
            $id = $route ? (string)$route->getArgument('id') : '0';
            return $ip . ':' . $id;
        });

    $tournamentJoinLimiter = new RateLimitMiddleware($db, 'tournament_join_ip_id', 30, 600,
        function (Request $r): string {
            $ip = RateLimitMiddleware::extractClientIp($r);
            $route = RouteContext::fromRequest($r)->getRoute();
            $id = $route ? (string)$route->getArgument('id') : '0';
            return $ip . ':' . $id;
        });

    $privateCodeResolveLimiter = new RateLimitMiddleware($db, 'tournament_private_resolve_ip', 30, 600,
        fn(Request $r) => RateLimitMiddleware::extractClientIp($r));

    return [
        'controllers' => [
            'auth' => $authController,
            'tournament' => $tournamentController,
            'admin' => $adminController,
            'myTournaments' => $myTournamentsController,
            'team' => $teamController,
            'userDashboard' => $userDashboardController,
            'match' => $matchController,
            'notification' => $notificationController,
        ],
        'middleware' => [
            'auth' => $authMiddleware,
        ],
        'limiters' => [
            'registerByIp' => $registerByIpLimiter,
            'loginByIp' => $loginByIpLimiter,
            'loginByEmail' => $loginByEmailLimiter,
            'googleByIp' => $googleByIpLimiter,
            'twoFaByIp' => $twoFaByIpLimiter,
            'twoFaByChallenge' => $twoFaByChallengeLimiter,
            'tournamentDetail' => $tournamentDetailLimiter,
            'tournamentJoin' => $tournamentJoinLimiter,
            'privateCodeResolve' => $privateCodeResolveLimiter,
        ],
    ];
}