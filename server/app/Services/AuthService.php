<?php

use Firebase\JWT\JWT;
use Google\Client as GoogleClient;

require_once __DIR__ . '/../Repositories/UserRepository.php';
require_once __DIR__ . '/../Repositories/LoginChallengeRepository.php';
require_once __DIR__ . '/../Repositories/RateLimitRepository.php';

class AuthService
{
    private PDO $db;
    private MailService $mailService;
    private UserRepository $userRepository;
    private LoginChallengeRepository $loginChallengeRepository;
    private RateLimitRepository $rateLimitRepository;

    private const OTP_TTL_MINUTES = 10;
    private const OTP_MAX_ATTEMPTS = 5;

    public function __construct(PDO $db, MailService $mailService)
    {
        $this->db = $db;
        $this->mailService = $mailService;

        $this->userRepository = new UserRepository($db);
        $this->loginChallengeRepository = new LoginChallengeRepository($db);
        $this->rateLimitRepository = new RateLimitRepository($db);
    }

    public function register(array $data): array
    {
        $username = trim((string)($data['username'] ?? ''));
        $fullName = $this->normalizeFullName((string)($data['full_name'] ?? ''));
        $email = strtolower(trim((string)($data['email'] ?? '')));
        $password = (string)($data['password'] ?? '');

        if ($username === '' || $fullName === '' || $email === '' || $password === '') {
            return $this->result(400, ['error' => 'Faltan campos (incluyendo nombre y apellidos)']);
        }

        if (!$this->isValidUsername($username)) {
            return $this->result(400, ['error' => 'Username inválido (3-30, letras, números o _)']);
        }

        if (!$this->isValidRealFullName($fullName)) {
            return $this->result(400, ['error' => 'Debes indicar nombre y apellidos reales (mínimo 2 palabras)']);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->result(400, ['error' => 'Email no válido']);
        }

        if (!$this->isStrongPassword($password)) {
            return $this->result(400, [
                'error' => 'La contraseña debe tener mínimo 8 caracteres e incluir mayúscula, minúscula y número'
            ]);
        }

        if ($this->userRepository->usernameExists($username)) {
            return $this->result(409, ['error' => 'Ese nombre de usuario ya está en uso']);
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);

        try {
            $this->userRepository->insertLocalUser($username, $fullName, $email, $hash);
            return $this->result(201, ['message' => 'Usuario registrado']);
        } catch (PDOException $e) {
            return $this->result(409, ['error' => 'Usuario o email ya existe']);
        }
    }

    public function login(array $data): array
    {
        $email = strtolower(trim((string)($data['email'] ?? '')));
        $password = (string)($data['password'] ?? '');

        if ($email === '' || $password === '') {
            return $this->result(400, ['error' => 'Email y contraseña son obligatorios']);
        }

        $user = $this->userRepository->findByEmail($email);

        if (!$user || !password_verify($password, (string)$user['password_hash'])) {
            return $this->result(401, ['error' => 'Credenciales incorrectas']);
        }

        $twoFactorMode = $this->getTwoFactorMode();
        $userTwoFactorEnabled = (int)($user['two_factor_enabled'] ?? 1) === 1;

        if (!$userTwoFactorEnabled || $twoFactorMode === 'disabled') {
            return $this->result(200, ['token' => $this->createJwtToken($user)]);
        }

        if ($twoFactorMode === 'shadow') {
            $this->triggerShadowTwoFactor($user);
            return $this->result(200, ['token' => $this->createJwtToken($user)]);
        }

        return $this->startTwoFactorChallenge($user);
    }

