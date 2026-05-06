<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class NotificationController
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function bootstrapTables(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS user_notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                type VARCHAR(40) NOT NULL,
                title VARCHAR(140) NOT NULL,
                body VARCHAR(500) NOT NULL,
                link_url VARCHAR(255) NULL,
                meta_json LONGTEXT NULL,
                is_read TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                read_at DATETIME NULL,
                INDEX idx_user_created (user_id, created_at),
                INDEX idx_user_read (user_id, is_read),
                CONSTRAINT fk_user_notifications_user
                    FOREIGN KEY (user_id) REFERENCES users(id)
                    ON DELETE CASCADE
            )
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS notification_delivery_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                unique_key_hash CHAR(64) NOT NULL UNIQUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }

    public function listMine(Request $req, Response $res): Response
    {
        $user = (array)$req->getAttribute('user');
        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0) {
            return $this->json($res, ['error' => 'No autorizado'], 401);
        }

        $limit = (int)($req->getQueryParams()['limit'] ?? 30);
        if ($limit < 1) $limit = 1;
        if ($limit > 100) $limit = 100;

        try {
            $stmt = $this->db->prepare("
                SELECT id, type, title, body, link_url, meta_json, is_read, created_at, read_at
                FROM user_notifications
                WHERE user_id = ?
                ORDER BY created_at DESC, id DESC
                LIMIT $limit
            ");
            $stmt->execute([$userId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $notifications = array_map(function (array $row): array {
                $meta = null;
                if (!empty($row['meta_json'])) {
                    $decoded = json_decode((string)$row['meta_json'], true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $meta = $decoded;
                    }
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

            $stmtUnread = $this->db->prepare("
                SELECT COUNT(*) FROM user_notifications WHERE user_id = ? AND is_read = 0
            ");
            $stmtUnread->execute([$userId]);
            $unread = (int)$stmtUnread->fetchColumn();

            return $this->json($res, [
                'unread_count' => $unread,
                'items' => $notifications,
            ]);
        } catch (Throwable $e) {
            error_log('notifications listMine error: ' . $e->getMessage());
            return $this->json($res, ['error' => 'No se pudieron cargar las notificaciones'], 500);
        }
    }

    public function markRead(Request $req, Response $res, array $args): Response
    {
        $user = (array)$req->getAttribute('user');
        $userId = (int)($user['id'] ?? 0);
        $id = (int)($args['id'] ?? 0);
        if ($userId <= 0) {
            return $this->json($res, ['error' => 'No autorizado'], 401);
        }
        if ($id <= 0) {
            return $this->json($res, ['error' => 'ID no válido'], 400);
        }

        try {
            $stmt = $this->db->prepare("
                UPDATE user_notifications
                SET is_read = 1, read_at = NOW()
                WHERE id = ? AND user_id = ?
            ");
            $stmt->execute([$id, $userId]);
            if ($stmt->rowCount() === 0) {
                return $this->json($res, ['error' => 'Notificación no encontrada'], 404);
            }
            return $this->json($res, ['message' => 'Notificación marcada como leída']);
        } catch (Throwable $e) {
            error_log('notifications markRead error: ' . $e->getMessage());
            return $this->json($res, ['error' => 'No se pudo actualizar la notificación'], 500);
        }
    }

    public function markAllRead(Request $req, Response $res): Response
    {
        $user = (array)$req->getAttribute('user');
        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0) {
            return $this->json($res, ['error' => 'No autorizado'], 401);
        }
        try {
            $stmt = $this->db->prepare("
                UPDATE user_notifications
                SET is_read = 1, read_at = NOW()
                WHERE user_id = ? AND is_read = 0
            ");
            $stmt->execute([$userId]);
            return $this->json($res, ['message' => 'Todas las notificaciones marcadas como leídas']);
        } catch (Throwable $e) {
            error_log('notifications markAllRead error: ' . $e->getMessage());
            return $this->json($res, ['error' => 'No se pudieron actualizar las notificaciones'], 500);
        }
    }

    public function runScheduler(Request $req, Response $res): Response
    {
        $headerToken = trim((string)$req->getHeaderLine('X-Internal-Scheduler-Token'));
        $expected = (string)($_ENV['INTERNAL_SCHEDULER_TOKEN'] ?? '');
        if ($expected !== '' && !hash_equals($expected, $headerToken)) {
            return $this->json($res, ['error' => 'No autorizado'], 403);
        }

        try {
            $created = 0;
            $created += $this->createMatchReminderNotifications();
            $created += $this->createScheduleChangeNotifications();
            $created += $this->createResultChangeNotifications();
            $created += $this->createSlotFreedNotifications();
            return $this->json($res, [
                'message' => 'Scheduler de notificaciones ejecutado',
                'created' => $created,
            ]);
        } catch (Throwable $e) {
            error_log('notifications runScheduler error: ' . $e->getMessage());
            return $this->json($res, ['error' => 'No se pudo ejecutar el scheduler'], 500);
        }
    }

    public function notifyMatchChangedByApi(int $matchId, string $kind): void
    {
        try {
            $stmt = $this->db->prepare("
                SELECT
                    m.id,
                    m.tournament_id,
                    m.scheduled_at,
                    m.score_a,
                    m.score_b,
                    m.status,
                    ta.name AS team_a_name,
                    tb.name AS team_b_name,
                    t.name AS tournament_name
                FROM tournament_matches m
                INNER JOIN tournaments t ON t.id = m.tournament_id
                LEFT JOIN teams ta ON ta.id = m.team_a_id
                LEFT JOIN teams tb ON tb.id = m.team_b_id
                WHERE m.id = ?
                LIMIT 1
            ");
            $stmt->execute([$matchId]);
            $match = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$match) return;

            $recipientIds = $this->getMatchParticipantUserIds($matchId);
            if (empty($recipientIds)) return;

            $tournamentName = (string)$match['tournament_name'];
            $a = (string)($match['team_a_name'] ?: 'Equipo A');
            $b = (string)($match['team_b_name'] ?: 'Equipo B');
            $link = '/matches/' . (int)$matchId;

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
                $key = hash('sha256', "match-change:$kind:$uid:$matchId:" . (string)$match['status'] . ':' . (string)$match['score_a'] . ':' . (string)$match['score_b'] . ':' . (string)$match['scheduled_at']);
                if ($this->alreadySent($key)) continue;
                $this->insertNotification((int)$uid, $type, $title, $body, $link, [
                    'match_id' => (int)$matchId,
                    'kind' => $kind,
                ]);
                $this->markSent($key);
            }
        } catch (Throwable $e) {
            error_log('notifications notifyMatchChangedByApi error: ' . $e->getMessage());
        }
    }

    public function notifySlotFreedByApi(int $tournamentId): void
    {
        try {
            $stmt = $this->db->prepare("
                SELECT id, name FROM tournaments WHERE id = ? LIMIT 1
            ");
            $stmt->execute([$tournamentId]);
            $tournament = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$tournament) return;

            $stmtWait = $this->db->prepare("
                SELECT user_id
                FROM waitlist_entries
                WHERE tournament_id = ? AND status = 'pending'
                ORDER BY created_at ASC, id ASC
                LIMIT 50
            ");
            $stmtWait->execute([$tournamentId]);
            $rows = $stmtWait->fetchAll(PDO::FETCH_ASSOC);
            if (empty($rows)) return;

            foreach ($rows as $row) {
                $uid = (int)$row['user_id'];
                if ($uid <= 0) continue;
                $key = hash('sha256', 'slot-freed:' . $tournamentId . ':' . $uid . ':' . date('Y-m-d-H'));
                if ($this->alreadySent($key)) continue;
                $this->insertNotification(
                    $uid,
                    'slot_freed',
                    'Se liberó una plaza',
                    'Hay una plaza disponible en un torneo donde estabas en lista de espera.',
                    '/tournaments/' . (int)$tournamentId,
                    ['tournament_id' => (int)$tournamentId]
                );
                $this->markSent($key);
            }
        } catch (Throwable $e) {
            error_log('notifications notifySlotFreedByApi error: ' . $e->getMessage());
        }
    }

    private function createMatchReminderNotifications(): int
    {
        $created = 0;
        $stmt = $this->db->query("
            SELECT
                m.id,
                m.scheduled_at,
                ta.name AS team_a_name,
                tb.name AS team_b_name,
                t.name AS tournament_name
            FROM tournament_matches m
            INNER JOIN tournaments t ON t.id = m.tournament_id
            LEFT JOIN teams ta ON ta.id = m.team_a_id
            LEFT JOIN teams tb ON tb.id = m.team_b_id
            WHERE m.scheduled_at IS NOT NULL
              AND m.status IN ('pending', 'in_progress')
              AND m.team_a_id IS NOT NULL
              AND m.team_b_id IS NOT NULL
              AND m.scheduled_at BETWEEN DATE_SUB(NOW(), INTERVAL 2 HOUR) AND DATE_ADD(NOW(), INTERVAL 30 HOUR)
        ");
        $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($matches as $match) {
            $matchId = (int)$match['id'];
            $scheduledAt = strtotime((string)$match['scheduled_at']);
            if (!$scheduledAt) continue;
            $secondsLeft = $scheduledAt - time();
            $tag = null;
            if ($secondsLeft > 23 * 3600 && $secondsLeft <= 25 * 3600) {
                $tag = '24h';
            } elseif ($secondsLeft > 50 * 60 && $secondsLeft <= 70 * 60) {
                $tag = '1h';
            } else {
                continue;
            }

            $recipientIds = $this->getMatchParticipantUserIds($matchId);
            if (empty($recipientIds)) continue;
            $a = (string)($match['team_a_name'] ?: 'Equipo A');
            $b = (string)($match['team_b_name'] ?: 'Equipo B');
            $tName = (string)$match['tournament_name'];
            $title = $tag === '24h' ? 'Recordatorio de partido (24h)' : 'Recordatorio de partido (1h)';
            $body = "Tienes {$a} vs {$b} en {$tName}.";

            foreach ($recipientIds as $uid) {
                $key = hash('sha256', "reminder:$tag:$uid:$matchId");
                if ($this->alreadySent($key)) {
                    continue;
                }
                $this->insertNotification(
                    (int)$uid,
                    'match_reminder_' . $tag,
                    $title,
                    $body,
                    '/matches/' . $matchId,
                    ['match_id' => $matchId, 'window' => $tag]
                );
                $this->markSent($key);
                $created++;
            }
        }

        return $created;
    }

    private function createScheduleChangeNotifications(): int
    {
        $created = 0;
        $stmt = $this->db->query("
            SELECT
                m.id,
                m.scheduled_at,
                m.updated_at,
                ta.name AS team_a_name,
                tb.name AS team_b_name,
                t.name AS tournament_name
            FROM tournament_matches m
            INNER JOIN tournaments t ON t.id = m.tournament_id
            LEFT JOIN teams ta ON ta.id = m.team_a_id
            LEFT JOIN teams tb ON tb.id = m.team_b_id
            WHERE m.scheduled_at IS NOT NULL
              AND m.team_a_id IS NOT NULL
              AND m.team_b_id IS NOT NULL
              AND m.status IN ('pending', 'in_progress')
              AND m.updated_at >= DATE_SUB(NOW(), INTERVAL 72 HOUR)
        ");
        $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($matches as $match) {
            $matchId = (int)$match['id'];
            $scheduledAt = (string)$match['scheduled_at'];
            $recipientIds = $this->getMatchParticipantUserIds($matchId);
            if (empty($recipientIds)) {
                continue;
            }

            $a = (string)($match['team_a_name'] ?: 'Equipo A');
            $b = (string)($match['team_b_name'] ?: 'Equipo B');
            $tName = (string)$match['tournament_name'];

            foreach ($recipientIds as $uid) {
                $key = hash('sha256', "schedule:$uid:$matchId:$scheduledAt");
                if ($this->alreadySent($key)) {
                    continue;
                }
                $this->insertNotification(
                    (int)$uid,
                    'schedule_changed',
                    'Cambio de horario',
                    "El horario de {$a} vs {$b} en {$tName} ha cambiado.",
                    '/matches/' . $matchId,
                    ['match_id' => $matchId, 'scheduled_at' => $scheduledAt]
                );
                $this->markSent($key);
                $created++;
            }
        }

        return $created;
    }

    private function createResultChangeNotifications(): int
    {
        $created = 0;
        $stmt = $this->db->query("
            SELECT
                e.id AS event_id,
                e.match_id,
                e.event_type,
                e.created_at,
                m.score_a,
                m.score_b,
                m.status,
                ta.name AS team_a_name,
                tb.name AS team_b_name,
                t.name AS tournament_name
            FROM tournament_match_events e
            INNER JOIN tournament_matches m ON m.id = e.match_id
            INNER JOIN tournaments t ON t.id = m.tournament_id
            LEFT JOIN teams ta ON ta.id = m.team_a_id
            LEFT JOIN teams tb ON tb.id = m.team_b_id
            WHERE e.event_type IN ('score_submitted', 'score_overridden', 'finalized')
              AND e.created_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
        ");
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($events as $event) {
            $matchId = (int)$event['match_id'];
            $eventId = (int)$event['event_id'];
            $recipientIds = $this->getMatchParticipantUserIds($matchId);
            if (empty($recipientIds)) {
                continue;
            }

            $a = (string)($event['team_a_name'] ?: 'Equipo A');
            $b = (string)($event['team_b_name'] ?: 'Equipo B');
            $tName = (string)$event['tournament_name'];
            $scoreA = (int)($event['score_a'] ?? 0);
            $scoreB = (int)($event['score_b'] ?? 0);
            $status = (string)($event['status'] ?? 'pending');

            foreach ($recipientIds as $uid) {
                $key = hash('sha256', "result-event:$uid:$eventId");
                if ($this->alreadySent($key)) {
                    continue;
                }
                $this->insertNotification(
                    (int)$uid,
                    'result_changed',
                    'Resultado actualizado',
                    "Nuevo resultado de {$a} vs {$b} en {$tName}: {$scoreA}-{$scoreB} ({$status}).",
                    '/matches/' . $matchId,
                    ['match_id' => $matchId, 'event_id' => $eventId]
                );
                $this->markSent($key);
                $created++;
            }
        }

        return $created;
    }

    private function createSlotFreedNotifications(): int
    {
        $created = 0;
        $stmt = $this->db->query("
            SELECT
                t.id,
                t.name,
                t.max_teams,
                COALESCE(tc.teams_count, 0) AS teams_count
            FROM tournaments t
            LEFT JOIN (
                SELECT tournament_id, COUNT(*) AS teams_count
                FROM teams
                GROUP BY tournament_id
            ) tc ON tc.tournament_id = t.id
            WHERE COALESCE(tc.teams_count, 0) < t.max_teams
        ");
        $tournaments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($tournaments as $t) {
            $tournamentId = (int)$t['id'];
            $stmtWait = $this->db->prepare("
                SELECT user_id
                FROM waitlist_entries
                WHERE tournament_id = ?
                  AND status = 'pending'
                ORDER BY created_at ASC, id ASC
                LIMIT 50
            ");
            $stmtWait->execute([$tournamentId]);
            $rows = $stmtWait->fetchAll(PDO::FETCH_ASSOC);
            if (empty($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                $uid = (int)($row['user_id'] ?? 0);
                if ($uid <= 0) {
                    continue;
                }
                $availableSlots = max(0, (int)$t['max_teams'] - (int)$t['teams_count']);
                $key = hash('sha256', "slot-freed:$tournamentId:$uid:$availableSlots");
                if ($this->alreadySent($key)) {
                    continue;
                }
                $this->insertNotification(
                    $uid,
                    'slot_freed',
                    'Se liberó una plaza',
                    'Hay una plaza libre en un torneo donde estabas en lista de espera.',
                    '/tournaments/' . $tournamentId,
                    ['tournament_id' => $tournamentId]
                );
                $this->markSent($key);
                $created++;
            }
        }

        return $created;
    }

    private function getMatchParticipantUserIds(int $matchId): array
    {
        $stmt = $this->db->prepare("
            SELECT DISTINCT tm.user_id
            FROM tournament_matches m
            INNER JOIN team_members tm ON tm.team_id IN (m.team_a_id, m.team_b_id)
            WHERE m.id = ? AND tm.pending_validation = 0
        ");
        $stmt->execute([$matchId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $ids = [];
        foreach ($rows as $row) {
            $id = (int)($row['user_id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return array_values(array_unique($ids));
    }

    private function insertNotification(int $userId, string $type, string $title, string $body, string $linkUrl = '', array $meta = []): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO user_notifications (user_id, type, title, body, link_url, meta_json, is_read)
            VALUES (?, ?, ?, ?, ?, ?, 0)
        ");
        $stmt->execute([
            $userId,
            $type,
            mb_substr($title, 0, 140),
            mb_substr($body, 0, 500),
            ($linkUrl !== '' ? mb_substr($linkUrl, 0, 255) : null),
            !empty($meta) ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    private function alreadySent(string $key): bool
    {
        $stmt = $this->db->prepare("
            SELECT id FROM notification_delivery_log WHERE unique_key_hash = ? LIMIT 1
        ");
        $stmt->execute([$key]);
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function markSent(string $key): void
    {
        $stmt = $this->db->prepare("
            INSERT IGNORE INTO notification_delivery_log (unique_key_hash) VALUES (?)
        ");
        $stmt->execute([$key]);
    }

    private function json(Response $res, array $payload, int $status = 200): Response
    {
        $res->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $res->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}