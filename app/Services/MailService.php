<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Envio de e-mail via SMTP (se configurado) ou mail() do PHP.
 *
 * Env vars:
 *   PORTAL_MAIL_FROM, PORTAL_MAIL_FROM_NAME
 *   PORTAL_SMTP_HOST, PORTAL_SMTP_PORT, PORTAL_SMTP_USER, PORTAL_SMTP_PASS
 *   PORTAL_SMTP_SECURE (tls|ssl|none)
 */
class MailService
{
    private string $fromEmail;
    private string $fromName;
    private string $smtpHost;
    private int $smtpPort;
    private string $smtpUser;
    private string $smtpPass;
    private string $smtpSecure;

    public function __construct(?array $mailConfig = null)
    {
        $mailConfig = $mailConfig ?? [];

        $this->fromEmail = trim((string) ($mailConfig['from'] ?? getenv('PORTAL_MAIL_FROM') ?: 'noreply@portal-wct.local'));
        $this->fromName = trim((string) ($mailConfig['from_name'] ?? getenv('PORTAL_MAIL_FROM_NAME') ?: 'Portal WCT — Tasks'));
        $this->smtpHost = trim((string) ($mailConfig['smtp_host'] ?? getenv('PORTAL_SMTP_HOST') ?: ''));
        $this->smtpPort = (int) ($mailConfig['smtp_port'] ?? getenv('PORTAL_SMTP_PORT') ?: 587);
        $this->smtpUser = trim((string) ($mailConfig['smtp_user'] ?? getenv('PORTAL_SMTP_USER') ?: ''));
        $this->smtpPass = (string) ($mailConfig['smtp_pass'] ?? (getenv('PORTAL_SMTP_PASS') !== false ? getenv('PORTAL_SMTP_PASS') : ''));
        $this->smtpSecure = strtolower(trim((string) ($mailConfig['smtp_secure'] ?? getenv('PORTAL_SMTP_SECURE') ?: 'tls')));
    }

    /**
     * @return array{ok: bool, error: string|null}
     */
    public function send(string $to, string $subject, string $bodyText, ?string $bodyHtml = null): array
    {
        $to = trim($to);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'E-mail do destinatário inválido.'];
        }

        if ($this->smtpHost !== '') {
            try {
                $this->sendSmtp($to, $subject, $bodyText, $bodyHtml);

                return ['ok' => true, 'error' => null];
            } catch (\Throwable $e) {
                return ['ok' => false, 'error' => 'SMTP: ' . $e->getMessage()];
            }
        }

        $headers = [
            'MIME-Version: 1.0',
            'From: ' . $this->encodeAddress($this->fromEmail, $this->fromName),
            'Reply-To: ' . $this->fromEmail,
            'X-Mailer: Portal-WCT',
        ];

        if ($bodyHtml !== null && $bodyHtml !== '') {
            $boundary = 'b_' . bin2hex(random_bytes(8));
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
            $message = "--{$boundary}\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n\r\n"
                . $bodyText . "\r\n"
                . "--{$boundary}\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\n\r\n"
                . $bodyHtml . "\r\n"
                . "--{$boundary}--\r\n";
        } else {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $message = $bodyText;
        }

        $ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $message, implode("\r\n", $headers));

        return $ok
            ? ['ok' => true, 'error' => null]
            : ['ok' => false, 'error' => 'Falha no mail() do PHP. Configure PORTAL_SMTP_HOST no ambiente.'];
    }

    private function sendSmtp(string $to, string $subject, string $bodyText, ?string $bodyHtml): void
    {
        $remote = ($this->smtpSecure === 'ssl' ? 'ssl://' : '') . $this->smtpHost;
        $errno = 0;
        $errstr = '';
        $fp = @fsockopen($remote, $this->smtpPort, $errno, $errstr, 20);
        if (!$fp) {
            throw new \RuntimeException("não conectou em {$this->smtpHost}:{$this->smtpPort} ({$errstr})");
        }
        stream_set_timeout($fp, 20);

        $this->expectSmtp($fp, 220);
        $this->smtpCmd($fp, 'EHLO portal-wct.local', 250);

        if ($this->smtpSecure === 'tls') {
            $this->smtpCmd($fp, 'STARTTLS', 220);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new \RuntimeException('STARTTLS falhou.');
            }
            $this->smtpCmd($fp, 'EHLO portal-wct.local', 250);
        }

        if ($this->smtpUser !== '') {
            $this->smtpCmd($fp, 'AUTH LOGIN', 334);
            $this->smtpCmd($fp, base64_encode($this->smtpUser), 334);
            $this->smtpCmd($fp, base64_encode($this->smtpPass), 235);
        }

        $this->smtpCmd($fp, 'MAIL FROM:<' . $this->fromEmail . '>', 250);
        $this->smtpCmd($fp, 'RCPT TO:<' . $to . '>', 250);
        $this->smtpCmd($fp, 'DATA', 354);

        $boundary = 'b_' . bin2hex(random_bytes(8));
        $headers = [
            'Date: ' . date('r'),
            'From: ' . $this->encodeAddress($this->fromEmail, $this->fromName),
            'To: <' . $to . '>',
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];

        $data = implode("\r\n", $headers) . "\r\n\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n\r\n"
            . $this->dotStuff($bodyText) . "\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n\r\n"
            . $this->dotStuff($bodyHtml ?? nl2br(htmlspecialchars($bodyText, ENT_QUOTES, 'UTF-8'))) . "\r\n"
            . "--{$boundary}--\r\n"
            . ".\r\n";

        fwrite($fp, $data);
        $this->expectSmtp($fp, 250);
        $this->smtpCmd($fp, 'QUIT', 221);
        fclose($fp);
    }

    private function smtpCmd($fp, string $cmd, int $expectCode): void
    {
        fwrite($fp, $cmd . "\r\n");
        $this->expectSmtp($fp, $expectCode);
    }

    private function expectSmtp($fp, int $expectCode): void
    {
        $response = '';
        while (($line = fgets($fp, 512)) !== false) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        $code = (int) substr($response, 0, 3);
        if ($code !== $expectCode) {
            throw new \RuntimeException("resposta SMTP {$code} (esperado {$expectCode}): " . trim($response));
        }
    }

    private function encodeAddress(string $email, string $name): string
    {
        if ($name === '') {
            return '<' . $email . '>';
        }

        return '=?UTF-8?B?' . base64_encode($name) . '?= <' . $email . '>';
    }

    private function dotStuff(string $body): string
    {
        return preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $body)) ?? $body;
    }
}
