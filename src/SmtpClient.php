<?php

declare(strict_types=1);

namespace VolunteerLine;

final class SmtpClient
{
    private string $host;
    private int $port;
    private string $encryption; // 'starttls', 'tls', or 'none'
    private string $username;
    private string $password;
    private int $timeout;
    private bool $verifyPeer;
    private string $ehloHost;

    public function __construct(
        string $host,
        int $port = 587,
        string $encryption = 'starttls',
        string $username = '',
        string $password = '',
        int $timeout = 20,
        bool $verifyPeer = true,
        string $ehloHost = 'localhost'
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->encryption = strtolower($encryption);
        $this->username = $username;
        $this->password = $password;
        $this->timeout = $timeout;
        $this->verifyPeer = $verifyPeer;
        $this->ehloHost = $ehloHost !== '' ? $ehloHost : 'localhost';
    }

    /**
     * @param array<int, string> $toEmails
     * @param array<int, array{filename: string, path: string, mime?: string}> $attachments
     */
    public function send(
        string $fromEmail,
        string $fromName,
        array $toEmails,
        string $subject,
        string $textBody,
        array $attachments = []
    ): void {
        if ($toEmails === []) {
            throw new \InvalidArgumentException('At least one recipient email address is required.');
        }

        $contextOptions = [
            'ssl' => [
                'verify_peer' => $this->verifyPeer,
                'verify_peer_name' => $this->verifyPeer,
                'allow_self_signed' => !$this->verifyPeer,
            ],
        ];
        $context = stream_context_create($contextOptions);

        $scheme = $this->encryption === 'tls' ? 'ssl://' : 'tcp://';
        $remote = $scheme . $this->host . ':' . $this->port;

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client($remote, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new \RuntimeException("SMTP connection failed ({$remote}): [{$errno}] {$errstr}");
        }

        stream_set_timeout($socket, $this->timeout);

        try {
            $this->expect($socket, [220]);
            $ehloResp = $this->command($socket, 'EHLO ' . $this->ehloHost, [250]);

            if ($this->encryption === 'starttls') {
                $this->command($socket, 'STARTTLS', [220]);
                $cryptoMethod = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
                $enabled = @stream_socket_enable_crypto($socket, true, $cryptoMethod);
                if ($enabled !== true) {
                    throw new \RuntimeException('Failed to negotiate STARTTLS encryption.');
                }
                $ehloResp = $this->command($socket, 'EHLO ' . $this->ehloHost, [250]);
            }

            if ($this->username !== '') {
                $this->authenticate($socket, $ehloResp);
            }

            $this->command($socket, 'MAIL FROM:<' . $this->sanitizeAddress($fromEmail) . '>', [250]);
            foreach ($toEmails as $to) {
                $this->command($socket, 'RCPT TO:<' . $this->sanitizeAddress($to) . '>', [250, 251]);
            }

            $this->command($socket, 'DATA', [354]);

            $rawMessage = $this->buildMimeMessage($fromEmail, $fromName, $toEmails, $subject, $textBody, $attachments);
            // Dot-stuff lines beginning with '.' per RFC 5321
            $dotStuffed = preg_replace('/^\./m', '..', $rawMessage) ?? $rawMessage;

            $this->writeAll($socket, $dotStuffed . "\r\n.\r\n");
            $this->expect($socket, [250]);

            $this->command($socket, 'QUIT', [221]);
        } finally {
            @fclose($socket);
        }
    }

    /**
     * @param resource $socket
     */
    private function authenticate($socket, string $ehloResp): void
    {
        $upper = strtoupper($ehloResp);
        if (str_contains($upper, 'AUTH') && !str_contains($upper, 'LOGIN') && str_contains($upper, 'PLAIN')) {
            $token = base64_encode("\0" . $this->username . "\0" . $this->password);
            $this->command($socket, 'AUTH PLAIN ' . $token, [235]);

            return;
        }

        $this->command($socket, 'AUTH LOGIN', [334]);
        $this->command($socket, base64_encode($this->username), [334]);
        $this->command($socket, base64_encode($this->password), [235]);
    }

    /**
     * @param resource $socket
     * @param array<int, int> $expectedCodes
     */
    private function command($socket, string $cmd, array $expectedCodes): string
    {
        $this->writeAll($socket, $cmd . "\r\n");

        return $this->expect($socket, $expectedCodes);
    }

