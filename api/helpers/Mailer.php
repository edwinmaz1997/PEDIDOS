<?php
// ============================================================
// Mailer Helper — PHPMailer + SMTP cPanel
// ============================================================
require_once __DIR__ . '/../libs/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/../libs/PHPMailer/SMTP.php';
require_once __DIR__ . '/../libs/PHPMailer/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

class Mailer {

    public static function sendVerificationCode(string $toEmail, string $toName, string $code): bool {
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = 'mail.nuevaexpress.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'verificacion@nuevaexpress.com';
            $mail->Password   = 'Verificacion2026.';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port       = 465;
            $mail->CharSet    = 'UTF-8';

            $mail->setFrom('verificacion@nuevaexpress.com', 'NuevaExpress');
            $mail->addAddress($toEmail, $toName);

            $mail->isHTML(true);
            $mail->Subject = 'Tu código de verificación — NuevaExpress';
            $mail->Body    = self::verificationTemplate($toName, $code);
            $mail->AltBody = "Hola {$toName}, tu código de verificación es: {$code}. Válido por 15 minutos.";

            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log('Mailer error: ' . $e->getMessage());
            return false;
        }
    }

    private static function verificationTemplate(string $name, string $code): string {
        return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:'DM Sans',Arial,sans-serif">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;padding:40px 0">
    <tr><td align="center">
      <table width="480" cellpadding="0" cellspacing="0" style="background:white;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08)">
        <!-- Header -->
        <tr>
          <td style="background:linear-gradient(135deg,#1a1a2e,#4A90D9);padding:32px;text-align:center">
            <div style="font-family:Arial,sans-serif;font-size:1.6rem;font-weight:800;color:white">
              Nueva<span style="color:#90cdf4">Express</span>
            </div>
            <div style="color:rgba(255,255,255,.7);font-size:.85rem;margin-top:4px">Tu plataforma de delivery local</div>
          </td>
        </tr>
        <!-- Body -->
        <tr>
          <td style="padding:40px 32px;text-align:center">
            <p style="font-size:1.05rem;color:#333;margin:0 0 8px">Hola, <strong>{$name}</strong> 👋</p>
            <p style="color:#666;font-size:.95rem;margin:0 0 32px">Usa el siguiente código para verificar tu correo electrónico:</p>
            <div style="background:#f0f4ff;border:2px dashed #4A90D9;border-radius:12px;padding:24px;margin:0 auto 32px;display:inline-block">
              <div style="font-size:2.8rem;font-weight:800;letter-spacing:12px;color:#1a1a2e;font-family:monospace">{$code}</div>
            </div>
            <p style="color:#999;font-size:.85rem;margin:0">Este código es válido por <strong>15 minutos</strong>.<br>Si no solicitaste esto, ignora este correo.</p>
          </td>
        </tr>
        <!-- Footer -->
        <tr>
          <td style="background:#f9f9f9;padding:20px 32px;text-align:center;border-top:1px solid #eee">
            <p style="color:#bbb;font-size:.78rem;margin:0">© 2026 NuevaExpress — Nueva Concepción, Escuintla</p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
    }
}