    public function googleLogin(array $data): array
    {
        $idToken = trim((string)($data['id_token'] ?? ''));

        if ($idToken === '') {
            return $this->result(400, ['error' => 'id_token es obligatorio']);
        }

        $googleClientId = $_ENV['GOOGLE_CLIENT_ID'] ?? '';
        if ($googleClientId === '') {
            return $this->result(500, ['error' => 'Google OAuth no está configurado en el servidor']);
        }

        $googleClient = new GoogleClient(['client_id' => $googleClientId]);
        $googlePayload = $googleClient->verifyIdToken($idToken);

        if (!$googlePayload) {
            return $this->result(401, ['error' => 'Token de Google inválido']);
        }

        $googleId = (string)($googlePayload['sub'] ?? '');
        $email = strtolower(trim((string)($googlePayload['email'] ?? '')));
        $emailVerified = (bool)($googlePayload['email_verified'] ?? false);
        $name = $this->normalizeFullName((string)($googlePayload['name'] ?? ''));
        $picture = trim((string)($googlePayload['picture'] ?? ''));

        if ($googleId === '' || $email === '') {
            return $this->result(401, ['error' => 'Google no devolvió datos suficientes']);
        }

        if (!$emailVerified) {
            return $this->result(401, ['error' => 'Tu correo de Google no está verificado']);
        }

        try {
            $this->db->beginTransaction();

            $user = $this->userRepository->findByGoogleIdOrEmail($googleId, $email);

            if (!$user) {
                if (!$this->isValidRealFullName($name)) {
                    $this->db->rollBack();
                    return $this->result(400, [
                        'error' => 'Google no proporcionó un nombre y apellidos válidos. Regístrate con formulario manual.'
                    ]);
                }

                $username = $this->generateUniqueUsername($name);
                $randomPassword = bin2hex(random_bytes(32));
                $passwordHash = password_hash($randomPassword, PASSWORD_DEFAULT);

                $newUserId = $this->userRepository->insertGoogleUser(
                    $username,
                    $name,
                    $email,
                    ($picture !== '' ? $picture : null),
                    $passwordHash,
                    $googleId
                );

                $user = $this->userRepository->findById($newUserId);
            } else {
                $googleIdToSet = empty($user['google_id']) ? $googleId : null;
                $fullNameToSet = (
                    (empty($user['full_name']) || trim((string)$user['full_name']) === '')
                    && $this->isValidRealFullName($name)
                ) ? $name : null;

                if ($googleIdToSet !== null || $fullNameToSet !== null) {
                    $this->userRepository->linkGoogleIdAndOptionalName((int)$user['id'], $googleIdToSet, $fullNameToSet);
                }

                $user = $this->userRepository->findById((int)$user['id']);
            }

            if (!$user) {
                throw new RuntimeException('No se pudo recuperar el usuario tras login Google');
            }

            $this->db->commit();

            $twoFactorMode = $this->getTwoFactorMode();
            $userTwoFactorEnabled = (int)($user['two_factor_enabled'] ?? 1) === 1;

            if (!$userTwoFactorEnabled || $twoFactorMode === 'disabled') {
                return $this->result(200, ['token' => $this->createJwtToken($user)]);
            }

            if ($twoFactorMode === 'shadow') {
                $this->triggerShadowTwoFactor($user);
                return $this->result(200, ['token' => $this->createJwtToken($user)]);
            }

            return $this->startTwoFactorChallenge($user);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('Google login error: ' . $e->getMessage());
            return $this->result(500, ['error' => 'No se pudo completar el login con Google']);
        }
    }

