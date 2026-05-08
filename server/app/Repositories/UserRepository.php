<?php

require_once __DIR__ . '/../Models/User.php';

class UserRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function usernameExists(string $username, ?int $excludeUserId = null): bool
    {
        if ($excludeUserId !== null) {
            $stmt = $this->db->prepare(
                "SELECT id FROM users WHERE LOWER(username) = LOWER(?) AND id <> ? LIMIT 1"
            );
            $stmt->execute([$username, $excludeUserId]);
        } else {
            $stmt = $this->db->prepare(
                "SELECT id FROM users WHERE LOWER(username) = LOWER(?) LIMIT 1"
            );
            $stmt->execute([$username]);
        }

        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, username, full_name, email, avatar_url, role, created_at, google_id, two_factor_enabled, password_hash
            FROM users
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findByGoogleIdOrEmail(string $googleId, string $email): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE google_id = ? OR email = ? LIMIT 1");
        $stmt->execute([$googleId, $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function insertLocalUser(string $username, string $fullName, string $email, string $passwordHash): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO users (username, full_name, email, password_hash)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$username, $fullName, $email, $passwordHash]);
        return (int)$this->db->lastInsertId();
    }

    public function insertGoogleUser(
        string $username,
        string $fullName,
        string $email,
        ?string $avatarUrl,
        string $passwordHash,
        string $googleId
    ): int {
        $stmt = $this->db->prepare("
            INSERT INTO users (username, full_name, email, avatar_url, password_hash, google_id)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$username, $fullName, $email, $avatarUrl, $passwordHash, $googleId]);
        return (int)$this->db->lastInsertId();
    }

    public function linkGoogleIdAndOptionalName(int $userId, ?string $googleId, ?string $fullName): void
    {
        $updates = [];
        $params = [];

        if ($googleId !== null && $googleId !== '') {
            $updates[] = 'google_id = ?';
            $params[] = $googleId;
        }

        if ($fullName !== null && trim($fullName) !== '') {
            $updates[] = 'full_name = ?';
            $params[] = trim($fullName);
        }

        if (empty($updates)) {
            return;
        }

        $params[] = $userId;
        $sql = 'UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ?';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    public function updateProfile(
        int $userId,
        string $username,
        string $email,
        ?string $fullName,
        ?string $avatarUrl
    ): void {
        $stmt = $this->db->prepare("
            UPDATE users
            SET username = ?, email = ?, full_name = ?, avatar_url = ?
            WHERE id = ?
        ");
        $stmt->execute([$username, $email, $fullName, $avatarUrl, $userId]);
    }

    public function getPdo(): PDO
    {
        return $this->db;
    }
}