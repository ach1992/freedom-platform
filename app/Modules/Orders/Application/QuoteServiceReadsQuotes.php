<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application;

use App\Modules\Agents\Domain\AgentPricingAction;
use App\Modules\Orders\Domain\QuoteOverrideSource;
use DateTimeZone;
use DomainException;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * @phpstan-type QuoteRow object{
 *     id:int|string, public_id:string, quote_key:string, request_payload_hash:string, user_id:int|string, account_type_snapshot:string,
 *     plan_offering_id:int|string, offering_code_snapshot:string, offering_version:int|string, offering_configuration_hash:string,
 *     offering_discount_eligible:int|bool|string, base_price_irr:int|string, override_source:string, override_reference_code:string|null,
 *     override_price_irr:int|string|null, effective_price_irr:int|string, discount_reference_code:string|null, discount_irr:int|string,
 *     final_price_irr:int|string, currency:string, configuration_snapshot_hash:string, agent_pricing_resolution_id:int|string|null,
 *     agent_pricing_resolution_public_id:string|null, agent_pricing_resolution_configuration_hash:string|null, agent_profile_id_snapshot:int|string|null,
 *     agent_pricing_profile_id_snapshot:int|string|null, agent_pricing_profile_public_id_snapshot:string|null, agent_pricing_profile_code_snapshot:string|null,
 *     agent_pricing_profile_version_snapshot:int|string|null, agent_pricing_profile_configuration_hash:string|null, agent_pricing_action_snapshot:string|null,
 *     agent_pricing_rule_id_snapshot:int|string|null, agent_pricing_rule_public_id_snapshot:string|null, agent_pricing_rule_code_snapshot:string|null,
 *     agent_pricing_rule_version_snapshot:int|string|null, agent_pricing_rule_configuration_hash:string|null, agent_discount_combination_allowed:int|bool|string|null,
 *     valid_from:string, expires_at:string
 * }
 */
trait QuoteServiceReadsQuotes
{
    /** @requirement BUY-002 DAT-004 QUA-001 */
    public function current(string $quotePublicId): QuoteReceipt
    {
        $this->assertUlid($quotePublicId, 'Quote public ID');
        $connection = $this->database->connection();
        /** @var QuoteRow|null $row */
        $row = $connection->table('quotes')
            ->where('public_id', $quotePublicId)
            ->first($this->quoteColumns());
        if ($row === null) {
            throw new DomainException('Quote does not exist.');
        }

        $receipt = $this->quoteReceipt($row, $row->request_payload_hash, true);
        if ($receipt->isExpiredAt($this->clock->now()->setTimezone(new DateTimeZone('UTC')))) {
            throw new RuntimeException('Quote has expired.');
        }

        return $receipt;
    }

    /** @return QuoteRow|null */
    private function quoteByKey(Connection $connection, string $quoteKey, bool $lock = false): ?object
    {
        $query = $connection->table('quotes')->where('quote_key', $quoteKey);
        if ($lock) {
            $query->lockForUpdate();
        }
        /** @var QuoteRow|null $row */
        $row = $query->first($this->quoteColumns());

        return $row;
    }

    /** @return QuoteRow|null */
    private function quoteById(Connection $connection, int $quoteId): ?object
    {
        /** @var QuoteRow|null $row */
        $row = $connection->table('quotes')->where('id', $quoteId)->first($this->quoteColumns());

        return $row;
    }

    /** @return list<string> */
    private function quoteColumns(): array
    {
        return [
            'id', 'public_id', 'quote_key', 'request_payload_hash', 'user_id', 'account_type_snapshot',
            'plan_offering_id', 'offering_code_snapshot', 'offering_version', 'offering_configuration_hash',
            'offering_discount_eligible', 'base_price_irr', 'override_source', 'override_reference_code',
            'override_price_irr', 'effective_price_irr', 'discount_reference_code', 'discount_irr',
            'final_price_irr', 'currency', 'configuration_snapshot_hash',
            'agent_pricing_resolution_id', 'agent_pricing_resolution_public_id',
            'agent_pricing_resolution_configuration_hash', 'agent_profile_id_snapshot',
            'agent_pricing_profile_id_snapshot', 'agent_pricing_profile_public_id_snapshot',
            'agent_pricing_profile_code_snapshot', 'agent_pricing_profile_version_snapshot',
            'agent_pricing_profile_configuration_hash', 'agent_pricing_action_snapshot',
            'agent_pricing_rule_id_snapshot', 'agent_pricing_rule_public_id_snapshot',
            'agent_pricing_rule_code_snapshot', 'agent_pricing_rule_version_snapshot',
            'agent_pricing_rule_configuration_hash', 'agent_discount_combination_allowed',
            'valid_from', 'expires_at',
        ];
    }

