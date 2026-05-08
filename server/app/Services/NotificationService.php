<?php

require_once __DIR__ . '/../Repositories/NotificationRepository.php';

class NotificationService
{
    private NotificationRepository $repo;

    public function __construct(PDO $db)
    {
        $this->repo = new NotificationRepository($db);
    }

    public function listMine(int $userId, int $limit): array
    {
        if ($userId <= 0) return $this->result(401, ['error' => 'No autorizado']);

        try {
            $rows = $this->repo->listForUser($userId, $limit);
            $items = array_map(function (array $row): array {
                $meta = null;
                if (!empty($row['meta_json'])) {
                    $decoded = json_decode((string)$row['meta_json'], true);
                    if (json_last_error() === JSON_ERROR_NONE) $meta = $decoded;
                }
                return [
                    'id' => (int)$row['id'],
                    'type' => (string)$row['type'],
                    'title' => (string)$row['title'],
                    'body' => (string)$row['body'],
                    'link_url' => (string)($row['link_url'] ?? ''),
                    'meta' => $meta,
                    'is_read' => (int)$row['is_read'] === 1,
                    'created_at' => $row['created_at'],
                    'read_at' => $row['read_at'],
                ];
            }, $rows);

            return $this->result(200, [
                'unread_count' => $this->repo->countUnread($userId),
                'items' => $items,
            ]);
        } catch (Throwable $e) {
            error_log('notifications listMine error: ' . $e->getMessage());
            return $this->result(500, ['error' => 'No se pudieron cargar las notificaciones']);
        }
    }

    public function markRead(int $userId, int $id): array
    {
        if ($userId <= 0) return $this->result(401, ['error' => 'No autorizado']);
        if ($id <= 0) return $this->result(400, ['error' => 'ID no válido']);

        try {
            $rows = $this->repo->markRead($id, $userId);
            if ($rows === 0) return $this->result(404, ['error' => 'Notificación no encontrada']);
            return $this->result(200, ['message' => 'Notificación marcada como leída']);
        } catch (Throwable $e) {
            error_log('notifications markRead error: ' . $e->getMessage());
            return $this->result(500, ['error' => 'No se pudo actualizar la notificación']);
        }
    }

    public function markAllRead(int $userId): array
    {
        if ($userId <= 0) return $this->result(401, ['error' => 'No autorizado']);
        try {
            $this->repo->markAllReadForUser($userId);
            return $this->result(200, ['message' => 'Todas las notificaciones marcadas como leídas']);
        } catch (Throwable $e) {
            error_log('notifications markAllRead error: ' . $e->getMessage());
            return $this->result(500, ['error' => 'No se pudieron actualizar las notificaciones']);
        }
    }

    public function runScheduler(string $headerToken): array
    {
        $expected = (string)($_ENV['INTERNAL_SCHEDULER_TOKEN'] ?? '');
        if ($expected !== '' && !hash_equals($expected, $headerToken)) {
            return $this->result(403, ['error' => 'No autorizado']);
        }
        try {
            $created = 0;
            $created += $this->createMatchReminderNotifications();
            $created += $this->createScheduleChangeNotifications();
            $created += $this->createResultChangeNotifications();
            $created += $this->createSlotFreedNotifications();
            return $this->result(200, [
                'message' => 'Scheduler de notificaciones ejecutado',
                'created' => $created,
            ]);
        } catch (Throwable $e) {
            error_log('notifications runScheduler error: ' . $e->getMessage());
            return $this->result(500, ['error' => 'No se pudo ejecutar el scheduler']);
        }
    }

