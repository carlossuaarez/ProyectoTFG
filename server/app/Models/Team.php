<?php

class Team
{
    public int $id;
    public int $tournamentId;
    public string $name;
    public ?string $logoUrl;
    public string $colorHex;
    public int $capacity;
    public string $teamStatus;
    public int $captainId;
    public ?string $registeredAt;

    public static function fromRow(array $row): self
    {
        $t = new self();
        $t->id = (int)($row['id'] ?? 0);
        $t->tournamentId = (int)($row['tournament_id'] ?? 0);
        $t->name = (string)($row['name'] ?? '');
        $t->logoUrl = isset($row['logo_url']) && $row['logo_url'] !== null
            ? (string)$row['logo_url'] : null;
        $t->colorHex = (string)($row['color_hex'] ?? '#0EA5E9');
        $t->capacity = (int)($row['capacity'] ?? 5);
        $t->teamStatus = (string)($row['team_status'] ?? 'incomplete');
        $t->captainId = (int)($row['captain_id'] ?? 0);
        $t->registeredAt = isset($row['registered_at']) ? (string)$row['registered_at'] : null;
        return $t;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tournament_id' => $this->tournamentId,
            'name' => $this->name,
            'logo_url' => $this->logoUrl,
            'color_hex' => $this->colorHex,
            'capacity' => $this->capacity,
            'team_status' => $this->teamStatus,
            'captain_id' => $this->captainId,
            'registered_at' => $this->registeredAt,
        ];
    }
}