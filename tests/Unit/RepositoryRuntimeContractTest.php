<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/** @requirement QUA-001 QUA-004 INS-001 */
final class RepositoryRuntimeContractTest extends TestCase
{
    public function test_runtime_timezone_and_phpunit_risky_gate_are_explicit(): void
    {
        self::assertSame('UTC', config('app.timezone'));
        self::assertSame('Asia/Tehran', config('business.display_timezone'));

        $environment = file_get_contents(base_path('.env.example'));
        self::assertIsString($environment);
        self::assertDoesNotMatchRegularExpression('/^APP_TIMEZONE=/m', $environment);
        self::assertMatchesRegularExpression('/^BUSINESS_TIMEZONE=Asia\/Tehran$/m', $environment);

        $phpunit = file_get_contents(base_path('phpunit.xml'));
        self::assertIsString($phpunit);
        self::assertStringContainsString('failOnRisky="true"', $phpunit);
    }

    public function test_operator_sample_exposes_supported_platform_runtime_controls(): void
    {
        $environment = file_get_contents(base_path('.env.example'));
        self::assertIsString($environment);

        foreach ([
            'C2C_GENERIC_REST_PROVIDERS_JSON',
            'GIFT_CARD_GENERIC_REST_PROVIDERS_JSON',
            'USDT_BEP20_GENERIC_REST_PROVIDERS_JSON',
            'SERVICE_DELIVERY_PRESENTATION_MODE',
            'SERVICE_DELIVERY_INLINE_LINK_THRESHOLD',
            'SERVICE_DELIVERY_QR_SOURCE_INDEX',
            'SERVICE_DELIVERY_MAX_DOCUMENT_BYTES',
            'SERVICE_NOTIFICATION_SYNC_SNAPSHOT_MAX_AGE_SECONDS',
            'SERVICE_NOTIFICATION_USAGE_MAX_RETRIES',
            'SERVICE_NOTIFICATION_USAGE_RETRY_BASE_SECONDS',
            'SERVICE_NOTIFICATION_USAGE_RETRY_MAX_SECONDS',
            'SERVICE_NOTIFICATION_STATE_MAX_RETRIES',
            'SERVICE_NOTIFICATION_STATE_RETRY_BASE_SECONDS',
            'SERVICE_NOTIFICATION_STATE_RETRY_MAX_SECONDS',
            'SERVICE_NOTIFICATION_SYNC_ISSUE_MAX_RETRIES',
            'SERVICE_NOTIFICATION_SYNC_ISSUE_RETRY_BASE_SECONDS',
            'SERVICE_NOTIFICATION_SYNC_ISSUE_RETRY_MAX_SECONDS',
            'TELEGRAM_PRIVATE_MEDIA_MAX_BYTES',
            'WALLET_CORRECTION_DUAL_APPROVAL_THRESHOLD_IRR',
            'WALLET_CORRECTION_APPROVAL_TTL_SECONDS',
        ] as $key) {
            self::assertMatchesRegularExpression('/^'.preg_quote($key, '/').'=.*$/m', $environment);
        }
    }
}
