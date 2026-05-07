<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

function registerRoutes(App $app, array $deps): void
{
    $controllers = $deps['controllers'];
    $limiters = $deps['limiters'];
    $authMiddleware = $deps['middleware']['auth'];

    // Preflight CORS
    $app->options('/{routes:.+}', function (Request $req, Response $res) {
        return $res;
    });

    $app->get('/', function (Request $req, Response $res) {
        $res->getBody()->write('API TourneyHub funcionando');
        return $res->withHeader('Content-Type', 'text/plain');
    });

    // Rutas auth públicas
    $app->post('/api/register', [$controllers['auth'], 'register'])
        ->add($limiters['registerByIp']);

    $app->post('/api/login', [$controllers['auth'], 'login'])
        ->add($limiters['loginByEmail'])
        ->add($limiters['loginByIp']);

    $app->post('/api/auth/google', [$controllers['auth'], 'googleLogin'])
        ->add($limiters['googleByIp']);

    $app->post('/api/2fa/verify', [$controllers['auth'], 'verify2fa'])
        ->add($limiters['twoFaByChallenge'])
        ->add($limiters['twoFaByIp']);

    // Rutas públicas torneos
    $app->get('/api/tournaments', [$controllers['tournament'], 'getAll']);

    $app->get('/api/tournaments/{id:[0-9]+}', [$controllers['tournament'], 'getById'])
        ->add($limiters['tournamentDetail']);

    $app->post('/api/tournaments/private/resolve', [$controllers['tournament'], 'resolvePrivateByCode'])
        ->add($limiters['privateCodeResolve']);

    // Scheduler interno de notificaciones (cron externo)
    $app->post('/api/internal/notifications/run', [$controllers['notification'], 'runScheduler']);

    // Perfil público (respeta privacidad)
    $app->get('/api/users/{username:[A-Za-z0-9_]{3,30}}/public', [$controllers['userDashboard'], 'getPublicProfile']);

    $app->group('/api', function ($group) use ($controllers, $limiters) {
        $group->get('/me', [$controllers['auth'], 'me']);
        $group->put('/me', [$controllers['auth'], 'updateProfile']);

        // Dashboard de usuario + privacidad
        $group->get('/users/me/dashboard', [$controllers['userDashboard'], 'getMyDashboard']);
        $group->patch('/users/me/privacy', [$controllers['userDashboard'], 'updateMyPrivacy']);

        $group->get('/tournaments/mine', [$controllers['myTournaments'], 'getMine']);
        $group->post('/tournaments', [$controllers['tournament'], 'create']);
        $group->put('/tournaments/{id:[0-9]+}', [$controllers['tournament'], 'update']);
        $group->post('/tournaments/{id:[0-9]+}/join', [$controllers['tournament'], 'join'])->add($limiters['tournamentJoin']);

        // EQUIPOS
        $group->get('/tournaments/{id:[0-9]+}/teams', [$controllers['team'], 'getTournamentTeams'])->add($limiters['tournamentDetail']);
        $group->post('/tournaments/{id:[0-9]+}/team-entry', [$controllers['team'], 'createTeamOrWaitlist'])->add($limiters['tournamentJoin']);
        $group->post('/teams/{id:[0-9]+}/invites', [$controllers['team'], 'createInvite']);
        $group->post('/team-invites/{code:[A-Z0-9]+}/accept', [$controllers['team'], 'acceptInvite']);
        $group->patch('/teams/{teamId:[0-9]+}/members/{memberId:[0-9]+}/validate', [$controllers['team'], 'validateMember']);
        $group->patch('/teams/{teamId:[0-9]+}/members/{memberId:[0-9]+}/role', [$controllers['team'], 'updateMemberRole']);

        // PARTIDOS / RESULTADOS
        $group->get('/tournaments/{id:[0-9]+}/matches', [$controllers['match'], 'getTournamentMatches'])->add($limiters['tournamentDetail']);
        $group->post('/tournaments/{id:[0-9]+}/matches/bootstrap', [$controllers['match'], 'bootstrapTournamentBracket'])->add($limiters['tournamentJoin']);
        $group->get('/matches/{id:[0-9]+}', [$controllers['match'], 'getMatchCenter']);
        $group->patch('/matches/{id:[0-9]+}/status', [$controllers['match'], 'updateMatchStatus']);
        $group->patch('/matches/{id:[0-9]+}/score', [$controllers['match'], 'submitMatchScore']);
        $group->patch('/matches/{id:[0-9]+}/score/finalize', [$controllers['match'], 'overrideMatchScore']);
        $group->patch('/matches/{id:[0-9]+}/confirm', [$controllers['match'], 'confirmMatchResult']);
        $group->post('/matches/{id:[0-9]+}/disputes', [$controllers['match'], 'openDispute']);
        $group->patch('/matches/{id:[0-9]+}/disputes/{disputeId:[0-9]+}', [$controllers['match'], 'updateDispute']);

        // Notificaciones
        $group->get('/notifications', [$controllers['notification'], 'listMine']);
        $group->patch('/notifications/{id:[0-9]+}/read', [$controllers['notification'], 'markRead']);
        $group->patch('/notifications/read-all', [$controllers['notification'], 'markAllRead']);

        // Admin
        $group->get('/admin/tournaments', [$controllers['admin'], 'getAllTournaments']);
        $group->get('/admin/kpis', [$controllers['admin'], 'getKpis']);
        $group->post('/admin/tournaments/bulk-delete', [$controllers['admin'], 'bulkDeleteTournaments']);
        $group->get('/admin/export/teams.csv', [$controllers['admin'], 'exportTeamsCsv']);
        $group->get('/admin/export/results.csv', [$controllers['admin'], 'exportResultsCsv']);
        $group->delete('/admin/tournaments/{id:[0-9]+}', [$controllers['admin'], 'deleteTournament']);
    })->add($authMiddleware);
}