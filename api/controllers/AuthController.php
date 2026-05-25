<?php
// ============================================================
// Auth Controller
// ============================================================

class AuthController {

    private $db;

    public function __construct() {
        $this->db = Database::connect();
    }

    // --------------------------------------------------------
    // POST /api/auth/register
    // --------------------------------------------------------
    public function register(array $body): void {
        $ip = Security::getClientIp();
        if (!Security::checkRateLimit($ip, 'register', 5, 3600)) {
            Response::error('Demasiados intentos de registro. Espera una hora.', 429);
        }

        $name     = trim($body['name'] ?? '');
        $email    = trim($body['email'] ?? '');
        $phone    = trim($body['phone'] ?? '');
        $password = $body['password'] ?? '';
        $role     = $body['role'] ?? 'cliente';

        // Validate
        $errors = [];
        if (strlen($name) < 2) $errors['name'] = 'El nombre debe tener al menos 2 caracteres';
        if (!Security::validateEmail($email)) $errors['email'] = 'Email inválido';
        if (strlen($password) < 8) $errors['password'] = 'La contraseña debe tener al menos 8 caracteres';
        if (!preg_match('/[A-Z]/', $password)) $errors['password'] = 'La contraseña debe tener al menos una mayúscula';
        if (!preg_match('/[0-9]/', $password)) $errors['password'] = 'La contraseña debe contener al menos un número';
        if ($phone && !Security::validatePhone($phone)) $errors['phone'] = 'Número de teléfono inválido';
        if (!in_array($role, ['cliente', 'negocio', 'repartidor'])) $errors['role'] = 'Rol inválido';
        if ($errors) Response::error('Datos inválidos', 422, $errors);

        // Check email unique
        $stmt = $this->db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) Response::error('El email ya está registrado', 409);

        // Get role_id
        $stmt = $this->db->prepare("SELECT id FROM roles WHERE name = ?");
        $stmt->execute([$role]);
        $roleRow = $stmt->fetch();
        if (!$roleRow) Response::error('Rol no encontrado', 400);

        // Insert user
        $hash = Security::hashPassword($password);
        $stmt = $this->db->prepare("INSERT INTO users (role_id, name, email, phone, password_hash) VALUES (?,?,?,?,?)");
        $stmt->execute([$roleRow['id'], $name, $email, $phone ?: null, $hash]);
        $userId = $this->db->lastInsertId();

        // Create session
        $token = $this->createSession($userId);

        Response::success([
            'token' => $token,
            'user'  => ['id' => $userId, 'name' => $name, 'email' => $email, 'role' => $role]
        ], 'Registro exitoso', 201);
    }

    // --------------------------------------------------------
    // POST /api/auth/login
    // --------------------------------------------------------
    public function login(array $body): void {
        $ip = Security::getClientIp();
        if (!Security::checkRateLimit($ip, 'login', LOGIN_MAX_ATTEMPTS, LOGIN_LOCKOUT_TIME)) {
            Response::error('Demasiados intentos fallidos. Cuenta bloqueada por 15 minutos.', 429);
        }

        $email    = trim($body['email'] ?? '');
        $password = $body['password'] ?? '';

        if (!$email || !$password) Response::error('Email y contraseña requeridos', 400);

        $stmt = $this->db->prepare("
            SELECT u.*, r.name as role
            FROM users u
            JOIN roles r ON u.role_id = r.id
            WHERE u.email = ?
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // Timing-safe: always run verify even if user not found
        $hash = $user['password_hash'] ?? '$2y$12$invalidhashtopreventtiming';
        if (!$user || !Security::verifyPassword($password, $hash)) {
            Response::error('Credenciales incorrectas', 401);
        }
        if (!$user['is_active']) Response::forbidden('Cuenta desactivada. Contacta soporte.');

        // Update last login
        $this->db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);

        // Create session
        $token = $this->createSession($user['id']);

        Response::success([
            'token' => $token,
            'user'  => [
                'id'    => $user['id'],
                'name'  => $user['name'],
                'email' => $user['email'],
                'phone' => $user['phone'],
                'role'  => $user['role'],
            ]
        ], 'Inicio de sesión exitoso');
    }

    // --------------------------------------------------------
    // POST /api/auth/logout
    // --------------------------------------------------------
    public function logout(): void {
        $user = AuthMiddleware::authenticate();
        $token = $this->extractToken();
        $this->db->prepare("DELETE FROM user_sessions WHERE token = ?")->execute([$token]);
        Response::success(null, 'Sesión cerrada correctamente');
    }

    // --------------------------------------------------------
    // GET /api/auth/me
    // --------------------------------------------------------
    public function me(): void {
        $user = AuthMiddleware::authenticate();
        unset($user['password_hash']);
        Response::success($user);
    }

    // --------------------------------------------------------
    // Private: create session token
    // --------------------------------------------------------
    private function createSession(int $userId): string {
        $token     = Security::generateToken(64);
        $expiresAt = date('Y-m-d H:i:s', time() + SESSION_LIFETIME);
        $ip        = Security::getClientIp();
        $ua        = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

        $this->db->prepare("
            INSERT INTO user_sessions (user_id, token, ip_address, user_agent, expires_at)
            VALUES (?,?,?,?,?)
        ")->execute([$userId, $token, $ip, $ua, $expiresAt]);

        return $token;
    }

    private function extractToken(): ?string {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) return trim($m[1]);
        return null;
    }
}