    public function verify2fa(array $data): array
    {
        if ($this->getTwoFactorMode() !== 'enforced') {
            return $this->result(400, ['error' => 'La verificación 2FA no está activa en este entorno.']);
        }

        $challengeId = trim((string)($data['challenge_id'] ?? ''));
        $code = trim((string)($data['code'] ?? ''));

        if ($challengeId === '' || $code === '') {
            return $this->result(400, ['error' => 'challenge_id y code son obligatorios']);
        }

        if (!preg_match('/^\d{6}$/', $code)) {
            return $this->result(400, ['error' => 'El código debe tener 6 dígitos']);
        }

        $challenge = $this->loginChallengeRepository->findChallengeWithUserByPublicId($challengeId);

        if (!$challenge) {
            return $this->result(404, ['error' => 'Desafío 2FA no encontrado']);
        }

        if (!empty($challenge['consumed_at'])) {
            return $this->result(410, ['error' => 'Este código ya fue usado']);
        }

        if (strtotime((string)$challenge['expires_at']) < time()) {
            $this->loginChallengeRepository->consumeById((int)$challenge['challenge_row_id']);
            return $this->result(410, ['error' => 'Código expirado, vuelve a iniciar sesión']);
        }

        $attempts = (int)$challenge['attempts'];
        if ($attempts >= self::OTP_MAX_ATTEMPTS) {
            return $this->result(429, ['error' => 'Demasiados intentos fallidos. Inicia sesión de nuevo']);
        }

        if (!password_verify($code, (string)$challenge['otp_hash'])) {
            $this->loginChallengeRepository->incrementAttempts((int)$challenge['challenge_row_id']);
            $remaining = max(0, self::OTP_MAX_ATTEMPTS - ($attempts + 1));
            return $this->result(401, [
                'error' => 'Código incorrecto',
                'remaining_attempts' => $remaining
            ]);
        }

        $this->loginChallengeRepository->consumeById((int)$challenge['challenge_row_id']);

        return $this->result(200, ['token' => $this->createJwtToken($challenge)]);
    }

    public function me(int $userId): array
    {
        if ($userId <= 0) {
            return $this->result(401, ['error' => 'No autorizado']);
        }

        $user = $this->userRepository->findById($userId);
        if (!$user) {
            return $this->result(404, ['error' => 'Usuario no encontrado']);
        }

        return $this->result(200, ['user' => $this->publicUser($user)]);
    }

    public function updateProfile(int $userId, array $data): array
    {
        if ($userId <= 0) {
            return $this->result(401, ['error' => 'No autorizado']);
        }

        $currentUser = $this->userRepository->findById($userId);
        if (!$currentUser) {
            return $this->result(404, ['error' => 'Usuario no encontrado']);
        }

        $username = array_key_exists('username', $data)
            ? trim((string)$data['username'])
            : (string)$currentUser['username'];

        $email = array_key_exists('email', $data)
            ? strtolower(trim((string)$data['email']))
            : (string)$currentUser['email'];

        $fullName = array_key_exists('full_name', $data)
            ? $this->normalizeFullName((string)$data['full_name'])
            : (string)($currentUser['full_name'] ?? '');

        $avatarUrl = array_key_exists('avatar_url', $data)
            ? trim((string)$data['avatar_url'])
            : (string)($currentUser['avatar_url'] ?? '');

        $avatarFileBase64 = array_key_exists('avatar_file_base64', $data)
            ? trim((string)$data['avatar_file_base64'])
            : '';

        if (!$this->isValidUsername($username)) {
            return $this->result(400, ['error' => 'Username inválido (3-30, letras, números o _)']);
        }

        if ($this->userRepository->usernameExists($username, $userId)) {
            return $this->result(409, ['error' => 'Ese nombre de usuario ya está en uso']);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->result(400, ['error' => 'Email no válido']);
        }

        if (array_key_exists('full_name', $data) && !$this->isValidRealFullName($fullName)) {
            return $this->result(400, ['error' => 'Debes indicar nombre y apellidos reales (mínimo 2 palabras)']);
        }

        if (mb_strlen($fullName) > 100) {
            return $this->result(400, ['error' => 'El nombre no puede superar 100 caracteres']);
        }

        if ($avatarFileBase64 !== '') {
            try {
                $avatarUrl = $this->storeAvatarFromBase64(
                    $avatarFileBase64,
                    $userId,
                    (string)($currentUser['avatar_url'] ?? '')
                );
            } catch (InvalidArgumentException $e) {
                return $this->result(400, ['error' => $e->getMessage()]);
            } catch (Throwable $e) {
                error_log('avatar upload error: ' . $e->getMessage());
                return $this->result(500, ['error' => 'No se pudo guardar la foto de perfil']);
            }
        }

        if (!$this->isValidAvatarUrl($avatarUrl)) {
            return $this->result(400, ['error' => 'La URL de la foto no es válida']);
        }

        try {
            $this->userRepository->updateProfile(
                $userId,
                $username,
                $email,
                ($fullName !== '' ? $fullName : null),
                ($avatarUrl !== '' ? $avatarUrl : null)
            );

            $updatedUser = $this->userRepository->findById($userId);
            if (!$updatedUser) {
                return $this->result(404, ['error' => 'Usuario no encontrado']);
            }

            $newToken = $this->createJwtToken($updatedUser);

            return $this->result(200, [
                'message' => 'Perfil actualizado correctamente',
                'token' => $newToken,
                'user' => $this->publicUser($updatedUser)
            ]);
        } catch (PDOException $e) {
            if ((int)$e->getCode() === 23000 || str_contains($e->getMessage(), 'Duplicate entry')) {
                return $this->result(409, ['error' => 'El username o email ya está en uso']);
            }
            return $this->result(500, ['error' => 'No se pudo actualizar el perfil']);
        }
    }

