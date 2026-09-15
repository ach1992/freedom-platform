<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Catalog;

use App\Modules\Catalog\Application\CustomPlanArithmetic;
use App\Modules\Catalog\Application\CustomPlanUsernameNormalizer;
use App\Modules\Catalog\Domain\CustomPlanPolicyDefinition;
use App\Modules\Catalog\Domain\CustomPlanPricing;
use App\Modules\Catalog\Domain\CustomPlanTagMatchMode;
use App\Modules\Catalog\Domain\CustomPlanUsernameMode;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** @requirement CAT-005 DAT-002 QUA-001 */
final class CustomPlanDomainTest extends TestCase
{
    public function test_policy_validates_ranges_pricing_and_username_rules(): void
    {
        $policy = new CustomPlanPolicyDefinition(
            true,
            10,
            100,
            10,
            30,
            90,
            30,
            new CustomPlanPricing(1_000, 100, 10, 2_000),
            new CustomPlanPricing(500, 80, 8, 1_500),
            true,
            CustomPlanTagMatchMode::All,
            CustomPlanUsernameMode::CustomerSelected,
            5,
            32,
            ['normal'],
            [10, 20],
            ['_', '-'],
            ['admin', 'support'],
        );

        self::assertSame(100, $policy->maximumDataGb);
        self::assertSame(['-', '_'], $policy->allowedSeparators);
        self::assertSame(['admin', 'support'], $policy->reservedWords);
    }

    public function test_policy_rejects_non_aligned_range(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CustomPlanPolicyDefinition(
            true,
            10,
            95,
            10,
            30,
            90,
            30,
            new CustomPlanPricing(0, 0, 0, 0),
            new CustomPlanPricing(0, 0, 0, 0),
            false,
            CustomPlanTagMatchMode::Any,
            CustomPlanUsernameMode::CustomerSelected,
            3,
            32,
            [],
            [],
            [],
            [],
        );
    }

    public function test_default_formula_applies_minimum_floor_and_agent_rate_set(): void
    {
        $components = (new CustomPlanArithmetic)->calculate(500, 80, 8, 2_000, 10, 30);
        self::assertSame(800, $components['data_price_irr']);
        self::assertSame(240, $components['day_price_irr']);
        self::assertSame(1_540, $components['subtotal_irr']);
        self::assertSame(460, $components['minimum_adjustment_irr']);
        self::assertSame(2_000, $components['final_price_irr']);
    }

    public function test_username_modes_are_normalized_and_reserved_words_fail_closed(): void
    {
        $normalizer = new CustomPlanUsernameNormalizer;
        self::assertSame(
            'u123456',
            $normalizer->normalize(
                CustomPlanUsernameMode::TelegramUserId,
                123456,
                null,
                'custom-plan-username-command-001',
                3,
                32,
                ['_'],
                [],
            ),
        );
        self::assertStringStartsWith(
            'u123456_',
            $normalizer->normalize(
                CustomPlanUsernameMode::TelegramUserIdSuffix,
                123456,
                null,
                'custom-plan-username-command-002',
                3,
                32,
                ['_'],
                [],
            ),
        );

        $this->expectException(DomainException::class);
        $normalizer->normalize(
            CustomPlanUsernameMode::CustomerSelected,
            null,
            'ADMIN',
            'custom-plan-username-command-003',
            3,
            32,
            ['_'],
            ['admin'],
        );
    }
}
