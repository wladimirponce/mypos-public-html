<?php

declare(strict_types=1);

namespace Mypos\Services;

use Mypos\Support\Env;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

final class MailService
{
    private function getMailer(): PHPMailer
    {
        $mail = new PHPMailer(true);

        $mail->isSMTP();
        $mail->Host       = (string) Env::get('MAIL_HOST', 'mail.mypos.cl');
        $mail->SMTPAuth   = true;
        $mail->Username   = (string) Env::get('MAIL_USERNAME', '');
        $mail->Password   = (string) Env::get('MAIL_PASSWORD', '');
        $mail->SMTPSecure = (string) Env::get('MAIL_ENCRYPTION', 'ssl') === 'tls'
            ? PHPMailer::ENCRYPTION_STARTTLS
            : PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = Env::int('MAIL_PORT', 465);
        $mail->CharSet    = 'UTF-8';

        if ($mail->Username === '' || $mail->Password === '') {
            throw new Exception('MAIL_USERNAME y MAIL_PASSWORD no configurados');
        }

        $mail->setFrom(
            (string) Env::get('MAIL_FROM_ADDRESS', $mail->Username),
            (string) Env::get('MAIL_FROM_NAME', 'MyPOS Admin')
        );

        return $mail;
    }

    public function enviarCorreoBienvenida(string $toEmail, string $nombreUsuario, string $razonSocial): void
    {
        try {
            $mail = $this->getMailer();
            $mail->addAddress($toEmail, $nombreUsuario);

            $mail->isHTML(true);
            $mail->Subject = 'Bienvenido a MyPOS';
            $mail->Body    = "
                <h2>Hola {$nombreUsuario},</h2>
                <p>Bienvenido a MyPOS. Hemos registrado exitosamente tu empresa <strong>{$razonSocial}</strong>.</p>
                <p>Ahora puedes acceder a la plataforma para continuar con la configuracion de tu cuenta.</p>
                <p>Saludos,<br>Equipo MyPOS</p>
            ";

            $mail->send();
        } catch (Exception $e) {
            error_log("Error al enviar correo de bienvenida: {$e->getMessage()}");
        }
    }

    public function enviarCorreoVerificacion(string $toEmail, string $nombreUsuario, string $razonSocial, string $token): void
    {
        try {
            $mail = $this->getMailer();
            $mail->addAddress($toEmail, $nombreUsuario);

            $frontendUrl = $_ENV['FRONTEND_URL'] ?? getenv('FRONTEND_URL') ?: 'https://www.mypos.cl';
            $verifyUrl = rtrim($frontendUrl, '/') . '/verify-email?token=' . urlencode($token);
            $year = date('Y');

            $mail->isHTML(true);
            $mail->Subject = 'Verifica tu cuenta - MyPOS';
            $mail->Body    = '
                <div style="background-color: #f8fafc; padding: 40px 20px; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif;">
                    <div style="max-width: 500px; margin: 0 auto; background-color: #ffffff; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);">
                        <!-- Header -->
                        <div style="background-color: #1e1b4b; padding: 24px; text-align: center;">
                            <img src="https://www.mypos.cl/img/isotipo.png" alt="MyPOS Logo" style="height: 48px; width: auto; vertical-align: middle;" />
                            <span style="color: #ffffff; font-size: 24px; font-weight: 800; letter-spacing: -0.5px; margin-left: 8px; font-family: inherit; vertical-align: middle;">My<span style="color: #f97316;">POS</span></span>
                        </div>
                        <!-- Content -->
                        <div style="padding: 32px 24px; text-align: center; color: #334155;">
                            <h2 style="color: #0f172a; font-size: 20px; font-weight: 700; margin-top: 0; margin-bottom: 16px;">¡Hola, ' . htmlspecialchars($nombreUsuario) . '!</h2>
                            <p style="font-size: 15px; line-height: 1.6; margin-bottom: 24px;">Gracias por registrarte en <strong>MyPOS</strong>. Para activar tu cuenta y poder avanzar en el registro de tu empresa <strong>' . htmlspecialchars($razonSocial) . '</strong>, necesitamos verificar tu dirección de correo electrónico.</p>
                            
                            <!-- Button -->
                            <div style="margin: 32px 0;">
                                <a href="' . $verifyUrl . '" style="background-color: #f97316; color: #ffffff; text-decoration: none; padding: 14px 32px; font-size: 15px; font-weight: 600; border-radius: 8px; box-shadow: 0 4px 6px -1px rgba(249, 115, 22, 0.2); display: inline-block;">Verificar correo electrónico</a>
                            </div>
                            
                            <p style="font-size: 13px; line-height: 1.6; color: #64748b; margin-top: 32px; margin-bottom: 0;">Si el botón no funciona, puedes copiar y pegar el siguiente enlace en tu navegador:<br><a href="' . $verifyUrl . '" style="color: #6366f1; text-decoration: underline; word-break: break-all;">' . $verifyUrl . '</a></p>
                        </div>
                        <!-- Footer -->
                        <div style="background-color: #f1f5f9; padding: 20px; text-align: center; font-size: 12px; color: #64748b; border-top: 1px solid #e2e8f0;">
                            <p style="margin: 0 0 8px 0;">Desarrollado por <strong>Agentika SpA</strong> · Hecho en Chile 🇨🇱</p>
                            <p style="margin: 0;">© ' . $year . ' MyPOS. Todos los derechos reservados.</p>
                        </div>
                    </div>
                </div>
            ';

            $mail->send();
        } catch (Exception $e) {
            error_log("Error al enviar correo de verificación: {$e->getMessage()}");
        }
    }

