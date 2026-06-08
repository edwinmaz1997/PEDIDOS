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
        $html = <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:Arial,sans-serif">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;padding:40px 0">
    <tr><td align="center">
      <table width="480" cellpadding="0" cellspacing="0" style="background:white;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08)">
        <tr><td style="background:linear-gradient(135deg,#1a1a2e,#4A90D9);padding:32px;text-align:center">
          <div style="font-size:1.6rem;font-weight:800;color:white">Nueva<span style="color:#90cdf4">Express</span></div>
          <div style="color:rgba(255,255,255,.7);font-size:.85rem;margin-top:4px">Tu plataforma de delivery local</div>
        </td></tr>
        <tr><td style="padding:40px 32px;text-align:center">
          <p style="font-size:1.05rem;color:#333;margin:0 0 8px">Hola, <strong>{$name}</strong> 👋</p>
          <p style="color:#666;font-size:.95rem;margin:0 0 32px">Recibiste este correo porque solicitaste restablecer tu contraseña.</p>
          <a href="{$url}" style="display:inline-block;background:#4A90D9;color:white;text-decoration:none;padding:14px 32px;border-radius:10px;font-weight:700;font-size:1rem">Restablecer contraseña</a>
          <p style="color:#999;font-size:.82rem;margin:24px 0 0">Este enlace es válido por <strong>1 hora</strong>.<br>Si no solicitaste esto, ignora este correo.</p>
        </td></tr>
        <tr><td style="background:#f9f9f9;padding:20px 32px;text-align:center;border-top:1px solid #eee">
          <p style="color:#bbb;font-size:.78rem;margin:0">© 2026 NuevaExpress — Nueva Concepción, Escuintla</p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body></html>
HTML;
        try {
            require_once __DIR__ . '/../libs/PHPMailer/PHPMailer.php';
            require_once __DIR__ . '/../libs/PHPMailer/SMTP.php';
            require_once __DIR__ . '/../libs/PHPMailer/Exception.php';
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = 'mail.nuevaexpress.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'verificacion@nuevaexpress.com';
            $mail->Password   = 'Verificacion2026.';
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port       = 465;
            $mail->CharSet    = 'UTF-8';
            $mail->setFrom('verificacion@nuevaexpress.com', 'NuevaExpress');
            $mail->addAddress($to, $name);
            $mail->isHTML(true);
            $mail->Subject = 'Restablecer contraseña — NuevaExpress';
            $mail->Body    = $html;
            $mail->AltBody = "Hola {$name}, usa este enlace para restablecer tu contraseña (válido 1 hora): {$url}";
            $mail->send();
        } catch (\Exception $e) {
            error_log('PasswordReset mail error: ' . $e->getMessage());
        }
    }
}
