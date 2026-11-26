<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use VolunteerLine\CallAndWinHandler;
use VolunteerLine\EmailServiceInterface;
use VolunteerLine\Helpers;
use VolunteerLine\Logger;
use VolunteerLine\ReferAndWinHandler;
use VolunteerLine\SessionStore;
use VolunteerLine\WavConverter;
use VolunteerLine\WebSocketServer;

final class InMemoryEmailService implements EmailServiceInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $sent = [];

    public function sendCallAndWin(array $data): void
    {
        $this->sent[] = ['type' => 'call_and_win', 'data' => $data, 'wav' => null];
    }

    public function sendReferAndWin(array $data, ?string $wavPath = null): void
    {
        $this->sent[] = ['type' => 'refer_and_win', 'data' => $data, 'wav' => $wavPath];
    }
}

function assertTrue(bool $cond, string $message): void
{
    if (!$cond) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
}

function assertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            sprintf('%s (expected %s, got %s)', $message, var_export($expected, true), var_export($actual, true))
        );
    }
}

function parseActionParams(string $xmlString): array
{
    $sx = @simplexml_load_string($xmlString);
    assertTrue($sx !== false, 'XML must parse cleanly: ' . $xmlString);

    $gather = $sx->Gather[0] ?? null;
    $action = $gather ? (string) ($gather['action'] ?? '') : '';
    if ($action === '' && isset($sx->Say[0]['action'])) {
        $action = (string) $sx->Say[0]['action'];
    }

    $query = [];
    if ($action !== '') {
        $qStr = (string) (parse_url($action, PHP_URL_QUERY) ?? '');
        parse_str($qStr, $query);
    }

    return [$sx, $query];
}

function encodeMaskedClientFrame(string $payload, int $opcode = 0x1): string
{
    $mask = "\x12\x34\x56\x78";
    $len = strlen($payload);
    $header = chr(0x80 | $opcode);

    if ($len <= 125) {
        $header .= chr(0x80 | $len);
    } elseif ($len <= 65535) {
        $header .= chr(0x80 | 126) . pack('n', $len);
    } else {
        $header .= chr(0x80 | 127) . pack('J', $len);
    }

    $repeatedMask = substr(str_repeat($mask, (int) ceil(max(1, $len) / 4)), 0, $len);
    $maskedPayload = $len > 0 ? ($payload ^ $repeatedMask) : '';

    return $header . $mask . $maskedPayload;
}

$tmpRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'volunteerline_test_' . bin2hex(random_bytes(4));
mkdir($tmpRoot, 0775, true);
mkdir($tmpRoot . '/logs', 0775, true);
mkdir($tmpRoot . '/storage/sessions', 0775, true);
mkdir($tmpRoot . '/storage/recordings', 0775, true);

