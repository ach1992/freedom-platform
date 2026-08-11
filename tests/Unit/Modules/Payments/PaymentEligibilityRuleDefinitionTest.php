<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Payments;

use App\Modules\Payments\Eligibility\Domain\PaymentEligibilityRuleDefinition;
use App\Modules\Payments\Eligibility\Domain\PaymentEligibilityRuleEffect;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PaymentEligibilityRuleDefinitionTest extends TestCase
{
    public function test_configuration_is_canonical_and_preserves_unavailable_fact_flags(): void
    {
        $definition = new PaymentEligibilityRuleDefinition(
            'bank_gateway',
            'vip_only',
            true,
            PaymentEligibilityRuleEffect::Allow,
            100,
            null,
            ['customer'],
            ['vip', 'normal', 'vip'],
            ['priority', 'priority', 'verified'],
            100,
            1_000,
            ['starter', 'starter'],
            [9, 2, 9],
            [4, 1, 4],
            'verified',
            null,
            '09:00',
            '17:00',
            true,
            true,
            true,
        );

        self::assertSame(
            ['account_types', 'effect', 'enabled', 'ends_at_utc', 'formula_version', 'maximum_amount_irr', 'method_code', 'minimum_amount_irr', 'offering_codes', 'priority', 'product_ids', 'required_agent_status', 'required_identity_status', 'requires_contact_otp_provenance', 'requires_daily_payment_limit', 'requires_purchase_history', 'rule_code', 'sales_server_ids', 'starts_at_utc', 'subject_user_id', 'tag_codes', 'tier_codes'],
            array_keys($definition->configuration()),
        );
        self::assertSame(['normal', 'vip'], $definition->configuration()['tier_codes']);
        self::assertSame(['priority', 'verified'], $definition->configuration()['tag_codes']);
        self::assertSame([2, 9], $definition->configuration()['product_ids']);
        self::assertTrue($definition->configuration()['requires_contact_otp_provenance']);
        self::assertTrue($definition->configuration()['requires_purchase_history']);
        self::assertTrue($definition->configuration()['requires_daily_payment_limit']);
    }

    public function test_invalid_range_and_time_window_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PaymentEligibilityRuleDefinition(
            'bank_gateway',
            'invalid',
            true,
            PaymentEligibilityRuleEffect::Allow,
            1,
            minimumAmountIrr: 2,
            maximumAmountIrr: 1,
        );
    }
}
