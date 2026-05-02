<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class MatchController
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function getTournamentMatches(Request $req, Response $res, array $args): Response
    {
        $user = (array)$req->getAttribute('user');
        $userId = (int)($user['id'] ?? 0);
        $tournamentId = (int)($args['id'] ?? 0);

        if ($userId <= 0) {
            return $this->json($res, ['error' => 'No autorizado'], 401);
        }
        if ($tournamentId <= 0) {
            return $this->json($res, ['error' => 'ID de torneo no valido'], 400);
        }

        try {
            $tournament = $this->getTournamentById($tournamentId);
            if (!$tournament) {
                return $this->json($res, ['error' => 'Torneo no encontrado'], 404);
            }

            $accessCode = $this->resolveAccessCodeFromRequest($req);
            if (!$this->canAccessTournament($user, $tournament, $accessCode)) {
                return $this->json($res, [
                    'error' => 'Este torneo es privado. Introduce el código de acceso.',
                    'requires_access_code' => true,
                ], 403);
            }

            $matches = $this->getTournamentMatchesFlat($tournamentId);
            $phaseTimeline = $this->buildPhaseTimeline($tournamentId);
            $teamsCount = $this->scalar("SELECT COUNT(*) FROM teams WHERE tournament_id = ?", [$tournamentId]);
            $format = (string)($tournament['format'] ?? 'single_elim');

            $standings = [];
            $standingsRules = [];
            if ($format === 'league') {
                $standings = $this->buildLeagueStandings($tournamentId);
                $standingsRules = $this->getStandingsRules();
            }

            $roundsMap = [];
            foreach ($matches as $match) {
                $round = (int)$match['round_number'];
                if (!isset($roundsMap[$round])) {
                    $roundsMap[$round] = [
                        'round_number' => $round,
                        'phase_label' => (string)$match['phase_label'],
                        'matches' => [],
                    ];
                }
                $roundsMap[$round]['matches'][] = $this->normalizeMatchRow($match);
            }

            ksort($roundsMap);
            $rounds = array_values($roundsMap);

            $isManager = $this->isAdminOrOwner($user, $tournament);
            $canBootstrap = $isManager && $teamsCount >= 2 && count($matches) === 0;

            return $this->json($res, [
                'tournament' => [
                    'id' => (int)$tournament['id'],
                    'name' => (string)$tournament['name'],
                    'max_teams' => (int)$tournament['max_teams'],
                    'format' => $format,
                    'visibility' => (string)$tournament['visibility'],
                    'created_by' => (int)$tournament['created_by'],
                ],
                'rounds' => $rounds,
                'phase_timeline' => $phaseTimeline,
                'standings' => $standings,
                'standings_tiebreak_rules' => $standingsRules,
                'tiebreak_rules' => $standingsRules,
                'standings_available' => ($format === 'league'),
                'permissions' => [
                    'can_manage' => $isManager,
                    'can_bootstrap' => $canBootstrap,
                ],
                'meta' => [
                    'teams_count' => $teamsCount,
                    'matches_count' => count($matches),
                ],
            ]);
        } catch (Throwable $e) {
            error_log('getTournamentMatches error: ' . $e->getMessage());
            return $this->json($res, ['error' => 'No se pudieron cargar los partidos'], 500);
        }
    }

    public function bootstrapTournamentBracket(Request $req, Response $res, array $args): Response
    {
        $user = (array)$req->getAttribute('user');
        $userId = (int)($user['id'] ?? 0);
        $tournamentId = (int)($args['id'] ?? 0);

        if ($userId <= 0) {
            return $this->json($res, ['error' => 'No autorizado'], 401);
        }
        if ($tournamentId <= 0) {
            return $this->json($res, ['error' => 'ID de torneo no valido'], 400);
        }

        try {
            $tournament = $this->getTournamentById($tournamentId);
            if (!$tournament) {
                return $this->json($res, ['error' => 'Torneo no encontrado'], 404);
            }

            if (!$this->isAdminOrOwner($user, $tournament)) {
                return $this->json($res, ['error' => 'Solo el creador o admin puede generar el bracket'], 403);
            }

            $existingMatches = $this->scalar("SELECT COUNT(*) FROM tournament_matches WHERE tournament_id = ?", [$tournamentId]);
            if ($existingMatches > 0) {
                return $this->json($res, ['error' => 'Este torneo ya tiene partidos generados'], 409);
            }

            $teamsStmt = $this->db->prepare("
                SELECT id, name
                FROM teams
                WHERE tournament_id = ?
                ORDER BY registered_at ASC, id ASC
            ");
            $teamsStmt->execute([$tournamentId]);
            $teams = $teamsStmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($teams) < 2) {
                return $this->json($res, ['error' => 'Se necesitan al menos 2 equipos para generar partidos'], 400);
            }

            $bracketSize = $this->nextPowerOfTwo(count($teams));
            $totalRounds = (int)round(log($bracketSize, 2));

            $this->db->beginTransaction();

            $insertStmt = $this->db->prepare("
                INSERT INTO tournament_matches (
                    tournament_id, round_number, phase_label, bracket_slot,
                    source_match_a_id, source_match_b_id,
                    team_a_id, team_b_id,
                    status, score_a, score_b, winner_team_id,
                    captain_a_confirmed, captain_b_confirmed, finalized_at,
                    created_by
                ) VALUES (
                    ?, ?, ?, ?,
                    ?, ?,
                    ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?,
                    ?
                )
            ");

            $roundMatchIds = [];
            $autoFinalizedMatchIds = [];

            for ($round = 1; $round <= $totalRounds; $round++) {
                $matchesInRound = (int)($bracketSize / (2 ** $round));
                $phaseLabel = $this->phaseLabel($round, $totalRounds);
                $roundMatchIds[$round] = [];

                for ($slot = 1; $slot <= $matchesInRound; $slot++) {
                    $sourceA = null;
                    $sourceB = null;
                    $teamA = null;
                    $teamB = null;
                    $status = 'pending';
                    $scoreA = 0;
                    $scoreB = 0;
                    $winner = null;
                    $confirmA = 0;
                    $confirmB = 0;
                    $finalizedAt = null;

                    if ($round === 1) {
                        $teamAIndex = (($slot - 1) * 2);
                        $teamBIndex = $teamAIndex + 1;
                        $teamA = isset($teams[$teamAIndex]) ? (int)$teams[$teamAIndex]['id'] : null;
                        $teamB = isset($teams[$teamBIndex]) ? (int)$teams[$teamBIndex]['id'] : null;

                        if (($teamA && !$teamB) || (!$teamA && $teamB)) {
                            $winner = $teamA ?: $teamB;
                            $status = 'finalized';
                            $confirmA = 1;
                            $confirmB = 1;
                            if ($teamA && !$teamB) {
                                $scoreA = 1;
                                $scoreB = 0;
                            } elseif (!$teamA && $teamB) {
                                $scoreA = 0;
                                $scoreB = 1;
                            }
                            $finalizedAt = date('Y-m-d H:i:s');
                        }
                    } else {
                        $sourceA = (int)$roundMatchIds[$round - 1][($slot * 2) - 2];
                        $sourceB = (int)$roundMatchIds[$round - 1][($slot * 2) - 1];
                    }

                    $insertStmt->execute([
                        $tournamentId,
                        $round,
                        $phaseLabel,
                        $slot,
                        $sourceA,
                        $sourceB,
                        $teamA,
                        $teamB,
                        $status,
                        $scoreA,
                        $scoreB,
                        $winner,
                        $confirmA,
                        $confirmB,
                        $finalizedAt,
                        $userId,
                    ]);

                    $matchId = (int)$this->db->lastInsertId();
                    $roundMatchIds[$round][] = $matchId;

                    $this->logMatchEvent(
                        $matchId,
                        'created',
                        $userId,
                        [
                            'phase_label' => $phaseLabel,
                            'round_number' => $round,
                            'slot' => $slot,
                        ]
                    );

                    if ($status === 'finalized' && $winner) {
                        $autoFinalizedMatchIds[] = $matchId;
                        $this->logMatchEvent(
                            $matchId,
                            'finalized',
                            $userId,
                            [
                                'auto_bye' => true,
                                'winner_team_id' => $winner,
                            ]
                        );
                    }
                }
            }

            foreach ($autoFinalizedMatchIds as $autoMatchId) {
                $this->propagateWinnerToChildren($autoMatchId);
            }

            $this->db->commit();

            return $this->json($res, [
                'message' => 'Bracket generado correctamente',
                'meta' => [
                    'teams_count' => count($teams),
                    'bracket_size' => $bracketSize,
                    'rounds' => $totalRounds,
                    'matches_created' => array_sum(array_map('count', $roundMatchIds)),
                ],
            ], 201);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('bootstrapTournamentBracket error: ' . $e->getMessage());
            return $this->json($res, ['error' => 'No se pudo generar el bracket'], 500);
        }
    }

    public function getMatchCenter(Request $req, Response $res, array $args): Response
    {
        $user = (array)$req->getAttribute('user');
        $userId = (int)($user['id'] ?? 0);
        $matchId = (int)($args['id'] ?? 0);

        if ($userId <= 0) {
            return $this->json($res, ['error' => 'No autorizado'], 401);
        }
        if ($matchId <= 0) {
            return $this->json($res, ['error' => 'ID de partido no valido'], 400);
        }

        try {
            $match = $this->getMatchWithTournament($matchId);
            if (!$match) {
                return $this->json($res, ['error' => 'Partido no encontrado'], 404);
            }

            $tournament = [
                'id' => (int)$match['tournament_id'],
                'name' => (string)$match['tournament_name'],
                'visibility' => (string)$match['tournament_visibility'],
                'created_by' => (int)$match['tournament_created_by'],
                'max_teams' => (int)$match['tournament_max_teams'],
            ];

            $accessCode = $this->resolveAccessCodeFromRequest($req);
            if (!$this->canAccessTournament($user, $tournament, $accessCode)) {
                return $this->json($res, [
                    'error' => 'Este torneo es privado. Introduce el código de acceso.',
                    'requires_access_code' => true,
                    'tournament_id' => (int)$match['tournament_id'],
                ], 403);
            }

            $flags = $this->getMatchParticipantFlags($match, $userId);
            $isManager = $this->isAdminOrOwner($user, $tournament);

            $timeline = $this->getMatchTimeline($matchId);
            $disputes = $this->getMatchDisputes($matchId);
            $phaseTimeline = $this->buildPhaseTimeline((int)$match['tournament_id']);

            return $this->json($res, [
                'match' => $this->normalizeMatchRow($match),
                'tournament' => [
                    'id' => (int)$match['tournament_id'],
                    'name' => (string)$match['tournament_name'],
                    'visibility' => (string)$match['tournament_visibility'],
                ],
                'permissions' => [
                    'can_manage' => $isManager,
                    'can_report_score' => $isManager || $flags['is_team_a_officer'] || $flags['is_team_b_officer'],
                    'can_force_edit_result' => $isManager,
                    'can_override_score' => $isManager,
                    'can_confirm_as_team_a' => !$isManager && $flags['is_team_a_captain'],
                    'can_confirm_as_team_b' => !$isManager && $flags['is_team_b_captain'],
                    'can_dispute' => $isManager || $flags['is_team_a_officer'] || $flags['is_team_b_officer'],
                ],
                'timeline' => $timeline,
                'phase_timeline' => $phaseTimeline,
                'disputes' => $disputes,
            ]);
        } catch (Throwable $e) {
            error_log('getMatchCenter error: ' . $e->getMessage());
            return $this->json($res, ['error' => 'No se pudo cargar el centro de partido'], 500);
        }
    }

    public function updateMatchStatus(Request $req, Response $res, array $args): Response
    {
        $user = (array)$req->getAttribute('user');
        $userId = (int)($user['id'] ?? 0);
        $matchId = (int)($args['id'] ?? 0);
        $data = (array)$req->getParsedBody();
        $newStatus = trim((string)($data['status'] ?? ''));

        if ($userId <= 0) {
            return $this->json($res, ['error' => 'No autorizado'], 401);
        }
        if ($matchId <= 0) {
            return $this->json($res, ['error' => 'ID de partido no valido'], 400);
        }
        if (!in_array($newStatus, ['pending', 'in_progress', 'finalized'], true)) {
            return $this->json($res, ['error' => 'Estado no valido'], 400);
        }

        try {
            $this->db->beginTransaction();

            $match = $this->getMatchWithTournament($matchId, true);
            if (!$match) {
                $this->db->rollBack();
                return $this->json($res, ['error' => 'Partido no encontrado'], 404);
            }

            $tournament = [
                'id' => (int)$match['tournament_id'],
                'name' => (string)$match['tournament_name'],
                'visibility' => (string)$match['tournament_visibility'],
                'created_by' => (int)$match['tournament_created_by'],
                'max_teams' => (int)$match['tournament_max_teams'],
            ];

            if (!$this->isAdminOrOwner($user, $tournament)) {
                $this->db->rollBack();
                return $this->json($res, ['error' => 'Solo el creador o admin puede cambiar el estado'], 403);
            }

            $tournamentFormat = (string)($match['tournament_format'] ?? 'single_elim');
            if ($newStatus === 'finalized' && !$match['winner_team_id'] && $tournamentFormat !== 'league') {
                $this->db->rollBack();
                return $this->json($res, ['error' => 'No se puede finalizar sin equipo ganador'], 400);
            }

            $finalizedAt = ($newStatus === 'finalized') ? date('Y-m-d H:i:s') : null;

            $updateStmt = $this->db->prepare("
                UPDATE tournament_matches
                SET status = ?, finalized_at = ?
                WHERE id = ?
            ");
            $updateStmt->execute([$newStatus, $finalizedAt, $matchId]);

            $this->logMatchEvent(
                $matchId,
                'status_change',
                $userId,
                [
                    'from' => (string)$match['status'],
                    'to' => $newStatus,
                ]
            );

            if ($newStatus === 'finalized') {
                $this->logMatchEvent(
                    $matchId,
                    'finalized',
                    $userId,
                    [
                        'winner_team_id' => (int)$match['winner_team_id'],
                    ]
                );
                $this->propagateWinnerToChildren($matchId);
            }

            $this->db->commit();
            return $this->json($res, ['message' => 'Estado de partido actualizado']);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('updateMatchStatus error: ' . $e->getMessage());
            return $this->json($res, ['error' => 'No se pudo actualizar el estado'], 500);
        }
    }

    public function submitMatchScore(Request $req, Response $res, array $args): Response
    {
        $user = (array)$req->getAttribute('user');
        $userId = (int)($user['id'] ?? 0);
        $matchId = (int)($args['id'] ?? 0);
        $data = (array)$req->getParsedBody();

        if ($userId <= 0) {
            return $this->json($res, ['error' => 'No autorizado'], 401);
        }
        if ($matchId <= 0) {
            return $this->json($res, ['error' => 'ID de partido no valido'], 400);
        }

        if (!array_key_exists('score_a', $data) || !array_key_exists('score_b', $data)) {
            return $this->json($res, ['error' => 'Debes enviar score_a y score_b'], 400);
        }

        $scoreA = (int)$data['score_a'];
        $scoreB = (int)$data['score_b'];
        if ($scoreA < 0 || $scoreA > 99 || $scoreB < 0 || $scoreB > 99) {
            return $this->json($res, ['error' => 'Los marcadores deben estar entre 0 y 99'], 400);
        }

        try {
            $this->db->beginTransaction();

            $match = $this->getMatchWithTournament($matchId, true);
            if (!$match) {
                $this->db->rollBack();
                return $this->json($res, ['error' => 'Partido no encontrado'], 404);
            }

            $tournament = [
                'id' => (int)$match['tournament_id'],
                'name' => (string)$match['tournament_name'],
                'visibility' => (string)$match['tournament_visibility'],
                'created_by' => (int)$match['tournament_created_by'],
                'max_teams' => (int)$match['tournament_max_teams'],
            ];

            $isManager = $this->isAdminOrOwner($user, $tournament);
            $flags = $this->getMatchParticipantFlags($match, $userId);
            $isParticipantOfficer = $flags['is_team_a_officer'] || $flags['is_team_b_officer'];

            if (!$isManager && !$isParticipantOfficer) {
                $this->db->rollBack();
                return $this->json($res, ['error' => 'No tienes permisos para reportar resultado'], 403);
            }

            $teamA = $match['team_a_id'] ? (int)$match['team_a_id'] : null;
            $teamB = $match['team_b_id'] ? (int)$match['team_b_id'] : null;
            if (!$teamA && !$teamB) {
                $this->db->rollBack();
                return $this->json($res, ['error' => 'Este partido aun no tiene equipos asignados'], 400);
            }

            $tournamentFormat = (string)($match['tournament_format'] ?? 'single_elim');
            $isLeague = ($tournamentFormat === 'league');

            if ($teamA && $teamB && $scoreA === $scoreB && !$isLeague) {
                $this->db->rollBack();
                return $this->json($res, ['error' => 'No puede haber empate en este tipo de partido'], 400);
            }

            if ($teamA && !$teamB) {
                $winner = $teamA;
            } elseif (!$teamA && $teamB) {
                $winner = $teamB;
            } elseif ($scoreA === $scoreB) {
                $winner = null;
            } else {
                $winner = ($scoreA > $scoreB) ? $teamA : $teamB;
            }

            $prevScoreA = (int)$match['score_a'];
            $prevScoreB = (int)$match['score_b'];
            $scoreChanged = ($prevScoreA !== $scoreA) || ($prevScoreB !== $scoreB);

            // Si cambia el resultado, se invalida la confirmación anterior
            // y debe volver a confirmarse por ambas partes.
            $captainAConfirmed = $scoreChanged ? 0 : (int)$match['captain_a_confirmed'];
            $captainBConfirmed = $scoreChanged ? 0 : (int)$match['captain_b_confirmed'];
            $status = 'in_progress';
            $finalizedAt = null;

            if (($teamA && !$teamB) || (!$teamA && $teamB)) {
                $captainAConfirmed = 1;
                $captainBConfirmed = 1;
            } elseif ($flags['is_team_a_officer']) {
                $captainAConfirmed = 1;
            } elseif ($flags['is_team_b_officer']) {
                $captainBConfirmed = 1;
            }

            if ($captainAConfirmed === 1 && $captainBConfirmed === 1) {
                $status = 'finalized';
                $finalizedAt = date('Y-m-d H:i:s');
            }

            $updateStmt = $this->db->prepare("
                UPDATE tournament_matches
                SET
                    score_a = ?,
                    score_b = ?,
                    winner_team_id = ?,
                    captain_a_confirmed = ?,
                    captain_b_confirmed = ?,
                    status = ?,
                    finalized_at = ?
                WHERE id = ?
            ");
            $updateStmt->execute([
                $scoreA,
                $scoreB,
                $winner,
                $captainAConfirmed,
                $captainBConfirmed,
                $status,
                $finalizedAt,
                $matchId,
            ]);

            $this->logMatchEvent(
                $matchId,
                'score_submitted',
                $userId,
                [
                    'score_a' => $scoreA,
                    'score_b' => $scoreB,
                    'winner_team_id' => $winner,
                    'requires_double_confirmation' => ($teamA && $teamB),
                ]
            );

            if ($status === 'finalized') {
                $this->logMatchEvent(
                    $matchId,
                    'finalized',
                    $userId,
                    [
                        'winner_team_id' => $winner,
                        'auto_bye' => true,
                    ]
                );
                if ($winner) {
                    $this->propagateWinnerToChildren($matchId);
                }
            }

            $this->db->commit();

            return $this->json($res, [
                'message' => ($status === 'finalized')
                    ? 'Resultado guardado y partido finalizado'
                    : ($scoreChanged
                        ? 'Resultado actualizado. Se requiere reconfirmacion de capitanes.'
                        : 'Resultado guardado. Pendiente de doble confirmacion'),
            ]);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('submitMatchScore error: ' . $e->getMessage());
            return $this->json($res, ['error' => 'No se pudo guardar el resultado'], 500);
        }
    }

    public function confirmMatchResult(Request $req, Response $res, array $args): Response
    {
        $user = (array)$req->getAttribute('user');
        $userId = (int)($user['id'] ?? 0);
        $matchId = (int)($args['id'] ?? 0);

        if ($userId <= 0) {
            return $this->json($res, ['error' => 'No autorizado'], 401);
        }
        if ($matchId <= 0) {
            return $this->json($res, ['error' => 'ID de partido no valido'], 400);
        }

        try {
            $this->db->beginTransaction();

            $match = $this->getMatchWithTournament($matchId, true);
            if (!$match) {
                $this->db->rollBack();
                return $this->json($res, ['error' => 'Partido no encontrado'], 404);
            }

            $teamA = $match['team_a_id'] ? (int)$match['team_a_id'] : null;
            $teamB = $match['team_b_id'] ? (int)$match['team_b_id'] : null;
            if (!$teamA || !$teamB) {
                $this->db->rollBack();
                return $this->json($res, ['error' => 'La doble confirmacion requiere 2 equipos asignados'], 400);
            }

            $tournamentFormat = (string)($match['tournament_format'] ?? 'single_elim');
            if (!(int)$match['winner_team_id'] && $tournamentFormat !== 'league') {
                $this->db->rollBack();
                return $this->json($res, ['error' => 'Primero debes reportar un resultado con ganador'], 400);
            }

            $flags = $this->getMatchParticipantFlags($match, $userId);
            if (!$flags['is_team_a_captain'] && !$flags['is_team_b_captain']) {
                $this->db->rollBack();
                return $this->json($res, ['error' => 'Solo capitan A o capitan B pueden confirmar'], 403);
            }

            $captainAConfirmed = (int)$match['captain_a_confirmed'];
            $captainBConfirmed = (int)$match['captain_b_confirmed'];
            if ($flags['is_team_a_captain']) {
                $captainAConfirmed = 1;
            }
            if ($flags['is_team_b_captain']) {
                $captainBConfirmed = 1;
            }

            $status = ((int)$captainAConfirmed === 1 && (int)$captainBConfirmed === 1)
                ? 'finalized'
                : 'in_progress';
            $finalizedAt = ($status === 'finalized') ? date('Y-m-d H:i:s') : null;

            $updateStmt = $this->db->prepare("
                UPDATE tournament_matches
                SET captain_a_confirmed = ?, captain_b_confirmed = ?, status = ?, finalized_at = ?
                WHERE id = ?
            ");
            $updateStmt->execute([$captainAConfirmed, $captainBConfirmed, $status, $finalizedAt, $matchId]);

            $this->logMatchEvent(
                $matchId,
                'captain_confirmed',
                $userId,
                [
                    'team_side' => $flags['is_team_a_captain'] ? 'A' : 'B',
                    'captain_a_confirmed' => $captainAConfirmed,
                    'captain_b_confirmed' => $captainBConfirmed,
                ]
            );

            if ($status === 'finalized') {
                $this->logMatchEvent(
                    $matchId,
                    'finalized',
                    $userId,
                    ['winner_team_id' => (int)$match['winner_team_id']]
                );
                if ((int)$match['winner_team_id']) {
                    $this->propagateWinnerToChildren($matchId);
                }
            }

            $this->db->commit();

            return $this->json($res, [
                'message' => ($status === 'finalized')
                    ? 'Resultado confirmado por ambos capitanes. Partido finalizado.'
                    : 'Confirmacion registrada. Falta la del otro capitan.',
                'captain_a_confirmed' => $captainAConfirmed === 1,
                'captain_b_confirmed' => $captainBConfirmed === 1,
                'status' => $status,
            ]);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('confirmMatchResult error: ' . $e->getMessage());
            return $this->json($res, ['error' => 'No se pudo confirmar el resultado'], 500);
        }
    }

    public function openDispute(Request $req, Response $res, array $args): Response
    {
        $user = (array)$req->getAttribute('user');
        $userId = (int)($user['id'] ?? 0);
        $matchId = (int)($args['id'] ?? 0);
        $data = (array)$req->getParsedBody();
        $reason = trim((string)($data['reason'] ?? ''));

        if ($userId <= 0) {
            return $this->json($res, ['error' => 'No autorizado'], 401);
        }
        if ($matchId <= 0) {
            return $this->json($res, ['error' => 'ID de partido no valido'], 400);
        }
        if ($reason === '' || mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            return $this->json($res, ['error' => 'El motivo debe tener entre 10 y 500 caracteres'], 400);
        }

        try {
            $this->db->beginTransaction();

            $match = $this->getMatchWithTournament($matchId, true);
            if (!$match) {
                $this->db->rollBack();
                return $this->json($res, ['error' => 'Partido no encontrado'], 404);
            }

            $tournament = [
                'id' => (int)$match['tournament_id'],
                'name' => (string)$match['tournament_name'],
                'visibility' => (string)$match['tournament_visibility'],
                'created_by' => (int)$match['tournament_created_by'],
                'max_teams' => (int)$match['tournament_max_teams'],
            ];

            $flags = $this->getMatchParticipantFlags($match, $userId);
            $isManager = $this->isAdminOrOwner($user, $tournament);
            if (!$isManager && !$flags['is_team_a_officer'] && !$flags['is_team_b_officer']) {
                $this->db->rollBack();
                return $this->json($res, ['error' => 'No tienes permisos para abrir disputa'], 403);
            }

            $insertStmt = $this->db->prepare("
                INSERT INTO tournament_match_disputes (match_id, created_by, reason, status)
                VALUES (?, ?, ?, 'open')
            ");
            $insertStmt->execute([$matchId, $userId, $reason]);
            $disputeId = (int)$this->db->lastInsertId();

            $this->logMatchEvent(
                $matchId,
                'dispute_opened',
                $userId,
                [
                    'dispute_id' => $disputeId,
                    'reason' => $reason,
                ]
            );

            $this->db->commit();

            return $this->json($res, [
                'message' => 'Disputa enviada correctamente',
                'dispute_id' => $disputeId,
            ], 201);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('openDispute error: ' . $e->getMessage());
            return $this->json($res, ['error' => 'No se pudo registrar la disputa'], 500);
        }
    }

    public function updateDispute(Request $req, Response $res, array $args): Response
    {
        $user = (array)$req->getAttribute('user');
        $userId = (int)($user['id'] ?? 0);
        $matchId = (int)($args['id'] ?? 0);
        $disputeId = (int)($args['disputeId'] ?? 0);
        $data = (array)$req->getParsedBody();
        $status = trim((string)($data['status'] ?? ''));
        $resolutionNote = trim((string)($data['resolution_note'] ?? ''));

        if ($userId <= 0) {
            return $this->json($res, ['error' => 'No autorizado'], 401);
        }
        if ($matchId <= 0 || $disputeId <= 0) {
            return $this->json($res, ['error' => 'Parametros no validos'], 400);
        }
        if (!in_array($status, ['open', 'reviewing', 'resolved', 'rejected'], true)) {
            return $this->json($res, ['error' => 'Estado de disputa no valido'], 400);
        }
        if (mb_strlen($resolutionNote) > 500) {
            return $this->json($res, ['error' => 'resolution_note no puede superar 500 caracteres'], 400);
        }

        try {
            $this->db->beginTransaction();

            $match = $this->getMatchWithTournament($matchId, true);
            if (!$match) {
                $this->db->rollBack();
                return $this->json($res, ['error' => 'Partido no encontrado'], 404);
            }

            $tournament = [
                'id' => (int)$match['tournament_id'],
                'name' => (string)$match['tournament_name'],
                'visibility' => (string)$match['tournament_visibility'],
                'created_by' => (int)$match['tournament_created_by'],
                'max_teams' => (int)$match['tournament_max_teams'],
            ];

            if (!$this->isAdminOrOwner($user, $tournament)) {
                $this->db->rollBack();
                return $this->json($res, ['error' => 'Solo el creador o admin puede gestionar disputas'], 403);
            }

            $checkStmt = $this->db->prepare("
                SELECT id, status
                FROM tournament_match_disputes
                WHERE id = ? AND match_id = ?
                LIMIT 1
                FOR UPDATE
            ");
            $checkStmt->execute([$disputeId, $matchId]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
            if (!$existing) {
                $this->db->rollBack();
                return $this->json($res, ['error' => 'Disputa no encontrada'], 404);
            }

            $resolvedBy = in_array($status, ['resolved', 'rejected'], true) ? $userId : null;
            $resolvedAt = in_array($status, ['resolved', 'rejected'], true) ? date('Y-m-d H:i:s') : null;

            $updateStmt = $this->db->prepare("
                UPDATE tournament_match_disputes
                SET
                    status = ?,
                    resolution_note = ?,
                    resolved_by = ?,
                    resolved_at = ?
                WHERE id = ?
            ");
            $updateStmt->execute([
                $status,
                ($resolutionNote !== '' ? $resolutionNote : null),
                $resolvedBy,
                $resolvedAt,
                $disputeId,
            ]);

            $this->logMatchEvent(
                $matchId,
                'dispute_updated',
                $userId,
                [
                    'dispute_id' => $disputeId,
                    'from' => (string)$existing['status'],
                    'to' => $status,
                    'resolution_note' => $resolutionNote,
                ]
            );

            $this->db->commit();
            return $this->json($res, ['message' => 'Disputa actualizada correctamente']);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('updateDispute error: ' . $e->getMessage());
            return $this->json($res, ['error' => 'No se pudo actualizar la disputa'], 500);
        }
    }

    private function getTournamentById(int $tournamentId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT
                id,
                name,
                max_teams,
                COALESCE(visibility, 'public') AS visibility,
                access_code_hash,
                created_by,
                COALESCE(format, 'single_elim') AS format
            FROM tournaments
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$tournamentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function getMatchWithTournament(int $matchId, bool $forUpdate = false): ?array
    {
        $sql = "
            SELECT
                m.*,
                t.name AS tournament_name,
                t.created_by AS tournament_created_by,
                COALESCE(t.visibility, 'public') AS tournament_visibility,
                t.access_code_hash AS tournament_access_code_hash,
                t.max_teams AS tournament_max_teams,
                COALESCE(t.format, 'single_elim') AS tournament_format,
                ta.name AS team_a_name,
                ta.logo_url AS team_a_logo_url,
                ta.color_hex AS team_a_color_hex,
                ta.captain_id AS team_a_captain_id,
                tb.name AS team_b_name,
                tb.logo_url AS team_b_logo_url,
                tb.color_hex AS team_b_color_hex,
                tb.captain_id AS team_b_captain_id,
                tw.name AS winner_team_name,
                (
                    SELECT m2.id
                    FROM tournament_matches m2
                    WHERE m2.source_match_a_id = m.id OR m2.source_match_b_id = m.id
                    ORDER BY m2.round_number ASC, m2.bracket_slot ASC
                    LIMIT 1
                ) AS next_match_id
            FROM tournament_matches m
            INNER JOIN tournaments t ON t.id = m.tournament_id
            LEFT JOIN teams ta ON ta.id = m.team_a_id
            LEFT JOIN teams tb ON tb.id = m.team_b_id
            LEFT JOIN teams tw ON tw.id = m.winner_team_id
            WHERE m.id = ?
            LIMIT 1
        ";
        if ($forUpdate) {
            $sql .= " FOR UPDATE";
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$matchId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function getTournamentMatchesFlat(int $tournamentId): array
    {
        $stmt = $this->db->prepare("
            SELECT
                m.*,
                ta.name AS team_a_name,
                ta.logo_url AS team_a_logo_url,
                ta.color_hex AS team_a_color_hex,
                tb.name AS team_b_name,
                tb.logo_url AS team_b_logo_url,
                tb.color_hex AS team_b_color_hex,
                tw.name AS winner_team_name,
                (
                    SELECT COUNT(*)
                    FROM tournament_match_disputes d
                    WHERE d.match_id = m.id
                      AND d.status IN ('open', 'reviewing')
                ) AS open_disputes_count
            FROM tournament_matches m
            LEFT JOIN teams ta ON ta.id = m.team_a_id
            LEFT JOIN teams tb ON tb.id = m.team_b_id
            LEFT JOIN teams tw ON tw.id = m.winner_team_id
            WHERE m.tournament_id = ?
            ORDER BY m.round_number ASC, m.bracket_slot ASC
        ");
        $stmt->execute([$tournamentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function buildPhaseTimeline(int $tournamentId): array
    {
        $stmt = $this->db->prepare("
            SELECT
                round_number,
                phase_label,
                COUNT(*) AS total_matches,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
                SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress_count,
                SUM(CASE WHEN status = 'finalized' THEN 1 ELSE 0 END) AS finalized_count
            FROM tournament_matches
            WHERE tournament_id = ?
            GROUP BY round_number, phase_label
            ORDER BY round_number ASC
        ");
        $stmt->execute([$tournamentId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $timeline = [];
        foreach ($rows as $row) {
            $timeline[] = [
                'round_number' => (int)$row['round_number'],
                'phase_label' => (string)$row['phase_label'],
                'total_matches' => (int)$row['total_matches'],
                'pending_count' => (int)$row['pending_count'],
                'in_progress_count' => (int)$row['in_progress_count'],
                'finalized_count' => (int)$row['finalized_count'],
            ];
        }
        return $timeline;
    }

    private function getMatchTimeline(int $matchId): array
    {
        $stmt = $this->db->prepare("
            SELECT
                e.id,
                e.event_type,
                e.payload_json,
                e.created_at,
                e.actor_user_id,
                u.username AS actor_username
            FROM tournament_match_events e
            LEFT JOIN users u ON u.id = e.actor_user_id
            WHERE e.match_id = ?
            ORDER BY e.created_at ASC, e.id ASC
        ");
        $stmt->execute([$matchId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $timeline = [];
        foreach ($rows as $row) {
            $payload = null;
            if (isset($row['payload_json']) && $row['payload_json'] !== null && $row['payload_json'] !== '') {
                $decoded = json_decode((string)$row['payload_json'], true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $payload = $decoded;
                }
            }

            $timeline[] = [
                'id' => (int)$row['id'],
                'event_type' => (string)$row['event_type'],
                'actor_user_id' => $row['actor_user_id'] !== null ? (int)$row['actor_user_id'] : null,
                'actor_username' => (string)($row['actor_username'] ?? ''),
                'payload' => $payload,
                'created_at' => $row['created_at'],
            ];
        }
        return $timeline;
    }

    private function getMatchDisputes(int $matchId): array
    {
        $stmt = $this->db->prepare("
            SELECT
                d.id,
                d.reason,
                d.status,
                d.resolution_note,
                d.created_at,
                d.updated_at,
                d.resolved_at,
                d.created_by,
                d.resolved_by,
                cu.username AS created_by_username,
                ru.username AS resolved_by_username
            FROM tournament_match_disputes d
            LEFT JOIN users cu ON cu.id = d.created_by
            LEFT JOIN users ru ON ru.id = d.resolved_by
            WHERE d.match_id = ?
            ORDER BY d.created_at DESC, d.id DESC
        ");
        $stmt->execute([$matchId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'id' => (int)$row['id'],
                'reason' => (string)$row['reason'],
                'status' => (string)$row['status'],
                'resolution_note' => (string)($row['resolution_note'] ?? ''),
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
                'resolved_at' => $row['resolved_at'],
                'created_by' => (int)$row['created_by'],
                'created_by_username' => (string)($row['created_by_username'] ?? ''),
                'resolved_by' => $row['resolved_by'] !== null ? (int)$row['resolved_by'] : null,
                'resolved_by_username' => (string)($row['resolved_by_username'] ?? ''),
            ];
        }
        return $result;
    }

    private function getMatchParticipantFlags(array $match, int $userId): array
    {
        $teamAId = $match['team_a_id'] ? (int)$match['team_a_id'] : null;
        $teamBId = $match['team_b_id'] ? (int)$match['team_b_id'] : null;

        $isTeamACaptain = $teamAId
            ? $this->hasTeamRole($teamAId, $userId, ['captain'])
            : false;
        $isTeamBCaptain = $teamBId
            ? $this->hasTeamRole($teamBId, $userId, ['captain'])
            : false;

        $isTeamAOfficer = $teamAId
            ? $this->hasTeamRole($teamAId, $userId, ['captain', 'co_captain'])
            : false;
        $isTeamBOfficer = $teamBId
            ? $this->hasTeamRole($teamBId, $userId, ['captain', 'co_captain'])
            : false;

        return [
            'is_team_a_captain' => $isTeamACaptain,
            'is_team_b_captain' => $isTeamBCaptain,
            'is_team_a_officer' => $isTeamAOfficer,
            'is_team_b_officer' => $isTeamBOfficer,
        ];
    }

    private function hasTeamRole(int $teamId, int $userId, array $roles): bool
    {
        if (empty($roles)) {
            return false;
        }

        $validRoles = ['captain', 'co_captain', 'player'];
        $allowed = array_values(array_filter(
            $roles,
            fn($role) => in_array($role, $validRoles, true)
        ));
        if (empty($allowed)) {
            return false;
        }

        $placeholders = implode(', ', array_fill(0, count($allowed), '?'));
        $stmt = $this->db->prepare("
            SELECT id
            FROM team_members
            WHERE team_id = ?
              AND user_id = ?
              AND pending_validation = 0
              AND role IN ($placeholders)
            LIMIT 1
        ");
        $stmt->execute(array_merge([$teamId, $userId], $allowed));
        if ($stmt->fetch(PDO::FETCH_ASSOC)) {
            return true;
        }

        // Fallback legacy: solo existe captain_id.
        if (!in_array('captain', $allowed, true)) {
            return false;
        }

        $legacyStmt = $this->db->prepare("
            SELECT id
            FROM teams
            WHERE id = ? AND captain_id = ?
            LIMIT 1
        ");
        $legacyStmt->execute([$teamId, $userId]);
        return (bool)$legacyStmt->fetch(PDO::FETCH_ASSOC);
    }

    private function canAccessTournament(array $user, array $tournament, string $accessCode = ''): bool
    {
        $visibility = (string)($tournament['visibility'] ?? 'public');
        if ($visibility !== 'private') {
            return true;
        }

        if ($this->isAdminOrOwner($user, $tournament)) {
            return true;
        }

        $userId = (int)($user['id'] ?? 0);
        if ($userId > 0 && $this->userBelongsToTournament($userId, (int)$tournament['id'])) {
            return true;
        }

        $hash = (string)($tournament['access_code_hash'] ?? '');
        if ($hash === '' && isset($tournament['tournament_access_code_hash'])) {
            $hash = (string)$tournament['tournament_access_code_hash'];
        }

        return $this->verifyAccessCode($accessCode, $hash);
    }

    private function userBelongsToTournament(int $userId, int $tournamentId): bool
    {
        $stmt = $this->db->prepare("
            SELECT tm.id
            FROM team_members tm
            INNER JOIN teams t ON t.id = tm.team_id
            WHERE t.tournament_id = ?
              AND tm.user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$tournamentId, $userId]);
        if ($stmt->fetch(PDO::FETCH_ASSOC)) {
            return true;
        }

        $legacyStmt = $this->db->prepare("
            SELECT id
            FROM teams
            WHERE tournament_id = ?
              AND captain_id = ?
            LIMIT 1
        ");
        $legacyStmt->execute([$tournamentId, $userId]);
        return (bool)$legacyStmt->fetch(PDO::FETCH_ASSOC);
    }

    private function isAdminOrOwner(array $user, array $tournament): bool
    {
        $role = (string)($user['role'] ?? '');
        if ($role === 'admin') {
            return true;
        }

        $userId = (int)($user['id'] ?? 0);
        return $userId > 0 && $userId === (int)($tournament['created_by'] ?? 0);
    }

    private function resolveAccessCodeFromRequest(Request $req): string
    {
        $body = (array)$req->getParsedBody();
        $fromBody = $this->sanitizeAccessCode((string)($body['access_code'] ?? ''));
        if ($fromBody !== '') {
            return $fromBody;
        }

        $fromHeader = $this->sanitizeAccessCode($req->getHeaderLine('X-Tournament-Code'));
        if ($fromHeader !== '') {
            return $fromHeader;
        }

        return $this->sanitizeAccessCode((string)($req->getQueryParams()['code'] ?? ''));
    }

    private function sanitizeAccessCode(string $code): string
    {
        return (string)preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($code)));
    }

    private function verifyAccessCode(string $candidate, string $hash): bool
    {
        if ($candidate === '' || $hash === '') {
            return false;
        }
        return password_verify($candidate, $hash);
    }

    private function nextPowerOfTwo(int $value): int
    {
        $power = 1;
        while ($power < $value) {
            $power <<= 1;
        }
        return $power;
    }

    private function phaseLabel(int $round, int $totalRounds): string
    {
        if ($totalRounds <= 1 || $round === $totalRounds) {
            return 'Final';
        }
        if ($round === $totalRounds - 1) {
            return 'Semifinal';
        }
        if ($round === $totalRounds - 2) {
            return 'Cuartos';
        }
        if ($round === $totalRounds - 3) {
            return 'Octavos';
        }
        return 'Ronda ' . $round;
    }

    private function buildLeagueStandings(int $tournamentId): array
    {
        $teamsStmt = $this->db->prepare("
            SELECT id, name
            FROM teams
            WHERE tournament_id = ?
            ORDER BY registered_at ASC, id ASC
        ");
        $teamsStmt->execute([$tournamentId]);
        $teams = $teamsStmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($teams)) {
            return [];
        }

        $table = [];
        foreach ($teams as $team) {
            $teamId = (int)$team['id'];
            $table[$teamId] = [
                'team_id' => $teamId,
                'team_name' => (string)$team['name'],
                'played' => 0,
                'won' => 0,
                'drawn' => 0,
                'lost' => 0,
                'goals_for' => 0,
                'goals_against' => 0,
                'goal_diff' => 0,
                'points' => 0,
            ];
        }

        $h2hPoints = [];
        $matchesStmt = $this->db->prepare("
            SELECT team_a_id, team_b_id, score_a, score_b
            FROM tournament_matches
            WHERE tournament_id = ?
              AND status = 'finalized'
              AND team_a_id IS NOT NULL
              AND team_b_id IS NOT NULL
        ");
        $matchesStmt->execute([$tournamentId]);
        $matches = $matchesStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($matches as $match) {
            $teamAId = (int)$match['team_a_id'];
            $teamBId = (int)$match['team_b_id'];
            if (!isset($table[$teamAId]) || !isset($table[$teamBId])) {
                continue;
            }

            $scoreA = (int)$match['score_a'];
            $scoreB = (int)$match['score_b'];

            $table[$teamAId]['played']++;
            $table[$teamBId]['played']++;
            $table[$teamAId]['goals_for'] += $scoreA;
            $table[$teamAId]['goals_against'] += $scoreB;
            $table[$teamBId]['goals_for'] += $scoreB;
            $table[$teamBId]['goals_against'] += $scoreA;

            $h2hPoints[$teamAId] ??= [];
            $h2hPoints[$teamBId] ??= [];
            $h2hPoints[$teamAId][$teamBId] = (int)($h2hPoints[$teamAId][$teamBId] ?? 0);
            $h2hPoints[$teamBId][$teamAId] = (int)($h2hPoints[$teamBId][$teamAId] ?? 0);

            if ($scoreA > $scoreB) {
                $table[$teamAId]['won']++;
                $table[$teamBId]['lost']++;
                $table[$teamAId]['points'] += 3;
                $h2hPoints[$teamAId][$teamBId] += 3;
            } elseif ($scoreA < $scoreB) {
                $table[$teamBId]['won']++;
                $table[$teamAId]['lost']++;
                $table[$teamBId]['points'] += 3;
                $h2hPoints[$teamBId][$teamAId] += 3;
            } else {
                $table[$teamAId]['drawn']++;
                $table[$teamBId]['drawn']++;
                $table[$teamAId]['points'] += 1;
                $table[$teamBId]['points'] += 1;
                $h2hPoints[$teamAId][$teamBId] += 1;
                $h2hPoints[$teamBId][$teamAId] += 1;
            }
        }

        foreach ($table as &$row) {
            $row['goal_diff'] = (int)$row['goals_for'] - (int)$row['goals_against'];
        }
        unset($row);

        $rows = array_values($table);
        usort($rows, function (array $a, array $b) use ($h2hPoints): int {
            if ($a['points'] !== $b['points']) {
                return $b['points'] <=> $a['points'];
            }
            if ($a['goal_diff'] !== $b['goal_diff']) {
                return $b['goal_diff'] <=> $a['goal_diff'];
            }
            if ($a['goals_for'] !== $b['goals_for']) {
                return $b['goals_for'] <=> $a['goals_for'];
            }

            $aVsB = (int)($h2hPoints[$a['team_id']][$b['team_id']] ?? 0);
            $bVsA = (int)($h2hPoints[$b['team_id']][$a['team_id']] ?? 0);
            if ($aVsB !== $bVsA) {
                return $bVsA <=> $aVsB;
            }

            $nameCmp = strcasecmp((string)$a['team_name'], (string)$b['team_name']);
            if ($nameCmp !== 0) {
                return $nameCmp;
            }

            return $a['team_id'] <=> $b['team_id'];
        });

        $position = 1;
        foreach ($rows as &$row) {
            $row['position'] = $position++;
        }
        unset($row);

        return $rows;
    }

    private function getStandingsRules(): array
    {
        return [
            'Puntos totales (PTS).',
            'Diferencia de goles (DG).',
            'Goles a favor (GF).',
            'Puntos en enfrentamiento directo entre empatados.',
            'Orden alfabético del nombre de equipo.',
        ];
    }

    private function logMatchEvent(int $matchId, string $eventType, ?int $actorUserId, array $payload = []): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO tournament_match_events (match_id, event_type, actor_user_id, payload_json)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([
            $matchId,
            $eventType,
            ($actorUserId && $actorUserId > 0) ? $actorUserId : null,
            !empty($payload) ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    private function propagateWinnerToChildren(int $matchId): void
    {
        $winnerStmt = $this->db->prepare("
            SELECT id, winner_team_id
            FROM tournament_matches
            WHERE id = ?
            LIMIT 1
        ");
        $winnerStmt->execute([$matchId]);
        $winnerRow = $winnerStmt->fetch(PDO::FETCH_ASSOC);
        if (!$winnerRow || !(int)$winnerRow['winner_team_id']) {
            return;
        }
        $winnerTeamId = (int)$winnerRow['winner_team_id'];

        $childrenStmt = $this->db->prepare("
            SELECT id, source_match_a_id, source_match_b_id, team_a_id, team_b_id
            FROM tournament_matches
            WHERE source_match_a_id = ? OR source_match_b_id = ?
        ");
        $childrenStmt->execute([$matchId, $matchId]);
        $children = $childrenStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($children as $child) {
            $childId = (int)$child['id'];
            $teamAId = $child['team_a_id'] !== null ? (int)$child['team_a_id'] : null;
            $teamBId = $child['team_b_id'] !== null ? (int)$child['team_b_id'] : null;

            if ((int)$child['source_match_a_id'] === $matchId) {
                $teamAId = $winnerTeamId;
            }
            if ((int)$child['source_match_b_id'] === $matchId) {
                $teamBId = $winnerTeamId;
            }

            $updateStmt = $this->db->prepare("
                UPDATE tournament_matches
                SET team_a_id = ?, team_b_id = ?
                WHERE id = ?
            ");
            $updateStmt->execute([$teamAId, $teamBId, $childId]);
        }
    }

    private function normalizeMatchRow(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'tournament_id' => (int)$row['tournament_id'],
            'round_number' => (int)$row['round_number'],
            'phase_label' => (string)$row['phase_label'],
            'bracket_slot' => (int)$row['bracket_slot'],
            'source_match_a_id' => $row['source_match_a_id'] !== null ? (int)$row['source_match_a_id'] : null,
            'source_match_b_id' => $row['source_match_b_id'] !== null ? (int)$row['source_match_b_id'] : null,
            'team_a_id' => $row['team_a_id'] !== null ? (int)$row['team_a_id'] : null,
            'team_a_name' => (string)($row['team_a_name'] ?? ''),
            'team_a_logo_url' => (string)($row['team_a_logo_url'] ?? ''),
            'team_a_color_hex' => (string)($row['team_a_color_hex'] ?? '#0EA5E9'),
            'team_b_id' => $row['team_b_id'] !== null ? (int)$row['team_b_id'] : null,
            'team_b_name' => (string)($row['team_b_name'] ?? ''),
            'team_b_logo_url' => (string)($row['team_b_logo_url'] ?? ''),
            'team_b_color_hex' => (string)($row['team_b_color_hex'] ?? '#64748B'),
            'status' => (string)$row['status'],
            'scheduled_at' => $row['scheduled_at'],
            'location_name' => (string)($row['location_name'] ?? ''),
            'score_a' => (int)$row['score_a'],
            'score_b' => (int)$row['score_b'],
            'winner_team_id' => $row['winner_team_id'] !== null ? (int)$row['winner_team_id'] : null,
            'winner_team_name' => (string)($row['winner_team_name'] ?? ''),
            'captain_a_confirmed' => (int)$row['captain_a_confirmed'] === 1,
            'captain_b_confirmed' => (int)$row['captain_b_confirmed'] === 1,
            'finalized_at' => $row['finalized_at'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'open_disputes_count' => isset($row['open_disputes_count']) ? (int)$row['open_disputes_count'] : 0,
            'next_match_id' => isset($row['next_match_id']) && $row['next_match_id'] !== null ? (int)$row['next_match_id'] : null,
        ];
    }

    private function scalar(string $sql, array $params = []): int
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)($stmt->fetchColumn() ?: 0);
    }

    private function json(Response $res, array $payload, int $status = 200): Response
    {
        $res->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $res->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}