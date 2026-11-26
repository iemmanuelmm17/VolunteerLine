<?php

declare(strict_types=1);

namespace VolunteerLine;

final class CallAndWinHandler
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
            $this->logger->warning('Call & Win request missing session id.', ['step' => $step]);
            return $xml->say('Sorry, your session could not be found. Goodbye.')->hangup()->toXml();
        }

        $session = $this->sessions->get($sid);
        if ($session === null) {
            $this->logger->warning('Call & Win session not found or expired.', ['sid' => $sid, 'step' => $step]);
            return $xml->say('Sorry, your call session has expired. Goodbye.')->hangup()->toXml();
        }

        return match ($step) {
            'team_entered' => $this->handleTeamEntered($sid, $session, $rawDigits, $xml),
            'team_confirmed' => $this->handleTeamConfirmed($sid, $session, $rawDigits, $xml),
            'calls_entered' => $this->handleCallsEntered($sid, $session, $rawDigits, $xml),
            'calls_confirmed' => $this->handleCallsConfirmed($sid, $session, $rawDigits, $xml),
            default => $this->handleUnknownStep($sid, $step, $xml),
        };
    }

    private function handleStart(string $ani, string $origCallId, XmlResponse $xml): string
    {
        $sid = Helpers::generateId(12);
        $timezone = (string) ($this->config['timezone'] ?? 'America/New_York');

        $session = [
            'sid' => $sid,
            'flow' => 'call_and_win',
            'caller_id' => Helpers::formatPhone10($ani),
            'raw_ani' => $ani,
            'orig_call_id' => $origCallId,
            'started_at' => Helpers::formatEasternNow($timezone),
            'retries' => 0,
            'reentries' => 0,
            'team_number' => null,
            'call_count' => null,
            'submitted' => false,
        ];
        $this->sessions->save($sid, $session);

        $this->logger->info('Call & Win call started.', [
            'sid' => $sid,
            'caller_id' => $session['caller_id'],
            'orig_call_id' => $origCallId,
        ]);

        $maxDigits = (int) ($this->config['team_number_max_digits'] ?? 10);
        $prompt = str_replace(
            '{max_digits}',
            (string) $maxDigits,
            (string) ($this->config['prompts']['call_and_win_welcome']
                ?? 'Welcome to Call and Win. Please enter your team number, up to {max_digits} digits.')
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
        $callCountMaxDigits = (int) ($this->config['call_count_max_digits'] ?? 10);

        if ($choice === '1') {
            $this->sessions->update($sid, static function (?array $cur): ?array {
                if ($cur === null) {
                    return null;
                }
                $cur['retries'] = 0;

                return $cur;
            });

            $prompt = (string) ($this->config['prompts']['enter_call_count']
                ?? 'Please enter the number of calls you made.');

            return $this->gatherDigitsXml(
                $xml,
                $this->actionUrl('calls_entered', $sid),
                $callCountMaxDigits,
                $prompt,
                true
            );
        }

        if ($choice === '2') {
            if ($this->exceededReentries($sid, $session)) {
                $this->logger->warning('Call & Win exceeded max re-entries on team number.', ['sid' => $sid]);
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
    private function handleCallsEntered(string $sid, array $session, string $rawDigits, XmlResponse $xml): string
    {
        $digits = Helpers::digitsOnly($rawDigits);
        $maxDigits = (int) ($this->config['call_count_max_digits'] ?? 10);

        if ($digits === '' || strlen($digits) > $maxDigits) {
            return $this->retryOrHangup(
                $sid,
                $session,
                $xml,
                $this->actionUrl('calls_entered', $sid),
                $maxDigits,
                true,
                'We did not receive a valid number of calls. Please enter the number of calls you made.',
                'We did not receive a valid number of calls. Goodbye.'
            );
        }

        $this->sessions->update($sid, static function (?array $cur) use ($digits): ?array {
            if ($cur === null) {
                return null;
            }
            $cur['call_count'] = $digits;
            $cur['retries'] = 0;

            return $cur;
        });

        $spoken = Helpers::digitsForSpeech($digits);
        $prompt = "You entered: {$spoken} calls. Press 1 to confirm and submit, or press 2 to re-enter.";

        return $this->gatherConfirmXml($xml, $this->actionUrl('calls_confirmed', $sid), $prompt);
    }

    /**
     * @param array<string, mixed> $session
     */
    private function handleCallsConfirmed(string $sid, array $session, string $rawDigits, XmlResponse $xml): string
    {
        $choice = Helpers::digitsOnly($rawDigits);
        $callCountMaxDigits = (int) ($this->config['call_count_max_digits'] ?? 10);

        if ($choice === '1') {
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
                return $xml->say('Thank you! Your Call and Win report has been submitted. Goodbye.')
                    ->hangup()
                    ->toXml();
            }

            if ($updated === null) {
                return $xml->say('Sorry, your call session has expired. Goodbye.')->hangup()->toXml();
            }

            try {
                $this->emailService->sendCallAndWin([
                    'team_number' => $updated['team_number'],
                    'call_count' => $updated['call_count'],
                    'caller_id' => $updated['caller_id'],
                    'timestamp' => $now,
                    'orig_call_id' => $updated['orig_call_id'] ?? '',
                ]);

                return $xml->say('Thank you! Your Call and Win report has been submitted. Goodbye.')
                    ->hangup()
                    ->toXml();
            } catch (\Throwable $e) {
                $this->logger->error('Failed to send Call & Win email.', [
                    'sid' => $sid,
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

                return $xml->say('Sorry, there was a problem submitting your report. Please try again later. Goodbye.')
                    ->hangup()
                    ->toXml();
            }
        }

        if ($choice === '2') {
            if ($this->exceededReentries($sid, $session)) {
                $this->logger->warning('Call & Win exceeded max re-entries on call count.', ['sid' => $sid]);
                return $xml->say('Maximum re-entries exceeded. Goodbye.')->hangup()->toXml();
            }

            return $this->gatherDigitsXml(
                $xml,
                $this->actionUrl('calls_entered', $sid),
                $callCountMaxDigits,
                'Please re-enter the number of calls you made.',
                true
            );
        }

        $spoken = Helpers::digitsForSpeech((string) ($session['call_count'] ?? ''));

        return $this->retryConfirmOrHangup(
            $sid,
            $session,
            $xml,
            $this->actionUrl('calls_confirmed', $sid),
            "Invalid selection. You entered: {$spoken} calls. Press 1 to confirm and submit, or press 2 to re-enter.",
            'We did not receive a valid confirmation. Goodbye.'
        );
    }

    private function handleUnknownStep(string $sid, string $step, XmlResponse $xml): string
    {
        $this->logger->warning('Unknown step in Call & Win.', ['sid' => $sid, 'step' => $step]);

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
            $this->logger->info('Call & Win max retries reached; hanging up.', [
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

        return Helpers::buildUrl($base, 'call-and-win.php?step=' . rawurlencode($step) . '&sid=' . rawurlencode($sid));
    }
}
