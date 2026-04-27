<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Firebase\JWT\JWT;
use Google\Client as GoogleClient;

class AuthController {
    private $db;
    private $mailService;

    private const OTP_TTL_MINUTES = 10;
    private const OTP_MAX_ATTEMPTS = 5;

    public function __construct($db, MailService $mailService)
    {
        $this->db = $db;
        $this->mailService = $mailService;
    }

    public function register(Request $req, Response $res)
    {
        $data = (array)$req->getParsedBody();

        $username = trim((string)($data['username'] ?? ''));
        $email = strtolower(trim((string)($data['email'] ?? '')));
        $password = (string)($data['password'] ?? '');

        if ($username === '' || $email === '' || $password === '') {
            return $this->json($res, ['error' => 'Faltan campos'], 400);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json($res, ['error' => 'Email no válido'], 400);
        }

        if (!$this->isStrongPassword($password)) {
            return $this->json($res, [
                'error' => 'La contraseña debe tener mínimo 8 caracteres e incluir mayúscula, minúscula y número'
            ], 400);
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);

        try {
            $stmt = $this->db->prepare("INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)");
            $stmt->execute([$username, $email, $hash]);
            return $this->json($res, ['message' => 'Usuario registrado'], 201);
        } catch (PDOException $e) {
            return $this->json($res, ['error' => 'Usuario o email ya existe'], 409);
        }
    }

    public function login(Request $req, Response $res)
    {
        $data = (array)$req->getParsedBody();

        $email = strtolower(trim((string)($data['email'] ?? '')));
        $password = (string)($data['password'] ?? '');

        if ($email === '' || $password === '') {
            return $this->json($res, ['error' => 'Email y contraseña son obligatorios'], 400);
        }

        $stmt = $this->db->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return $this->json($res, ['error' => 'Credenciales incorrectas'], 401);
        }

        if ((int)($user['two_factor_enabled'] ?? 1) === 0) {
            return $this->issueJwtResponse($user, $res);
        }

