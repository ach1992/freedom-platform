<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Support;

use App\Modules\Support\Application\SupportAlertPolicy;
use Illuminate\Config\Repository;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SupportAlertPolicyTest extends TestCase
{
    public function test_alert_toggles_are_independent_and_disabled_sla_needs_no_threshold(): void
    {
        $enabledSla = new SupportAlertPolicy(new Repository([
            'support' => [
                'alerts' => [
                    'new_ticket' => ['enabled' => false],
                    'sla_delay' => ['enabled' => true, 'threshold_seconds' => 120],
                    'delivery_failure' => ['enabled' => false],
                ],
            ],
        ]));

        self::assertFalse($enabledSla->newTicketEnabled());
        self::assertSame(120, $enabledSla->slaThresholdSeconds());
        self::assertFalse($enabledSla->deliveryFailureEnabled());

        $disabledSla = new SupportAlertPolicy(new Repository([
            'support' => [
                'alerts' => [
                    'new_ticket' => ['enabled' => true],
                    'sla_delay' => ['enabled' => false, 'threshold_seconds' => null],
                    'delivery_failure' => ['enabled' => true],
                ],
            ],
        ]));

        self::assertTrue($disabledSla->newTicketEnabled());
        self::assertNull($disabledSla->slaThresholdSeconds());
        self::assertTrue($disabledSla->deliveryFailureEnabled());
    }

    public function test_invalid_new_ticket_toggle_fails_closed(): void
    {
        $policy = new SupportAlertPolicy(new Repository([
            'support' => [
                'alerts' => [
                    'new_ticket' => ['enabled' => 'invalid'],
                    'sla_delay' => ['enabled' => false, 'threshold_seconds' => null],
                    'delivery_failure' => ['enabled' => true],
                ],
            ],
        ]));

        $this->expectException(RuntimeException::class);
        $policy->newTicketEnabled();
    }

    public function test_invalid_delivery_failure_toggle_fails_closed(): void
    {
        $policy = new SupportAlertPolicy(new Repository([
            'support' => [
                'alerts' => [
                    'new_ticket' => ['enabled' => true],
                    'sla_delay' => ['enabled' => false, 'threshold_seconds' => null],
                    'delivery_failure' => ['enabled' => 'invalid'],
                ],
            ],
        ]));

        $this->expectException(RuntimeException::class);
        $policy->deliveryFailureEnabled();
    }

    public function test_enabled_sla_requires_a_bounded_threshold(): void
    {
        $policy = new SupportAlertPolicy(new Repository([
            'support' => [
                'alerts' => [
                    'new_ticket' => ['enabled' => true],
                    'sla_delay' => ['enabled' => true, 'threshold_seconds' => 59],
                    'delivery_failure' => ['enabled' => true],
                ],
            ],
        ]));

        $this->expectException(RuntimeException::class);
        $policy->slaThresholdSeconds();
    }
}