    public function notifyMatchChangedByApi(int $matchId, string $kind): void
    {
        try {
            $match = $this->repo->findMatchSummary($matchId);
            if (!$match) return;

            $recipientIds = $this->repo->fetchMatchParticipantUserIds($matchId);
            if (empty($recipientIds)) return;

            $tournamentName = (string)$match['tournament_name'];
            $a = (string)($match['team_a_name'] ?: 'Equipo A');
            $b = (string)($match['team_b_name'] ?: 'Equipo B');
            $link = '/matches/' . $matchId;

            $title = 'Actualización de partido';
            $body = "Se actualizó el partido {$a} vs {$b} en {$tournamentName}.";
            $type = 'match_update';
            if ($kind === 'schedule') {
                $type = 'schedule_changed';
                $title = 'Cambio de horario';
                $body = "El horario del partido {$a} vs {$b} cambió. Revisa los detalles.";
            } elseif ($kind === 'result') {
                $type = 'result_changed';
                $title = 'Resultado actualizado';
                $body = "Se actualizó el resultado de {$a} vs {$b}.";
            }

            foreach ($recipientIds as $uid) {
                $key = hash('sha256', "match-change:$kind:$uid:$matchId:" . (string)$match['status']
                    . ':' . (string)$match['score_a'] . ':' . (string)$match['score_b']
                    . ':' . (string)$match['scheduled_at']);
                if ($this->repo->alreadySent($key)) continue;
                $this->insert($uid, $type, $title, $body, $link, ['match_id' => $matchId, 'kind' => $kind]);
                $this->repo->markSent($key);
            }
        } catch (Throwable $e) {
            error_log('notifications notifyMatchChangedByApi error: ' . $e->getMessage());
        }
    }

    public function notifySlotFreedByApi(int $tournamentId): void
    {
        try {
            if (!$this->repo->findTournamentSummary($tournamentId)) return;
            $rows = $this->repo->fetchPendingWaitlistByTournament($tournamentId, 50);
            foreach ($rows as $row) {
                $uid = (int)$row['user_id'];
                if ($uid <= 0) continue;
                $key = hash('sha256', 'slot-freed:' . $tournamentId . ':' . $uid . ':' . date('Y-m-d-H'));
                if ($this->repo->alreadySent($key)) continue;
                $this->insert(
                    $uid, 'slot_freed', 'Se liberó una plaza',
                    'Hay una plaza disponible en un torneo donde estabas en lista de espera.',
                    '/tournaments/' . $tournamentId,
                    ['tournament_id' => $tournamentId]
                );
                $this->repo->markSent($key);
            }
        } catch (Throwable $e) {
            error_log('notifications notifySlotFreedByApi error: ' . $e->getMessage());
        }
    }

    private function createMatchReminderNotifications(): int
    {
        $created = 0;
        foreach ($this->repo->fetchUpcomingMatches() as $match) {
            $matchId = (int)$match['id'];
            $scheduledAt = strtotime((string)$match['scheduled_at']);
            if (!$scheduledAt) continue;
            $secondsLeft = $scheduledAt - time();
            if ($secondsLeft > 23 * 3600 && $secondsLeft <= 25 * 3600) $tag = '24h';
            elseif ($secondsLeft > 50 * 60 && $secondsLeft <= 70 * 60) $tag = '1h';
            else continue;

            $recipientIds = $this->repo->fetchMatchParticipantUserIds($matchId);
            if (empty($recipientIds)) continue;
            $a = (string)($match['team_a_name'] ?: 'Equipo A');
            $b = (string)($match['team_b_name'] ?: 'Equipo B');
            $tName = (string)$match['tournament_name'];
            $title = $tag === '24h' ? 'Recordatorio de partido (24h)' : 'Recordatorio de partido (1h)';
            $body = "Tienes {$a} vs {$b} en {$tName}.";

            foreach ($recipientIds as $uid) {
                $key = hash('sha256', "reminder:$tag:$uid:$matchId");
                if ($this->repo->alreadySent($key)) continue;
                $this->insert((int)$uid, 'match_reminder_' . $tag, $title, $body,
                    '/matches/' . $matchId, ['match_id' => $matchId, 'window' => $tag]);
                $this->repo->markSent($key);
                $created++;
            }
        }
        return $created;
    }

