<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Application;

use App\Shared\Application\OutboxDispatchOutcome;
use App\Shared\Application\OutboxEventHandler;
use App\Shared\Application\OutboxMessage;
use App\Shared\Application\OutboxMessageRouter;
use PHPUnit\Framework\TestCase;

final class OutboxMessageRouterTest extends TestCase
{
    public function test_multiple_contract_versions_for_one_event_type_can_coexist(): void
    {
        $v1 = new RecordingVersionedOutboxHandler('example.changed', 1, OutboxDispatchOutcome::Success);
        $v2 = new RecordingVersionedOutboxHandler('example.changed', 2, OutboxDispatchOutcome::RetryableFailure);
        $router = new OutboxMessageRouter([$v1, $v2]);

        self::assertSame(OutboxDispatchOutcome::Success, $router->handle($this->message(1)));
        self::assertSame(OutboxDispatchOutcome::RetryableFailure, $router->handle($this->message(2)));
        self::assertSame([1], $v1->versions);
        self::assertSame([2], $v2->versions);
    }

    public function test_unknown_contract_version_fails_before_any_handler_side_effect(): void
    {
        $v1 = new RecordingVersionedOutboxHandler('example.changed', 1, OutboxDispatchOutcome::Success);
        $v2 = new RecordingVersionedOutboxHandler('example.changed', 2, OutboxDispatchOutcome::Success);
        $router = new OutboxMessageRouter([$v1, $v2]);

        self::assertSame(OutboxDispatchOutcome::DefinitiveFailure, $router->handle($this->message(3)));
        self::assertSame([], $v1->versions);
        self::assertSame([], $v2->versions);
    }

    private function message(int $contractVersion): OutboxMessage
    {
        return new OutboxMessage(
            '0198a4c7-ff31-7bb9-8222-000000000001',
            'example.changed:aggregate-1',
            'example.changed',
            'example',
            'aggregate-1',
            ['public_id' => 'aggregate-1'],
            'correlation-router-version-test',
            1,
            $contractVersion,
        );
    }
}

final class RecordingVersionedOutboxHandler implements OutboxEventHandler
{
    /** @var list<int> */
    public array $versions = [];

    public function __construct(
        private readonly string $type,
        private readonly int $version,
        private readonly OutboxDispatchOutcome $outcome,
    ) {}

    public function eventType(): string
    {
        return $this->type;
    }

    public function contractVersion(): int
    {
        return $this->version;
    }

    public function handle(OutboxMessage $message): OutboxDispatchOutcome
    {
        $this->versions[] = $message->contractVersion;

        return $this->outcome;
    }
}
