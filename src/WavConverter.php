<?php

declare(strict_types=1);

namespace VolunteerLine;

final class WavConverter
{
    /** @var array<int, string>|null */
    private static ?array $mulawLookup = null;

    /**
     * Convert raw 8 kHz, 1-channel G.711 mu-law audio bytes into a 16-bit PCM WAV file payload.
     */
    public static function mulawToWav(string $mulawBytes, int $sampleRate = 8000): string
    {
        $pcmBytes = self::decodeMulawToPcm16($mulawBytes);
        $dataSize = strlen($pcmBytes);

        $numChannels = 1;
        $bitsPerSample = 16;
        $blockAlign = $numChannels * ($bitsPerSample / 8); // 2
        $byteRate = $sampleRate * $blockAlign;            // 16000

        $header = 'RIFF'
            . pack('V', 36 + $dataSize)
            . 'WAVE'
            . 'fmt '
            . pack('VvvVVvv', 16, 1, $numChannels, $sampleRate, $byteRate, $blockAlign, $bitsPerSample)
            . 'data'
            . pack('V', $dataSize);

        return $header . $pcmBytes;
    }

    public static function writeMulawAsWavFile(string $mulawBytes, string $wavPath, int $sampleRate = 8000): void
    {
        $dir = dirname($wavPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $wav = self::mulawToWav($mulawBytes, $sampleRate);
        $tmpPath = $wavPath . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmpPath, $wav, LOCK_EX) === false) {
            throw new \RuntimeException("Failed to write temporary WAV file: {$tmpPath}");
        }

        if (!@rename($tmpPath, $wavPath)) {
            @unlink($tmpPath);
            throw new \RuntimeException("Failed to move WAV file into place: {$wavPath}");
        }
    }

    public static function decodeMulawToPcm16(string $mulawBytes): string
    {
        $table = self::getMulawLookupTable();
        $length = strlen($mulawBytes);
        $out = '';

        for ($i = 0; $i < $length; $i++) {
            $out .= $table[ord($mulawBytes[$i])];
        }

        return $out;
    }

    /**
     * Precompute 256-entry ITU-T G.711 mu-law -> signed 16-bit little-endian PCM lookup table.
     *
     * @return array<int, string>
     */
    private static function getMulawLookupTable(): array
    {
        if (self::$mulawLookup !== null) {
            return self::$mulawLookup;
        }

        $table = [];
        for ($i = 0; $i < 256; $i++) {
            $u = (~$i) & 0xFF;
            $sign = $u & 0x80;
            $exponent = ($u >> 4) & 0x07;
            $mantissa = $u & 0x0F;

            $sample = (($mantissa << 3) + 0x84) << $exponent;
            $sample -= 0x84;
            $pcm = $sign !== 0 ? -$sample : $sample;

            $table[$i] = pack('v', $pcm & 0xFFFF);
        }

        self::$mulawLookup = $table;

        return $table;
    }
}