    public function enviarDte(
        string $toEmail,
        string $nombreCliente,
        string $tipoDocumento,
        int    $folio,
        string $razonSocialEmpresa,
        int    $total,
        string $fecha,
        string $rutEmpresa = '',
    ): void {
        try {
            $mail = $this->getMailer();
            $mail->addAddress($toEmail, $nombreCliente);

            $tipoLabel = match ($tipoDocumento) {
                'BOLETA'  => 'Boleta Electrónica',
                'FACTURA' => 'Factura Electrónica',
                default   => $tipoDocumento,
            };

            $folioStr   = $folio > 0 ? 'N° ' . number_format($folio, 0, '', '') : '';
            $totalStr   = '$' . number_format($total, 0, ',', '.');
            [$y, $m, $d] = array_pad(explode('-', $fecha), 3, '');
            $fechaLocal = $d && $m && $y ? "{$d}/{$m}/{$y}" : $fecha;
            $year       = date('Y');

            $mail->isHTML(true);
            $mail->Subject = "{$tipoLabel} {$folioStr} — {$razonSocialEmpresa}";
            $mail->Body    = '
<div style="background-color:#f8fafc;padding:40px 20px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;">
  <div style="max-width:520px;margin:0 auto;background:#ffffff;border-radius:12px;border:1px solid #e2e8f0;overflow:hidden;box-shadow:0 4px 6px -1px rgba(0,0,0,0.05);">
    <!-- Header -->
    <div style="background-color:#1e1b4b;padding:24px;text-align:center;">
      <img src="https://www.mypos.cl/img/isotipo.png" alt="MyPOS" style="height:40px;width:auto;vertical-align:middle;" />
      <span style="color:#fff;font-size:22px;font-weight:800;letter-spacing:-0.5px;margin-left:8px;vertical-align:middle;">My<span style="color:#f97316;">POS</span></span>
    </div>
    <!-- Body -->
    <div style="padding:32px 28px;color:#334155;">
      <p style="font-size:15px;margin-top:0;">Hola <strong>' . htmlspecialchars($nombreCliente) . '</strong>,</p>
      <p style="font-size:15px;line-height:1.6;margin-bottom:24px;">
        <strong>' . htmlspecialchars($razonSocialEmpresa) . '</strong> te ha emitido un documento tributario.
      </p>
      <!-- Document card -->
      <div style="background:#f1f5f9;border-radius:10px;padding:20px 24px;margin-bottom:24px;">
        <table style="width:100%;border-collapse:collapse;font-size:14px;">
          <tr><td style="padding:5px 0;color:#64748b;">Documento</td>
              <td style="padding:5px 0;text-align:right;font-weight:600;color:#0f172a;">' . htmlspecialchars($tipoLabel) . ' ' . htmlspecialchars($folioStr) . '</td></tr>
          <tr><td style="padding:5px 0;color:#64748b;">Fecha</td>
              <td style="padding:5px 0;text-align:right;color:#0f172a;">' . htmlspecialchars($fechaLocal) . '</td></tr>
          ' . ($rutEmpresa !== '' ? '<tr><td style="padding:5px 0;color:#64748b;">RUT Emisor</td>
              <td style="padding:5px 0;text-align:right;color:#0f172a;">' . htmlspecialchars($rutEmpresa) . '</td></tr>' : '') . '
          <tr><td style="padding:10px 0 0;font-size:16px;font-weight:700;color:#0f172a;border-top:1px solid #cbd5e1;">Total</td>
              <td style="padding:10px 0 0;text-align:right;font-size:20px;font-weight:800;color:#1e1b4b;border-top:1px solid #cbd5e1;">' . htmlspecialchars($totalStr) . '</td></tr>
        </table>
      </div>
      <p style="font-size:13px;color:#64748b;margin-bottom:0;">
        Este es un comprobante automático. Si tienes dudas, comunícate directamente con <strong>' . htmlspecialchars($razonSocialEmpresa) . '</strong>.
      </p>
    </div>
    <!-- Footer -->
    <div style="background:#f1f5f9;padding:16px 24px;text-align:center;font-size:12px;color:#64748b;border-top:1px solid #e2e8f0;">
      <p style="margin:0 0 4px;">Procesado por <strong>MyPOS</strong> · Desarrollado por Agentika SpA · Hecho en Chile 🇨🇱</p>
      <p style="margin:0;">© ' . $year . ' MyPOS. Todos los derechos reservados.</p>
    </div>
  </div>
</div>';

            $mail->send();
        } catch (\Throwable $e) {
            error_log('[MailService] enviarDte error: ' . $e->getMessage());
        }
    }

    public function enviarBoletaPago(string $toEmail, string $nombreUsuario, float $monto, array $dteData = []): void
    {
        try {
            $mail = $this->getMailer();
            $mail->addAddress($toEmail, $nombreUsuario);

            $mail->isHTML(true);
            $mail->Subject = 'Comprobante de Pago MyPOS';
            $mail->Body    = "
                <h2>Hola {$nombreUsuario},</h2>
                <p>Hemos recibido el pago exitosamente.</p>
                <p>Monto pagado: <strong>$" . number_format($monto, 0, ',', '.') . " CLP</strong></p>
                <p>Adjunto a este correo encontraras la Boleta Electronica correspondiente a tu pago.</p>
                <p>Saludos,<br>Equipo MyPOS</p>
            ";

            // If we have a PDF file path in $dteData, we could attach it.
            // For now, we just simulate sending the email if no actual PDF is passed.
            if (!empty($dteData['pdf_path']) && file_exists($dteData['pdf_path'])) {
                $mail->addAttachment($dteData['pdf_path'], 'Boleta.pdf');
            }

            $mail->send();
        } catch (Exception $e) {
            error_log("Error al enviar boleta por correo: {$e->getMessage()}");
        }
    }
}
