<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Provisioning\Application\ServiceLifecycleCommandContext;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** @requirement SVC-006 SEC-002 SEC-003 QUA-001 */
final class ServiceLifecycleCommandContextTest extends TestCase
{
    public function test_context_derives_stable_request_hash_for_one_user_actor(): void
    {
        $context = new ServiceLifecycleCommandContext(
            'request-service-command-0001',
            'correlation-service-command-0001',
            'user_requested',
            'User requested lifecycle action.',
            actorUserId: 42,
        );

        self::assertSame('user', $context->actorType());
        self::assertSame(42, $context->actorId());
        self::assertSame(hash('sha256', 'request-service-command-0001'), $context->requestHash());
    }

    public function test_context_rejects_control_characters_before_authority_creation(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ServiceLifecycleCommandContext(
            'request-service-command-0002',
            'correlation-service-command-0002',
            'user_requested',
            "unsafe\x00reason",
            actorUserId: 42,
        );
    }

    public function test_context_rejects_ambiguous_actor_identity(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ServiceLifecycleCommandContext(
            'request-service-command-0003',
            'correlation-service-command-0003',
            'admin_requested',
            'Administrator requested lifecycle action.',
            actorAdministratorId: 7,
            actorUserId: 42,
        );
    }

    public function test_context_rejects_oversized_reason(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ServiceLifecycleCommandContext(
            'request-service-command-0004',
            'correlation-service-command-0004',
            'user_requested',
            str_repeat('x', 1001),
            actorUserId: 42,
        );
    }
}