    /**
     * @param resource $socket
     * @param array<int, int> $expectedCodes
     */
    private function expect($socket, array $expectedCodes): string
    {
        [$code, $body] = $this->readResponse($socket);
        if (!in_array($code, $expectedCodes, true)) {
            throw new \RuntimeException("Unexpected SMTP response (code {$code}): {$body}");
        }

        return $body;
    }

    /**
     * @param resource $socket
     * @return array{0: int, 1: string}
     */
    private function readResponse($socket): array
    {
        $lines = [];
        $code = 0;

        while (($line = fgets($socket, 8192)) !== false) {
            $lines[] = rtrim($line, "\r\n");
            if (strlen($line) >= 3 && ctype_digit(substr($line, 0, 3))) {
                $code = (int) substr($line, 0, 3);
                if (strlen($line) < 4 || $line[3] === ' ') {
                    break;
                }
            } else {
                break;
            }
        }

        if ($lines === []) {
            $meta = stream_get_meta_data($socket);
            if (!empty($meta['timed_out'])) {
                throw new \RuntimeException('SMTP read timed out.');
            }
            throw new \RuntimeException('SMTP connection closed unexpectedly.');
        }

        return [$code, implode("\n", $lines)];
    }

    /**
     * @param resource $socket
     */
    private function writeAll($socket, string $data): void
    {
        $total = strlen($data);
        $written = 0;
        while ($written < $total) {
            $bytes = @fwrite($socket, substr($data, $written));
            if ($bytes === false || $bytes === 0) {
                throw new \RuntimeException('Failed to write to SMTP socket.');
            }
            $written += $bytes;
        }
    }

    /**
     * @param array<int, string> $toEmails
     * @param array<int, array{filename: string, path: string, mime?: string}> $attachments
     */
    private function buildMimeMessage(
        string $fromEmail,
        string $fromName,
        array $toEmails,
        string $subject,
        string $textBody,
        array $attachments
    ): string {
        $fromHeader = $fromName !== ''
            ? $this->encodeHeader($fromName) . ' <' . $this->sanitizeAddress($fromEmail) . '>'
            : '<' . $this->sanitizeAddress($fromEmail) . '>';

        $toHeader = implode(', ', array_map(
            fn (string $addr): string => '<' . $this->sanitizeAddress($addr) . '>',
            $toEmails
        ));

        $domain = substr(strrchr($fromEmail, '@') ?: '@localhost', 1);
        $messageId = sprintf('<%s.%s@%s>', bin2hex(random_bytes(8)), (string) time(), $domain);

        $headers = [
            'Date: ' . gmdate('r'),
            'From: ' . $fromHeader,
            'To: ' . $toHeader,
            'Subject: ' . $this->encodeHeader($subject),
            'Message-ID: ' . $messageId,
            'MIME-Version: 1.0',
        ];

        $normalizedBody = str_replace(["\r\n", "\r", "\n"], "\r\n", $textBody);

        if ($attachments === []) {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: base64';

            return implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($normalizedBody));
        }

        $boundary = '=_VL_' . bin2hex(random_bytes(16));
        $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';

        $parts = [];
        $parts[] = '--' . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($normalizedBody));

        foreach ($attachments as $att) {
            $path = $att['path'];
            $content = @file_get_contents($path);
            if ($content === false) {
                throw new \RuntimeException("Unable to read attachment file: {$path}");
            }
            $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $att['filename']) ?: 'attachment.wav';
            $mime = $att['mime'] ?? 'audio/wav';

            $parts[] = '--' . $boundary . "\r\n"
                . "Content-Type: {$mime}; name=\"{$filename}\"\r\n"
                . "Content-Transfer-Encoding: base64\r\n"
                . "Content-Disposition: attachment; filename=\"{$filename}\"\r\n\r\n"
                . chunk_split(base64_encode($content));
        }

        $parts[] = '--' . $boundary . '--';

        return implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $parts);
    }

    private function encodeHeader(string $value): string
    {
        $clean = str_replace(["\r", "\n"], '', $value);
        if (preg_match('/[^\x20-\x7E]/', $clean) === 1) {
            return '=?UTF-8?B?' . base64_encode($clean) . '?=';
        }

        return $clean;
    }

    private function sanitizeAddress(string $email): string
    {
        return trim(str_replace(["\r", "\n", '<', '>'], '', $email));
    }
}
