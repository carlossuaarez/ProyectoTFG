<?php

require_once __DIR__ . '/../Repositories/MyTournamentsRepository.php';

class MyTournamentsService
{
    private MyTournamentsRepository $repo;

    public function __construct(PDO $db)
    {
        $this->repo = new MyTournamentsRepository($db);
    }

    public function getMine(int $userId): array
    {
        if ($userId <= 0) {
            return $this->result(401, ['error' => 'No autorizado']);
        }

        try {
            return $this->result(200, [
                'joined' => $this->repo->fetchJoinedAsCaptain($userId),
                'created' => $this->repo->fetchCreatedByUser($userId),
            ]);
        } catch (Throwable $e) {
            error_log('get my tournaments error: ' . $e->getMessage());
            return $this->result(500, ['error' => 'No se pudieron cargar tus torneos']);
        }
    }

    private function result(int $status, array $payload): array
    {
        return ['status' => $status, 'payload' => $payload];
    }
}