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

    private static function send(string $toEmail, string $toName, string $subject, string $htmlBody, string $altBody): bool {
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = 'mail.nuevaexpress.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'notificaciones@nuevaexpress.com';
            $mail->Password   = 'Edwinpalma971.';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port       = 465;
            $mail->CharSet    = 'UTF-8';

            $mail->setFrom('notificaciones@nuevaexpress.com', 'NuevaExpress');
            $mail->addAddress($toEmail, $toName);

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;
            $mail->AltBody = $altBody;

            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log('Mailer error: ' . $e->getMessage());
            return false;
        }
    }

    // ── Plantilla base ────────────────────────────────────────
    private static function template(string $name, string $icon, string $title, string $body, string $btnText = '', string $btnUrl = ''): string {
        $btn = $btnText ? "
            <div style=\"text-align:center;margin-top:28px\">
              <a href=\"{$btnUrl}\" style=\"background:linear-gradient(135deg,#1a1a2e,#4A90D9);color:white;text-decoration:none;padding:13px 32px;border-radius:10px;font-weight:700;font-size:.95rem;display:inline-block\">{$btnText}</a>
            </div>" : '';

        return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:'DM Sans',Arial,sans-serif">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;padding:40px 0">
    <tr><td align="center">
      <table width="480" cellpadding="0" cellspacing="0" style="background:white;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08)">
        <tr>
          <td style="background:linear-gradient(135deg,#1a1a2e,#4A90D9);padding:28px 32px;text-align:center">
            <div style="font-size:2rem;margin-bottom:6px">{$icon}</div>
            <div style="font-family:Arial,sans-serif;font-size:1.4rem;font-weight:800;color:white">Nueva<span style="color:#90cdf4">Express</span></div>
            <div style="color:rgba(255,255,255,.7);font-size:.82rem;margin-top:2px">Tu plataforma de delivery local</div>
          </td>
        </tr>
        <tr>
          <td style="padding:36px 32px">
            <p style="font-size:1rem;color:#333;margin:0 0 6px">Hola, <strong>{$name}</strong> 👋</p>
            <h2 style="font-size:1.25rem;color:#1a1a2e;margin:0 0 20px">{$title}</h2>
            {$body}
            {$btn}
          </td>
        </tr>
        <tr>
          <td style="background:#f9f9f9;padding:18px 32px;text-align:center;border-top:1px solid #eee">
            <p style="color:#bbb;font-size:.76rem;margin:0">© 2026 NuevaExpress — Nueva Concepción, Escuintla<br>
            ¿Necesitas ayuda? Escríbenos al <a href="https://wa.me/50231586340" style="color:#4A90D9">+502 3158-6340</a></p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
    }

    // ── 1. Pedido aceptado ────────────────────────────────────
    public static function sendGeneric(string $toEmail, string $toName, string $subject, string $htmlBody): bool {
        return self::send($toEmail, $toName, $subject, $htmlBody, strip_tags($htmlBody));
    }

    public static function orderAccepted(string $toEmail, string $toName, string $orderNumber, string $businessName, int $estimatedTime): bool {
        $body = "
            <div style='background:#f0fdf4;border-left:4px solid #22c55e;border-radius:8px;padding:16px;margin-bottom:16px'>
                <strong style='color:#15803d'>✅ Tu pedido fue aceptado</strong>
            </div>
            <table style='width:100%;font-size:.9rem;color:#444'>
                <tr><td style='padding:6px 0;color:#888'>Pedido</td><td style='font-weight:700'>#{$orderNumber}</td></tr>
                <tr><td style='padding:6px 0;color:#888'>Negocio</td><td>{$businessName}</td></tr>
                <tr><td style='padding:6px 0;color:#888'>Tiempo estimado</td><td><strong style='color:#1a1a2e'>{$estimatedTime} minutos</strong></td></tr>
            </table>
            <p style='color:#666;font-size:.88rem;margin-top:16px'>El negocio está preparando tu pedido. Te avisaremos cuando esté en camino.</p>";

        return self::send(
            $toEmail, $toName,
            "✅ Tu pedido #{$orderNumber} fue aceptado — NuevaExpress",
            self::template($toName, '✅', "¡Tu pedido fue aceptado!", $body, 'Ver mis pedidos', 'https://nuevaexpress.com/cliente/index.html'),
            "Hola {$toName}, tu pedido #{$orderNumber} en {$businessName} fue aceptado. Tiempo estimado: {$estimatedTime} minutos."
        );
    }

    // ── 2. Pedido listo para recoger ─────────────────────────
    public static function orderReady(string $toEmail, string $toName, string $orderNumber, string $businessName): bool {
        $body = "
            <div style='background:#f0f9ff;border-left:4px solid #4A90D9;border-radius:8px;padding:16px;margin-bottom:16px'>
                <strong style='color:#0369a1'>📦 Tu pedido está listo</strong>
            </div>
            <p style='color:#444;font-size:.9rem'><strong>{$businessName}</strong> terminó de preparar tu pedido <strong>#{$orderNumber}</strong>.</p>
            <p style='color:#666;font-size:.88rem'>En breve un repartidor recogerá tu pedido y lo llevará a tu dirección. 🛵</p>";

        return self::send(
            $toEmail, $toName,
            "📦 Tu pedido #{$orderNumber} está listo — NuevaExpress",
            self::template($toName, '📦', "¡Tu pedido está listo!", $body, 'Ver mis pedidos', 'https://nuevaexpress.com/cliente/index.html'),
            "Hola {$toName}, tu pedido #{$orderNumber} en {$businessName} está listo y pronto saldrá a entregarse."
        );
    }

    // ── 3. Pedido en camino ───────────────────────────────────
    public static function orderOnTheWay(string $toEmail, string $toName, string $orderNumber, string $repartidorName = ''): bool {
        $repartidor = $repartidorName ? "Tu repartidor <strong>{$repartidorName}</strong> ya recogió tu pedido." : "Tu pedido ya fue recogido.";
        $body = "
            <div style='background:#fefce8;border-left:4px solid #f59e0b;border-radius:8px;padding:16px;margin-bottom:16px'>
                <strong style='color:#b45309'>🛵 Tu pedido va en camino</strong>
            </div>
            <p style='color:#444;font-size:.9rem'>{$repartidor}</p>
            <p style='color:#666;font-size:.88rem'>Pronto llegará a tu dirección. ¡Prepárate para recibirlo! 🏠</p>";

        return self::send(
            $toEmail, $toName,
            "🛵 Tu pedido #{$orderNumber} va en camino — NuevaExpress",
            self::template($toName, '🛵', "¡Tu pedido va en camino!", $body, 'Ver mis pedidos', 'https://nuevaexpress.com/cliente/index.html'),
            "Hola {$toName}, tu pedido #{$orderNumber} ya fue recogido y va en camino a tu dirección."
        );
    }

    // ── 4. Pedido entregado ───────────────────────────────────
    public static function orderDelivered(string $toEmail, string $toName, string $orderNumber, string $businessName): bool {
        $body = "
            <div style='background:#f0fdf4;border-left:4px solid #22c55e;border-radius:8px;padding:16px;margin-bottom:16px'>
                <strong style='color:#15803d'>✔️ Pedido entregado exitosamente</strong>
            </div>
            <p style='color:#444;font-size:.9rem'>Tu pedido <strong>#{$orderNumber}</strong> de <strong>{$businessName}</strong> fue entregado.</p>
            <p style='color:#666;font-size:.88rem'>¡Gracias por elegir NuevaExpress! Esperamos que disfrutes tu pedido. 😊</p>
            <p style='color:#666;font-size:.88rem;margin-top:12px'>¿Tienes algún problema con tu pedido? Contáctanos al <a href='https://wa.me/50231586340' style='color:#4A90D9'>+502 3158-6340</a></p>";

        return self::send(
            $toEmail, $toName,
            "✔️ Tu pedido #{$orderNumber} fue entregado — NuevaExpress",
            self::template($toName, '✔️', "¡Pedido entregado!", $body, 'Hacer otro pedido', 'https://nuevaexpress.com'),
            "Hola {$toName}, tu pedido #{$orderNumber} de {$businessName} fue entregado exitosamente. ¡Gracias por usar NuevaExpress!"
        );
    }

    // ── Alerta de pedido disponible para repartidor ─────────
    public static function buildRepartidorAlert(string $name, string $detail): string {
        $body = "
            <div style='background:#fefce8;border-left:4px solid #f59e0b;border-radius:8px;padding:16px;margin-bottom:16px'>
                <strong style='color:#b45309'>🛵 Hay un nuevo pedido de delivery disponible</strong>
            </div>
            <p style='color:#444;font-size:.9rem'>{$detail}</p>
            <p style='color:#666;font-size:.88rem;margin-top:12px'>Ingresa a la app para tomarlo antes que otro repartidor.</p>";

        return self::template($name, '🛵', '¡Nuevo pedido disponible!', $body, 'Ver pedidos disponibles', 'https://nuevaexpress.com/repartidor/index.html');
    }

    // ── Verificación (existente) ──────────────────────────────
    public static function sendVerificationCode(string $toEmail, string $toName, string $code): bool {
        $body = "
            <p style='color:#666;font-size:.95rem;margin:0 0 32px'>Usa el siguiente código para verificar tu correo electrónico:</p>
            <div style='background:#f0f4ff;border:2px dashed #4A90D9;border-radius:12px;padding:24px;text-align:center;margin-bottom:24px'>
              <div style='font-size:2.8rem;font-weight:800;letter-spacing:12px;color:#1a1a2e;font-family:monospace'>{$code}</div>
            </div>
            <p style='color:#999;font-size:.85rem;text-align:center'>Este código es válido por <strong>15 minutos</strong>.<br>Si no solicitaste esto, ignora este correo.</p>";

        return self::send(
            $toEmail, $toName,
            'Tu código de verificación — NuevaExpress',
            self::template($toName, '🔐', 'Verifica tu correo', $body),
            "Hola {$toName}, tu código de verificación es: {$code}. Válido por 15 minutos."
        );
    }
}
