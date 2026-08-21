<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Agents\Domain\AgentPricingAction;
use App\Modules\Orders\Application\QuoteAgentPricingContext;
use App\Modules\Orders\Application\QuotePricingInput;
use App\Modules\Orders\Application\QuoteReceipt;
use App\Modules\Orders\Application\ServicePackageQuoteContext;
use App\Modules\Orders\Domain\QuoteAction;
use App\Modules\Orders\Domain\QuoteOverrideSource;
use App\Modules\Panels\Application\Contracts\PanelServiceStatus;
use App\Modules\Payments\Eligibility\Application\PaymentEligibilityDecisionReceipt;
use App\Modules\Provisioning\Domain\AutoRenewPriceChangeMode;
use DateTimeImmutable;
use DomainException;
use Illuminate\Database\Connection;
use RuntimeException;

trait ServiceAutoRenewalRemoteOperations
{
    /** @param ServiceAutoRenewConfigurationFacts $facts */
    private function refreshRemoteObservation(object $facts): bool
    {
        $observation = $this->remoteObservation($facts);
        $correlationId = hash('sha256', 'auto-renew-observation:'.(int) $facts->config_id.':'.$observation['evidence_hash']);

        return $this->database->connection()->transaction(function (Connection $connection) use ($facts, $observation, $correlationId): bool {
            $current = $this->configurationFactsOn($connection, (int) $facts->config_id, true);
            if (! (bool) $current->enabled
                || (int) $current->configuration_version !== (int) $facts->configuration_version
                || (int) $current->remote_identity_generation !== (int) $facts->remote_identity_generation) {
                return false;
            }

            $connection->table('service_auto_renew_configurations')->where('id', (int) $facts->config_id)->update([
                'observed_expires_at' => $this->databaseDateTime($observation['expires_at']),
                'expiry_observed_at' => $this->timestamp(),
                'observed_expiry_evidence_hash' => $observation['evidence_hash'],
                'observed_expiry_source' => 'remote_snapshot',
                'observed_remote_identity_generation' => (int) $facts->remote_identity_generation,
                'last_correlation_id' => $correlationId,
                'updated_at' => $this->timestamp(),
            ]);

            return true;
        }, 3);
    }

    /**
     * @param ServiceAutoRenewConfigurationFacts $facts
     * @return array{expires_at:DateTimeImmutable,evidence_hash:string}
     */
    private function remoteObservation(object $facts): array
    {
        if ($facts->service_target_id === null || ! is_string($facts->remote_service_id) || $facts->remote_service_id === '') {
            throw new DomainException('Auto-renew Service has no current remote identity.');
        }
        // Network/provider I/O must stay outside any DB transaction.
        if ($this->database->connection()->transactionLevel() !== 0) {
            throw new RuntimeException('Auto-renew remote observation cannot run inside a database transaction.');
        }
        $adapter = $this->panelAdapters->resolve((int) $facts->service_target_id);
        $remote = $adapter->findByRemoteId($facts->remote_service_id);
        if ($remote === null || ! hash_equals($facts->remote_service_id, $remote->remoteId)) {
            throw new DomainException('Auto-renew could not verify current remote Service identity.');
        }
        if ($remote->expiresAt === null) {
            throw new DomainException('Auto-renew requires finite remote expiry evidence.');
        }
        if (in_array($remote->status, [PanelServiceStatus::Unknown, PanelServiceStatus::Disabled], true)) {
            throw new DomainException('Auto-renew remote Service state is not renewable.');
        }

        return ['expires_at' => $remote->expiresAt, 'evidence_hash' => strtolower($remote->canonicalHash)];
    }

    /**
     * @param ServiceAutoRenewAttemptRow $attempt
     * @param ServiceAutoRenewConfigurationFacts $facts
     */
    private function freshQuote(object $attempt, object $facts): QuoteReceipt
    {
        $ttlMinutes = $this->boundedConfigInt('auto_renew.quote_ttl_minutes', 15, 1, 120);
        $bucket = intdiv($this->clock->now()->getTimestamp(), $ttlMinutes * 60);
        $agentContext = $facts->account_type === 'agent'
            ? new QuoteAgentPricingContext((int) $facts->user_id, AgentPricingAction::Renew)
            : null;
        $quote = $this->quotes->create(
            'service.auto-renew.quote.'.(string) $attempt->public_id.'.'.$bucket,
            (int) $facts->user_id,
            (int) $facts->plan_offering_id,
            new QuotePricingInput(
                QuoteOverrideSource::None,
                null,
                null,
                null,
                0,
                $this->clock->now()->modify('+'.$ttlMinutes.' minutes'),
            ),
            (string) $attempt->correlation_id,
            $agentContext,
            new ServicePackageQuoteContext((string) $facts->service_public_id, (string) $facts->package_code),
        );
        if ($quote->action !== QuoteAction::Renew || $quote->servicePackage === null || $quote->finalPriceIrr < 1) {
            throw new DomainException('Auto-renew requires a positive current renewal Quote.');
        }

        return $quote;
    }

    /** @return array{mode:AutoRenewPriceChangeMode,absolute:?int,percentage:?int} */
    private function pricePolicyForOffering(int $planOfferingId): array
    {
        return $this->pricePolicyForOfferingOn($this->database->connection(), $planOfferingId, false);
    }

    /** @return array{mode:AutoRenewPriceChangeMode,absolute:?int,percentage:?int} */
    private function pricePolicyForOfferingOn(Connection $connection, int $planOfferingId, bool $lock): array
    {
        $query = $connection->table('plan_offering_auto_renew_policies')
            ->where('plan_offering_id', $planOfferingId);
        if ($lock) {
            $query->lockForUpdate();
        }
        /** @var object{price_change_mode:string,absolute_increase_limit_irr:int|string|null,percentage_increase_limit_bps:int|string|null}|null $row */
        $row = $query->first(['price_change_mode', 'absolute_increase_limit_irr', 'percentage_increase_limit_bps']);
        if ($row === null) {
            return ['mode' => AutoRenewPriceChangeMode::Stop, 'absolute' => null, 'percentage' => null];
        }

        return [
            'mode' => AutoRenewPriceChangeMode::from((string) $row->price_change_mode),
            'absolute' => $row->absolute_increase_limit_irr === null ? null : (int) $row->absolute_increase_limit_irr,
            'percentage' => $row->percentage_increase_limit_bps === null ? null : (int) $row->percentage_increase_limit_bps,
        ];
    }

    private function walletIsEligible(PaymentEligibilityDecisionReceipt $decision): bool
    {
        foreach ($decision->methods as $method) {
            if ($method['method_code'] === 'wallet') {
                return true;
            }
        }

        return false;
    }

    private function walletAccountId(int $userId): ?int
    {
        $id = $this->database->connection()->table('ledger_accounts')
            ->where('owner_user_id', $userId)
            ->where('wallet_bucket', 'cash')
            ->where('account_class', 'liability')
            ->where('currency', 'IRR')
            ->where('is_active', true)
            ->value('id');

        return is_int($id) || is_string($id) ? (int) $id : null;
    }
}
