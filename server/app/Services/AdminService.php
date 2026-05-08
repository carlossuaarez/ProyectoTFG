<?php

require_once __DIR__ . '/../Repositories/AdminRepository.php';

class AdminService
{
    private AdminRepository $repo;

    public function __construct(PDO $db)
    {
        $this->repo = new AdminRepository($db);
    }

    public function getAllTournaments(array $user): array
    {
        if (($user['role'] ?? '') !== 'admin') {
            return $this->result(403, ['error' => 'No autorizado']);
        }

        try {
            return $this->result(200, $this->repo->fetchAllTournamentsForAdmin());
        } catch (Throwable $e) {
            error_log('admin getAllTournaments error: ' . $e->getMessage());
            return $this->result(500, ['error' => 'No se pudo cargar el panel de administración']);
        }
    }

    public function deleteTournament(array $user, int $tournamentId): array
    {
        if (($user['role'] ?? '') !== 'admin') {
            return $this->result(403, ['error' => 'No autorizado']);
        }
        if ($tournamentId <= 0) {
            return $this->result(400, ['error' => 'ID de torneo no válido']);
        }

        try {
            $deleted = $this->repo->deleteTournament($tournamentId);
            if ($deleted === 0) {
                return $this->result(404, ['error' => 'Torneo no encontrado']);
            }
            return $this->result(200, ['message' => 'Torneo eliminado']);
        } catch (Throwable $e) {
            error_log('admin deleteTournament error: ' . $e->getMessage());
            return $this->result(500, ['error' => 'No se pudo eliminar el torneo']);
        }
    }

    private function result(int $status, array $payload): array
    {
        return ['status' => $status, 'payload' => $payload];
    }
}