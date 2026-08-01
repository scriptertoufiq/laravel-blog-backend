<?php

namespace App\Services\Media;

/**
 * Reconciles what the application wants to accept with what PHP will actually
 * let through.
 *
 * `post_max_size` and `upload_max_filesize` are enforced by PHP itself, before
 * a single line of framework code runs — an oversized body is rejected with a
 * 413 and the request never reaches validation. So a `max:` rule larger than
 * those limits is a promise the server cannot keep: the client gets an opaque
 * 413 instead of the friendly validation error the rule was written to produce.
 */
class UploadLimits
{
    /**
     * Room left for multipart boundaries and the other fields in the form.
     *
     * The whole request body counts against `post_max_size`, not just the file.
     */
    private const OVERHEAD_KILOBYTES = 64;

    /**
     * The largest upload the app should accept, in kilobytes.
     *
     * Never larger than PHP permits, so validation always wins the race against
     * PHP's own limit and the client gets a readable message.
     */
    public function maxKilobytes(int $desired): int
    {
        return max(1, min($desired, $this->serverCapacityInKilobytes()));
    }

    /**
     * Whether PHP is configured to accept everything the app wants to accept.
     *
     * Useful for surfacing a misconfiguration rather than silently shrinking.
     */
    public function permits(int $desired): bool
    {
        return $this->serverCapacityInKilobytes() >= $desired;
    }

    /**
     * The largest file PHP will accept, in kilobytes, allowing for overhead.
     */
    public function serverCapacityInKilobytes(): int
    {
        $perFile = $this->toKilobytes((string) ini_get('upload_max_filesize'));
        $perRequest = $this->toKilobytes((string) ini_get('post_max_size'));

        // A `post_max_size` of 0 means "unlimited".
        if ($perRequest <= 0) {
            $perRequest = PHP_INT_MAX;
        }

        if ($perFile <= 0) {
            $perFile = PHP_INT_MAX;
        }

        return max(1, min($perFile, $perRequest - self::OVERHEAD_KILOBYTES));
    }

    /**
     * Turn a PHP shorthand byte value ("2M", "512K", "1G") into kilobytes.
     */
    private function toKilobytes(string $value): int
    {
        $value = trim($value);

        if ($value === '') {
            return 0;
        }

        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024,
            'm' => $number * 1024,
            'k' => $number,
            default => intdiv($number, 1024),
        };
    }
}
