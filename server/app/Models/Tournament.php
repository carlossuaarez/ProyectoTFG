<?php

class Tournament
{
    public int $id;
    public string $name;
    public ?string $description;
    public string $game;
    public string $type;
    public int $maxTeams;
    public string $format;
    public string $startDate;
    public string $startTime;
    public ?string $prize;
    public ?string $locationName;
    public ?string $locationAddress;
    public ?float $locationLat;
    public ?float $locationLng;
    public int $isOnline;
    public string $visibility;
    public ?string $accessCodeHash;
    public ?string $accessCodeLast4;
    public int $createdBy;
    public ?string $createdByUsername;
    public ?string $createdAt;

    public static function fromRow(array $row): self
    {
        $t = new self();
        $t->id = (int)($row['id'] ?? 0);
        $t->name = (string)($row['name'] ?? '');
        $t->description = array_key_exists('description', $row) ? ($row['description'] !== null ? (string)$row['description'] : null) : null;
        $t->game = (string)($row['game'] ?? '');
        $t->type = (string)($row['type'] ?? 'sports');
        $t->maxTeams = (int)($row['max_teams'] ?? 0);
        $t->format = (string)($row['format'] ?? 'single_elim');
        $t->startDate = (string)($row['start_date'] ?? '');
        $t->startTime = (string)($row['start_time'] ?? '00:00:00');
        $t->prize = array_key_exists('prize', $row) ? ($row['prize'] !== null ? (string)$row['prize'] : null) : null;
        $t->locationName = array_key_exists('location_name', $row) ? ($row['location_name'] !== null ? (string)$row['location_name'] : null) : null;
        $t->locationAddress = array_key_exists('location_address', $row) ? ($row['location_address'] !== null ? (string)$row['location_address'] : null) : null;
        $t->locationLat = array_key_exists('location_lat', $row) && $row['location_lat'] !== null ? (float)$row['location_lat'] : null;
        $t->locationLng = array_key_exists('location_lng', $row) && $row['location_lng'] !== null ? (float)$row['location_lng'] : null;
        $t->isOnline = (int)($row['is_online'] ?? 0);
        $t->visibility = (string)($row['visibility'] ?? 'public');
        $t->accessCodeHash = array_key_exists('access_code_hash', $row) ? ($row['access_code_hash'] !== null ? (string)$row['access_code_hash'] : null) : null;
        $t->accessCodeLast4 = array_key_exists('access_code_last4', $row) ? ($row['access_code_last4'] !== null ? (string)$row['access_code_last4'] : null) : null;
        $t->createdBy = (int)($row['created_by'] ?? 0);
        $t->createdByUsername = array_key_exists('created_by_username', $row) ? ($row['created_by_username'] !== null ? (string)$row['created_by_username'] : null) : null;
        $t->createdAt = array_key_exists('created_at', $row) ? ($row['created_at'] !== null ? (string)$row['created_at'] : null) : null;
        return $t;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'game' => $this->game,
            'type' => $this->type,
            'max_teams' => $this->maxTeams,
            'format' => $this->format,
            'start_date' => $this->startDate,
            'start_time' => $this->startTime,
            'prize' => $this->prize,
            'location_name' => $this->locationName,
            'location_address' => $this->locationAddress,
            'location_lat' => $this->locationLat,
            'location_lng' => $this->locationLng,
            'is_online' => $this->isOnline,
            'visibility' => $this->visibility,
            'access_code_hash' => $this->accessCodeHash,
            'access_code_last4' => $this->accessCodeLast4,
            'created_by' => $this->createdBy,
            'created_by_username' => $this->createdByUsername,
            'created_at' => $this->createdAt,
        ];
    }
}