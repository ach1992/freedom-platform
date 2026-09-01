<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Provisioning;

use App\Modules\Provisioning\Application\TelegramOwnedServiceAllowedActionResolver;
use App\Modules\Telegram\Application\TelegramOwnedServiceAction;
use PHPUnit\Framework\TestCase;

final class TelegramOwnedServiceAllowedActionResolverTest extends TestCase
{
    public function test_all_supported_actions_are_returned_in_deterministic_order_when_current_policy_package_and_capability_evidence_allow_them(): void
    {
        $actions = (new TelegramOwnedServiceAllowedActionResolver)->resolve(
            'active',
            true,
            $this->policies(),
            ['add_data_days', 'renewal', 'add_days', 'add_data'],
            ['reset_usage', 'atomic_service_entitlements', 'add_data_allowance', 'update_expiry'],
        );

        self::assertSame(TelegramOwnedServiceAction::ordered(), $actions);
    }

    public function test_customer_policy_package_and_intrinsic_capability_each_fail_closed_for_only_the_affected_actions(): void
    {
        $resolver = new TelegramOwnedServiceAllowedActionResolver;
        $policies = $this->policies();
        $policies['renew']['customer_enabled'] = false;

        $actions = $resolver->resolve(
            'suspended',
            true,
            $policies,
            ['renewal', 'add_data', 'add_days'],
            ['reset_usage', 'add_data_allowance', 'update_expiry'],
        );

        self::assertSame([
            TelegramOwnedServiceAction::AddData,
            TelegramOwnedServiceAction::AddDays,
            TelegramOwnedServiceAction::ResetUsage,
        ], $actions);

        $withoutExpiry = $resolver->resolve(
            'active',
            true,
            $this->policies(),
            ['renewal', 'add_data', 'add_days', 'add_data_days'],
            ['reset_usage', 'add_data_allowance', 'atomic_service_entitlements'],
        );
        self::assertSame([
            TelegramOwnedServiceAction::AddData,
            TelegramOwnedServiceAction::ResetUsage,
        ], $withoutExpiry);
    }

    public function test_policy_specific_capability_is_required_in_addition_to_intrinsic_capabilities(): void
    {
        $policies = $this->policies();
        $policies['add_data']['required_capability_code'] = 'custom_data_gate';
        $resolver = new TelegramOwnedServiceAllowedActionResolver;

        $withoutPolicyCapability = $resolver->resolve(
            'active',
            true,
            $policies,
            ['renewal', 'add_data', 'add_days', 'add_data_days'],
            ['reset_usage', 'update_expiry', 'add_data_allowance', 'atomic_service_entitlements'],
        );
        self::assertNotContains(TelegramOwnedServiceAction::AddData, $withoutPolicyCapability);

        $withPolicyCapability = $resolver->resolve(
            'active',
            true,
            $policies,
            ['renewal', 'add_data', 'add_days', 'add_data_days'],
            ['reset_usage', 'update_expiry', 'add_data_allowance', 'atomic_service_entitlements', 'custom_data_gate'],
        );
        self::assertContains(TelegramOwnedServiceAction::AddData, $withPolicyCapability);
    }

    public function test_unprovisioned_or_incompatible_lifecycle_has_no_allowed_actions_and_unsupported_policy_is_ignored(): void
    {
        $policies = $this->policies();
        $policies['change_plan'] = ['customer_enabled' => true, 'required_capability_code' => null];
        $resolver = new TelegramOwnedServiceAllowedActionResolver;
        $packages = ['renewal', 'add_data', 'add_days', 'add_data_days'];
        $capabilities = ['reset_usage', 'update_expiry', 'add_data_allowance', 'atomic_service_entitlements'];

        self::assertSame([], $resolver->resolve('active', false, $policies, $packages, $capabilities));
        self::assertSame([], $resolver->resolve('retired', true, $policies, $packages, $capabilities));
        self::assertSame(
            TelegramOwnedServiceAction::ordered(),
            $resolver->resolve('active', true, $policies, $packages, $capabilities),
        );
    }

    /** @return array<string,array{customer_enabled:bool,required_capability_code:?string}> */
    private function policies(): array
    {
        return [
            'renew' => ['customer_enabled' => true, 'required_capability_code' => null],
            'add_data' => ['customer_enabled' => true, 'required_capability_code' => null],
            'add_days' => ['customer_enabled' => true, 'required_capability_code' => null],
            'add_data_days' => ['customer_enabled' => true, 'required_capability_code' => null],
            'reset_usage' => ['customer_enabled' => true, 'required_capability_code' => null],
        ];
    }
}