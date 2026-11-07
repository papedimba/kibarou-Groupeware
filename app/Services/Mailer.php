<?php

declare(strict_types=1);

namespace App\Services;

use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;

/**
 * Envoi SMTP via PHPMailer (appelé uniquement par la tâche CRON).
 * Sans MAIL_HOST configuré, les emails sont écrits dans storage/logs/mail.log (développement).
 */
final class Mailer
{
    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config, private readonly string $logDir)
    {
    }

    public function send(string $to, string $subject, string $body): void
    {
        if (empty($this->config['host'])) {
            $entry = sprintf("--- %s\nTo: %s\nSubject: %s\n\n%s\n", gmdate('c'), $to, $subject, $body);
            if (@file_put_contents($this->logDir . '/mail.log', $entry, FILE_APPEND | LOCK_EX) === false) {
                throw new RuntimeException('Impossible d\'écrire mail.log');
            }
            return;
        }
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = (string) $this->config['host'];
        $mail->Port = (int) $this->config['port'];
        $mail->SMTPAuth = !empty($this->config['username']);
        $mail->Username = (string) $this->config['username'];
        $mail->Password = (string) $this->config['password'];
        $mail->SMTPSecure = $this->config['encryption'] === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Timeout = 15;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom((string) $this->config['from_address'], (string) $this->config['from_name']);
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->isHTML(false);
        $mail->send();
    }
}
