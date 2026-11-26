<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use VolunteerLine\Helpers;
use VolunteerLine\WavConverter;

try {
    $config = volunteerline_load_config();
    $recId = Helpers::sanitizeToken(Helpers::requestParam('id'));
    $token = Helpers::requestParam('token');
    $secret = (string) ($config['recording_access_secret'] ?? '');

    if ($recId === '' || !Helpers::verifyRecordingToken($recId, $token, $secret)) {
        http_response_code(403);
        exit('Forbidden');
    }

    $recDir = rtrim((string) ($config['storage_dir'] ?? (__DIR__ . '/../storage')), '/\\')
        . DIRECTORY_SEPARATOR . 'recordings';
    $wavPath = $recDir . DIRECTORY_SEPARATOR . $recId . '.wav';
    $ulawPath = $recDir . DIRECTORY_SEPARATOR . $recId . '.ulaw';

    $deadline = microtime(true) + 2.5;
    while (microtime(true) < $deadline) {
        if (is_file($wavPath) && (int) @filesize($wavPath) > 44) {
            break;
        }
        usleep(50000);
    }

    if ((!is_file($wavPath) || (int) @filesize($wavPath) <= 44) && is_file($ulawPath)) {
        $raw = @file_get_contents($ulawPath);
        if (is_string($raw) && $raw !== '') {
            WavConverter::writeMulawAsWavFile($raw, $wavPath);
        }
    }

    if (!is_file($wavPath)) {
        http_response_code(404);
        exit('Not found');
    }

    $playbackCodec = strtolower((string) ($config['playback_wav_codec'] ?? 'pcm_mulaw'));
    $wavPayload = @file_get_contents($wavPath);
    if ($wavPayload === false) {
        http_response_code(500);
        exit('Server error');
    }

    if ($playbackCodec === 'pcm_mulaw') {
        $wavPayload = WavConverter::pcm16WavToMulawWav($wavPayload);
    }

    header('Content-Type: audio/wav');
    header('Content-Length: ' . strlen($wavPayload));
    header('Cache-Control: no-store');
    echo $wavPayload;
} catch (\Throwable $e) {
    @error_log('Recording serve error: ' . $e->getMessage());
    http_response_code(500);
    exit('Server error');
}