try {
    // 1. Helpers
    assertSame('2125551234', Helpers::formatPhone10('+1 (212) 555-1234'), 'Format +1 10-digit');
    assertSame('2125551234', Helpers::formatPhone10('12125551234'), 'Format 11-digit starting with 1');
    assertSame('Unavailable', Helpers::formatPhone10(''), 'Format empty ANI');
    assertSame('1, 2, 3, 4', Helpers::digitsForSpeech('1234#'), 'Digits for speech strips #');

    $token = Helpers::signRecordingId('rec123', 'my-secret');
    assertTrue(Helpers::verifyRecordingToken('rec123', $token, 'my-secret'), 'Valid HMAC token');
    assertTrue(!Helpers::verifyRecordingToken('rec123', $token, 'wrong-secret'), 'Invalid HMAC token');

    // 2. WavConverter
    $wav = WavConverter::mulawToWav("\xFF\x00\x80");
    assertSame('RIFF', substr($wav, 0, 4), 'RIFF header');
    assertSame('WAVE', substr($wav, 8, 4), 'WAVE header');
    assertSame(44 + 6, strlen($wav), '3 samples * 2 bytes + 44 header');
    $samples = unpack('v3', substr($wav, 44));
    assertSame(0, $samples[1], '0xFF mu-law -> 0 PCM');
    assertSame((-32124) & 0xFFFF, $samples[2], '0x00 mu-law -> -32124 PCM');
    assertSame(32124, $samples[3], '0x80 mu-law -> +32124 PCM');

    // 3. WebSocketServer frame decoding & handshake key
    assertSame(
        's3pPLMBiTxaQ9kYGzzhZRbK+xOo=',
        WebSocketServer::computeAcceptKey('dGhlIHNhbXBsZSBub25jZQ=='),
        'RFC 6455 Sec-WebSocket-Accept test vector'
    );
    $frameBytes = encodeMaskedClientFrame('{"event":"connected"}') . encodeMaskedClientFrame('{"event":"stop"}');
    $half1 = substr($frameBytes, 0, 10);
    $half2 = substr($frameBytes, 10);
    $buf = $half1;
    assertSame([], WebSocketServer::decodeFrames($buf), 'Partial frame waits for more bytes');
    $buf .= $half2;
    $decoded = WebSocketServer::decodeFrames($buf);
    assertSame(2, count($decoded), 'Decodes 2 frames once complete');
    assertSame('{"event":"connected"}', $decoded[0]['payload'], 'Frame 1 payload');
    assertSame('{"event":"stop"}', $decoded[1]['payload'], 'Frame 2 payload');

    // 4. Call & Win Flow
    $config = require __DIR__ . '/../config/config.example.php';
    $config['app_base_url'] = 'https://voice.example.org';
    $config['ws_public_url'] = 'wss://voice.example.org/ws';
    $config['log_dir'] = $tmpRoot . '/logs';
    $config['storage_dir'] = $tmpRoot . '/storage';
    $config['recording_access_secret'] = 'test-secret';

    $logger = new Logger($config['log_dir'], $config['timezone']);
    $sessions = new SessionStore($config['storage_dir']);
    $emailer = new InMemoryEmailService();
    $callWin = new CallAndWinHandler($config, $sessions, $logger, $emailer);

    $xml = $callWin->handle(['step' => 'start', 'NmsAni' => '12125550199', 'OrigCallID' => 'call-1']);
    [, $q] = parseActionParams($xml);
    assertSame('team_entered', $q['step'], 'Call&Win step=team_entered');
    $sid = $q['sid'];

    // Enter team number 456# -> re-enter (2) -> 789 -> confirm (1)
    $xml = $callWin->handle(['step' => 'team_entered', 'sid' => $sid, 'Digits' => '456#']);
    [$sx, $q] = parseActionParams($xml);
    assertSame('team_confirmed', $q['step'], 'Confirm team number');
    assertTrue(str_contains((string) $sx->Gather->Say, '4, 5, 6'), 'Reads digits one by one');

    $xml = $callWin->handle(['step' => 'team_confirmed', 'sid' => $sid, 'Digits' => '2']);
    [, $q] = parseActionParams($xml);
    assertSame('team_entered', $q['step'], 'Re-enter team number');

    $xml = $callWin->handle(['step' => 'team_entered', 'sid' => $sid, 'Digits' => '789']);
    [, $q] = parseActionParams($xml);
    $xml = $callWin->handle(['step' => 'team_confirmed', 'sid' => $sid, 'Digits' => '1']);
    [, $q] = parseActionParams($xml);
    assertSame('calls_entered', $q['step'], 'Move to calls_entered');

    $xml = $callWin->handle(['step' => 'calls_entered', 'sid' => $sid, 'Digits' => '25']);
    [, $q] = parseActionParams($xml);
    assertSame('calls_confirmed', $q['step'], 'Confirm calls');

    $xml = $callWin->handle(['step' => 'calls_confirmed', 'sid' => $sid, 'Digits' => '1']);
    $sx = simplexml_load_string($xml);
    assertTrue(isset($sx->Hangup), 'Hangup after Call & Win submission');
    assertSame(1, count($emailer->sent), 'Sent 1 Call & Win email');
    assertSame('789', $emailer->sent[0]['data']['team_number'], 'Email team_number');
    assertSame('25', $emailer->sent[0]['data']['call_count'], 'Email call_count');
    assertSame('2125550199', $emailer->sent[0]['data']['caller_id'], 'Email 10-digit caller_id');

    // 5. Refer & Win Flow (with playback & confirmation)
    $referWin = new ReferAndWinHandler($config, $sessions, $logger, $emailer);
    $xml = $referWin->handle(['step' => 'start', 'NmsAni' => '3055551122', 'OrigCallID' => 'call-2']);
    [, $q] = parseActionParams($xml);
    $sid2 = $q['sid'];

    $xml = $referWin->handle(['step' => 'team_entered', 'sid' => $sid2, 'Digits' => '101']);
    $xml = $referWin->handle(['step' => 'team_confirmed', 'sid' => $sid2, 'Digits' => '1']);
    [, $q] = parseActionParams($xml);
    assertSame('phone_entered', $q['step'], 'Move to phone_entered');

    // Invalid 7-digit phone triggers retry
    $xml = $referWin->handle(['step' => 'phone_entered', 'sid' => $sid2, 'Digits' => '5551234']);
    [, $q] = parseActionParams($xml);
    assertSame('phone_entered', $q['step'], 'Retry on 7-digit phone');

    $xml = $referWin->handle(['step' => 'phone_entered', 'sid' => $sid2, 'Digits' => '4075558899']);
    $xml = $referWin->handle(['step' => 'phone_confirmed', 'sid' => $sid2, 'Digits' => '1']);
    $sx = simplexml_load_string($xml);
    assertTrue(isset($sx->Start->Stream), 'Starts WebSocket <Stream> for recording');
    [, $q] = parseActionParams($xml);
    assertSame('record_done', $q['step'], 'Gather # goes to record_done');

    // Simulate WebSocket server saving the WAV file
    $sess2 = $sessions->get($sid2);
    $recId = (string) $sess2['current_recording_id'];
    $wavPath = $config['storage_dir'] . '/recordings/' . $recId . '.wav';
    WavConverter::writeMulawAsWavFile(str_repeat("\x10", 800), $wavPath);

    $xml = $referWin->handle(['step' => 'record_done', 'sid' => $sid2, 'Digits' => '#']);
    $sx = simplexml_load_string($xml);
    assertTrue(isset($sx->Stop->Stream), 'Stops WebSocket <Stream>');
    assertTrue(isset($sx->Play), 'Plays recording before confirming');

    $xml = $referWin->handle(['step' => 'recording_confirmed', 'sid' => $sid2, 'Digits' => '1']);
    $sx = simplexml_load_string($xml);
    assertTrue(isset($sx->Hangup), 'Hangs up after Refer & Win submit');
    assertSame(2, count($emailer->sent), 'Sent Refer & Win email');
    assertSame('refer_and_win', $emailer->sent[1]['type'], 'Email type refer_and_win');
    assertSame('4075558899', $emailer->sent[1]['data']['friend_phone'], 'Friend phone in email');
    assertTrue(is_file($wavPath), 'keep_recordings=true preserves WAV file');

    // 6. Test 3-retries hangup
    $xml = $callWin->handle(['step' => 'start', 'NmsAni' => '2125550000', 'OrigCallID' => 'call-3']);
    [, $q] = parseActionParams($xml);
    $sid3 = $q['sid'];
    $callWin->handle(['step' => 'team_entered', 'sid' => $sid3, 'Digits' => '']);
    $callWin->handle(['step' => 'team_entered', 'sid' => $sid3, 'Digits' => '']);
    $xml = $callWin->handle(['step' => 'team_entered', 'sid' => $sid3, 'Digits' => '']);
    $sx = simplexml_load_string($xml);
    assertTrue(isset($sx->Hangup), 'Hangs up after 3 failed retries');

    echo "ALL TESTS PASSED!\n";
} finally {
    // Cleanup temp directory
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmpRoot, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($tmpRoot);
}
