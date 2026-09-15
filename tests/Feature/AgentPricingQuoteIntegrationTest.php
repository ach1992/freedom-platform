<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Orders\Application\QuotePricingInput;
use App\Modules\Orders\Domain\QuoteOverrideSource;
use App\Shared\Application\Clock;
use DateTimeImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class MutableAgentPricingQuoteClock implements Clock
{
    public function __construct(public DateTimeImmutable $value) {}

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
}

/** @requirement AGT-005 BUY-002 PRO-001 DAT-002 DAT-003 DAT-004 SEC-001 SEC-002 QUA-001 */
final class AgentPricingQuoteIntegrationTest extends TestCase
{
    use AgentPricingQuoteIntegrationAuthorizationScenarios;
    use AgentPricingQuoteIntegrationCoreScenarios;
    use AgentPricingQuoteIntegrationHistoryScenarios;
    use AgentPricingQuoteIntegrationTestSupport;
    use RefreshDatabase;

    private MutableAgentPricingQuoteClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->clock = new MutableAgentPricingQuoteClock(new DateTimeImmutable('2026-08-09T06:00:00+00:00'));
        $this->app->instance(Clock::class, $this->clock);
    }

    private function pricing(?string $discountReference, int $discountIrr): QuotePricingInput
    {
        return new QuotePricingInput(
            QuoteOverrideSource::None,
            null,
            null,
            $discountReference,
            $discountIrr,
            $this->clock->value->modify('+30 minutes'),
        );
    }

    private function correlation(string $suffix): string
    {
        return substr(hash('sha256', 'agent-pricing-quote:'.$suffix), 0, 64);
    }

    private function assertAuthorizationDenied(callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected authorization denial.');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }
    }

    private function assertDomainMessage(string $message, callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected domain exception.');
        } catch (DomainException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }

    private function assertRuntimeMessage(string $message, callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected runtime exception.');
        } catch (RuntimeException $exception) {
            self::assertSame($message, $exception->getMessage());
        }
    }

    private function assertQueryRejected(callable $callback): void
    {
        try {
            $callback();
            self::fail('Expected MariaDB rejection.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }
}
