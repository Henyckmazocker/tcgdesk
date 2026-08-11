<?php

declare(strict_types=1);

namespace App\Infrastructure\RateLimit;

use Psr\Log\LoggerInterface;

/**
 * File-based rate limit counter store with atomic increments.
 *
 * Implements a fixed-window counter. Each key maps to a JSON file holding the
 * current count and the window reset timestamp. Concurrency safety is provided
 * by an exclusive flock during the read-modify-write cycle, so it is correct
 * across PHP workers on a single host without any extra extension.
 *
 * The interface is intentionally minimal so it can be swapped for a Redis-backed
 * implementation if the app is ever scaled to multiple replicas.
 */
class FileRateLimitStore
{
    private string $dir;
    private LoggerInterface $logger;

    /** Probability (1 in N) of running garbage collection on a hit. */
    private const GC_DIVISOR = 100;

    public function __construct(string $dir, LoggerInterface $logger)
    {
        $this->dir    = rtrim($dir, '/');
        $this->logger = $logger;

        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0755, true);
        }
    }

    /**
     * Register a hit for $key within a fixed window of $windowSeconds.
     *
     * @return array{count: int, reset_at: int} Current count and the unix
     *                                          timestamp when the window resets.
     */
    public function hit(string $key, int $windowSeconds): array
    {
        $now  = time();
        $path = $this->filePath($key);

        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            // Fail open: if we cannot persist counters we must not block traffic.
            $this->logger->error('RateLimit store: cannot open counter file', ['key' => $key, 'path' => $path]);
            return ['count' => 0, 'reset_at' => $now + $windowSeconds];
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                $this->logger->error('RateLimit store: cannot acquire lock', ['key' => $key]);
                return ['count' => 0, 'reset_at' => $now + $windowSeconds];
            }

            $raw  = stream_get_contents($handle);
            $data = $raw ? json_decode($raw, true) : null;

            if (!is_array($data) || !isset($data['reset_at'], $data['count']) || $now >= (int) $data['reset_at']) {
                // New or expired window: start fresh.
                $data = ['count' => 1, 'reset_at' => $now + $windowSeconds];
            } else {
                $data['count'] = (int) $data['count'] + 1;
            }

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($data));
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        if (random_int(1, self::GC_DIVISOR) === 1) {
            $this->gc();
        }

        return ['count' => (int) $data['count'], 'reset_at' => (int) $data['reset_at']];
    }

    /**
     * Delete expired counter files. Cheap best-effort cleanup.
     */
    public function gc(): int
    {
        $now   = time();
        $count = 0;

        foreach (glob($this->dir . '/*.json') ?: [] as $file) {
            $raw  = @file_get_contents($file);
            $data = $raw ? json_decode($raw, true) : null;

            if (!is_array($data) || !isset($data['reset_at']) || $now >= (int) $data['reset_at']) {
                if (@unlink($file)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Map a rate-limit key to a safe filename.
     */
    private function filePath(string $key): string
    {
        $sanitized = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key);

        if (strlen($sanitized) > 200) {
            $sanitized = substr($sanitized, 0, 180) . '_' . md5($key);
        }

        return $this->dir . '/' . $sanitized . '.json';
    }
}
