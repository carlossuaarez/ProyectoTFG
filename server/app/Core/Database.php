<?php

final class Database
{
    private function __construct()
    {
    }

    public static function connect(array $env): PDO
    {
        $required = ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'];
        foreach ($required as $key) {
            if (!isset($env[$key]) || trim((string)$env[$key]) === '') {
                throw new RuntimeException("Falta configuración requerida: {$key}");
            }
        }

        $host = trim((string)$env['DB_HOST']);
        $dbname = trim((string)$env['DB_NAME']);
        $user = trim((string)$env['DB_USER']);
        $pass = (string)$env['DB_PASS'];

        try {
            $pdo = new PDO(
                "mysql:host={$host};dbname={$dbname};charset=utf8mb4",
                $user,
                $pass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );

            return $pdo;
        } catch (PDOException $e) {
            error_log('DB connection error: ' . $e->getMessage());
            throw new RuntimeException('No se pudo conectar a la base de datos.');
        }
    }
}