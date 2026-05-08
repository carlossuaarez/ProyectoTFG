<?php

require_once __DIR__ . '/../Repositories/MatchRepository.php';
require_once __DIR__ . '/AccessControlService.php';

class MatchService
{
    private PDO $db;
    private MatchRepository $repo;
    private AccessControlService $access;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->repo = new MatchRepository($db);
        $this->access = new AccessControlService($db);
    }

    public function getTournamentMatches(array $user, int $tournamentId, string $accessCode): array
    {
        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0) return $this->result(401, ['error' => 'No autorizado']);
        if ($tournamentId <= 0) return $this->result(400, ['error' => 'ID de torneo no valido']);

        try {
            $tournament = $this->repo->findTournamentById($tournamentId);
            if (!$tournament) return $this->result(404, ['error' => 'Torneo no encontrado']);

            if (!$this->access->canAccessTournament($user, $tournament, $accessCode)) {
                return $this->result(403, [
                    'error' => 'Este torneo es privado. Introduce el código de acceso.',
                    'requires_access_code' => true,
                ]);
            }

            $matches = $this->repo->fetchTournamentMatchesFlat($tournamentId);
            $phaseTimeline = $this->buildPhaseTimeline($tournamentId);
            $teamsCount = $this->repo->countTeamsInTournament($tournamentId);
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

            $isManager = $this->access->isAdminOrOwner($user, $tournament);
            $canBootstrap = $isManager && $teamsCount >= 2 && count($matches) === 0;

            return $this->result(200, [
                'tournament' => [
                    'id' => (int)$tournament['id'],
                    'name' => (string)$tournament['name'],
                    'max_teams' => (int)$tournament['max_teams'],
                    'format' => $format,
                    'visibility' => (string)$tournament['visibility'],
                    'created_by' => (int)$tournament['created_by'],
                ],
                'rounds' => array_values($roundsMap),
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
            return $this->result(500, ['error' => 'No se pudieron cargar los partidos']);
        }
    }

    public function bootstrapTournamentBracket(array $user, int $tournamentId): array
    {
        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0) return $this->result(401, ['error' => 'No autorizado']);
        if ($tournamentId <= 0) return $this->result(400, ['error' => 'ID de torneo no valido']);

        try {
            $tournament = $this->repo->findTournamentById($tournamentId);
            if (!$tournament) return $this->result(404, ['error' => 'Torneo no encontrado']);
            if (!$this->access->isAdminOrOwner($user, $tournament)) {
                return $this->result(403, ['error' => 'Solo el creador o admin puede generar el bracket']);
            }
            if ($this->repo->countMatchesInTournament($tournamentId) > 0) {
                return $this->result(409, ['error' => 'Este torneo ya tiene partidos generados']);
            }

            $teams = $this->repo->fetchTeamsForBracket($tournamentId);
            if (count($teams) < 2) {
                return $this->result(400, ['error' => 'Se necesitan al menos 2 equipos para generar partidos']);
            }

            $bracketSize = $this->nextPowerOfTwo(count($teams));
            $totalRounds = (int)round(log($bracketSize, 2));

            $this->db->beginTransaction();
            $roundMatchIds = [];
            $autoFinalizedMatchIds = [];

            for ($round = 1; $round <= $totalRounds; $round++) {
                $matchesInRound = (int)($bracketSize / (2 ** $round));
                $phaseLabel = $this->phaseLabel($round, $totalRounds);
                $roundMatchIds[$round] = [];

                for ($slot = 1; $slot <= $matchesInRound; $slot++) {
                    $sourceA = null; $sourceB = null;
                    $teamA = null; $teamB = null;
                    $status = 'pending';
                    $scoreA = 0; $scoreB = 0;
                    $winner = null;
                    $confirmA = 0; $confirmB = 0;
                    $finalizedAt = null;

                    if ($round === 1) {
                        $teamAIndex = (($slot - 1) * 2);
                        $teamBIndex = $teamAIndex + 1;
                        $teamA = isset($teams[$teamAIndex]) ? (int)$teams[$teamAIndex]['id'] : null;
                        $teamB = isset($teams[$teamBIndex]) ? (int)$teams[$teamBIndex]['id'] : null;

                        if (($teamA && !$teamB) || (!$teamA && $teamB)) {
                            $winner = $teamA ?: $teamB;
                            $status = 'finalized';
                            $confirmA = 1; $confirmB = 1;
                            if ($teamA && !$teamB) { $scoreA = 1; $scoreB = 0; }
                            elseif (!$teamA && $teamB) { $scoreA = 0; $scoreB = 1; }
                            $finalizedAt = date('Y-m-d H:i:s');
                        }
                    } else {
                        $sourceA = (int)$roundMatchIds[$round - 1][($slot * 2) - 2];
                        $sourceB = (int)$roundMatchIds[$round - 1][($slot * 2) - 1];
                    }

                    $matchId = $this->repo->insertMatch([
                        'tournament_id' => $tournamentId,
                        'round_number' => $round,
                        'phase_label' => $phaseLabel,
                        'bracket_slot' => $slot,
                        'source_match_a_id' => $sourceA,
                        'source_match_b_id' => $sourceB,
                        'team_a_id' => $teamA,
                        'team_b_id' => $teamB,
                        'status' => $status,
                        'score_a' => $scoreA,
                        'score_b' => $scoreB,
                        'winner_team_id' => $winner,
                        'captain_a_confirmed' => $confirmA,
                        'captain_b_confirmed' => $confirmB,
                        'finalized_at' => $finalizedAt,
                        'created_by' => $userId,
                    ]);
                    $roundMatchIds[$round][] = $matchId;

                    $this->repo->logMatchEvent($matchId, 'created', $userId, [
                        'phase_label' => $phaseLabel,
                        'round_number' => $round,
                        'slot' => $slot,
                    ]);

                    if ($status === 'finalized' && $winner) {
                        $autoFinalizedMatchIds[] = $matchId;
                        $this->repo->logMatchEvent($matchId, 'finalized', $userId, [
                            'auto_bye' => true,
                            'winner_team_id' => $winner,
                        ]);
                    }
                }
            }

            foreach ($autoFinalizedMatchIds as $autoMatchId) {
                $this->propagateWinnerToChildren($autoMatchId);
            }

            $this->db->commit();

            return $this->result(201, [
                'message' => 'Bracket generado correctamente',
                'meta' => [
                    'teams_count' => count($teams),
                    'bracket_size' => $bracketSize,
                    'rounds' => $totalRounds,
                    'matches_created' => array_sum(array_map('count', $roundMatchIds)),
                ],
            ]);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log('bootstrapTournamentBracket error: ' . $e->getMessage());
            return $this->result(500, ['error' => 'No se pudo generar el bracket']);
        }
    }

    public function getMatchCenter(array $user, int $matchId, string $accessCode): array
    {
        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0) return $this->result(401, ['error' => 'No autorizado']);
        if ($matchId <= 0) return $this->result(400, ['error' => 'ID de partido no valido']);

        try {
            $match = $this->repo->findMatchWithTournament($matchId);
            if (!$match) return $this->result(404, ['error' => 'Partido no encontrado']);

            $tournament = $this->extractTournamentFromMatch($match);

            if (!$this->access->canAccessTournament($user, $tournament, $accessCode)) {
                return $this->result(403, [
                    'error' => 'Este torneo es privado. Introduce el código de acceso.',
                    'requires_access_code' => true,
                    'tournament_id' => (int)$match['tournament_id'],
                ]);
            }

            $flags = $this->getMatchParticipantFlags($match, $userId);
            $isManager = $this->access->isAdminOrOwner($user, $tournament);
            $timeline = $this->mapMatchTimeline($this->repo->fetchMatchTimeline($matchId));
            $disputes = $this->mapMatchDisputes($this->repo->fetchMatchDisputes($matchId));
            $phaseTimeline = $this->buildPhaseTimeline((int)$match['tournament_id']);

            return $this->result(200, [
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
            return $this->result(500, ['error' => 'No se pudo cargar el centro de partido']);
        }
    }

    public function updateMatchStatus(array $user, int $matchId, string $newStatus): array
    {
        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0) return $this->result(401, ['error' => 'No autorizado']);
        if ($matchId <= 0) return $this->result(400, ['error' => 'ID de partido no valido']);
        if (!in_array($newStatus, ['pending', 'in_progress', 'finalized'], true)) {
            return $this->result(400, ['error' => 'Estado no valido']);
        }

        try {
            $this->db->beginTransaction();
            $match = $this->repo->findMatchWithTournament($matchId, true);
            if (!$match) { $this->db->rollBack(); return $this->result(404, ['error' => 'Partido no encontrado']); }

            $tournament = $this->extractTournamentFromMatch($match);
            if (!$this->access->isAdminOrOwner($user, $tournament)) {
                $this->db->rollBack();
                return $this->result(403, ['error' => 'Solo el creador o admin puede cambiar el estado']);
            }

            $tournamentFormat = (string)($match['tournament_format'] ?? 'single_elim');
            if ($newStatus === 'finalized' && !$match['winner_team_id'] && $tournamentFormat !== 'league') {
                $this->db->rollBack();
                return $this->result(400, ['error' => 'No se puede finalizar sin equipo ganador']);
            }

            $finalizedAt = ($newStatus === 'finalized') ? date('Y-m-d H:i:s') : null;
            $this->repo->updateMatchStatus($matchId, $newStatus, $finalizedAt);

            $this->repo->logMatchEvent($matchId, 'status_change', $userId, [
                'from' => (string)$match['status'],
                'to' => $newStatus,
            ]);

            if ($newStatus === 'finalized') {
                $this->repo->logMatchEvent($matchId, 'finalized', $userId, [
                    'winner_team_id' => (int)$match['winner_team_id'],
                ]);
                $this->propagateWinnerToChildren($matchId);
            }

            $this->db->commit();
            return $this->result(200, ['message' => 'Estado de partido actualizado']);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log('updateMatchStatus error: ' . $e->getMessage());
            return $this->result(500, ['error' => 'No se pudo actualizar el estado']);
        }
    }

    public function submitMatchScore(array $user, int $matchId, array $data): array
    {
        return $this->doScoreUpdate($user, $matchId, $data, false);
    }

    public function overrideMatchScore(array $user, int $matchId, array $data): array
    {
        return $this->doScoreUpdate($user, $matchId, $data, true);
    }

    private function doScoreUpdate(array $user, int $matchId, array $data, bool $forceFinalize): array
    {
        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0) return $this->result(401, ['error' => 'No autorizado']);
        if ($matchId <= 0) return $this->result(400, ['error' => 'ID de partido no valido']);
        if (!array_key_exists('score_a', $data) || !array_key_exists('score_b', $data)) {
            return $this->result(400, ['error' => 'Debes enviar score_a y score_b']);
        }

        $scoreA = (int)$data['score_a'];
        $scoreB = (int)$data['score_b'];
        if ($scoreA < 0 || $scoreA > 99 || $scoreB < 0 || $scoreB > 99) {
            return $this->result(400, ['error' => 'Los marcadores deben estar entre 0 y 99']);
        }

        try {
            $this->db->beginTransaction();
            $match = $this->repo->findMatchWithTournament($matchId, true);
            if (!$match) { $this->db->rollBack(); return $this->result(404, ['error' => 'Partido no encontrado']); }

            $tournament = $this->extractTournamentFromMatch($match);
            $isManager = $this->access->isAdminOrOwner($user, $tournament);
            $flags = $this->getMatchParticipantFlags($match, $userId);
            $isParticipantOfficer = $flags['is_team_a_officer'] || $flags['is_team_b_officer'];

            if ($forceFinalize) {
                if (!$isManager) {
                    $this->db->rollBack();
                    return $this->result(403, ['error' => 'Solo el creador o admin puede forzar el resultado']);
                }
            } else {
                if (!$isManager && !$isParticipantOfficer) {
                    $this->db->rollBack();
                    return $this->result(403, ['error' => 'No tienes permisos para reportar resultado']);
                }
            }

            $teamA = $match['team_a_id'] ? (int)$match['team_a_id'] : null;
            $teamB = $match['team_b_id'] ? (int)$match['team_b_id'] : null;
            if (!$teamA && !$teamB) {
                $this->db->rollBack();
                return $this->result(400, ['error' => 'Este partido aun no tiene equipos asignados']);
            }

            $tournamentFormat = (string)($match['tournament_format'] ?? 'single_elim');
            $isLeague = ($tournamentFormat === 'league');

            if ($teamA && $teamB && $scoreA === $scoreB && !$isLeague) {
                $this->db->rollBack();
                return $this->result(400, ['error' => 'No puede haber empate en este tipo de partido']);
            }

            if ($teamA && !$teamB) $winner = $teamA;
            elseif (!$teamA && $teamB) $winner = $teamB;
            elseif ($scoreA === $scoreB) $winner = null;
            else $winner = ($scoreA > $scoreB) ? $teamA : $teamB;

            $prevScoreA = (int)$match['score_a'];
            $prevScoreB = (int)$match['score_b'];
            $scoreChanged = ($prevScoreA !== $scoreA) || ($prevScoreB !== $scoreB);

            $captainAConfirmed = $scoreChanged ? 0 : (int)$match['captain_a_confirmed'];
            $captainBConfirmed = $scoreChanged ? 0 : (int)$match['captain_b_confirmed'];

            if ($forceFinalize) {
                $captainAConfirmed = 1;
                $captainBConfirmed = 1;
            } elseif (($teamA && !$teamB) || (!$teamA && $teamB)) {
                $captainAConfirmed = 1;
                $captainBConfirmed = 1;
            } elseif ($flags['is_team_a_officer']) {
                $captainAConfirmed = 1;
            } elseif ($flags['is_team_b_officer']) {
                $captainBConfirmed = 1;
            }

            $status = 'in_progress';
            $finalizedAt = null;
            if ($captainAConfirmed === 1 && $captainBConfirmed === 1) {
                $status = 'finalized';
                $finalizedAt = date('Y-m-d H:i:s');
            }

            $this->repo->updateMatchScore(
                $matchId, $scoreA, $scoreB, $winner,
                $captainAConfirmed, $captainBConfirmed,
                $status, $finalizedAt
            );

            $this->repo->logMatchEvent(
                $matchId,
                $forceFinalize ? 'score_overridden' : 'score_submitted',
                $userId,
                [
                    'score_a' => $scoreA,
                    'score_b' => $scoreB,
                    'winner_team_id' => $winner,
                    'requires_double_confirmation' => (!$forceFinalize && $teamA && $teamB),
                ]
            );

            if ($status === 'finalized') {
                $this->repo->logMatchEvent($matchId, 'finalized', $userId, [
                    'winner_team_id' => $winner,
                    'auto_bye' => true,
                ]);
                if ($winner) {
                    $this->propagateWinnerToChildren($matchId);
                }
            }

            $this->db->commit();

            if ($forceFinalize) {
                $message = 'Resultado forzado y partido finalizado';
            } elseif ($status === 'finalized') {
                $message = 'Resultado guardado y partido finalizado';
            } elseif ($scoreChanged) {
                $message = 'Resultado actualizado. Se requiere reconfirmacion de capitanes.';
            } else {
                $message = 'Resultado guardado. Pendiente de doble confirmacion';
            }

            return $this->result(200, ['message' => $message]);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log('submitMatchScore error: ' . $e->getMessage());
            return $this->result(500, ['error' => 'No se pudo guardar el resultado']);
        }
    }

    public function confirmMatchResult(array $user, int $matchId): array
    {
        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0) return $this->result(401, ['error' => 'No autorizado']);
        if ($matchId <= 0) return $this->result(400, ['error' => 'ID de partido no valido']);

        try {
            $this->db->beginTransaction();
            $match = $this->repo->findMatchWithTournament($matchId, true);
            if (!$match) { $this->db->rollBack(); return $this->result(404, ['error' => 'Partido no encontrado']); }

            $teamA = $match['team_a_id'] ? (int)$match['team_a_id'] : null;
            $teamB = $match['team_b_id'] ? (int)$match['team_b_id'] : null;
            if (!$teamA || !$teamB) {
                $this->db->rollBack();
                return $this->result(400, ['error' => 'La doble confirmacion requiere 2 equipos asignados']);
            }

            $tournamentFormat = (string)($match['tournament_format'] ?? 'single_elim');
            if (!(int)$match['winner_team_id'] && $tournamentFormat !== 'league') {
                $this->db->rollBack();
                return $this->result(400, ['error' => 'Primero debes reportar un resultado con ganador']);
            }

            $flags = $this->getMatchParticipantFlags($match, $userId);
            if (!$flags['is_team_a_captain'] && !$flags['is_team_b_captain']) {
                $this->db->rollBack();
                return $this->result(403, ['error' => 'Solo capitan A o capitan B pueden confirmar']);
            }

            $captainAConfirmed = (int)$match['captain_a_confirmed'];
            $captainBConfirmed = (int)$match['captain_b_confirmed'];
            if ($flags['is_team_a_captain']) $captainAConfirmed = 1;
            if ($flags['is_team_b_captain']) $captainBConfirmed = 1;

            $status = ($captainAConfirmed === 1 && $captainBConfirmed === 1) ? 'finalized' : 'in_progress';
            $finalizedAt = ($status === 'finalized') ? date('Y-m-d H:i:s') : null;

            $this->repo->updateMatchConfirmations($matchId, $captainAConfirmed, $captainBConfirmed, $status, $finalizedAt);

            $this->repo->logMatchEvent($matchId, 'captain_confirmed', $userId, [
                'team_side' => $flags['is_team_a_captain'] ? 'A' : 'B',
                'captain_a_confirmed' => $captainAConfirmed,
                'captain_b_confirmed' => $captainBConfirmed,
            ]);

            if ($status === 'finalized') {
                $this->repo->logMatchEvent($matchId, 'finalized', $userId, ['winner_team_id' => (int)$match['winner_team_id']]);
                if ((int)$match['winner_team_id']) {
                    $this->propagateWinnerToChildren($matchId);
                }
            }

            $this->db->commit();
            return $this->result(200, [
                'message' => ($status === 'finalized')
                    ? 'Resultado confirmado por ambos capitanes. Partido finalizado.'
                    : 'Confirmacion registrada. Falta la del otro capitan.',
                'captain_a_confirmed' => $captainAConfirmed === 1,
                'captain_b_confirmed' => $captainBConfirmed === 1,
                'status' => $status,
            ]);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log('confirmMatchResult error: ' . $e->getMessage());
            return $this->result(500, ['error' => 'No se pudo confirmar el resultado']);
        }
    }

    public function openDispute(array $user, int $matchId, array $data): array
    {
        $userId = (int)($user['id'] ?? 0);
        $reason = trim((string)($data['reason'] ?? ''));

        if ($userId <= 0) return $this->result(401, ['error' => 'No autorizado']);
        if ($matchId <= 0) return $this->result(400, ['error' => 'ID de partido no valido']);
        if ($reason === '' || mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            return $this->result(400, ['error' => 'El motivo debe tener entre 10 y 500 caracteres']);
        }

        try {
            $this->db->beginTransaction();
            $match = $this->repo->findMatchWithTournament($matchId, true);
            if (!$match) { $this->db->rollBack(); return $this->result(404, ['error' => 'Partido no encontrado']); }

            $tournament = $this->extractTournamentFromMatch($match);
            $flags = $this->getMatchParticipantFlags($match, $userId);
            $isManager = $this->access->isAdminOrOwner($user, $tournament);
            if (!$isManager && !$flags['is_team_a_officer'] && !$flags['is_team_b_officer']) {
                $this->db->rollBack();
                return $this->result(403, ['error' => 'No tienes permisos para abrir disputa']);
            }

            $disputeId = $this->repo->insertDispute($matchId, $userId, $reason);
            $this->repo->logMatchEvent($matchId, 'dispute_opened', $userId, [
                'dispute_id' => $disputeId,
                'reason' => $reason,
            ]);

            $this->db->commit();
            return $this->result(201, ['message' => 'Disputa enviada correctamente', 'dispute_id' => $disputeId]);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log('openDispute error: ' . $e->getMessage());
            return $this->result(500, ['error' => 'No se pudo registrar la disputa']);
        }
    }

    public function updateDispute(array $user, int $matchId, int $disputeId, array $data): array
    {
        $userId = (int)($user['id'] ?? 0);
        $status = trim((string)($data['status'] ?? ''));
        $resolutionNote = trim((string)($data['resolution_note'] ?? ''));

        if ($userId <= 0) return $this->result(401, ['error' => 'No autorizado']);
        if ($matchId <= 0 || $disputeId <= 0) return $this->result(400, ['error' => 'Parametros no validos']);
        if (!in_array($status, ['open', 'reviewing', 'resolved', 'rejected'], true)) {
            return $this->result(400, ['error' => 'Estado de disputa no valido']);
        }
        if (mb_strlen($resolutionNote) > 500) {
            return $this->result(400, ['error' => 'resolution_note no puede superar 500 caracteres']);
        }

        try {
            $this->db->beginTransaction();
            $match = $this->repo->findMatchWithTournament($matchId, true);
            if (!$match) { $this->db->rollBack(); return $this->result(404, ['error' => 'Partido no encontrado']); }

            $tournament = $this->extractTournamentFromMatch($match);
            if (!$this->access->isAdminOrOwner($user, $tournament)) {
                $this->db->rollBack();
                return $this->result(403, ['error' => 'Solo el creador o admin puede gestionar disputas']);
            }

            $existing = $this->repo->findDisputeForUpdate($disputeId, $matchId);
            if (!$existing) { $this->db->rollBack(); return $this->result(404, ['error' => 'Disputa no encontrada']); }

            $resolvedBy = in_array($status, ['resolved', 'rejected'], true) ? $userId : null;
            $resolvedAt = in_array($status, ['resolved', 'rejected'], true) ? date('Y-m-d H:i:s') : null;

            $this->repo->updateDispute(
                $disputeId, $status,
                ($resolutionNote !== '' ? $resolutionNote : null),
                $resolvedBy, $resolvedAt
            );

            $this->repo->logMatchEvent($matchId, 'dispute_updated', $userId, [
                'dispute_id' => $disputeId,
                'from' => (string)$existing['status'],
                'to' => $status,
                'resolution_note' => $resolutionNote,
            ]);

            $this->db->commit();
            return $this->result(200, ['message' => 'Disputa actualizada correctamente']);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log('updateDispute error: ' . $e->getMessage());
            return $this->result(500, ['error' => 'No se pudo actualizar la disputa']);
        }
    }

    private function extractTournamentFromMatch(array $match): array
    {
        return [
            'id' => (int)$match['tournament_id'],
            'name' => (string)$match['tournament_name'],
            'visibility' => (string)$match['tournament_visibility'],
            'created_by' => (int)$match['tournament_created_by'],
            'max_teams' => (int)$match['tournament_max_teams'],
            'access_code_hash' => (string)($match['tournament_access_code_hash'] ?? ''),
        ];
    }

    private function getMatchParticipantFlags(array $match, int $userId): array
    {
        $teamAId = $match['team_a_id'] ? (int)$match['team_a_id'] : null;
        $teamBId = $match['team_b_id'] ? (int)$match['team_b_id'] : null;

        return [
            'is_team_a_captain' => $teamAId ? $this->hasTeamRole($teamAId, $userId, ['captain']) : false,
            'is_team_b_captain' => $teamBId ? $this->hasTeamRole($teamBId, $userId, ['captain']) : false,
            'is_team_a_officer' => $teamAId ? $this->hasTeamRole($teamAId, $userId, ['captain', 'co_captain']) : false,
            'is_team_b_officer' => $teamBId ? $this->hasTeamRole($teamBId, $userId, ['captain', 'co_captain']) : false,
        ];
    }

    private function hasTeamRole(int $teamId, int $userId, array $roles): bool
    {
        $valid = ['captain', 'co_captain', 'player'];
        $allowed = array_values(array_filter($roles, fn($r) => in_array($r, $valid, true)));
        if (empty($allowed)) return false;

        if ($this->repo->hasTeamRoleNew($teamId, $userId, $allowed)) return true;

        if (!in_array('captain', $allowed, true)) return false;
        return $this->repo->teamCaptainIs($teamId, $userId);
    }

    private function buildPhaseTimeline(int $tournamentId): array
    {
        $rows = $this->repo->fetchPhaseTimelineRaw($tournamentId);
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

    private function mapMatchTimeline(array $rows): array
    {
        $timeline = [];
        foreach ($rows as $row) {
            $payload = null;
            if (!empty($row['payload_json'])) {
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

    private function mapMatchDisputes(array $rows): array
    {
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

    private function nextPowerOfTwo(int $value): int
    {
        $power = 1;
        while ($power < $value) $power <<= 1;
        return $power;
    }

    private function phaseLabel(int $round, int $totalRounds): string
    {
        if ($totalRounds <= 1 || $round === $totalRounds) return 'Final';
        if ($round === $totalRounds - 1) return 'Semifinal';
        if ($round === $totalRounds - 2) return 'Cuartos';
        if ($round === $totalRounds - 3) return 'Octavos';
        return 'Ronda ' . $round;
    }

    private function buildLeagueStandings(int $tournamentId): array
    {
        $teams = $this->repo->fetchLeagueTeams($tournamentId);
        if (empty($teams)) return [];

        $table = [];
        foreach ($teams as $team) {
            $teamId = (int)$team['id'];
            $table[$teamId] = [
                'team_id' => $teamId,
                'team_name' => (string)$team['name'],
                'played' => 0, 'won' => 0, 'drawn' => 0, 'lost' => 0,
                'goals_for' => 0, 'goals_against' => 0,
                'goal_diff' => 0, 'points' => 0,
            ];
        }

        $h2hPoints = [];
        $matches = $this->repo->fetchFinalizedMatchesForLeague($tournamentId);

        foreach ($matches as $match) {
            $aId = (int)$match['team_a_id'];
            $bId = (int)$match['team_b_id'];
            if (!isset($table[$aId]) || !isset($table[$bId])) continue;
            $sa = (int)$match['score_a']; $sb = (int)$match['score_b'];

            $table[$aId]['played']++; $table[$bId]['played']++;
            $table[$aId]['goals_for'] += $sa; $table[$aId]['goals_against'] += $sb;
            $table[$bId]['goals_for'] += $sb; $table[$bId]['goals_against'] += $sa;

            $h2hPoints[$aId] ??= [];
            $h2hPoints[$bId] ??= [];
            $h2hPoints[$aId][$bId] = (int)($h2hPoints[$aId][$bId] ?? 0);
            $h2hPoints[$bId][$aId] = (int)($h2hPoints[$bId][$aId] ?? 0);

            if ($sa > $sb) {
                $table[$aId]['won']++; $table[$bId]['lost']++;
                $table[$aId]['points'] += 3;
                $h2hPoints[$aId][$bId] += 3;
            } elseif ($sa < $sb) {
                $table[$bId]['won']++; $table[$aId]['lost']++;
                $table[$bId]['points'] += 3;
                $h2hPoints[$bId][$aId] += 3;
            } else {
                $table[$aId]['drawn']++; $table[$bId]['drawn']++;
                $table[$aId]['points'] += 1; $table[$bId]['points'] += 1;
                $h2hPoints[$aId][$bId] += 1;
                $h2hPoints[$bId][$aId] += 1;
            }
        }

        foreach ($table as &$row) {
            $row['goal_diff'] = (int)$row['goals_for'] - (int)$row['goals_against'];
        }
        unset($row);

        $rows = array_values($table);
        usort($rows, function (array $a, array $b) use ($h2hPoints): int {
            if ($a['points'] !== $b['points']) return $b['points'] <=> $a['points'];
            if ($a['goal_diff'] !== $b['goal_diff']) return $b['goal_diff'] <=> $a['goal_diff'];
            if ($a['goals_for'] !== $b['goals_for']) return $b['goals_for'] <=> $a['goals_for'];
            $aVsB = (int)($h2hPoints[$a['team_id']][$b['team_id']] ?? 0);
            $bVsA = (int)($h2hPoints[$b['team_id']][$a['team_id']] ?? 0);
            if ($aVsB !== $bVsA) return $bVsA <=> $aVsB;
            $nameCmp = strcasecmp((string)$a['team_name'], (string)$b['team_name']);
            if ($nameCmp !== 0) return $nameCmp;
            return $a['team_id'] <=> $b['team_id'];
        });

        $position = 1;
        foreach ($rows as &$row) $row['position'] = $position++;
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

    private function propagateWinnerToChildren(int $matchId): void
    {
        $row = $this->repo->findMatchForPropagation($matchId);
        if (!$row || !(int)$row['winner_team_id']) return;
        $winnerTeamId = (int)$row['winner_team_id'];

        foreach ($this->repo->fetchChildMatches($matchId) as $child) {
            $childId = (int)$child['id'];
            $teamAId = $child['team_a_id'] !== null ? (int)$child['team_a_id'] : null;
            $teamBId = $child['team_b_id'] !== null ? (int)$child['team_b_id'] : null;

            if ((int)$child['source_match_a_id'] === $matchId) $teamAId = $winnerTeamId;
            if ((int)$child['source_match_b_id'] === $matchId) $teamBId = $winnerTeamId;

            $this->repo->updateChildMatchTeams($childId, $teamAId, $teamBId);
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

    private function result(int $status, array $payload): array
    {
        return ['status' => $status, 'payload' => $payload];
    }
}