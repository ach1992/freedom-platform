<?php

declare(strict_types=1);

namespace App\Modules\Promotions\BenefitCodes\Application;

use App\Modules\Promotions\BenefitCodes\Domain\BenefitCodeAudience;
use App\Modules\Promotions\BenefitCodes\Domain\BenefitCodeDefinition;
use App\Modules\Promotions\BenefitCodes\Domain\BenefitCodeState;
use App\Modules\Promotions\BenefitCodes\Domain\BenefitCodeType;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Database\Connection;
use RuntimeException;

trait BenefitCodePersistenceSupport
{
    private function timestamp(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function date(?DateTimeImmutable $date): ?string
    {
        return $date?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    /** @param array<string, mixed> $payload */
    private function hash(array $payload): string
    {
        return hash('sha256', $this->json($payload));
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload): string
    {
        ksort($payload, SORT_STRING);
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > 8192) {
            throw new DomainException('Benefit code snapshot exceeds the storage boundary.');
        }

        return $json;
    }

    private function assertMutationKey(string $key): void
    {
        if (preg_match('/\A[A-Za-z0-9:_-]{8,128}\z/', $key) !== 1) {
            throw new \InvalidArgumentException('Benefit code mutation key is invalid.');
        }
    }

    private function assertUlid(string $value, string $label): void
    {
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $value) !== 1) {
            throw new \InvalidArgumentException($label.' is invalid.');
        }
    }

    private function positive(mixed $value, string $label): int
    {
        if ((! is_int($value) && ! is_string($value)) || ! ctype_digit((string) $value) || (int) $value < 1) {
            throw new RuntimeException($label.' is invalid.');
        }

        return (int) $value;
    }

    /** @return object{id:int|string,public_id:string,campaign_code:string,type:string,version_id:int|string,version:int|string,state:string,configuration_snapshot:string,configuration_hash:string}|null */
    private function latestCampaign(Connection $db, string $campaignCode, bool $lock = false): ?object
    {
        $query = $db->table('benefit_code_campaigns as c')
            ->join('benefit_code_campaign_versions as v', 'v.benefit_code_campaign_id', '=', 'c.id')
            ->where('c.campaign_code', $campaignCode)
            ->orderByDesc('v.version');
        if ($lock) {
            $query->lockForUpdate();
        }
        /** @var object{id:int|string,public_id:string,campaign_code:string,type:string,version_id:int|string,version:int|string,state:string,configuration_snapshot:string,configuration_hash:string}|null $row */
        $row = $query->first(['c.id', 'c.public_id', 'c.campaign_code', 'c.type', 'v.id as version_id', 'v.version', 'v.state', 'v.configuration_snapshot', 'v.configuration_hash']);

        return $row;
    }

    /** @return object{id:int|string,benefit_code_campaign_id:int|string,mutation_payload_hash:string,version:int|string,state:string,configuration_snapshot:string,configuration_hash:string,campaign_public_id:string,campaign_code:string,type:string}|null */
    private function campaignVersionByMutationKey(Connection $db, string $key, bool $lock = false): ?object
    {
        $query = $db->table('benefit_code_campaign_versions as v')
            ->join('benefit_code_campaigns as c', 'c.id', '=', 'v.benefit_code_campaign_id')
            ->where('v.mutation_key', $key);
        if ($lock) {
            $query->lockForUpdate();
        }
        /** @var object{id:int|string,benefit_code_campaign_id:int|string,mutation_payload_hash:string,version:int|string,state:string,configuration_snapshot:string,configuration_hash:string,campaign_public_id:string,campaign_code:string,type:string}|null $row */
        $row = $query->first([
            'v.id', 'v.benefit_code_campaign_id', 'v.mutation_payload_hash', 'v.version', 'v.state',
            'v.configuration_snapshot', 'v.configuration_hash', 'c.public_id as campaign_public_id',
            'c.campaign_code', 'c.type',
        ]);

        return $row;
    }

    /** @param object{id:int|string,benefit_code_campaign_id:int|string,mutation_payload_hash:string,version:int|string,state:string,configuration_snapshot:string,configuration_hash:string,campaign_public_id:string,campaign_code:string,type:string} $row */
    private function campaignReceipt(object $row, string $payloadHash, bool $replayed): BenefitCodeCampaignVersionReceipt
    {
        if (! isset($row->mutation_payload_hash) || ! is_string($row->mutation_payload_hash) || ! hash_equals($row->mutation_payload_hash, $payloadHash)) {
            throw new RuntimeException('Benefit code campaign mutation key conflict.');
        }
        $type = BenefitCodeType::tryFrom((string) $row->type) ?? throw new RuntimeException('Stored benefit code campaign type is invalid.');
        $state = BenefitCodeState::tryFrom((string) $row->state) ?? throw new RuntimeException('Stored benefit code campaign state is invalid.');

        return new BenefitCodeCampaignVersionReceipt(
            $this->positive($row->benefit_code_campaign_id, 'Benefit code campaign ID'),
            (string) $row->campaign_public_id,
            (string) $row->campaign_code,
            $type,
            $this->positive($row->version, 'Benefit code campaign version'),
            $state,
            (string) $row->configuration_hash,
            $replayed,
        );
    }

    /** @return array<string, mixed> */
    private function decodeConfiguration(string $json, string $expectedHash): array
    {
        if (! hash_equals(hash('sha256', $json), $expectedHash)) {
            throw new RuntimeException('Stored benefit code configuration hash mismatch.');
        }
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException('Stored benefit code configuration is invalid.');
        }

        return $decoded;
    }

    /** @param array<string,mixed> $configuration */
    private function stateFromConfiguration(array $configuration): BenefitCodeState
    {
        $state = $configuration['state'] ?? null;
        return is_string($state) && BenefitCodeState::tryFrom($state) !== null
            ? BenefitCodeState::from($state)
            : throw new RuntimeException('Stored benefit code state is invalid.');
    }

    /** @param array<string,mixed> $configuration */
    private function audienceFromConfiguration(array $configuration): BenefitCodeAudience
    {
        $audience = $configuration['audience'] ?? null;
        return is_string($audience) && BenefitCodeAudience::tryFrom($audience) !== null
            ? BenefitCodeAudience::from($audience)
            : throw new RuntimeException('Stored benefit code audience is invalid.');
    }

    /** @return object{id:int|string,public_id:string,issuance_key:string,request_payload_hash:string,benefit_code_campaign_id:int|string,benefit_code_campaign_version_id:int|string,quantity:int|string,campaign_code:string,campaign_version:int|string}|null */
    private function issuanceByKey(Connection $db, string $key, bool $lock = false): ?object
    {
        $query = $db->table('benefit_code_issuances as i')
            ->join('benefit_code_campaigns as c', 'c.id', '=', 'i.benefit_code_campaign_id')
            ->join('benefit_code_campaign_versions as v', 'v.id', '=', 'i.benefit_code_campaign_version_id')
            ->where('i.issuance_key', $key);
        if ($lock) {
            $query->lockForUpdate();
        }
        /** @var object{id:int|string,public_id:string,issuance_key:string,request_payload_hash:string,benefit_code_campaign_id:int|string,benefit_code_campaign_version_id:int|string,quantity:int|string,campaign_code:string,campaign_version:int|string}|null $row */
        $row = $query->first([
            'i.id', 'i.public_id', 'i.issuance_key', 'i.request_payload_hash', 'i.benefit_code_campaign_id',
            'i.benefit_code_campaign_version_id', 'i.quantity', 'c.campaign_code', 'v.version as campaign_version',
        ]);

        return $row;
    }

    /**
     * @param object{id:int|string,public_id:string,issuance_key:string,request_payload_hash:string,benefit_code_campaign_id:int|string,benefit_code_campaign_version_id:int|string,quantity:int|string,campaign_code:string,campaign_version:int|string} $issuance
     * @param array<string,string> $plaintextByPublicId
     */
    private function issuanceReceipt(Connection $db, object $issuance, string $payloadHash, bool $replayed, array $plaintextByPublicId = []): BenefitCodeIssueReceipt
    {
        if (! hash_equals((string) $issuance->request_payload_hash, $payloadHash)) {
            throw new RuntimeException('Benefit code issuance key conflict.');
        }
        $rows = $db->table('benefit_codes')->where('benefit_code_issuance_id', $this->positive($issuance->id, 'Benefit code issuance ID'))->orderBy('id')->get(['public_id', 'display_mask']);
        $items = [];
        foreach ($rows as $row) {
            $publicId = (string) $row->public_id;
            $items[] = new BenefitCodeIssuedItem(
                $publicId,
                (string) $row->display_mask,
                $replayed ? null : ($plaintextByPublicId[$publicId] ?? null),
            );
        }
        if (count($items) !== $this->positive($issuance->quantity, 'Benefit code issuance quantity')) {
            throw new RuntimeException('Stored benefit code issuance is incomplete.');
        }

        return new BenefitCodeIssueReceipt(
            $this->positive($issuance->id, 'Benefit code issuance ID'),
            (string) $issuance->public_id,
            (string) $issuance->campaign_code,
            $this->positive($issuance->campaign_version, 'Benefit code campaign version'),
            $items,
            $replayed,
        );
    }

    /** @return object{id:int|string,public_id:string,benefit_code_campaign_id:int|string,benefit_code_campaign_version_id:int|string,lookup_hash:string,key_version:int|string,display_mask:string,campaign_code:string,type:string,version:int|string,state:string,configuration_snapshot:string,configuration_hash:string}|null */
    private function codeByHash(Connection $db, string $lookupHash, bool $lock = false): ?object
    {
        $query = $db->table('benefit_codes as b')
            ->join('benefit_code_campaigns as c', 'c.id', '=', 'b.benefit_code_campaign_id')
            ->join('benefit_code_campaign_versions as v', 'v.id', '=', 'b.benefit_code_campaign_version_id')
            ->where('b.lookup_hash', $lookupHash);
        if ($lock) {
            $query->lockForUpdate();
        }
        /** @var object{id:int|string,public_id:string,benefit_code_campaign_id:int|string,benefit_code_campaign_version_id:int|string,lookup_hash:string,key_version:int|string,display_mask:string,campaign_code:string,type:string,version:int|string,state:string,configuration_snapshot:string,configuration_hash:string}|null $row */
        $row = $query->first([
            'b.id', 'b.public_id', 'b.benefit_code_campaign_id', 'b.benefit_code_campaign_version_id',
            'b.lookup_hash', 'b.key_version', 'b.display_mask', 'c.campaign_code', 'c.type',
            'v.version', 'v.state', 'v.configuration_snapshot', 'v.configuration_hash',
        ]);

        return $row;
    }

    /** @return object{id:int|string,public_id:string,mutation_key:string,request_payload_hash:string,benefit_code_id:int|string,code_public_id:string}|null */
    private function disableByKey(Connection $db, string $key, bool $lock = false): ?object
    {
        $query = $db->table('benefit_code_disables as d')->join('benefit_codes as b', 'b.id', '=', 'd.benefit_code_id')->where('d.mutation_key', $key);
        if ($lock) {
            $query->lockForUpdate();
        }
        /** @var object{id:int|string,public_id:string,mutation_key:string,request_payload_hash:string,benefit_code_id:int|string,code_public_id:string}|null $row */
        $row = $query->first(['d.id', 'd.public_id', 'd.mutation_key', 'd.request_payload_hash', 'd.benefit_code_id', 'b.public_id as code_public_id']);

        return $row;
    }

    /** @return object{id:int|string,public_id:string,redemption_key:string,request_payload_hash:string,benefit_code_id:int|string,user_id:int|string,campaign_code_snapshot:string,campaign_version:int|string,type_snapshot:string,ledger_transaction_id:int|string|null}|null */
    private function redemptionByKey(Connection $db, string $key, bool $lock = false): ?object
    {
        $query = $db->table('benefit_code_redemptions')->where('redemption_key', $key);
        if ($lock) {
            $query->lockForUpdate();
        }
        /** @var object{id:int|string,public_id:string,redemption_key:string,request_payload_hash:string,benefit_code_id:int|string,user_id:int|string,campaign_code_snapshot:string,campaign_version:int|string,type_snapshot:string,ledger_transaction_id:int|string|null}|null $row */
        $row = $query->first([
            'id', 'public_id', 'redemption_key', 'request_payload_hash', 'benefit_code_id', 'user_id',
            'campaign_code_snapshot', 'campaign_version', 'type_snapshot', 'ledger_transaction_id',
        ]);

        return $row;
    }

    /** @param object{id:int|string,public_id:string,redemption_key:string,request_payload_hash:string,benefit_code_id:int|string,user_id:int|string,campaign_code_snapshot:string,campaign_version:int|string,type_snapshot:string,ledger_transaction_id:int|string|null} $row */
    private function redemptionReceipt(Connection $db, object $row, string $payloadHash, bool $replayed): BenefitCodeRedemptionReceipt
    {
        if (! hash_equals((string) $row->request_payload_hash, $payloadHash)) {
            throw new RuntimeException('Benefit code redemption key conflict.');
        }
        $type = BenefitCodeType::tryFrom((string) $row->type_snapshot) ?? throw new RuntimeException('Stored benefit code redemption type is invalid.');
        $codePublicId = $db->table('benefit_codes')->where('id', $this->positive($row->benefit_code_id, 'Benefit code ID'))->value('public_id');
        if (! is_string($codePublicId)) {
            throw new RuntimeException('Stored benefit code redemption identity is invalid.');
        }
        $entitlement = $db->table('benefit_code_free_service_entitlements')->where('benefit_code_redemption_id', $this->positive($row->id, 'Benefit code redemption ID'))->value('public_id');
        $grant = $db->table('benefit_code_discount_grants')->where('benefit_code_redemption_id', $this->positive($row->id, 'Benefit code redemption ID'))->value('public_id');

        return new BenefitCodeRedemptionReceipt(
            $this->positive($row->id, 'Benefit code redemption ID'),
            (string) $row->public_id,
            $codePublicId,
            $this->positive($row->user_id, 'Benefit code redemption user ID'),
            (string) $row->campaign_code_snapshot,
            $this->positive($row->campaign_version, 'Benefit code redemption campaign version'),
            $type,
            $row->ledger_transaction_id === null ? null : $this->positive($row->ledger_transaction_id, 'Benefit code ledger transaction ID'),
            is_string($entitlement) ? $entitlement : null,
            is_string($grant) ? $grant : null,
            $replayed,
        );
    }
}