    private function startTwoFactorChallenge(array $user): array
    {
        $retryAfter = 0;
        if (!$this->rateLimitRepository->consume('otp_user', 'user:' . (string)$user['id'], 3, 600, $retryAfter)) {
            return $this->result(429, [
                'error' => 'Has solicitado demasiados códigos. Espera antes de volver a intentarlo.',
                'retry_after_seconds' => $retryAfter
            ]);
        }

        $otpCode = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $otpHash = password_hash($otpCode, PASSWORD_DEFAULT);
        $challengeId = $this->generateUuidV4();
        $expiresAt = (new DateTimeImmutable('+' . self::OTP_TTL_MINUTES . ' minutes'))->format('Y-m-d H:i:s');

        $this->loginChallengeRepository->invalidateOpenByUserId((int)$user['id']);
        $this->loginChallengeRepository->insert($challengeId, (int)$user['id'], $otpHash, $expiresAt);

        try {
            $this->mailService->sendOtpCode(
                (string)$user['email'],
                (string)($user['username'] ?? 'Usuario'),
                $otpCode
            );
        } catch (Throwable $e) {
            error_log('2FA mail delivery failed: ' . $e->getMessage());

            if ($this->shouldBypassTwoFactorOnMailFailure() && $this->getTwoFactorMode() !== 'enforced') {
                $this->loginChallengeRepository->deleteByPublicId($challengeId);
                return $this->result(200, ['token' => $this->createJwtToken($user)]);
            }

            $this->loginChallengeRepository->deleteByPublicId($challengeId);
            return $this->result(500, ['error' => 'No se pudo enviar el código de verificación.']);
        }

        return $this->result(200, [
            'requires_2fa' => true,
            'challenge_id' => $challengeId,
            'email_hint' => $this->maskEmail((string)$user['email'])
        ]);
    }

    private function triggerShadowTwoFactor(array $user): void
    {
        try {
            $this->startTwoFactorChallenge($user);
        } catch (Throwable $e) {
            error_log('Shadow 2FA error: ' . $e->getMessage());
        }
    }

    private function createJwtToken(array $user): string
    {
        $payload = [
            'id' => (int)$user['id'],
            'username' => (string)$user['username'],
            'role' => (string)($user['role'] ?? 'user'),
            'exp' => time() + 3600
        ];

        return JWT::encode($payload, $_ENV['JWT_SECRET'], 'HS256');
    }

    private function publicUser(array $user): array
    {
        return [
            'id' => (int)$user['id'],
            'username' => (string)$user['username'],
            'full_name' => (string)($user['full_name'] ?? ''),
            'email' => (string)$user['email'],
            'avatar_url' => (string)($user['avatar_url'] ?? ''),
            'role' => (string)($user['role'] ?? 'user'),
            'created_at' => $user['created_at'] ?? null
        ];
    }

