<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\AccessControl\Application\AdministratorPermissionAuthorizer;
use App\Modules\Provisioning\Domain\AutoRenewPriceChangeMode;
use App\Shared\Application\Clock;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use RuntimeException;

/** @requirement SVC-007 ACL-002 DAT-002 DAT-003 QUA-001 */
final readonly class ServiceAutoRenewPolicyService
{
    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
        private AdministratorPermissionAuthorizer $authorizer,
    ) {}

    public function configure(
        string $requestKey,
        int $administratorId,
        int $planOfferingId,
        AutoRenewPriceChangeMode $mode,
        ?int $absoluteIncreaseLimitIrr,
        ?int $percentageIncreaseLimitBps,
        string $reasonCode,
        string $reason,
        string $correlationId,
    ): ServiceAutoRenewPolicyReceipt {
        $this->assertToken($requestKey, 'Auto-renew policy request key', 8, 128);
        $this->assertPositiveId($administratorId, 'Auto-renew policy administrator ID');
        $this->assertPositiveId($planOfferingId, 'Auto-renew policy offering ID');
        $this->assertToken($reasonCode, 'Auto-renew policy reason code', 2, 64);
        $this->assertText($reason, 'Auto-renew policy reason', 4, 1000);
        $this->assertToken($correlationId, 'Auto-renew policy correlation ID', 8, 64);
        $this->assertLimits($mode, $absoluteIncreaseLimitIrr, $percentageIncreaseLimitBps);
        $this->authorizer->authorize($administratorId, 'catalog.manage');

        $requestHash = hash('sha256', $requestKey);
        $payloadHash = hash('sha256', json_encode([
            'administrator_id' => $administratorId,
            'plan_offering_id' => $planOfferingId,
            'mode' => $mode->value,
            'absolute_increase_limit_irr' => $absoluteIncreaseLimitIrr,
            'percentage_increase_limit_bps' => $percentageIncreaseLimitBps,
            'reason_code' => $reasonCode,
            'reason' => trim($reason),
        ], JSON_THROW_ON_ERROR));

        try {
            return $this->database->connection()->transaction(function (Connection $connection) use (
            $requestHash,
            $payloadHash,
            $administratorId,
            $planOfferingId,
            $mode,
            $absoluteIncreaseLimitIrr,
            $percentageIncreaseLimitBps,
            $reasonCode,
            $reason,
            $correlationId,
        ): ServiceAutoRenewPolicyReceipt {
            $history = $connection->table('plan_offering_auto_renew_policy_histories')
                ->where('request_key_hash', $requestHash)
                ->first([
                    'auto_renew_policy_id', 'version', 'price_change_mode', 'absolute_increase_limit_irr',
                    'percentage_increase_limit_bps', 'payload_hash',
                ]);
            if ($history !== null) {
                if (! hash_equals((string) $history->payload_hash, $payloadHash)) {
                    throw new DomainException('Auto-renew policy request key conflicts with an accepted request.');
                }

                $policy = $connection->table('plan_offering_auto_renew_policies')
                    ->where('id', (int) $history->auto_renew_policy_id)
                    ->first(['plan_offering_id']);
                if ($policy === null || (int) $policy->plan_offering_id !== $planOfferingId) {
                    throw new RuntimeException('Auto-renew policy replay authority is inconsistent.');
                }

                return new ServiceAutoRenewPolicyReceipt(
                    (int) $history->auto_renew_policy_id,
                    $planOfferingId,
                    AutoRenewPriceChangeMode::from((string) $history->price_change_mode),
                    $history->absolute_increase_limit_irr === null ? null : (int) $history->absolute_increase_limit_irr,
                    $history->percentage_increase_limit_bps === null ? null : (int) $history->percentage_increase_limit_bps,
                    (int) $history->version,
                    true,
                );
            }

            $offering = $connection->table('plan_offerings')
                ->where('id', $planOfferingId)
                ->lockForUpdate()
                ->first(['id', 'state', 'auto_renew_allowed']);
            if ($offering === null || $offering->state !== 'active' || ! (bool) $offering->auto_renew_allowed) {
                throw new DomainException('Auto-renew policy requires an active auto-renew-enabled offering.');
            }

            $policy = $connection->table('plan_offering_auto_renew_policies')
                ->where('plan_offering_id', $planOfferingId)
                ->lockForUpdate()
                ->first([
                    'id', 'price_change_mode', 'absolute_increase_limit_irr',
                    'percentage_increase_limit_bps', 'version',
                ]);
            $timestamp = $this->timestamp();
            if ($policy === null) {
                $policyId = (int) $connection->table('plan_offering_auto_renew_policies')->insertGetId([
                    'plan_offering_id' => $planOfferingId,
                    'price_change_mode' => $mode->value,
                    'absolute_increase_limit_irr' => $absoluteIncreaseLimitIrr,
                    'percentage_increase_limit_bps' => $percentageIncreaseLimitBps,
                    'version' => 1,
                    'actor_administrator_id' => $administratorId,
                    'correlation_id' => $correlationId,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
                $version = 1;
            } else {
                $policyId = (int) $policy->id;
                $changed = $policy->price_change_mode !== $mode->value
                    || ($policy->absolute_increase_limit_irr === null ? null : (int) $policy->absolute_increase_limit_irr) !== $absoluteIncreaseLimitIrr
                    || ($policy->percentage_increase_limit_bps === null ? null : (int) $policy->percentage_increase_limit_bps) !== $percentageIncreaseLimitBps;
                $version = (int) $policy->version;
                if ($changed) {
                    $version++;
                    $connection->table('plan_offering_auto_renew_policies')->where('id', $policyId)->update([
                        'price_change_mode' => $mode->value,
                        'absolute_increase_limit_irr' => $absoluteIncreaseLimitIrr,
                        'percentage_increase_limit_bps' => $percentageIncreaseLimitBps,
                        'version' => $version,
                        'actor_administrator_id' => $administratorId,
                        'correlation_id' => $correlationId,
                        'updated_at' => $timestamp,
                    ]);
                }
            }

            $connection->table('plan_offering_auto_renew_policy_histories')->insert([
                'auto_renew_policy_id' => $policyId,
                'version' => $version,
                'price_change_mode' => $mode->value,
                'absolute_increase_limit_irr' => $absoluteIncreaseLimitIrr,
                'percentage_increase_limit_bps' => $percentageIncreaseLimitBps,
                'actor_administrator_id' => $administratorId,
                'request_key_hash' => $requestHash,
                'payload_hash' => $payloadHash,
                'reason_code' => $reasonCode,
                'reason' => trim($reason),
                'correlation_id' => $correlationId,
                'created_at' => $timestamp,
            ]);

            return new ServiceAutoRenewPolicyReceipt(
                $policyId,
                $planOfferingId,
                $mode,
                $absoluteIncreaseLimitIrr,
                $percentageIncreaseLimitBps,
                $version,
                false,
            );
            }, 3);
        } catch (QueryException $exception) {
            $replay = $this->replay($requestHash, $payloadHash, $planOfferingId);
            if ($replay !== null) {
                return $replay;
            }

            throw $exception;
        }
    }

    private function replay(string $requestHash, string $payloadHash, int $planOfferingId): ?ServiceAutoRenewPolicyReceipt
    {
        $history = $this->database->connection()->table('plan_offering_auto_renew_policy_histories')
            ->where('request_key_hash', $requestHash)
            ->first([
                'auto_renew_policy_id', 'version', 'price_change_mode', 'absolute_increase_limit_irr',
                'percentage_increase_limit_bps', 'payload_hash',
            ]);
        if ($history === null) {
            return null;
        }
        if (! hash_equals((string) $history->payload_hash, $payloadHash)) {
            throw new DomainException('Auto-renew policy request key conflicts with an accepted request.');
        }
        $offeringId = $this->database->connection()->table('plan_offering_auto_renew_policies')
            ->where('id', (int) $history->auto_renew_policy_id)
            ->value('plan_offering_id');
        if ($offeringId === null || (int) $offeringId !== $planOfferingId) {
            throw new RuntimeException('Auto-renew policy replay authority is inconsistent.');
        }

        return new ServiceAutoRenewPolicyReceipt(
            (int) $history->auto_renew_policy_id,
            $planOfferingId,
            AutoRenewPriceChangeMode::from((string) $history->price_change_mode),
            $history->absolute_increase_limit_irr === null ? null : (int) $history->absolute_increase_limit_irr,
            $history->percentage_increase_limit_bps === null ? null : (int) $history->percentage_increase_limit_bps,
            (int) $history->version,
            true,
        );
    }

    private function assertLimits(
        AutoRenewPriceChangeMode $mode,
        ?int $absoluteIncreaseLimitIrr,
        ?int $percentageIncreaseLimitBps,
    ): void {
        if ($absoluteIncreaseLimitIrr !== null && $absoluteIncreaseLimitIrr < 0) {
            throw new DomainException('Auto-renew absolute price increase limit must be non-negative integer IRR.');
        }
        if ($percentageIncreaseLimitBps !== null && ($percentageIncreaseLimitBps < 0 || $percentageIncreaseLimitBps > 1_000_000)) {
            throw new DomainException('Auto-renew percentage price increase limit is invalid.');
        }
        if ($mode === AutoRenewPriceChangeMode::WithinLimit) {
            if ($absoluteIncreaseLimitIrr === null && $percentageIncreaseLimitBps === null) {
                throw new DomainException('Auto-renew within-limit policy requires at least one configured bound.');
            }

            return;
        }
        if ($absoluteIncreaseLimitIrr !== null || $percentageIncreaseLimitBps !== null) {
            throw new DomainException('Auto-renew price limits are valid only for within-limit policy.');
        }
    }

    private function timestamp(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }

    private function assertPositiveId(int $value, string $label): void
    {
        if ($value < 1) {
            throw new DomainException($label.' must be positive.');
        }
    }

    private function assertToken(string $value, string $label, int $minimum, int $maximum): void
    {
        if (strlen($value) < $minimum || strlen($value) > $maximum || preg_match('/\A[a-zA-Z0-9_.:-]+\z/', $value) !== 1) {
            throw new DomainException($label.' is invalid.');
        }
    }

    private function assertText(string $value, string $label, int $minimum, int $maximum): void
    {
        $trimmed = trim($value);
        if (mb_strlen($trimmed) < $minimum || mb_strlen($trimmed) > $maximum || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $trimmed) === 1) {
            throw new DomainException($label.' is invalid.');
        }
    }
}
