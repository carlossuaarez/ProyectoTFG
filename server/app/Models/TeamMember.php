<?php

class TeamMember
{
    public int $id;
    public int $teamId;
    public int $userId;
    public string $role;
    public int $pendingValidation;
    public ?string $joinedAt;

    public static function fromRow(array $row): self
    {
        $m = new self();
        $m->id = (int)($row['id'] ?? 0);
        $m->teamId = (int)($row['team_id'] ?? 0);
        $m->userId = (int)($row['user_id'] ?? 0);
        $m->role = (string)($row['role'] ?? 'player');
        $m->pendingValidation = (int)($row['pending_validation'] ?? 0);
        $m->joinedAt = isset($row['joined_at']) ? (string)$row['joined_at'] : null;
        return $m;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'team_id' => $this->teamId,
            'user_id' => $this->userId,
            'role' => $this->role,
            'pending_validation' => $this->pendingValidation,
            'joined_at' => $this->joinedAt,
        ];
    }

    public function isCaptain(): bool
    {
        return $this->role === 'captain' && $this->pendingValidation === 0;
    }

    public function isOfficer(): bool
    {
        return in_array($this->role, ['captain', 'co_captain'], true)
            && $this->pendingValidation === 0;
    }
}