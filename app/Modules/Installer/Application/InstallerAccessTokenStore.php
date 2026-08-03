<?php

declare(strict_types=1);

namespace App\Modules\Installer\Application;

use App\Shared\Application\Clock;
use App\Shared\Application\RandomGenerator;
use DateInterval;
use DateTimeImmutable;
use Exception;
use InvalidArgumentException;
use RuntimeException;

final readonly class InstallerAccessTokenStore
{
    /** @requirement INS-001 SEC-007 QUA-011 */
    public function __construct(
        private Clock $clock,
        private RandomGenerator $random,
        private string $path,
    ) {}

    public function issue(int $ttlMinutes): string
    {
        if ($ttlMinutes < 1 || $ttlMinutes > 60) {
            throw new InvalidArgumentException('Installer token TTL must be between 1 and 60 minutes.');
        }

        $token = bin2hex($this->random->bytes(32));
        $expiresAt = $this->clock->now()->add(new DateInterval("PT{$ttlMinutes}M"));

        $this->writeAtomically([
            'token_hash' => hash('sha256', $token),
            'expires_at' => $expiresAt->format(DATE_ATOM),
            'consumed_at' => null,
        ]);

        return $token;
    }

    public function consume(string $token): bool
    {
        $handle = @fopen($this->path, 'c+');

        if ($handle === false) {
            return false;
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException('Could not lock installer access file.');
            }

            $contents = stream_get_contents($handle);
            $record = is_string($contents) ? json_decode($contents, true) : null;

            if (! is_array($record)
                || ! is_string($record['token_hash'] ?? null)
                || ! is_string($record['expires_at'] ?? null)
                || ($record['consumed_at'] ?? null) !== null
            ) {
                return false;
            }

            try {
                $expiresAt = new DateTimeImmutable($record['expires_at']);
            } catch (Exception) {
                return false;
            }
            $valid = $expiresAt > $this->clock->now()
                && hash_equals($record['token_hash'], hash('sha256', $token));

            if (! $valid) {
                return false;
            }

            $record['consumed_at'] = $this->clock->now()->format(DATE_ATOM);
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode($record, JSON_THROW_ON_ERROR));
            fflush($handle);

            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    /** @param array{token_hash: string, expires_at: string, consumed_at: null} $record */
    private function writeAtomically(array $record): void
    {
        $directory = dirname($this->path);

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Could not create installer access directory.');
        }

        $temporary = tempnam($directory, 'access-');

        if ($temporary === false) {
            throw new RuntimeException('Could not create installer access file.');
        }

        try {
            if (file_put_contents($temporary, json_encode($record, JSON_THROW_ON_ERROR), LOCK_EX) === false) {
                throw new RuntimeException('Could not write installer access file.');
            }

            if (! chmod($temporary, 0600)) {
                throw new RuntimeException('Could not secure installer access file permissions.');
            }

            if (! rename($temporary, $this->path)) {
                throw new RuntimeException('Could not activate installer access file.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
