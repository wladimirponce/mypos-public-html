<?php

declare(strict_types=1);

namespace Mypos\Services;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

final class MailService
{
    private function getMailer(): PHPMailer
    {
        $mail = new PHPMailer(true);

        // Server settings
        $mail->isSMTP();
        $mail->Host       = 'mail.mypos.cl';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'pagos@mypos.cl';
        $mail->Password   = 'FeActiva3342';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = 465;

        // Default Sender
        $mail->setFrom('pagos@mypos.cl', 'MyPOS Admin');

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
