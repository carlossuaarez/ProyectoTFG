<?php

class LoginChallengeRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function invalidateOpenByUserId(int $userId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE login_challenges SET consumed_at = NOW() WHERE user_id = ? AND consumed_at IS NULL"
        );
        $stmt->execute([$userId]);
    }

    public function insert(string $publicId, int $userId, string $otpHash, string $expiresAt): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO login_challenges (public_id, user_id, otp_hash, expires_at)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$publicId, $userId, $otpHash, $expiresAt]);
    }

    public function findChallengeWithUserByPublicId(string $publicId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT
                lc.id AS challenge_row_id,
                lc.public_id,
                lc.user_id,
                lc.otp_hash,
                lc.attempts,
                lc.expires_at,
                lc.consumed_at,
                u.id AS id,
                u.username,
                u.role,
                u.email
            FROM login_challenges lc
            INNER JOIN users u ON u.id = lc.user_id
            WHERE lc.public_id = ?
            LIMIT 1
        ");
        $stmt->execute([$publicId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function incrementAttempts(int $challengeRowId): void
    {
        $stmt = $this->db->prepare("UPDATE login_challenges SET attempts = attempts + 1 WHERE id = ?");
        $stmt->execute([$challengeRowId]);
    }

    public function consumeById(int $challengeRowId): void
    {
        $stmt = $this->db->prepare("UPDATE login_challenges SET consumed_at = NOW() WHERE id = ?");
        $stmt->execute([$challengeRowId]);
    }

    public function deleteByPublicId(string $publicId): void
    {
        $stmt = $this->db->prepare("DELETE FROM login_challenges WHERE public_id = ?");
        $stmt->execute([$publicId]);
    }

    public function getPdo(): PDO
    {
        return $this->db;
    }
}