    private function isValidUsername(string $username): bool
    {
        return (bool)preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username);
    }

    private function isValidRealFullName(string $fullName): bool
    {
        if ($fullName === '') return false;
        if (mb_strlen($fullName) < 5 || mb_strlen($fullName) > 100) return false;

        if (!preg_match('/^[\p{L}][\p{L}\p{M}\'\-\s]+$/u', $fullName)) {
            return false;
        }

        $parts = preg_split('/\s+/u', $fullName, -1, PREG_SPLIT_NO_EMPTY);
        if (!$parts || count($parts) < 2) {
            return false;
        }

        foreach ($parts as $part) {
            if (mb_strlen($part) < 2) {
                return false;
            }
        }

        return true;
    }

    private function normalizeFullName(string $fullName): string
    {
        $fullName = trim($fullName);
        $fullName = preg_replace('/\s+/u', ' ', $fullName);
        return (string)$fullName;
    }

    private function isValidAvatarUrl(string $url): bool
    {
        if ($url === '') return true;
        if (mb_strlen($url) > 255) return false;
        if (str_starts_with($url, '/uploads/avatars/')) return true;
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    private function storeAvatarFromBase64(string $dataUri, int $userId, string $currentAvatarUrl = ''): string
    {
        if (!preg_match('/^data:image\/(png|jpe?g|webp);base64,([A-Za-z0-9+\/=]+)$/i', $dataUri, $matches)) {
            throw new InvalidArgumentException('Formato de imagen no válido (usa PNG, JPG o WEBP)');
        }

        $extRaw = strtolower($matches[1]);
        $ext = match ($extRaw) {
            'jpeg', 'jpg' => 'jpg',
            'png' => 'png',
            'webp' => 'webp',
            default => throw new InvalidArgumentException('Formato de imagen no permitido')
        };

        $binary = base64_decode($matches[2], true);
        if ($binary === false) {
            throw new InvalidArgumentException('No se pudo procesar la imagen');
        }

        if (strlen($binary) > 2 * 1024 * 1024) {
            throw new InvalidArgumentException('La imagen no puede superar 2 MB');
        }

        $imageInfo = @getimagesizefromstring($binary);
        if ($imageInfo === false) {
            throw new InvalidArgumentException('El archivo no es una imagen válida');
        }

        $avatarsDir = __DIR__ . '/../../public/uploads/avatars';
        if (!is_dir($avatarsDir) && !mkdir($avatarsDir, 0755, true) && !is_dir($avatarsDir)) {
            throw new RuntimeException('No se pudo crear el directorio de avatares');
        }

        $filename = 'u' . $userId . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $absolutePath = $avatarsDir . '/' . $filename;

        if (file_put_contents($absolutePath, $binary) === false) {
            throw new RuntimeException('No se pudo guardar la imagen');
        }

        if ($currentAvatarUrl !== '' && str_starts_with($currentAvatarUrl, '/uploads/avatars/')) {
            $oldRelative = ltrim($currentAvatarUrl, '/');
            $oldAbsolute = __DIR__ . '/../../public/' . $oldRelative;
            if (is_file($oldAbsolute) && is_writable($oldAbsolute)) {
                @unlink($oldAbsolute);
            }
        }

        return '/uploads/avatars/' . $filename;
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
            if (!$this->userRepository->usernameExists($candidate)) {
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

    private function getTwoFactorMode(): string
    {
        $mode = strtolower(trim((string)($_ENV['AUTH_2FA_MODE'] ?? 'enforced')));
        if (!in_array($mode, ['disabled', 'shadow', 'enforced'], true)) {
            $mode = 'enforced';
        }
        return $mode;
    }

    private function isStrongPassword(string $password): bool
    {
        return (bool)preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/', $password);
    }

    private function shouldBypassTwoFactorOnMailFailure(): bool
    {
        $bypassEnabled = filter_var(
            $_ENV['AUTH_DEV_BYPASS_2FA_ON_MAIL_FAILURE'] ?? 'false',
            FILTER_VALIDATE_BOOLEAN
        );

        if (!$bypassEnabled) {
            return false;
        }

        $appEnv = strtolower(trim((string)($_ENV['APP_ENV'] ?? 'production')));
        return in_array($appEnv, ['dev', 'development', 'local', 'test'], true);
    }

    private function result(int $status, array $payload): array
    {
        return ['status' => $status, 'payload' => $payload];
    }
}