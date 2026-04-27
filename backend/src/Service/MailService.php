<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

class MailService
{
    public function sendOtpCode(string $toEmail, string $toName, string $otpCode): void
    {
        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host = $this->getEnv('MAIL_HOST');
            $mail->SMTPAuth = true;
            $mail->Username = $this->getEnv('MAIL_USERNAME');
            $mail->Password = $this->getEnv('MAIL_PASSWORD');
            $mail->SMTPSecure = $this->getEnv('MAIL_ENCRYPTION'); // tls / ssl
            $mail->Port = (int)$this->getEnv('MAIL_PORT');
            $mail->CharSet = 'UTF-8';

            $fromAddress = $this->getEnv('MAIL_FROM_ADDRESS');
            $fromName = $this->getEnv('MAIL_FROM_NAME');

            $mail->setFrom($fromAddress, $fromName);
            $mail->addAddress($toEmail, $toName ?: 'Usuario');

            $safeName = htmlspecialchars($toName ?: 'Usuario', ENT_QUOTES, 'UTF-8');
            $safeCode = htmlspecialchars($otpCode, ENT_QUOTES, 'UTF-8');

            $mail->isHTML(true);
            $mail->Subject = 'Tu código de verificación (2FA) - TourneyHub';
            $mail->Body = "
                <div style=\"font-family: Arial, sans-serif; max-width: 560px; margin: 0 auto;\">
                    <h2 style=\"color: #0f172a;\">Verificación en 2 pasos</h2>
                    <p>Hola <strong>{$safeName}</strong>,</p>
                    <p>Tu código de verificación para iniciar sesión en <strong>TourneyHub</strong> es:</p>
                    <div style=\"font-size: 28px; font-weight: 700; letter-spacing: 4px; color: #0369a1; margin: 12px 0;\">
                        {$safeCode}
                    </div>
                    <p>Este código caduca en 10 minutos.</p>
                    <p>Si no has intentado iniciar sesión, ignora este correo.</p>
                </div>
            ";
            $mail->AltBody = "Tu código de verificación (2FA) es: {$otpCode}. Caduca en 10 minutos.";

            $mail->send();
        } catch (MailException $e) {
            throw new RuntimeException('No se pudo enviar el correo de verificación: ' . $e->getMessage());
        }
    }

    private function getEnv(string $key): string
    {
        $value = $_ENV[$key] ?? '';
        if ($value === '') {
            throw new RuntimeException("Falta configurar {$key} en .env");
        }
        return $value;
    }
}