<?php

declare(strict_types=1);

namespace VolunteerLine;

final class WavConverter
{
    /** @var array<int, string>|null */
    private static ?array $mulawLookup = null;

    /**
     * Convert raw 8 kHz, 1-channel G.711 mu-law audio bytes into a 16-bit linear PCM WAV payload.
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

    /**
     * Wrap raw 8 kHz, 1-channel G.711 mu-law audio bytes into a PCMU (WAVE_FORMAT_MULAW, codec=7) WAV payload.
     */
    public static function mulawToMulawWav(string $mulawBytes, int $sampleRate = 8000): string
    {
        $dataSize = strlen($mulawBytes);
        $pad = ($dataSize % 2) === 1 ? "\x00" : '';

        $numChannels = 1;
        $bitsPerSample = 8;
        $blockAlign = 1;
        $byteRate = $sampleRate;

        // RIFF body: 'WAVE'(4) + 'fmt '(26) + 'fact'(12) + 'data'(8) + dataSize + pad
        $riffSize = 50 + $dataSize + strlen($pad);

        return 'RIFF'
            . pack('V', $riffSize)
            . 'WAVE'
            . 'fmt '
            . pack('VvvVVvvv', 18, 7, $numChannels, $sampleRate, $byteRate, $blockAlign, $bitsPerSample, 0)
            . 'fact'
            . pack('VV', 4, $dataSize)
            . 'data'
            . pack('V', $dataSize)
            . $mulawBytes
            . $pad;
    }

    /**
     * Convert a 16-bit PCM WAV file payload into a PCMU (G.711 mu-law) WAV payload.
     */
    public static function pcm16WavToMulawWav(string $pcmWavBytes): string
    {
        if (strlen($pcmWavBytes) < 44 || substr($pcmWavBytes, 0, 4) !== 'RIFF' || substr($pcmWavBytes, 8, 4) !== 'WAVE') {
            throw new \InvalidArgumentException('Invalid WAV header.');
        }

        $offset = 12;
        $totalLen = strlen($pcmWavBytes);
        $sampleRate = 8000;
        $formatTag = 1;
        $channels = 1;
        $bitsPerSample = 16;
        $dataBytes = '';

        while ($offset + 8 <= $totalLen) {
            $chunkId = substr($pcmWavBytes, $offset, 4);
            $chunkSize = (int) unpack('Vlen', substr($pcmWavBytes, $offset + 4, 4))['len'];
            $chunkStart = $offset + 8;

            if ($chunkStart + $chunkSize > $totalLen) {
                break;
            }

            if ($chunkId === 'fmt ' && $chunkSize >= 16) {
                $fmt = unpack(
                    'vtag/vchannels/Vsrate/Vbrate/vbalign/vbits',
                    substr($pcmWavBytes, $chunkStart, 16)
                );
                $formatTag = (int) $fmt['tag'];
                $channels = (int) $fmt['channels'];
                $sampleRate = (int) $fmt['srate'];
                $bitsPerSample = (int) $fmt['bits'];
            } elseif ($chunkId === 'data') {
                $dataBytes = substr($pcmWavBytes, $chunkStart, $chunkSize);
            }

            $offset = $chunkStart + $chunkSize + ($chunkSize % 2);
        }

        if ($formatTag === 7) {
            // Already G.711 mu-law WAV
            return $pcmWavBytes;
        }

        if ($formatTag !== 1 || $channels !== 1 || $bitsPerSample !== 16) {
            throw new \RuntimeException(
                "Unsupported source WAV format for mu-law conversion (tag={$formatTag}, ch={$channels}, bits={$bitsPerSample})."
            );
        }

        $mulawBytes = self::encodePcm16ToMulaw($dataBytes);

        return self::mulawToMulawWav($mulawBytes, $sampleRate);
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

    public static function encodePcm16ToMulaw(string $pcmBytes): string
    {
        $length = strlen($pcmBytes);
        $out = '';

        for ($i = 0; $i + 1 < $length; $i += 2) {
            $lo = ord($pcmBytes[$i]);
            $hi = ord($pcmBytes[$i + 1]);
            $sample = $lo | ($hi << 8);
            if ($sample >= 0x8000) {
                $sample -= 0x10000;
            }
            $out .= chr(self::pcm16SampleToMulaw($sample));
        }

        return $out;
    }

    public static function pcm16SampleToMulaw(int $pcm): int
    {
        $mask = 0xFF;
        if ($pcm < 0) {
            $pcm = -$pcm;
            $mask = 0x7F;
        }
        if ($pcm > 32635) {
            $pcm = 32635;
        }
        $pcm += 0x84;

        $exponent = 7;
        for ($expMask = 0x4000; ($pcm & $expMask) === 0 && $exponent > 0; $exponent--, $expMask >>= 1) {
        }
        $mantissa = ($pcm >> ($exponent + 3)) & 0x0F;
        $ulaw = ($exponent << 4) | $mantissa;

        return (~$ulaw) & $mask;
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