    /** @param QuoteRow $row */
    private function quoteReceipt(object $row, string $requestPayloadHash, bool $replayed): QuoteReceipt
    {
        if (! hash_equals($row->request_payload_hash, $requestPayloadHash)) {
            throw new RuntimeException('Quote key conflict.');
        }
        $overrideSource = QuoteOverrideSource::tryFrom($row->override_source)
            ?? throw new RuntimeException('Stored quote override source is invalid.');

        return new QuoteReceipt(
            $this->positiveDatabaseInt($row->id, 'Quote ID'),
            $row->public_id,
            $row->quote_key,
            $this->positiveDatabaseInt($row->user_id, 'Quote user ID'),
            $row->account_type_snapshot,
            $this->positiveDatabaseInt($row->plan_offering_id, 'Quote offering ID'),
            $row->offering_code_snapshot,
            $this->positiveDatabaseInt($row->offering_version, 'Quote offering version'),
            $row->offering_configuration_hash,
            (bool) $row->offering_discount_eligible,
            $this->nonNegativeDatabaseInt($row->base_price_irr, 'Quote base price'),
            $overrideSource,
            $row->override_reference_code,
            $row->override_price_irr === null ? null : $this->nonNegativeDatabaseInt($row->override_price_irr, 'Quote override price'),
            $this->nonNegativeDatabaseInt($row->effective_price_irr, 'Quote effective price'),
            $row->discount_reference_code,
            $this->nonNegativeDatabaseInt($row->discount_irr, 'Quote discount'),
            $this->nonNegativeDatabaseInt($row->final_price_irr, 'Quote final price'),
            $row->currency,
            $row->configuration_snapshot_hash,
            $this->agentPricingSnapshotFromRow($row),
            $this->databaseDateTimeFromString($row->valid_from, 'Quote valid-from'),
            $this->databaseDateTimeFromString($row->expires_at, 'Quote expiry'),
            $replayed,
        );
    }

    private function agentPricingSnapshotFromRow(object $row): ?QuoteAgentPricingSnapshot
    {
        if ($row->agent_pricing_resolution_id === null) {
            return null;
        }
        if ($row->agent_pricing_resolution_public_id === null
            || $row->agent_pricing_resolution_configuration_hash === null
            || $row->agent_profile_id_snapshot === null
            || $row->agent_pricing_profile_id_snapshot === null
            || $row->agent_pricing_profile_public_id_snapshot === null
            || $row->agent_pricing_profile_code_snapshot === null
            || $row->agent_pricing_profile_version_snapshot === null
            || $row->agent_pricing_profile_configuration_hash === null
            || $row->agent_pricing_action_snapshot === null
            || $row->agent_discount_combination_allowed === null) {
            throw new RuntimeException('Stored quote agent pricing binding is incomplete.');
        }

        $action = AgentPricingAction::tryFrom($row->agent_pricing_action_snapshot)
            ?? throw new RuntimeException('Stored quote agent pricing action is invalid.');

        return new QuoteAgentPricingSnapshot(
            $this->positiveDatabaseInt($row->agent_pricing_resolution_id, 'Quote agent pricing resolution ID'),
            $row->agent_pricing_resolution_public_id,
            $row->agent_pricing_resolution_configuration_hash,
            $this->positiveDatabaseInt($row->agent_profile_id_snapshot, 'Quote agent profile ID'),
            $this->positiveDatabaseInt($row->agent_pricing_profile_id_snapshot, 'Quote agent pricing profile ID'),
            $row->agent_pricing_profile_public_id_snapshot,
            $row->agent_pricing_profile_code_snapshot,
            $this->positiveDatabaseInt($row->agent_pricing_profile_version_snapshot, 'Quote agent pricing profile version'),
            $row->agent_pricing_profile_configuration_hash,
            $action,
            $row->agent_pricing_rule_id_snapshot === null ? null : $this->positiveDatabaseInt($row->agent_pricing_rule_id_snapshot, 'Quote agent pricing rule ID'),
            $row->agent_pricing_rule_public_id_snapshot,
            $row->agent_pricing_rule_code_snapshot,
            $row->agent_pricing_rule_version_snapshot === null ? null : $this->positiveDatabaseInt($row->agent_pricing_rule_version_snapshot, 'Quote agent pricing rule version'),
            $row->agent_pricing_rule_configuration_hash,
            (bool) $row->agent_discount_combination_allowed,
        );
    }
}