        return $this->startTwoFactorChallenge($user, $res);
    }

    public function googleLogin(Request $req, Response $res)
    {
        $data = (array)$req->getParsedBody();
        $idToken = trim((string)($data['id_token'] ?? ''));

        if ($idToken === '') {
            return $this->json($res, ['error' => 'id_token es obligatorio'], 400);
        }

        $googleClientId = $_ENV['GOOGLE_CLIENT_ID'] ?? '';
        if ($googleClientId === '') {
            return $this->json($res, ['error' => 'Google OAuth no está configurado en el servidor'], 500);
        }

        $googleClient = new GoogleClient(['client_id' => $googleClientId]);
        $googlePayload = $googleClient->verifyIdToken($idToken);

        if (!$googlePayload) {
            return $this->json($res, ['error' => 'Token de Google inválido'], 401);
        }

        $googleId = (string)($googlePayload['sub'] ?? '');
        $email = strtolower(trim((string)($googlePayload['email'] ?? '')));
        $emailVerified = (bool)($googlePayload['email_verified'] ?? false);
        $name = trim((string)($googlePayload['name'] ?? 'usuario_google'));

        if ($googleId === '' || $email === '') {
            return $this->json($res, ['error' => 'Google no devolvió datos suficientes'], 401);
        }

        if (!$emailVerified) {
            return $this->json($res, ['error' => 'Tu correo de Google no está verificado'], 401);
        }

        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("SELECT * FROM users WHERE google_id = ? OR email = ? LIMIT 1");
            $stmt->execute([$googleId, $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                $username = $this->generateUniqueUsername($name);
                $randomPassword = bin2hex(random_bytes(32));
                $passwordHash = password_hash($randomPassword, PASSWORD_DEFAULT);

                $insert = $this->db->prepare("
                    INSERT INTO users (username, email, password_hash, google_id)
                    VALUES (?, ?, ?, ?)
                ");
                $insert->execute([$username, $email, $passwordHash, $googleId]);

                $newUserId = (int)$this->db->lastInsertId();
                $userStmt = $this->db->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
                $userStmt->execute([$newUserId]);
                $user = $userStmt->fetch(PDO::FETCH_ASSOC);
            } else {
                if (empty($user['google_id'])) {
                    $update = $this->db->prepare("UPDATE users SET google_id = ? WHERE id = ?");
                    $update->execute([$googleId, $user['id']]);
                    $user['google_id'] = $googleId;
                }
            }

            $this->db->commit();

            if ((int)($user['two_factor_enabled'] ?? 1) === 0) {
                return $this->issueJwtResponse($user, $res);
            }

            return $this->startTwoFactorChallenge($user, $res);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return $this->json($res, ['error' => 'No se pudo completar el login con Google'], 500);
        }
    }

    public function verify2fa(Request $req, Response $res)
    {
        $data = (array)$req->getParsedBody();

        $challengeId = trim((string)($data['challenge_id'] ?? ''));
        $code = trim((string)($data['code'] ?? ''));

        if ($challengeId === '' || $code === '') {
            return $this->json($res, ['error' => 'challenge_id y code son obligatorios'], 400);
        }

        if (!preg_match('/^\d{6}$/', $code)) {
            return $this->json($res, ['error' => 'El código debe tener 6 dígitos'], 400);
        }

        $stmt = $this->db->prepare("
            SELECT
                lc.id AS challenge_row_id,
                lc.public_id,
                lc.user_id,
                lc.otp_hash,
                lc.attempts,
                lc.expires_at,
                lc.consumed_at,
                u.id AS id,
                u.username,
                u.role,
                u.email
            FROM login_challenges lc
            INNER JOIN users u ON u.id = lc.user_id
            WHERE lc.public_id = ?
            LIMIT 1
        ");
        $stmt->execute([$challengeId]);
        $challenge = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$challenge) {
            return $this->json($res, ['error' => 'Desafío 2FA no encontrado'], 404);
        }

        if (!empty($challenge['consumed_at'])) {
            return $this->json($res, ['error' => 'Este código ya fue usado'], 410);
        }

        if (strtotime($challenge['expires_at']) < time()) {
            $expire = $this->db->prepare("UPDATE login_challenges SET consumed_at = NOW() WHERE id = ?");
            $expire->execute([$challenge['challenge_row_id']]);
            return $this->json($res, ['error' => 'Código expirado, vuelve a iniciar sesión'], 410);
        }

        $attempts = (int)$challenge['attempts'];
        if ($attempts >= self::OTP_MAX_ATTEMPTS) {
            return $this->json($res, ['error' => 'Demasiados intentos fallidos. Inicia sesión de nuevo'], 429);
        }

        if (!password_verify($code, $challenge['otp_hash'])) {
            $update = $this->db->prepare("UPDATE login_challenges SET attempts = attempts + 1 WHERE id = ?");
            $update->execute([$challenge['challenge_row_id']]);

            $remaining = max(0, self::OTP_MAX_ATTEMPTS - ($attempts + 1));
            return $this->json($res, [
                'error' => 'Código incorrecto',
                'remaining_attempts' => $remaining
            ], 401);
        }

        $consume = $this->db->prepare("UPDATE login_challenges SET consumed_at = NOW() WHERE id = ?");
        $consume->execute([$challenge['challenge_row_id']]);

        return $this->issueJwtResponse($challenge, $res);
    }

    private function startTwoFactorChallenge(array $user, Response $res): Response
    {
        $retryAfter = 0;
        if (!$this->consumeRateLimit('otp_user', 'user:' . (string)$user['id'], 3, 600, $retryAfter)) {
            return $this->json($res, [
                'error' => 'Has solicitado demasiados códigos. Espera antes de volver a intentarlo.',
                'retry_after_seconds' => $retryAfter
            ], 429);
        }

        $otpCode = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $otpHash = password_hash($otpCode, PASSWORD_DEFAULT);
        $challengeId = $this->generateUuidV4();
        $expiresAt = (new DateTimeImmutable('+' . self::OTP_TTL_MINUTES . ' minutes'))->format('Y-m-d H:i:s');

        // Invalida desafíos anteriores sin consumir para este usuario
        $invalidate = $this->db->prepare("UPDATE login_challenges SET consumed_at = NOW() WHERE user_id = ? AND consumed_at IS NULL");
        $invalidate->execute([$user['id']]);

        $insert = $this->db->prepare("
            INSERT INTO login_challenges (public_id, user_id, otp_hash, expires_at)
            VALUES (?, ?, ?, ?)
        ");
        $insert->execute([$challengeId, $user['id'], $otpHash, $expiresAt]);

        try {
            $this->mailService->sendOtpCode(
                (string)$user['email'],
                (string)($user['username'] ?? 'Usuario'),
                $otpCode
            );
        } catch (Throwable $e) {
            $cleanup = $this->db->prepare("DELETE FROM login_challenges WHERE public_id = ?");
            $cleanup->execute([$challengeId]);
            return $this->json($res, ['error' => $e->getMessage()], 500);
        }

        return $this->json($res, [
            'requires_2fa' => true,
            'challenge_id' => $challengeId,
            'email_hint' => $this->maskEmail((string)$user['email'])
        ]);
    }

    private function issueJwtResponse(array $user, Response $res): Response
    {
        $payload = [
            'id' => (int)$user['id'],
            'username' => (string)$user['username'],
            'role' => (string)($user['role'] ?? 'user'),
            'exp' => time() + 3600
        ];

        $jwt = JWT::encode($payload, $_ENV['JWT_SECRET'], 'HS256');

        return $this->json($res, ['token' => $jwt]);
    }

    private function json(Response $res, array $payload, int $status = 200): Response
    {
        $res->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $res->withStatus($status)->withHeader('Content-Type', 'application/json');
    }

    private function generateUuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private function maskEmail(string $email): string
    {
        if (!str_contains($email, '@')) {
            return '***';
        }

        [$local, $domain] = explode('@', $email, 2);
        if ($local === '') {
            return '***@' . $domain;
        }

        if (strlen($local) <= 2) {
            $maskedLocal = substr($local, 0, 1) . '*';
        } else {
            $maskedLocal = substr($local, 0, 1) . str_repeat('*', max(1, strlen($local) - 2)) . substr($local, -1);
        }

        return $maskedLocal . '@' . $domain;
    }

    private function generateUniqueUsername(string $rawName): string
    {
        $base = $this->normalizeUsername($rawName);
        $candidate = $base;
        $counter = 1;

        while (true) {
            $stmt = $this->db->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
            $stmt->execute([$candidate]);

            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                return $candidate;
            }

            $candidate = $base . '_' . $counter;
            $counter++;
        }
    }

    private function normalizeUsername(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_]+/', '_', $value);
        $value = trim((string)$value, '_');

        if ($value === '') {
            $value = 'usuario';
        }

        if (strlen($value) > 20) {
            $value = substr($value, 0, 20);
        }

        return $value;
    }

    private function consumeRateLimit(
    string $bucket,
    string $rawKey,
    int $maxRequests,
    int $windowSeconds,
    int &$retryAfter = 0
    ): bool {
        $retryAfter = 0;
        $key = trim($rawKey) !== '' ? strtolower(trim($rawKey)) : 'anonymous';
        $keyHash = hash('sha256', $key);

        try {
            $this->db->beginTransaction();

            $select = $this->db->prepare(
                "SELECT id, hits, reset_at
                FROM rate_limits
                WHERE bucket = ? AND key_hash = ?
                LIMIT 1
                FOR UPDATE"
            );
            $select->execute([$bucket, $keyHash]);
            $row = $select->fetch(PDO::FETCH_ASSOC);

            $newResetAt = (new DateTimeImmutable('now +' . $windowSeconds . ' seconds'))
                ->format('Y-m-d H:i:s');

            if (!$row) {
                $insert = $this->db->prepare(
                    "INSERT INTO rate_limits (bucket, key_hash, hits, reset_at)
                    VALUES (?, ?, 1, ?)"
                );
                $insert->execute([$bucket, $keyHash, $newResetAt]);
                $this->db->commit();
                return true;
            }

            $hits = (int)$row['hits'];
            $resetTs = strtotime((string)$row['reset_at']) ?: 0;
            $nowTs = time();

            if ($resetTs <= $nowTs) {
                $update = $this->db->prepare("UPDATE rate_limits SET hits = 1, reset_at = ? WHERE id = ?");
                $update->execute([$newResetAt, $row['id']]);
                $this->db->commit();
                return true;
            }

            if ($hits >= $maxRequests) {
                $retryAfter = max(1, $resetTs - $nowTs);
                $this->db->rollBack();
                return false;
            }

            $update = $this->db->prepare("UPDATE rate_limits SET hits = hits + 1 WHERE id = ?");
            $update->execute([$row['id']]);

            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('consumeRateLimit error: ' . $e->getMessage());
            // fail-open en error técnico
            return true;
        }
    }

    private function isStrongPassword(string $password): bool {
        // mínimo 8, al menos una minúscula, una mayúscula y un número
        return (bool)preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/', $password);
    }

} 

