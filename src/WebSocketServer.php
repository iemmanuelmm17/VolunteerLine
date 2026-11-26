<?php

declare(strict_types=1);

namespace VolunteerLine;

final class WebSocketServer
{
    private const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /** @var array<string, mixed> */
    private array $config;
    private SessionStore $sessions;
    private Logger $logger;
    private EmailServiceInterface $emailService;

    /** @var array<int, array<string, mixed>> */
    private array $clients = [];
    private int $nextClientId = 1;
    private bool $running = true;

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

    public function run(): void
    {
        $host = (string) ($this->config['ws_bind_host'] ?? '127.0.0.1');
        $port = (int) ($this->config['ws_bind_port'] ?? 8090);

        $errno = 0;
        $errstr = '';
        $server = @stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);
        if ($server === false) {
            throw new \RuntimeException("Failed to start WebSocket server on {$host}:{$port}: [{$errno}] {$errstr}");
        }

        stream_set_blocking($server, false);
        $this->logger->info("WebSocket media server listening on {$host}:{$port}");
        echo "WebSocket media server listening on ws://{$host}:{$port}\n";

        $lastPruneAt = time();

        while ($this->running) {
            $read = [$server];
            foreach ($this->clients as $client) {
                $read[] = $client['socket'];
            }
            $write = null;
            $except = null;

            $changed = @stream_select($read, $write, $except, 1);
            if ($changed === false) {
                break;
            }

            if (in_array($server, $read, true)) {
                $conn = @stream_socket_accept($server, 0);
                if ($conn !== false) {
                    stream_set_blocking($conn, false);
                    $id = $this->nextClientId++;
                    $this->clients[$id] = [
                        'id' => $id,
                        'socket' => $conn,
                        'handshake_done' => false,
                        'http_buffer' => '',
                        'ws_buffer' => '',
                        'fragment_opcode' => null,
                        'fragment_payload' => '',
                        'rec_id' => '',
                        'sid' => '',
                        'call_id' => '',
                        'stream_id' => '',
                        'max_seconds' => (int) ($this->config['max_recording_seconds'] ?? 60),
                        'started_at' => null,
                        'last_activity_at' => microtime(true),
                        'mulaw_buffer' => '',
                        'media_packets' => 0,
                        'stopped_cleanly' => false,
                        'max_duration_reached' => false,
                        'finalized' => false,
                    ];
                    $this->logger->info('WebSocket TCP client accepted.', ['client_id' => $id]);
                }
            }

            foreach ($this->clients as $id => $client) {
                if (!isset($this->clients[$id])) {
                    continue;
                }
                $sock = $client['socket'];
                if (!in_array($sock, $read, true)) {
                    continue;
                }

                $chunk = @fread($sock, 65536);
                if ($chunk === '' || $chunk === false) {
                    if (@feof($sock) || $chunk === false) {
                        $this->disconnectClient($id, false);
                    }
                    continue;
                }

                $this->clients[$id]['last_activity_at'] = microtime(true);

                if (!$this->clients[$id]['handshake_done']) {
                    $this->clients[$id]['http_buffer'] .= $chunk;
                    $this->processHandshake($id);
                } else {
                    $this->clients[$id]['ws_buffer'] .= $chunk;
                    $this->processFrames($id);
                }
            }

            $this->checkTimeouts();

            if (time() - $lastPruneAt >= 3600) {
                $this->sessions->pruneExpired(86400);
                $lastPruneAt = time();
            }
        }

