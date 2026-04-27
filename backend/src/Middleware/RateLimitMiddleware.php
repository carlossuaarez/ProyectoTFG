<?php
use Psr\Http\Message\ServerRequestInterface as Request;

class RateLimitMiddleware
{
    private PDO $db;
    private string $bucket;
    private int $maxRequests;
    private int $windowSeconds;
    private $keyResolver;

    public function __construct(PDO $db, string $bucket, int $maxRequests, int $windowSeconds, callable $keyResolver)
    {
        $this->db = $db;
        $this->bucket = $bucket;
        $this->maxRequests = $maxRequests;
        $this->windowSeconds = $windowSeconds;
        $this->keyResolver = $keyResolver;
    }

    public function __invoke(Request $request, $handler)
    {
        $rawKey = trim((string)call_user_func($this->keyResolver, $request));
        if ($rawKey === '') {
            $rawKey = 'anonymous';
        }

        $keyHash = hash('sha256', strtolower($rawKey));
        $allowed = true;
        $retryAfter = 0;
        $remaining = $this->maxRequests - 1;

        try {
            if (random_int(1, 100) === 1) {
                $this->purgeExpired();
            }

            $this->db->beginTransaction();

            $select = $this->db->prepare(
                "SELECT id, hits, reset_at
                 FROM rate_limits
                 WHERE bucket = ? AND key_hash = ?
                 LIMIT 1
                 FOR UPDATE"
            );
            $select->execute([$this->bucket, $keyHash]);
            $row = $select->fetch(PDO::FETCH_ASSOC);

            $newResetAt = (new DateTimeImmutable('now +' . $this->windowSeconds . ' seconds'))
                ->format('Y-m-d H:i:s');

            if (!$row) {
                $insert = $this->db->prepare(
                    "INSERT INTO rate_limits (bucket, key_hash, hits, reset_at)
                     VALUES (?, ?, 1, ?)"
                );
                $insert->execute([$this->bucket, $keyHash, $newResetAt]);
                $remaining = $this->maxRequests - 1;
            } else {
                $hits = (int)$row['hits'];
                $resetTs = strtotime((string)$row['reset_at']) ?: 0;
                $nowTs = time();

                if ($resetTs <= $nowTs) {
                    $update = $this->db->prepare(
                        "UPDATE rate_limits SET hits = 1, reset_at = ? WHERE id = ?"
                    );
                    $update->execute([$newResetAt, $row['id']]);
                    $remaining = $this->maxRequests - 1;
                } elseif ($hits >= $this->maxRequests) {
                    $allowed = false;
                    $retryAfter = max(1, $resetTs - $nowTs);
                    $remaining = 0;
                } else {
                    $update = $this->db->prepare(
                        "UPDATE rate_limits SET hits = hits + 1 WHERE id = ?"
                    );
                    $update->execute([$row['id']]);
                    $remaining = max(0, $this->maxRequests - ($hits + 1));
                }
            }

            if ($allowed) {
                $this->db->commit();
            } else {
                $this->db->rollBack();
            }
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('Rate limiter error: ' . $e->getMessage());
            // Fail-open para no tumbar la app si la tabla falla.
            return $handler->handle($request);
        }

        if (!$allowed) {
            $response = new \Slim\Psr7\Response(429);
            $response->getBody()->write(json_encode([
                'error' => 'Demasiadas peticiones. Inténtalo más tarde.',
                'retry_after_seconds' => $retryAfter
            ]));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Retry-After', (string)$retryAfter)
                ->withHeader('X-RateLimit-Limit', (string)$this->maxRequests)
                ->withHeader('X-RateLimit-Remaining', '0');
        }

        $response = $handler->handle($request);
        return $response
            ->withHeader('X-RateLimit-Limit', (string)$this->maxRequests)
            ->withHeader('X-RateLimit-Remaining', (string)$remaining);
    }

    public static function extractClientIp(Request $request): string
    {
        $xff = $request->getHeaderLine('X-Forwarded-For');
        if ($xff !== '') {
            $parts = explode(',', $xff);
            return trim($parts[0]);
        }

        $server = $request->getServerParams();
        return (string)($server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    private function purgeExpired(): void
    {
        $stmt = $this->db->prepare("DELETE FROM rate_limits WHERE reset_at < NOW()");
        $stmt->execute();
    }
}