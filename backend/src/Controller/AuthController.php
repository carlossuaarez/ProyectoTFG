<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Firebase\JWT\JWT;

class AuthController {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function register(Request $req, Response $res) {
        $data = $req->getParsedBody();
        $username = $data['username'] ?? '';
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';

        if (!$username || !$email || !$password) {
            $res->getBody()->write(json_encode(['error' => 'Faltan campos']));
            return $res->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $this->db->prepare("INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)");
        try {
            $stmt->execute([$username, $email, $hash]);
            $res->getBody()->write(json_encode(['message' => 'Usuario registrado']));
            return $res->withStatus(201)->withHeader('Content-Type', 'application/json');
        } catch (PDOException $e) {
            $res->getBody()->write(json_encode(['error' => 'Usuario o email ya existe']));
            return $res->withStatus(409)->withHeader('Content-Type', 'application/json');
        }
    }

    public function login(Request $req, Response $res) {
        $data = $req->getParsedBody();
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';

        $stmt = $this->db->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $res->getBody()->write(json_encode(['error' => 'Credenciales incorrectas']));
            return $res->withStatus(401)->withHeader('Content-Type', 'application/json');
        }

        $payload = [
            'id' => $user['id'],
            'username' => $user['username'],
            'role' => $user['role'],
            'exp' => time() + 3600
        ];
        $jwt = JWT::encode($payload, $_ENV['JWT_SECRET'], 'HS256');

        $res->getBody()->write(json_encode(['token' => $jwt]));
        return $res->withHeader('Content-Type', 'application/json');
    }
}