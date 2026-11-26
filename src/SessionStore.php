<?php

declare(strict_types=1);

namespace VolunteerLine;

final class SessionStore
{
    private string $sessionsDir;

    public function __construct(string $storageDir)
    {
        $this->sessionsDir = rtrim($storageDir, '/\\') . DIRECTORY_SEPARATOR . 'sessions';
        if (!is_dir($this->sessionsDir)) {
            @mkdir($this->sessionsDir, 0775, true);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public function save(string $sid, array $data): void
    {
        $path = $this->pathFor($sid);
        $data['updated_at'] = time();
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode session JSON.');
        }

        $fp = @fopen($path, 'c+b');
        if ($fp === false) {
            throw new \RuntimeException("Unable to open session file: {$path}");
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                throw new \RuntimeException("Unable to lock session file: {$path}");
            }
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, $json);
            fflush($fp);
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $sid): ?array
    {
        $path = $this->pathFor($sid);
        if (!is_file($path)) {
            return null;
        }

        $fp = @fopen($path, 'rb');
        if ($fp === false) {
            return null;
        }

        try {
            if (!flock($fp, LOCK_SH)) {
                return null;
            }
            $contents = stream_get_contents($fp);
            if ($contents === false || $contents === '') {
                return null;
            }
            $decoded = json_decode($contents, true);

            return is_array($decoded) ? $decoded : null;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * Atomically read, mutate, and write a session file under an exclusive lock.
     *
     * @param callable(array<string, mixed>|null): (array<string, mixed>|null) $mutator
     * @return array<string, mixed>|null
     */
    public function update(string $sid, callable $mutator): ?array
    {
        $path = $this->pathFor($sid);
        $fp = @fopen($path, 'c+b');
        if ($fp === false) {
            throw new \RuntimeException("Unable to open session file: {$path}");
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                throw new \RuntimeException("Unable to lock session file: {$path}");
            }

            $contents = stream_get_contents($fp);
            $current = null;
            if (is_string($contents) && $contents !== '') {
                $decoded = json_decode($contents, true);
                if (is_array($decoded)) {
                    $current = $decoded;
                }
            }

            $updated = $mutator($current);
            if ($updated === null) {
                return null;
            }

            $updated['updated_at'] = time();
            $json = json_encode($updated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                throw new \RuntimeException('Failed to encode updated session JSON.');
            }

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, $json);
            fflush($fp);

            return $updated;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    public function pruneExpired(int $maxAgeSeconds = 86400): int
    {
        $deleted = 0;
        $files = @glob($this->sessionsDir . DIRECTORY_SEPARATOR . '*.json');
        if (!is_array($files)) {
            return 0;
        }

        $cutoff = time() - $maxAgeSeconds;
        foreach ($files as $file) {
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime < $cutoff) {
                if (@unlink($file)) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    private function pathFor(string $sid): string
    {
        $clean = Helpers::sanitizeToken($sid);
        if ($clean === '') {
            throw new \InvalidArgumentException('Invalid session id.');
        }

        return $this->sessionsDir . DIRECTORY_SEPARATOR . $clean . '.json';
    }
}
