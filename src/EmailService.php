<?php

declare(strict_types=1);

namespace VolunteerLine;

final class EmailService implements EmailServiceInterface
{
    /** @var array<string, mixed> */
    private array $config;
    private Logger $logger;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config, Logger $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function sendCallAndWin(array $data): void
    {
        $recipients = Helpers::normalizeEmailList($this->config['call_and_win_email_to'] ?? []);
        $teamNumber = (string) ($data['team_number'] ?? '');
        $callCount = (string) ($data['call_count'] ?? '');
        $callerId = (string) ($data['caller_id'] ?? 'Unavailable');
        $timestamp = (string) ($data['timestamp'] ?? Helpers::formatEasternNow((string) ($this->config['timezone'] ?? 'America/New_York')));
        $callId = (string) ($data['orig_call_id'] ?? '');

        $subject = "Call & Win Submission - Team {$teamNumber}";
        $body = implode("\n", array_filter([
            'New Call & Win submission received:',
            '',
            "Team Number: {$teamNumber}",
            "Number of Calls: {$callCount}",
            "Caller ID: {$callerId}",
            "Date and Time: {$timestamp}",
            $callId !== '' ? "Call ID: {$callId}" : null,
        ], static fn (?string $line): bool => $line !== null));

        $this->createSmtpClient()->send(
            (string) $this->config['smtp_from_email'],
            (string) ($this->config['smtp_from_name'] ?? 'Volunteer Line'),
            $recipients,
            $subject,
            $body
        );

        $this->logger->info('Call & Win email sent.', [
            'to' => $recipients,
            'team_number' => $teamNumber,
            'call_count' => $callCount,
            'caller_id' => $callerId,
            'timestamp' => $timestamp,
            'orig_call_id' => $callId,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function sendReferAndWin(array $data, ?string $wavPath = null): void
    {
        $recipients = Helpers::normalizeEmailList($this->config['refer_and_win_email_to'] ?? []);
        $teamNumber = (string) ($data['team_number'] ?? '');
        $friendPhone = (string) ($data['friend_phone'] ?? '');
        $callerId = (string) ($data['caller_id'] ?? 'Unavailable');
        $timestamp = (string) ($data['timestamp'] ?? Helpers::formatEasternNow((string) ($this->config['timezone'] ?? 'America/New_York')));
        $callId = (string) ($data['orig_call_id'] ?? '');

        $subject = "Refer & Win Submission - Team {$teamNumber}";
        $hasAttachment = $wavPath !== null && is_file($wavPath) && filesize($wavPath) > 0;

        $body = implode("\n", array_filter([
            'New Refer & Win submission received:',
            '',
            "Team Number: {$teamNumber}",
            "Friend's Phone Number: {$friendPhone}",
            "Caller ID: {$callerId}",
            "Date and Time: {$timestamp}",
            $callId !== '' ? "Call ID: {$callId}" : null,
            $hasAttachment ? 'Voice Message: Attached (.wav)' : 'Voice Message: Not available',
        ], static fn (?string $line): bool => $line !== null));

        $attachments = [];
        if ($hasAttachment) {
            $safeTeam = Helpers::digitsOnly($teamNumber) ?: 'unknown';
            $attachments[] = [
                'filename' => "refer-and-win-team-{$safeTeam}.wav",
                'path' => $wavPath,
                'mime' => 'audio/wav',
            ];
        }

        $this->createSmtpClient()->send(
            (string) $this->config['smtp_from_email'],
            (string) ($this->config['smtp_from_name'] ?? 'Volunteer Line'),
            $recipients,
            $subject,
            $body,
            $attachments
        );

        $this->logger->info('Refer & Win email sent.', [
            'to' => $recipients,
            'team_number' => $teamNumber,
            'friend_phone' => $friendPhone,
            'caller_id' => $callerId,
            'timestamp' => $timestamp,
            'orig_call_id' => $callId,
            'attachment' => $hasAttachment ? basename($wavPath) : null,
        ]);
    }

    private function createSmtpClient(): SmtpClient
    {
        $appHost = (string) (parse_url((string) ($this->config['app_base_url'] ?? ''), PHP_URL_HOST) ?: 'localhost');

        return new SmtpClient(
            (string) $this->config['smtp_host'],
            (int) ($this->config['smtp_port'] ?? 587),
            (string) ($this->config['smtp_encryption'] ?? 'starttls'),
            (string) ($this->config['smtp_username'] ?? ''),
            (string) ($this->config['smtp_password'] ?? ''),
            (int) ($this->config['smtp_timeout'] ?? 20),
            (bool) ($this->config['smtp_verify_peer'] ?? true),
            $appHost
        );
    }
}
