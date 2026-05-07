<?php

class RateLimitRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Misma lógica que ya tenías en AuthController::consumeRateLimit,
     * extraída para vaciar el controlador sin cambiar comportamiento.
     */
    public function consume(
        string $bucket,
        string $rawKey,
        int $maxRequests,
        int $windowSeconds,
        int &$retryAfter = 0
    ): bool {
        $retryAfter = 0;
        $key = trim($rawKey) !== '' ? strtolower(trim($rawKey)) : 'anonymous';
        $keyHash = hash('sha256', $key);

        try {
            $this->db->beginTransaction();

            $select = $this->db->prepare(
                "SELECT id, hits, reset_at
                 FROM rate_limits
                 WHERE bucket = ? AND key_hash = ?
                 LIMIT 1
                 FOR UPDATE"
            );
            $select->execute([$bucket, $keyHash]);
            $row = $select->fetch(PDO::FETCH_ASSOC);

            $newResetAt = (new DateTimeImmutable('now +' . $windowSeconds . ' seconds'))
                ->format('Y-m-d H:i:s');

            if (!$row) {
                $insert = $this->db->prepare(
                    "INSERT INTO rate_limits (bucket, key_hash, hits, reset_at)
                     VALUES (?, ?, 1, ?)"
                );
                $insert->execute([$bucket, $keyHash, $newResetAt]);
                $this->db->commit();
                return true;
            }

            $hits = (int)$row['hits'];
            $resetTs = strtotime((string)$row['reset_at']) ?: 0;
            $nowTs = time();

            if ($resetTs <= $nowTs) {
                $update = $this->db->prepare(
                    "UPDATE rate_limits SET hits = 1, reset_at = ? WHERE id = ?"
                );
                $update->execute([$newResetAt, $row['id']]);
                $this->db->commit();
                return true;
            }

            if ($hits >= $maxRequests) {
                $retryAfter = max(1, $resetTs - $nowTs);
                $this->db->rollBack();
                return false;
            }

            $update = $this->db->prepare("UPDATE rate_limits SET hits = hits + 1 WHERE id = ?");
            $update->execute([$row['id']]);

            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('RateLimitRepository consume error: ' . $e->getMessage());
            // fail-open para mantener comportamiento actual
            return true;
        }
    }
}