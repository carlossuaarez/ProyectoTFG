<?php

class User
{
    public int $id;
    public string $username;
    public string $fullName;
    public string $email;
    public string $avatarUrl;
    public string $role;
    public ?string $googleId;
    public int $twoFactorEnabled;
    public ?string $createdAt;

    public static function fromRow(array $row): self
    {
        $u = new self();
        $u->id = (int)($row['id'] ?? 0);
        $u->username = (string)($row['username'] ?? '');
        $u->fullName = (string)($row['full_name'] ?? '');
        $u->email = (string)($row['email'] ?? '');
        $u->avatarUrl = (string)($row['avatar_url'] ?? '');
        $u->role = (string)($row['role'] ?? 'user');
        $u->googleId = isset($row['google_id']) ? (string)$row['google_id'] : null;
        $u->twoFactorEnabled = (int)($row['two_factor_enabled'] ?? 1);
        $u->createdAt = isset($row['created_at']) ? (string)$row['created_at'] : null;
        return $u;
    }

    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'full_name' => $this->fullName,
            'email' => $this->email,
            'avatar_url' => $this->avatarUrl,
            'role' => $this->role,
            'created_at' => $this->createdAt,
        ];
    }
}