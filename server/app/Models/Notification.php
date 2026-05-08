<?php

class Notification
{
    public int $id;
    public int $userId;
    public string $type;
    public string $title;
    public string $body;
    public ?string $linkUrl;
    public ?array $meta;
    public int $isRead;
    public ?string $createdAt;
    public ?string $readAt;

    public static function fromRow(array $row): self
    {
        $n = new self();
        $n->id = (int)($row['id'] ?? 0);
        $n->userId = (int)($row['user_id'] ?? 0);
        $n->type = (string)($row['type'] ?? '');
        $n->title = (string)($row['title'] ?? '');
        $n->body = (string)($row['body'] ?? '');
        $n->linkUrl = isset($row['link_url']) && $row['link_url'] !== null
            ? (string)$row['link_url'] : null;

        $n->meta = null;
        if (!empty($row['meta_json'])) {
            $decoded = json_decode((string)$row['meta_json'], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $n->meta = $decoded;
            }
        }

        $n->isRead = (int)($row['is_read'] ?? 0);
        $n->createdAt = isset($row['created_at']) ? (string)$row['created_at'] : null;
        $n->readAt = isset($row['read_at']) && $row['read_at'] !== null
            ? (string)$row['read_at'] : null;
        return $n;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->userId,
            'type' => $this->type,
            'title' => $this->title,
            'body' => $this->body,
            'link_url' => $this->linkUrl,
            'meta' => $this->meta,
            'is_read' => $this->isRead === 1,
            'created_at' => $this->createdAt,
            'read_at' => $this->readAt,
        ];
    }
}