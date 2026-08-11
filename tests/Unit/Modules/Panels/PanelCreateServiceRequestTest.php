<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Panels;

use App\Modules\Panels\Application\Contracts\PanelCreateServiceRequest;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use stdClass;

/** @requirement PRV-001 PRV-002 SEC-002 QUA-001 */
final class PanelCreateServiceRequestTest extends TestCase
{
    public function test_validated_attributes_are_stored_in_deterministic_key_order(): void
    {
        $request = new PanelCreateServiceRequest(
            'operation-00000001',
            'panel:create:request-00000001',
            'fp_user_001',
            'fake-default',
            null,
            null,
            ['profile_code' => 'vless-ws-tls', 'device_limit' => 1],
        );

        self::assertSame(
            ['device_limit' => 1, 'profile_code' => 'vless-ws-tls'],
            $request->validatedAttributes,
        );
        self::assertNull($request->dataLimitBytes);
    }

    public function test_blank_idempotency_key_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Panel idempotency key is invalid.');

        new PanelCreateServiceRequest(
            'operation-00000001',
            '',
            'fp_user_001',
            'fake-default',
            null,
            null,
            [],
        );
    }

    public function test_zero_data_limit_is_rejected_instead_of_implicitly_meaning_unlimited(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Panel data limit must be positive or unlimited.');

        new PanelCreateServiceRequest(
            'operation-00000001',
            'panel:create:request-00000001',
            'fp_user_001',
            'fake-default',
            0,
            null,
            [],
        );
    }

    public function test_non_scalar_validated_attribute_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Panel validated attribute value is invalid.');

        new PanelCreateServiceRequest(
            'operation-00000001',
            'panel:create:request-00000001',
            'fp_user_001',
            'fake-default',
            null,
            null,
            ['invalid' => new stdClass],
        );
    }
}
