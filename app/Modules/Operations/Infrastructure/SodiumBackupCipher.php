<?php

declare(strict_types=1);

namespace App\Modules\Operations\Infrastructure;

use App\Modules\Operations\Application\Contracts\BackupArtifactCipher;
use RuntimeException;

final readonly class SodiumBackupCipher implements BackupArtifactCipher
{
    private const MAGIC = "FBKENC1\n";

    private const CHUNK_BYTES = 1_048_576;

    public function __construct(private string $key)
    {
        if (strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw new RuntimeException('The backup encryption key is invalid.');
        }
    }

    public function algorithm(): string
    {
        return 'xchacha20poly1305-secretstream-v1';
    }

    public function keyId(): string
    {
        return substr(hash('sha256', $this->key), 0, 16);
    }

    /** @return array{bytes:int,sha256:string} */
    public function encryptFile(string $sourcePath, string $destinationPath): array
    {
        $input = $this->openRead($sourcePath);
        $output = $this->openWrite($destinationPath);
        $hash = hash_init('sha256');
        $bytes = 0;
        $complete = false;

        try {
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($this->key);
            $this->writeHashed($output, self::MAGIC.$header, $hash, $bytes);

            $current = fread($input, self::CHUNK_BYTES);
            if ($current === false) {
                throw new RuntimeException('The backup plaintext could not be read.');
            }

            while (true) {
                $next = fread($input, self::CHUNK_BYTES);
                if ($next === false) {
                    throw new RuntimeException('The backup plaintext could not be read.');
                }

                $tag = $next === ''
                    ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                    : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
                $ciphertext = sodium_crypto_secretstream_xchacha20poly1305_push($state, $current, '', $tag);
                $this->writeHashed(
                    $output,
                    pack('N', strlen($ciphertext)).$ciphertext,
                    $hash,
                    $bytes,
                );

                if ($next === '') {
                    break;
                }

                $current = $next;
            }

            if (! fflush($output)) {
                throw new RuntimeException('The encrypted backup could not be flushed.');
            }

            $complete = true;
        } finally {
            fclose($input);
            fclose($output);

            if (! $complete && is_file($destinationPath)) {
                @unlink($destinationPath);
            }
        }

        if (! chmod($destinationPath, 0600)) {
            throw new RuntimeException('The encrypted backup permissions could not be secured.');
        }

        return ['bytes' => $bytes, 'sha256' => hash_final($hash)];
    }

    public function decryptFile(string $sourcePath, string $destinationPath): void
    {
        $input = $this->openRead($sourcePath);
        $output = $this->openWrite($destinationPath);
        $complete = false;

        try {
            $prefix = $this->readExact($input, strlen(self::MAGIC));
            if (! hash_equals(self::MAGIC, $prefix)) {
                throw new RuntimeException('The encrypted backup header is invalid.');
            }

            $header = $this->readExact(
                $input,
                SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES,
            );
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $this->key);
            $finalSeen = false;

            while (! feof($input)) {
                $lengthBytes = fread($input, 4);
                if ($lengthBytes === false) {
                    throw new RuntimeException('The encrypted backup frame could not be read.');
                }

                if ($lengthBytes === '') {
                    break;
                }

                if (strlen($lengthBytes) !== 4) {
                    throw new RuntimeException('The encrypted backup frame is truncated.');
                }

                $length = unpack('Nlength', $lengthBytes)['length'] ?? 0;
                if ($length < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES
                    || $length > self::CHUNK_BYTES + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES
                ) {
                    throw new RuntimeException('The encrypted backup frame length is invalid.');
                }

                $ciphertext = $this->readExact($input, $length);
                $pulled = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $ciphertext);
                if ($pulled === false) {
                    throw new RuntimeException('The encrypted backup authentication failed.');
                }

                [$plaintext, $tag] = $pulled;
                if (! in_array($tag, [
                    SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE,
                    SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL,
                ], true)) {
                    throw new RuntimeException('The encrypted backup frame sequence is invalid.');
                }

                if ($plaintext !== '' && fwrite($output, $plaintext) !== strlen($plaintext)) {
                    throw new RuntimeException('The decrypted backup could not be written.');
                }

                if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                    $finalSeen = true;
                    if (! feof($input) && fread($input, 1) !== '') {
                        throw new RuntimeException('The encrypted backup contains trailing data.');
                    }
                    break;
                }
            }

            if (! $finalSeen || ! fflush($output)) {
                throw new RuntimeException('The encrypted backup final frame is missing.');
            }

            $complete = true;
        } finally {
            fclose($input);
            fclose($output);

            if (! $complete && is_file($destinationPath)) {
                @unlink($destinationPath);
            }
        }

        if (! chmod($destinationPath, 0600)) {
            throw new RuntimeException('The decrypted backup permissions could not be secured.');
        }
    }

    /** @return resource */
    private function openRead(string $path)
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('The backup source could not be opened.');
        }

        return $handle;
    }

    /** @return resource */
    private function openWrite(string $path)
    {
        $handle = fopen($path, 'x+b');
        if ($handle === false) {
            throw new RuntimeException('The backup destination could not be created.');
        }

        return $handle;
    }

    /** @param resource $handle */
    private function readExact($handle, int $length): string
    {
        if ($length < 1) {
            throw new RuntimeException('The encrypted backup read length is invalid.');
        }
        $buffer = '';

        while (strlen($buffer) < $length) {
            $remaining = $length - strlen($buffer);
            /** @var positive-int $remaining */
            $chunk = fread($handle, $remaining);
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('The encrypted backup is truncated.');
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }

    /** @param resource $handle */
    private function writeHashed($handle, string $bytesToWrite, \HashContext $hash, int &$total): void
    {
        if (fwrite($handle, $bytesToWrite) !== strlen($bytesToWrite)) {
            throw new RuntimeException('The encrypted backup could not be written.');
        }

        hash_update($hash, $bytesToWrite);
        $total += strlen($bytesToWrite);
    }
}
