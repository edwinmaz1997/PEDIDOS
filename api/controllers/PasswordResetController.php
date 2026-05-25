<?php
// ============================================================
// Password Reset Controller
// ============================================================
class PasswordResetController {
    private $db;
    public function __construct() { $this->db = Database::connect(); }

    // POST /api/auth/forgot-password
    public function forgot(array $body): void {
        $ip = Security::getClientIp();
        if (!Security::checkRateLimit($ip, 'forgot', 3, 3600)) {
            Response::error('Demasiados intentos. Espera una hora.', 429);
        }
        $email = trim($body['email'] ?? '');
        if (!Security::validateEmail($email)) Response::error('Email inválido', 400);

        $stmt = $this->db->prepare("SELECT id, name FROM users WHERE email = ? AND is_active = 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // Always return success to prevent email enumeration
        if ($user) {
            $token     = Security::generateToken(32);
            $expiresAt = date('Y-m-d H:i:s', time() + 3600);
            // Invalidate old tokens
            $this->db->prepare("DELETE FROM password_resets WHERE email = ?")->execute([$email]);
            $this->db->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?,?,?)")
                     ->execute([$email, $token, $expiresAt]);

            $resetUrl = APP_URL . '/restablecer-contrasena.html?token=' . $token;
            $this->sendResetEmail($email, $user['name'], $resetUrl);
        }
        Response::success(null, 'Si el correo existe recibirás un enlace para restablecer tu contraseña.');
    }

    // POST /api/auth/reset-password
    public function reset(array $body): void {
        $token    = Security::sanitize($body['token'] ?? '');
        $password = $body['password'] ?? '';

        if (!$token) Response::error('Token requerido', 400);
        if (strlen($password) < 8) Response::error('La contraseña debe tener al menos 8 caracteres', 400);
        if (!preg_match('/[A-Z]/', $password)) Response::error('Debe contener al menos una mayúscula', 400);
        if (!preg_match('/[0-9]/', $password)) Response::error('Debe contener al menos un número', 400);

        $stmt = $this->db->prepare("SELECT * FROM password_resets WHERE token = ? AND expires_at > NOW() AND used = 0");
        $stmt->execute([$token]);
        $reset = $stmt->fetch();
        if (!$reset) Response::error('Token inválido o expirado', 400);

        $hash = Security::hashPassword($password);
        $this->db->prepare("UPDATE users SET password_hash = ? WHERE email = ?")->execute([$hash, $reset['email']]);
        $this->db->prepare("UPDATE password_resets SET used = 1 WHERE token = ?")->execute([$token]);
        // Invalidate all sessions
        $stmt = $this->db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$reset['email']]);
        $user = $stmt->fetch();
        if ($user) $this->db->prepare("DELETE FROM user_sessions WHERE user_id = ?")->execute([$user['id']]);

        Response::success(null, 'Contraseña restablecida correctamente.');
    }

    // POST /api/auth/verify-reset-token
    public function verifyToken(array $body): void {
        $token = Security::sanitize($body['token'] ?? '');
        $stmt  = $this->db->prepare("SELECT email FROM password_resets WHERE token = ? AND expires_at > NOW() AND used = 0");
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        if (!$row) Response::error('Token inválido o expirado', 400);
        Response::success(['email' => $row['email']]);
    }

    private function sendResetEmail(string $to, string $name, string $url): void {
        $subject = 'Restablecer contraseña — NuevaExpress';
        $body    = "Hola {$name},\n\nRecibiste este correo porque solicitaste restablecer tu contraseña en NuevaExpress.\n\nHaz clic en el siguiente enlace (válido por 1 hora):\n{$url}\n\nSi no solicitaste este cambio, ignora este correo.\n\nEquipo NuevaExpress\nhttps://nuevaexpress.com";
        $headers = "From: NuevaExpress <noreply@nuevaexpress.com>\r\nReply-To: noreply@nuevaexpress.com\r\nX-Mailer: PHP/" . phpversion();
        @mail($to, $subject, $body, $headers);
    }
}