        @fclose($server);
    }

    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * Encode an RFC 6455 server-to-client WebSocket frame (unmasked).
     */
    public static function encodeFrame(string $payload, int $opcode = 0x1): string
    {
        $firstByte = 0x80 | ($opcode & 0x0F);
        $len = strlen($payload);

        if ($len <= 125) {
            return chr($firstByte) . chr($len) . $payload;
        }
        if ($len <= 65535) {
            return chr($firstByte) . chr(126) . pack('n', $len) . $payload;
        }

        return chr($firstByte) . chr(127) . pack('J', $len) . $payload;
    }

    /**
     * Decode as many complete RFC 6455 frames as possible from a byte buffer.
     *
     * @return array<int, array{fin: bool, opcode: int, payload: string}>
     */
    public static function decodeFrames(string &$buffer): array
    {
        $frames = [];

        while (strlen($buffer) >= 2) {
            $b0 = ord($buffer[0]);
            $b1 = ord($buffer[1]);
            $fin = ($b0 & 0x80) !== 0;
            $opcode = $b0 & 0x0F;
            $masked = ($b1 & 0x80) !== 0;
            $len = $b1 & 0x7F;
            $offset = 2;

            if ($len === 126) {
                if (strlen($buffer) < 4) {
                    break;
                }
                $unpacked = unpack('nlen', substr($buffer, 2, 2));
                $len = (int) $unpacked['len'];
                $offset = 4;
            } elseif ($len === 127) {
                if (strlen($buffer) < 10) {
                    break;
                }
                $unpacked = unpack('Jlen', substr($buffer, 2, 8));
                $len = (int) $unpacked['len'];
                $offset = 10;
            }

            $maskLen = $masked ? 4 : 0;
            if (strlen($buffer) < $offset + $maskLen + $len) {
                break;
            }

            $maskKey = '';
            if ($masked) {
                $maskKey = substr($buffer, $offset, 4);
                $offset += 4;
            }

            $payload = $len > 0 ? substr($buffer, $offset, $len) : '';
            if ($masked && $len > 0) {
                $repeatedMask = substr(str_repeat($maskKey, (int) ceil($len / 4)), 0, $len);
                $payload = $payload ^ $repeatedMask;
            }

            $buffer = substr($buffer, $offset + $len);
            $frames[] = [
                'fin' => $fin,
                'opcode' => $opcode,
                'payload' => $payload,
            ];
        }

        return $frames;
    }

    public static function computeAcceptKey(string $secWebSocketKey): string
    {
        return base64_encode(sha1(trim($secWebSocketKey) . self::GUID, true));
    }

    private function processHandshake(int $id): void
    {
        $buf = $this->clients[$id]['http_buffer'];
        $headerEnd = strpos($buf, "\r\n\r\n");
        if ($headerEnd === false) {
            if (strlen($buf) > 16384) {
                $this->disconnectClient($id, false);
            }
            return;
        }

        $rawHeaders = substr($buf, 0, $headerEnd);
        $leftover = substr($buf, $headerEnd + 4);
        $lines = explode("\r\n", $rawHeaders);
        $requestLine = $lines[0] ?? '';

        if (preg_match('#^GET\s+(\S+)\s+HTTP/1\.[01]$#i', $requestLine, $m) === 1) {
            $queryStr = (string) (parse_url($m[1], PHP_URL_QUERY) ?? '');
            $query = [];
            parse_str($queryStr, $query);
            if (!empty($query['rec']) && is_string($query['rec'])) {
                $this->clients[$id]['rec_id'] = Helpers::sanitizeToken($query['rec']);
            }
            if (!empty($query['sid']) && is_string($query['sid'])) {
                $this->clients[$id]['sid'] = Helpers::sanitizeToken($query['sid']);
            }
        }

        $wsKey = null;
        $wsProtocol = null;
        foreach (array_slice($lines, 1) as $line) {
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $pos)));
            $val = trim(substr($line, $pos + 1));
            if ($name === 'sec-websocket-key') {
                $wsKey = $val;
            } elseif ($name === 'sec-websocket-protocol') {
                $wsProtocol = $val;
            }
        }

        if ($wsKey === null || $wsKey === '') {
            $this->logger->warning('WebSocket handshake failed: missing Sec-WebSocket-Key.', [
                'client_id' => $id,
                'request_line' => $requestLine,
            ]);
            @fwrite($this->clients[$id]['socket'], "HTTP/1.1 400 Bad Request\r\nConnection: close\r\n\r\n");
            $this->disconnectClient($id, false);
            return;
        }

        $accept = self::computeAcceptKey($wsKey);
        $response = "HTTP/1.1 101 Switching Protocols\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Accept: {$accept}\r\n";

        if ($wsProtocol !== null && $wsProtocol !== '') {
            $firstProto = trim(explode(',', $wsProtocol)[0]);
            if ($firstProto !== '') {
                $response .= "Sec-WebSocket-Protocol: {$firstProto}\r\n";
            }
        }

        $response .= "\r\n";

        @fwrite($this->clients[$id]['socket'], $response);

        $this->logger->info('WebSocket handshake completed.', [
            'client_id' => $id,
            'request_line' => $requestLine,
            'rec_id' => $this->clients[$id]['rec_id'],
            'sid' => $this->clients[$id]['sid'],
        ]);

        $this->clients[$id]['handshake_done'] = true;
        $this->clients[$id]['http_buffer'] = '';
        $this->clients[$id]['ws_buffer'] = $leftover;

        if ($leftover !== '') {
            $this->processFrames($id);
        }
    }

    private function processFrames(int $id): void
    {
        if (!isset($this->clients[$id])) {
            return;
        }

        $frames = self::decodeFrames($this->clients[$id]['ws_buffer']);
        foreach ($frames as $frame) {
            if (!isset($this->clients[$id])) {
                break;
            }

            $opcode = $frame['opcode'];
            $fin = $frame['fin'];
            $payload = $frame['payload'];

            if ($opcode === 0x8) {
                // Close frame
                @fwrite($this->clients[$id]['socket'], self::encodeFrame('', 0x8));
                $this->disconnectClient($id, (bool) $this->clients[$id]['stopped_cleanly']);
                return;
            }

            if ($opcode === 0x9) {
                // Ping -> Pong
                @fwrite($this->clients[$id]['socket'], self::encodeFrame($payload, 0xA));
                continue;
            }

            if ($opcode === 0xA) {
                continue;
            }

            if ($opcode === 0x1 || $opcode === 0x2) {
                if ($fin) {
                    $this->handleJsonMessage($id, $payload);
                } else {
                    $this->clients[$id]['fragment_opcode'] = $opcode;
                    $this->clients[$id]['fragment_payload'] = $payload;
                }
            } elseif ($opcode === 0x0) {
                $this->clients[$id]['fragment_payload'] .= $payload;
                if ($fin) {
                    $full = $this->clients[$id]['fragment_payload'];
                    $this->clients[$id]['fragment_opcode'] = null;
                    $this->clients[$id]['fragment_payload'] = '';
                    $this->handleJsonMessage($id, $full);
                }
            }
        }
    }

    private function handleJsonMessage(int $id, string $jsonText): void
    {
        $data = json_decode($jsonText, true);
        if (!is_array($data) || !isset($data['event'])) {
            return;
        }

        $event = (string) $data['event'];
        switch ($event) {
            case 'connected':
                $this->logger->info('WebSocket stream connected.', [
                    'rec_id' => $this->clients[$id]['rec_id'],
                    'sid' => $this->clients[$id]['sid'],
                ]);
                break;

            case 'start':
                $start = is_array($data['start'] ?? null) ? $data['start'] : [];
                $custom = is_array($start['customParameters'] ?? null) ? $start['customParameters'] : [];

                if (!empty($custom['recId'])) {
                    $this->clients[$id]['rec_id'] = Helpers::sanitizeToken((string) $custom['recId']);
                }
                if (!empty($custom['sid'])) {
                    $this->clients[$id]['sid'] = Helpers::sanitizeToken((string) $custom['sid']);
                }
                if (!empty($custom['maxSeconds'])) {
                    $this->clients[$id]['max_seconds'] = max(5, (int) $custom['maxSeconds']);
                }
                $this->clients[$id]['stream_id'] = (string) ($start['stream_id'] ?? '');
                $this->clients[$id]['call_id'] = (string) ($start['call_id'] ?? ($custom['callId'] ?? ''));
                $this->clients[$id]['started_at'] = microtime(true);

                $this->logger->info('WebSocket stream started.', [
                    'rec_id' => $this->clients[$id]['rec_id'],
                    'sid' => $this->clients[$id]['sid'],
                    'stream_id' => $this->clients[$id]['stream_id'],
                    'call_id' => $this->clients[$id]['call_id'],
                    'tracks' => $start['tracks'] ?? null,
                    'mediaFormat' => $start['mediaFormat'] ?? null,
                ]);
                break;

            case 'media':
                if ($this->clients[$id]['max_duration_reached']) {
                    break;
                }
                if ($this->clients[$id]['started_at'] === null) {
                    $this->clients[$id]['started_at'] = microtime(true);
                }
                $elapsed = microtime(true) - (float) $this->clients[$id]['started_at'];
                if ($elapsed > (float) $this->clients[$id]['max_seconds']) {
                    $this->clients[$id]['max_duration_reached'] = true;
                    $this->logger->info('Max recording duration reached on stream.', [
                        'rec_id' => $this->clients[$id]['rec_id'],
                        'max_seconds' => $this->clients[$id]['max_seconds'],
                    ]);
                    @fwrite($this->clients[$id]['socket'], self::encodeFrame('{"event":"stop"}', 0x1));
                    break;
                }

                $media = is_array($data['media'] ?? null) ? $data['media'] : [];
                $b64 = (string) ($media['payload'] ?? '');
                if ($b64 !== '') {
                    $raw = base64_decode($b64, true);
                    if ($raw !== false) {
                        $this->clients[$id]['mulaw_buffer'] .= $raw;
                        $this->clients[$id]['media_packets']++;
                        if ($this->clients[$id]['media_packets'] === 1) {
                            $this->logger->info('First WebSocket media packet received.', [
                                'rec_id' => $this->clients[$id]['rec_id'],
                                'track' => $media['track'] ?? null,
                                'bytes' => strlen($raw),
                            ]);
                        }
                    }
                }
                break;

            case 'stop':
                $this->clients[$id]['stopped_cleanly'] = true;
                $this->finalizeRecording($id, true);
                @fwrite($this->clients[$id]['socket'], self::encodeFrame('', 0x8));
                $this->disconnectClient($id, true);
                break;
        }
    }

    private function checkTimeouts(): void
    {
        $now = microtime(true);
        foreach ($this->clients as $id => $client) {
            if ($now - (float) $client['last_activity_at'] > 90.0) {
                $this->logger->warning('WebSocket client timed out due to inactivity.', [
                    'rec_id' => $client['rec_id'],
                ]);
                $this->disconnectClient($id, false);
            }
        }
    }

    private function disconnectClient(int $id, bool $stoppedCleanly): void
    {
        if (!isset($this->clients[$id])) {
            return;
        }
        $client = $this->clients[$id];
        unset($this->clients[$id]);

        @fclose($client['socket']);

        if (!$client['finalized']) {
            $this->finalizeClientData($client, $stoppedCleanly);
        }
    }

    private function finalizeRecording(int $id, bool $stoppedCleanly): void
    {
        if (!isset($this->clients[$id]) || $this->clients[$id]['finalized']) {
            return;
        }
        $this->clients[$id]['finalized'] = true;
        $this->finalizeClientData($this->clients[$id], $stoppedCleanly);
    }

    /**
     * @param array<string, mixed> $client
     */
    private function finalizeClientData(array $client, bool $stoppedCleanly): void
    {
        $recId = Helpers::sanitizeToken((string) ($client['rec_id'] ?? ''));
        if ($recId === '') {
            $recId = Helpers::sanitizeToken((string) ($client['stream_id'] ?? ''));
        }
        $sid = Helpers::sanitizeToken((string) ($client['sid'] ?? ''));
        $mulaw = (string) ($client['mulaw_buffer'] ?? '');

        if ($recId === '' || $mulaw === '') {
            return;
        }

        $recDir = rtrim((string) ($this->config['storage_dir'] ?? (__DIR__ . '/../storage')), '/\\')
            . DIRECTORY_SEPARATOR . 'recordings';
        if (!is_dir($recDir)) {
            @mkdir($recDir, 0775, true);
        }

        $ulawPath = $recDir . DIRECTORY_SEPARATOR . $recId . '.ulaw';
        $wavPath = $recDir . DIRECTORY_SEPARATOR . $recId . '.wav';

        @file_put_contents($ulawPath, $mulaw, LOCK_EX);
        WavConverter::writeMulawAsWavFile($mulaw, $wavPath, 8000);
        @unlink($ulawPath);

        $this->logger->info('Saved WAV recording from WebSocket stream.', [
            'rec_id' => $recId,
            'sid' => $sid,
            'bytes_mulaw' => strlen($mulaw),
            'stopped_cleanly' => $stoppedCleanly,
        ]);

        // If stream ended due to caller hangup (not a normal <Stop> verb)
        if (!$stoppedCleanly && $sid !== '') {
            $this->handleEarlyHangup($sid, $recId, $wavPath, $ulawPath);
        }
    }

    private function handleEarlyHangup(string $sid, string $recId, string $wavPath, string $ulawPath): void
    {
        $discardOnEarlyHangup = (bool) ($this->config['discard_on_early_hangup'] ?? true);
        if ($discardOnEarlyHangup) {
            $this->logger->info('Caller hung up before completing referral; discarding email.', [
                'sid' => $sid,
                'rec_id' => $recId,
            ]);
            if (empty($this->config['keep_recordings'])) {
                @unlink($wavPath);
                @unlink($ulawPath);
            }
            return;
        }

        $timezone = (string) ($this->config['timezone'] ?? 'America/New_York');
        $now = Helpers::formatEasternNow($timezone);
        $shouldSend = false;

        $session = $this->sessions->update($sid, static function (?array $cur) use ($recId, $now, &$shouldSend): ?array {
            if ($cur === null) {
                return null;
            }
            if (!empty($cur['submitted'])) {
                return $cur;
            }
            $superseded = is_array($cur['superseded_recordings'] ?? null) ? $cur['superseded_recordings'] : [];
            if (!empty($superseded[$recId])) {
                return $cur;
            }
            if (($cur['current_recording_id'] ?? '') !== $recId) {
                return $cur;
            }

            $cur['submitted'] = true;
            $cur['submitted_at'] = $now;
            $cur['submitted_via'] = 'early_hangup';
            $shouldSend = true;

            return $cur;
        });

        if (!$shouldSend || $session === null) {
            return;
        }

        try {
            $this->emailService->sendReferAndWin([
                'team_number' => $session['team_number'],
                'friend_phone' => $session['friend_phone'],
                'caller_id' => $session['caller_id'],
                'timestamp' => $now,
                'orig_call_id' => $session['orig_call_id'] ?? '',
            ], $wavPath);

            if (empty($this->config['keep_recordings'])) {
                @unlink($wavPath);
                @unlink($ulawPath);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send Refer & Win email on early hangup.', [
                'sid' => $sid,
                'rec_id' => $recId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