    private function createScheduleChangeNotifications(): int
    {
        $created = 0;
        foreach ($this->repo->fetchRecentScheduleChanges() as $match) {
            $matchId = (int)$match['id'];
            $scheduledAt = (string)$match['scheduled_at'];
            $recipientIds = $this->repo->fetchMatchParticipantUserIds($matchId);
            if (empty($recipientIds)) continue;
            $a = (string)($match['team_a_name'] ?: 'Equipo A');
            $b = (string)($match['team_b_name'] ?: 'Equipo B');
            $tName = (string)$match['tournament_name'];
            foreach ($recipientIds as $uid) {
                $key = hash('sha256', "schedule:$uid:$matchId:$scheduledAt");
                if ($this->repo->alreadySent($key)) continue;
                $this->insert((int)$uid, 'schedule_changed', 'Cambio de horario',
                    "El horario de {$a} vs {$b} en {$tName} ha cambiado.",
                    '/matches/' . $matchId,
                    ['match_id' => $matchId, 'scheduled_at' => $scheduledAt]);
                $this->repo->markSent($key);
                $created++;
            }
        }
        return $created;
    }

    private function createResultChangeNotifications(): int
    {
        $created = 0;
        foreach ($this->repo->fetchRecentResultEvents() as $event) {
            $matchId = (int)$event['match_id'];
            $eventId = (int)$event['event_id'];
            $recipientIds = $this->repo->fetchMatchParticipantUserIds($matchId);
            if (empty($recipientIds)) continue;
            $a = (string)($event['team_a_name'] ?: 'Equipo A');
            $b = (string)($event['team_b_name'] ?: 'Equipo B');
            $tName = (string)$event['tournament_name'];
            $sa = (int)($event['score_a'] ?? 0);
            $sb = (int)($event['score_b'] ?? 0);
            $status = (string)($event['status'] ?? 'pending');
            foreach ($recipientIds as $uid) {
                $key = hash('sha256', "result-event:$uid:$eventId");
                if ($this->repo->alreadySent($key)) continue;
                $this->insert((int)$uid, 'result_changed', 'Resultado actualizado',
                    "Nuevo resultado de {$a} vs {$b} en {$tName}: {$sa}-{$sb} ({$status}).",
                    '/matches/' . $matchId,
                    ['match_id' => $matchId, 'event_id' => $eventId]);
                $this->repo->markSent($key);
                $created++;
            }
        }
        return $created;
    }

    private function createSlotFreedNotifications(): int
    {
        $created = 0;
        foreach ($this->repo->fetchTournamentsWithFreeSlots() as $t) {
            $tournamentId = (int)$t['id'];
            $rows = $this->repo->fetchPendingWaitlistByTournament($tournamentId, 50);
            if (empty($rows)) continue;
            foreach ($rows as $row) {
                $uid = (int)($row['user_id'] ?? 0);
                if ($uid <= 0) continue;
                $availableSlots = max(0, (int)$t['max_teams'] - (int)$t['teams_count']);
                $key = hash('sha256', "slot-freed:$tournamentId:$uid:$availableSlots");
                if ($this->repo->alreadySent($key)) continue;
                $this->insert($uid, 'slot_freed', 'Se liberó una plaza',
                    'Hay una plaza libre en un torneo donde estabas en lista de espera.',
                    '/tournaments/' . $tournamentId,
                    ['tournament_id' => $tournamentId]);
                $this->repo->markSent($key);
                $created++;
            }
        }
        return $created;
    }

    private function insert(int $userId, string $type, string $title, string $body, string $linkUrl = '', array $meta = []): void
    {
        $this->repo->insertNotification(
            $userId, $type,
            mb_substr($title, 0, 140),
            mb_substr($body, 0, 500),
            ($linkUrl !== '' ? mb_substr($linkUrl, 0, 255) : null),
            !empty($meta) ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null
        );
    }

    public function bootstrapTables(): void
    {
        $this->repo->bootstrapTables();
    }

    private function result(int $status, array $payload): array
    {
        return ['status' => $status, 'payload' => $payload];
    }
}