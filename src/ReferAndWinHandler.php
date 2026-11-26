<?php

declare(strict_types=1);

namespace VolunteerLine;

final class ReferAndWinHandler
{
    /** @var array<string, mixed> */
    private array $config;
    private SessionStore $sessions;
    private Logger $logger;
    private EmailServiceInterface $emailService;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        array $config,
        SessionStore $sessions,
        Logger $logger,
        EmailServiceInterface $emailService
    ) {
        $this->config = $config;
        $this->sessions = $sessions;
        $this->logger = $logger;
        $this->emailService = $emailService;
    }

    /**
     * Handle an HTTP request from NetSapiens Web Responder and return XML.
     *
     * @param array<string, string> $params
     */
    public function handle(array $params): string
    {
        $step = $params['step'] ?? 'start';
        $sid = Helpers::sanitizeToken($params['sid'] ?? '');
        $rawDigits = $params['Digits'] ?? '';
        $ani = $params['NmsAni'] ?? '';
        $origCallId = $params['OrigCallID'] ?? '';

        $xml = new XmlResponse(
            (string) ($this->config['tts_voice'] ?? 'male'),
            (string) ($this->config['tts_language'] ?? 'en-US')
        );

        if ($step === 'start') {
            return $this->handleStart($ani, $origCallId, $xml);
        }

        if ($sid === '') {
            $this->logger->warning('Refer & Win request missing session id.', ['step' => $step]);
            return $xml->say('Sorry, your session could not be found. Goodbye.')->hangup()->toXml();
        }

        $session = $this->sessions->get($sid);
        if ($session === null) {
            $this->logger->warning('Refer & Win session not found or expired.', ['sid' => $sid, 'step' => $step]);
            return $xml->say('Sorry, your call session has expired. Goodbye.')->hangup()->toXml();
        }

        return match ($step) {
            'team_entered' => $this->handleTeamEntered($sid, $session, $rawDigits, $xml),
            'team_confirmed' => $this->handleTeamConfirmed($sid, $session, $rawDigits, $xml),
            'phone_entered' => $this->handlePhoneEntered($sid, $session, $rawDigits, $xml),
            'phone_confirmed' => $this->handlePhoneConfirmed($sid, $session, $rawDigits, $xml),
            'record_done' => $this->handleRecordDone($sid, $session, $rawDigits, $xml),
            'recording_confirmed' => $this->handleRecordingConfirmed($sid, $session, $rawDigits, $xml),
            'finalize' => $this->finalizeReferral($sid, $xml),
            default => $this->handleUnknownStep($sid, $step, $xml),
        };
    }

    private function handleStart(string $ani, string $origCallId, XmlResponse $xml): string
    {
        $sid = Helpers::generateId(12);
        $timezone = (string) ($this->config['timezone'] ?? 'America/New_York');

        $session = [
            'sid' => $sid,
            'flow' => 'refer_and_win',
            'caller_id' => Helpers::formatPhone10($ani),
            'raw_ani' => $ani,
            'orig_call_id' => $origCallId,
            'started_at' => Helpers::formatEasternNow($timezone),
            'retries' => 0,
            'reentries' => 0,
            'team_number' => null,
            'friend_phone' => null,
            'current_recording_id' => null,
            'superseded_recordings' => [],
            'submitted' => false,
        ];
        $this->sessions->save($sid, $session);

        $this->logger->info('Refer & Win call started.', [
            'sid' => $sid,
            'caller_id' => $session['caller_id'],
            'orig_call_id' => $origCallId,
        ]);

        $maxDigits = (int) ($this->config['team_number_max_digits'] ?? 10);
        $prompt = str_replace(
            '{max_digits}',
            (string) $maxDigits,
            (string) ($this->config['prompts']['refer_and_win_welcome']
                ?? 'Welcome to Refer and Win. Please enter your team number, up to {max_digits} digits.')
        );

        return $this->gatherDigitsXml(
            $xml,
            $this->actionUrl('team_entered', $sid),
            $maxDigits,
            $prompt,
            true
        );
    }

    /**
     * @param array<string, mixed> $session
     */
    private function handleTeamEntered(string $sid, array $session, string $rawDigits, XmlResponse $xml): string
    {
        $digits = Helpers::digitsOnly($rawDigits);
        $maxDigits = (int) ($this->config['team_number_max_digits'] ?? 10);

        if ($digits === '' || strlen($digits) > $maxDigits) {
            return $this->retryOrHangup(
                $sid,
                $session,
                $xml,
                $this->actionUrl('team_entered', $sid),
                $maxDigits,
                true,
                "We did not receive a valid team number. Please enter your team number, up to {$maxDigits} digits.",
                'We did not receive a valid team number. Goodbye.'
            );
        }

        $this->sessions->update($sid, static function (?array $cur) use ($digits): ?array {
            if ($cur === null) {
                return null;
            }
            $cur['team_number'] = $digits;
            $cur['retries'] = 0;

            return $cur;
        });

        $spoken = Helpers::digitsForSpeech($digits);
        $prompt = "You entered team number: {$spoken}. Press 1 to confirm, or press 2 to re-enter.";

        return $this->gatherConfirmXml($xml, $this->actionUrl('team_confirmed', $sid), $prompt);
    }

    /**
     * @param array<string, mixed> $session
     */
    private function handleTeamConfirmed(string $sid, array $session, string $rawDigits, XmlResponse $xml): string
    {
        $choice = Helpers::digitsOnly($rawDigits);
        $teamMaxDigits = (int) ($this->config['team_number_max_digits'] ?? 10);
        $friendDigits = (int) ($this->config['friend_phone_digits'] ?? 10);

        if ($choice === '1') {
            $this->sessions->update($sid, static function (?array $cur): ?array {
                if ($cur === null) {
                    return null;
                }
                $cur['retries'] = 0;

                return $cur;
            });

            $prompt = (string) ($this->config['prompts']['enter_friend_phone']
                ?? 'Please enter your friend\'s 10 digit phone number.');

            return $this->gatherDigitsXml(
                $xml,
                $this->actionUrl('phone_entered', $sid),
                $friendDigits,
                $prompt,
                true
            );
        }

        if ($choice === '2') {
            if ($this->exceededReentries($sid, $session)) {
                $this->logger->warning('Refer & Win exceeded max re-entries on team number.', ['sid' => $sid]);
                return $xml->say('Maximum re-entries exceeded. Goodbye.')->hangup()->toXml();
            }

            return $this->gatherDigitsXml(
                $xml,
                $this->actionUrl('team_entered', $sid),
                $teamMaxDigits,
                "Please re-enter your team number, up to {$teamMaxDigits} digits.",
                true
            );
        }

        $spoken = Helpers::digitsForSpeech((string) ($session['team_number'] ?? ''));

        return $this->retryConfirmOrHangup(
            $sid,
            $session,
            $xml,
            $this->actionUrl('team_confirmed', $sid),
            "Invalid selection. You entered team number: {$spoken}. Press 1 to confirm, or press 2 to re-enter.",
            'We did not receive a valid confirmation. Goodbye.'
        );
    }

    /**
     * @param array<string, mixed> $session
     */
    private function handlePhoneEntered(string $sid, array $session, string $rawDigits, XmlResponse $xml): string
    {
        $digits = Helpers::digitsOnly($rawDigits);
        $expectedDigits = (int) ($this->config['friend_phone_digits'] ?? 10);

        if (strlen($digits) !== $expectedDigits) {
            return $this->retryOrHangup(
                $sid,
                $session,
                $xml,
                $this->actionUrl('phone_entered', $sid),
                $expectedDigits,
                true,
                "Invalid phone number. Please enter your friend's {$expectedDigits} digit phone number.",
                'We did not receive a valid phone number. Goodbye.'
            );
        }

        $this->sessions->update($sid, static function (?array $cur) use ($digits): ?array {
            if ($cur === null) {
                return null;
            }
            $cur['friend_phone'] = $digits;
            $cur['retries'] = 0;

            return $cur;
        });

        $spoken = Helpers::digitsForSpeech($digits);
        $prompt = "You entered phone number: {$spoken}. Press 1 to confirm, or press 2 to re-enter.";

        return $this->gatherConfirmXml($xml, $this->actionUrl('phone_confirmed', $sid), $prompt);
    }

    /**
     * @param array<string, mixed> $session
     */
    private function handlePhoneConfirmed(string $sid, array $session, string $rawDigits, XmlResponse $xml): string
    {
        $choice = Helpers::digitsOnly($rawDigits);
        $friendDigits = (int) ($this->config['friend_phone_digits'] ?? 10);

        if ($choice === '1') {
            return $this->startRecordingXml($sid, $xml, false);
        }

        if ($choice === '2') {
            if ($this->exceededReentries($sid, $session)) {
                $this->logger->warning('Refer & Win exceeded max re-entries on phone number.', ['sid' => $sid]);
                return $xml->say('Maximum re-entries exceeded. Goodbye.')->hangup()->toXml();
            }

            return $this->gatherDigitsXml(
                $xml,
                $this->actionUrl('phone_entered', $sid),
                $friendDigits,
                "Please re-enter your friend's {$friendDigits} digit phone number.",
                true
            );
        }

        $spoken = Helpers::digitsForSpeech((string) ($session['friend_phone'] ?? ''));

        return $this->retryConfirmOrHangup(
            $sid,
            $session,
            $xml,
            $this->actionUrl('phone_confirmed', $sid),
            "Invalid selection. You entered phone number: {$spoken}. Press 1 to confirm, or press 2 to re-enter.",
            'We did not receive a valid confirmation. Goodbye.'
        );
    }

    private function startRecordingXml(string $sid, XmlResponse $xml, bool $isRerecord): string
    {
        $recId = Helpers::generateId(10);
        $maxRecSeconds = max(5, (int) ($this->config['max_recording_seconds'] ?? 60));

        $updated = $this->sessions->update($sid, static function (?array $cur) use ($recId): ?array {
            if ($cur === null) {
                return null;
            }
            $prevId = $cur['current_recording_id'] ?? null;
            if (is_string($prevId) && $prevId !== '') {
                $superseded = is_array($cur['superseded_recordings'] ?? null) ? $cur['superseded_recordings'] : [];
                $superseded[$prevId] = true;
                $cur['superseded_recordings'] = $superseded;
            }
            $cur['current_recording_id'] = $recId;
            $cur['retries'] = 0;

            return $cur;
        });

        $wsBase = (string) ($this->config['ws_public_url'] ?? '');
        $sep = str_contains($wsBase, '?') ? '&' : '?';
        $wsUrl = $wsBase . $sep . 'rec=' . rawurlencode($recId) . '&sid=' . rawurlencode($sid);

        $instructions = $isRerecord
            ? 'Let us try again. Please say your friend\'s name and address, and press pound when finished.'
            : (string) ($this->config['prompts']['record_instructions']
                ?? 'Please leave a short voice message with your friend\'s name and address. Press pound when you are finished.');

        $startPrompt = (string) ($this->config['prompts']['record_start_beep_prompt']
            ?? 'Please begin speaking now, and press pound when finished.');

        $this->logger->info('Starting Refer & Win voice recording stream.', [
            'sid' => $sid,
            'rec_id' => $recId,
            'rerecord' => $isRerecord,
            'max_seconds' => $maxRecSeconds,
        ]);

        return $xml
            ->say($instructions)
            ->startStream('rec_' . $recId, $wsUrl, 'inbound', [
                'recId' => $recId,
                'sid' => $sid,
                'callId' => (string) ($updated['orig_call_id'] ?? ''),
                'maxSeconds' => (string) $maxRecSeconds,
            ])
            ->gather([
                'input' => 'dtmf',
                'numDigits' => 1,
                'timeout' => $maxRecSeconds,
                'action' => $this->actionUrl('record_done', $sid),
            ], static function (XmlResponse $nested) use ($startPrompt): void {
                $nested->say($startPrompt);
            })
            ->toXml();
    }

    /**
     * @param array<string, mixed> $session
     */
    private function handleRecordDone(string $sid, array $session, string $rawDigits, XmlResponse $xml): string
    {
        $recId = Helpers::sanitizeToken((string) ($session['current_recording_id'] ?? ''));
        if ($recId === '') {
            $this->logger->warning('record_done called without active recording id.', ['sid' => $sid]);
            return $xml->say('Sorry, there was a problem with your recording. Goodbye.')->hangup()->toXml();
        }

        $this->logger->info('Refer & Win recording finished.', [
            'sid' => $sid,
            'rec_id' => $recId,
            'digits' => $rawDigits,
        ]);

        $xml->stopStream('rec_' . $recId);

        $confirmBeforeSend = (bool) ($this->config['refer_confirm_before_send'] ?? true);
        if (!$confirmBeforeSend) {
            return $xml->say('Please hold while we submit your referral.', $this->actionUrl('finalize', $sid))
                ->toXml();
        }

        $secret = (string) ($this->config['recording_access_secret'] ?? '');
        $token = Helpers::signRecordingId($recId, $secret);
        $playUrl = Helpers::buildUrl(
            (string) ($this->config['app_base_url'] ?? ''),
            'recording.php?id=' . rawurlencode($recId) . '&token=' . rawurlencode($token)
        );

        return $xml
            ->say('Thank you. Here is your recorded message.')
            ->play($playUrl)
            ->gather([
                'input' => 'dtmf',
                'numDigits' => 1,
                'timeout' => (int) ($this->config['gather_timeout_seconds'] ?? 10),
                'action' => $this->actionUrl('recording_confirmed', $sid),
            ], static function (XmlResponse $nested): void {
                $nested->say('Press 1 to confirm and submit your referral, or press 2 to re-record your message.');
            })
            ->toXml();
    }

    /**
     * @param array<string, mixed> $session
     */
    private function handleRecordingConfirmed(string $sid, array $session, string $rawDigits, XmlResponse $xml): string
    {
        $choice = Helpers::digitsOnly($rawDigits);

        if ($choice === '1') {
            return $this->finalizeReferral($sid, $xml);
        }

        if ($choice === '2') {
            if ($this->exceededReentries($sid, $session)) {
                $this->logger->warning('Refer & Win exceeded max re-entries on recording.', ['sid' => $sid]);
                return $xml->say('Maximum re-recordings exceeded. Goodbye.')->hangup()->toXml();
            }

            $oldRecId = Helpers::sanitizeToken((string) ($session['current_recording_id'] ?? ''));
            if ($oldRecId !== '' && empty($this->config['keep_recordings'])) {
                $this->deleteRecordingFiles($oldRecId);
            }

            return $this->startRecordingXml($sid, $xml, true);
        }

        return $this->retryConfirmOrHangup(
            $sid,
            $session,
            $xml,
            $this->actionUrl('recording_confirmed', $sid),
            'Invalid selection. Press 1 to confirm and submit your referral, or press 2 to re-record your message.',
            'We did not receive a valid confirmation. Goodbye.'
        );
    }

    private function finalizeReferral(string $sid, XmlResponse $xml): string
    {
        $session = $this->sessions->get($sid);
        if ($session === null) {
            return $xml->say('Sorry, your call session has expired. Goodbye.')->hangup()->toXml();
        }

        $recId = Helpers::sanitizeToken((string) ($session['current_recording_id'] ?? ''));
        $wavPath = $recId !== '' ? $this->waitForWavFile($recId) : null;

        if ($wavPath === null || !is_file($wavPath) || (int) @filesize($wavPath) <= 44) {
            $this->logger->warning('Recording WAV missing or empty at referral finalization.', [
                'sid' => $sid,
                'rec_id' => $recId,
            ]);

            $maxRetries = (int) ($this->config['max_retries'] ?? 3);
            $retries = ((int) ($session['retries'] ?? 0)) + 1;
            $this->sessions->update($sid, static function (?array $cur) use ($retries): ?array {
                if ($cur === null) {
                    return null;
                }
                $cur['retries'] = $retries;

                return $cur;
            });

            if ($retries >= $maxRetries) {
                return $xml->say('Sorry, we could not capture your voice message. Goodbye.')->hangup()->toXml();
            }

            return $this->startRecordingXml($sid, $xml, true);
        }

        $timezone = (string) ($this->config['timezone'] ?? 'America/New_York');
        $now = Helpers::formatEasternNow($timezone);
        $alreadySubmitted = false;

        $updated = $this->sessions->update($sid, static function (?array $cur) use ($now, &$alreadySubmitted): ?array {
            if ($cur === null) {
                return null;
            }
            if (!empty($cur['submitted'])) {
                $alreadySubmitted = true;
                return $cur;
            }
            $cur['submitted'] = true;
            $cur['submitted_at'] = $now;

            return $cur;
        });

        if ($alreadySubmitted) {
            return $xml->say('Thank you! Your referral has been submitted. Goodbye.')->hangup()->toXml();
        }

        if ($updated === null) {
            return $xml->say('Sorry, your call session has expired. Goodbye.')->hangup()->toXml();
        }

        try {
            $this->emailService->sendReferAndWin([
                'team_number' => $updated['team_number'],
                'friend_phone' => $updated['friend_phone'],
                'caller_id' => $updated['caller_id'],
                'timestamp' => $now,
                'orig_call_id' => $updated['orig_call_id'] ?? '',
            ], $wavPath);

            if (empty($this->config['keep_recordings'])) {
                $this->deleteRecordingFiles($recId);
            }

            return $xml->say('Thank you! Your referral has been submitted. Goodbye.')->hangup()->toXml();
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send Refer & Win email.', [
                'sid' => $sid,
                'rec_id' => $recId,
                'error' => $e->getMessage(),
            ]);

            $this->sessions->update($sid, static function (?array $cur): ?array {
                if ($cur === null) {
                    return null;
                }
                $cur['submitted'] = false;
                $cur['last_email_error'] = true;

                return $cur;
            });

            return $xml->say('Sorry, there was a problem submitting your referral. Please try again later. Goodbye.')
                ->hangup()
                ->toXml();
        }
    }

    private function waitForWavFile(string $recId): ?string
    {
        $recDir = rtrim((string) ($this->config['storage_dir'] ?? (__DIR__ . '/../storage')), '/\\')
            . DIRECTORY_SEPARATOR . 'recordings';
        $wavPath = $recDir . DIRECTORY_SEPARATOR . $recId . '.wav';
        $ulawPath = $recDir . DIRECTORY_SEPARATOR . $recId . '.ulaw';

        $deadline = microtime(true) + 2.5;
        while (microtime(true) < $deadline) {
            if (is_file($wavPath) && (int) @filesize($wavPath) > 44) {
                return $wavPath;
            }
            usleep(50000);
        }

        // Fallback: convert raw .ulaw file if WebSocket server was still flushing
        if (is_file($ulawPath)) {
            $raw = @file_get_contents($ulawPath);
            if (is_string($raw) && $raw !== '') {
                WavConverter::writeMulawAsWavFile($raw, $wavPath);
                return $wavPath;
            }
        }

        return is_file($wavPath) ? $wavPath : null;
    }

    private function deleteRecordingFiles(string $recId): void
    {
        $recDir = rtrim((string) ($this->config['storage_dir'] ?? (__DIR__ . '/../storage')), '/\\')
            . DIRECTORY_SEPARATOR . 'recordings';
        @unlink($recDir . DIRECTORY_SEPARATOR . $recId . '.wav');
        @unlink($recDir . DIRECTORY_SEPARATOR . $recId . '.ulaw');
    }

    private function handleUnknownStep(string $sid, string $step, XmlResponse $xml): string
    {
        $this->logger->warning('Unknown step in Refer & Win.', ['sid' => $sid, 'step' => $step]);

        return $xml->say('Sorry, an unexpected error occurred. Goodbye.')->hangup()->toXml();
    }

    private function gatherDigitsXml(
        XmlResponse $xml,
        string $actionUrl,
        int $numDigits,
        string $prompt,
        bool $useDigitEndTimeout
    ): string {
        $attrs = [
            'input' => 'dtmf',
            'numDigits' => $numDigits,
            'timeout' => (int) ($this->config['gather_timeout_seconds'] ?? 10),
            'action' => $actionUrl,
        ];
        if ($useDigitEndTimeout) {
            $attrs['digitEndTimeout'] = (int) ($this->config['gather_digit_end_timeout_seconds'] ?? 3);
        }

        return $xml->gather($attrs, static function (XmlResponse $nested) use ($prompt): void {
            $nested->say($prompt);
        })->toXml();
    }

    private function gatherConfirmXml(XmlResponse $xml, string $actionUrl, string $prompt): string
    {
        return $this->gatherDigitsXml($xml, $actionUrl, 1, $prompt, false);
    }

    /**
     * @param array<string, mixed> $session
     */
    private function retryOrHangup(
        string $sid,
        array $session,
        XmlResponse $xml,
        string $actionUrl,
        int $numDigits,
        bool $useDigitEndTimeout,
        string $retryPrompt,
        string $hangupPrompt
    ): string {
        $maxRetries = (int) ($this->config['max_retries'] ?? 3);
        $retries = ((int) ($session['retries'] ?? 0)) + 1;

        $this->sessions->update($sid, static function (?array $cur) use ($retries): ?array {
            if ($cur === null) {
                return null;
            }
            $cur['retries'] = $retries;

            return $cur;
        });

        if ($retries >= $maxRetries) {
            $this->logger->info('Refer & Win max retries reached; hanging up.', [
                'sid' => $sid,
                'retries' => $retries,
            ]);

            return $xml->say($hangupPrompt)->hangup()->toXml();
        }

        return $this->gatherDigitsXml($xml, $actionUrl, $numDigits, $retryPrompt, $useDigitEndTimeout);
    }

    /**
     * @param array<string, mixed> $session
     */
    private function retryConfirmOrHangup(
        string $sid,
        array $session,
        XmlResponse $xml,
        string $actionUrl,
        string $retryPrompt,
        string $hangupPrompt
    ): string {
        return $this->retryOrHangup($sid, $session, $xml, $actionUrl, 1, false, $retryPrompt, $hangupPrompt);
    }

    /**
     * @param array<string, mixed> $session
     */
    private function exceededReentries(string $sid, array $session): bool
    {
        $maxReentries = (int) ($this->config['max_reentries'] ?? 10);
        $reentries = ((int) ($session['reentries'] ?? 0)) + 1;

        $this->sessions->update($sid, static function (?array $cur) use ($reentries): ?array {
            if ($cur === null) {
                return null;
            }
            $cur['reentries'] = $reentries;
            $cur['retries'] = 0;

            return $cur;
        });

        return $reentries > $maxReentries;
    }

    private function actionUrl(string $step, string $sid): string
    {
        $base = (string) ($this->config['app_base_url'] ?? '');

        return Helpers::buildUrl($base, 'refer-and-win.php?step=' . rawurlencode($step) . '&sid=' . rawurlencode($sid));
    }
}
