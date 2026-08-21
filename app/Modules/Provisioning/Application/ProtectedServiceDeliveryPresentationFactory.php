<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Panels\Application\Contracts\SensitiveDeliveryArtifacts;
use App\Modules\Telegram\Application\ProtectedTelegramPresentation;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Contracts\Config\Repository;
use Throwable;

/**
 * A bounded policy selects exactly one protected presentation. QR source data
 * and generated SVG remain in memory and never enter durable evidence.
 */
final readonly class ProtectedServiceDeliveryPresentationFactory
{
    public function __construct(private Repository $config) {}

    public function make(SensitiveDeliveryArtifacts $artifacts): ?ProtectedTelegramPresentation
    {
        return $this->mode() === 'qr'
            ? $this->qrPresentation($artifacts)
            : $this->textPresentation($artifacts);
    }

    private function textPresentation(SensitiveDeliveryArtifacts $artifacts): ?ProtectedTelegramPresentation
    {
        $links = $artifacts->revealForAuthorizedDelivery();
        if ($links === []) {
            return null;
        }

        $threshold = $this->boundedInt('inline_link_threshold', 1, 8);
        if (count($links) > $threshold) {
            $links = [reset($links)];
        }

        $parts = ['Service details'];
        foreach ($links as $index => $link) {
            $parts[] = count($links) === 1 ? $link : 'Link '.($index + 1).":\n".$link;
        }

        return ProtectedTelegramPresentation::text(implode("\n\n", $parts));
    }

    private function qrPresentation(SensitiveDeliveryArtifacts $artifacts): ?ProtectedTelegramPresentation
    {
        $sources = $artifacts->revealQrSourcesForAuthorizedDelivery();
        $source = $sources[$this->boundedInt('qr_source_index', 0, 7)] ?? null;
        if (! is_string($source) || $source === '') {
            return null;
        }

        $maximumDocumentBytes = $this->boundedInt('max_document_bytes', 1_024, 1_048_576);
        try {
            $svg = (new SvgWriter)->write(new QrCode(
                data: $source,
                encoding: new Encoding('UTF-8'),
                errorCorrectionLevel: ErrorCorrectionLevel::Medium,
                size: 300,
                margin: 10,
                roundBlockSizeMode: RoundBlockSizeMode::Margin,
            ))->getString();
        } catch (Throwable) {
            return null;
        }
        if (strlen($svg) > $maximumDocumentBytes) {
            return null;
        }

        return ProtectedTelegramPresentation::svgDocument($svg, 'Service details');
    }

    private function mode(): string
    {
        $mode = $this->config->get('service_delivery.presentation.mode', 'text');
        if (! is_string($mode) || ! in_array($mode, ['text', 'qr'], true)) {
            throw new \LogicException('Service delivery presentation mode is invalid.');
        }

        return $mode;
    }

    private function boundedInt(string $key, int $minimum, int $maximum): int
    {
        $value = $this->config->get('service_delivery.presentation.'.$key);
        if (is_string($value) && preg_match('/\A(?:0|[1-9][0-9]*)\z/', $value) === 1) {
            $value = (int) $value;
        }
        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            throw new \LogicException('Service delivery presentation policy is invalid.');
        }

        return $value;
    }
}
