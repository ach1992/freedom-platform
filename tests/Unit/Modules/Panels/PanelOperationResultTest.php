<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Panels;

use App\Modules\Panels\Application\Contracts\PanelOperationOutcome;
use App\Modules\Panels\Application\Contracts\PanelOperationResult;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** @requirement PRV-001 SEC-002 QUA-001 */
final class PanelOperationResultTest extends TestCase
{
    public function test_safe_provider_metadata_is_preserved(): void
    {
        $result = new PanelOperationResult(
            PanelOperationOutcome::RetryableFailure,
            null,
            'provider_http_429',
            'Provider rate limit was reached.',
        );

        self::assertSame('provider_http_429', $result->providerCode);
        self::assertSame('Provider rate limit was reached.', $result->safeMessage);
    }

    public function test_raw_multiline_provider_message_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Panel safe result message is invalid.');

        new PanelOperationResult(
            PanelOperationOutcome::DefinitiveFailure,
            null,
            'provider_failure',
            "Provider response:\nraw body",
        );
    }

    public function test_unstructured_provider_code_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Panel provider result code is invalid.');

        new PanelOperationResult(
            PanelOperationOutcome::DefinitiveFailure,
            null,
            'HTTP 500 / raw',
            'Provider request failed.',
        );
    }
}
