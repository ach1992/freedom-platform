<?php

declare(strict_types=1);

namespace App\Modules\Panels\Infrastructure;

use Psr\Http\Message\ResponseInterface;

final class PanelResponseGuard
{
    public const MAX_BYTES = 1_048_576;

    public function onHeaders(ResponseInterface $response): void
    {
        $this->assertMetadata(
            $response->getHeaderLine('Content-Encoding'),
            $response->getHeaderLine('Content-Length'),
        );
    }

    public function assertMetadata(string $contentEncoding, string $contentLength): void
    {
        $encoding = strtolower(trim($contentEncoding));
        if ($encoding !== '' && $encoding !== 'identity') {
            throw new PanelResponseRejected(
                PanelHttpFailureType::Protocol,
                'Panel response compression is not accepted.',
            );
        }

        $length = trim($contentLength);
        if ($length === '') {
            return;
        }
        if (! ctype_digit($length)) {
            throw new PanelResponseRejected(
                PanelHttpFailureType::Protocol,
                'Panel response length metadata is invalid.',
            );
        }
        if ((int) $length > self::MAX_BYTES) {
            throw new PanelResponseRejected(
                PanelHttpFailureType::ResponseTooLarge,
                'Panel response exceeded the configured size limit.',
            );
        }
    }

    public function progress(
        float $downloadTotal,
        float $downloadedBytes,
        float $uploadTotal,
        float $uploadedBytes,
    ): void {
        unset($uploadTotal, $uploadedBytes);

        if ($downloadTotal > self::MAX_BYTES || $downloadedBytes > self::MAX_BYTES) {
            throw new PanelResponseRejected(
                PanelHttpFailureType::ResponseTooLarge,
                'Panel response exceeded the configured size limit.',
            );
        }
    }
}
