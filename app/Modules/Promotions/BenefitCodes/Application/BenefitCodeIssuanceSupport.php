<?php

declare(strict_types=1);

namespace App\Modules\Promotions\BenefitCodes\Application;

use App\Modules\AccessControl\Application\AccessChangeContext;
use App\Modules\Promotions\BenefitCodes\Domain\BenefitCodeState;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use RuntimeException;

trait BenefitCodeIssuanceSupport
{
    private function issueCodes(BenefitCodeIssueRequest $request, AccessChangeContext $context): BenefitCodeIssueReceipt
    {
        $context->requireReason();
        $this->authorizer->authorize($context->actorAdministratorId, self::MANAGE_PERMISSION);

        $ownerChosen = $request->ownerChosenCodes !== [];
        $normalizedOwnerCodes = [];
        $ownerHashes = [];
        if ($ownerChosen) {
            foreach ($request->ownerChosenCodes as $code) {
                $normalized = $this->codec->normalize($code);
                $this->codec->assertOwnerChosenStrength($normalized);
                $lookupHash = $this->hasher->hash($normalized);
                if (isset($ownerHashes[$lookupHash])) {
                    throw new DomainException('Owner-chosen benefit code batch contains a duplicate.');
                }
                $ownerHashes[$lookupHash] = true;
                $normalizedOwnerCodes[] = $normalized;
            }
        }
        $payloadHash = $this->hash([
            'actor_administrator_id' => $context->actorAdministratorId,
            'campaign_code' => $request->campaignCode->value,
            'owner_chosen_hashes' => array_keys($ownerHashes),
            'quantity' => $request->quantity,
        ]);

        try {
            return $this->database->connection()->transaction(function (Connection $db) use (
                $request,
                $context,
                $ownerChosen,
                $normalizedOwnerCodes,
                $payloadHash,
            ): BenefitCodeIssueReceipt {
                $existing = $this->issuanceByKey($db, $request->issuanceKey, true);
                if ($existing !== null) {
                    return $this->issuanceReceipt($db, $existing, $payloadHash, true);
                }
                $campaign = $this->latestCampaign($db, $request->campaignCode->value, true);
                if ($campaign === null || $campaign->state !== BenefitCodeState::Active->value) {
                    throw new DomainException('Benefit code issuance requires an active campaign.');
                }
                $this->decodeConfiguration($campaign->configuration_snapshot, $campaign->configuration_hash);
                $campaignId = $this->positive($campaign->id, 'Benefit code campaign ID');
                $campaignVersionId = $this->positive($campaign->version_id, 'Benefit code campaign version ID');
                $issuanceId = (int) $db->table('benefit_code_issuances')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'issuance_key' => $request->issuanceKey,
                    'request_payload_hash' => $payloadHash,
                    'benefit_code_campaign_id' => $campaignId,
                    'benefit_code_campaign_version_id' => $campaignVersionId,
                    'quantity' => $request->quantity,
                    'owner_chosen' => $ownerChosen,
                    'actor_administrator_id' => $context->actorAdministratorId,
                    'reason_code' => $context->reasonCode,
                    'reason' => $context->requireReason(),
                    'correlation_id' => $context->correlationId,
                    'created_at' => $this->timestamp(),
                ]);

                $plaintextByPublicId = [];
                for ($index = 0; $index < $request->quantity; $index++) {
                    [$publicId, $displayCode] = $ownerChosen
                        ? $this->insertOwnerCode($db, $issuanceId, $campaignId, $campaignVersionId, $normalizedOwnerCodes[$index])
                        : $this->insertRandomCode($db, $issuanceId, $campaignId, $campaignVersionId);
                    $plaintextByPublicId[$publicId] = $displayCode;
                }
                $created = $this->issuanceByKey($db, $request->issuanceKey);
                if ($created === null) {
                    throw new RuntimeException('Benefit code issuance persistence failed.');
                }

                return $this->issuanceReceipt($db, $created, $payloadHash, false, $plaintextByPublicId);
            });
        } catch (QueryException $exception) {
            $existing = $this->issuanceByKey($this->database->connection(), $request->issuanceKey);
            if ($existing !== null) {
                return $this->issuanceReceipt($this->database->connection(), $existing, $payloadHash, true);
            }

            throw $exception;
        }
    }

    /** @return array{0:string,1:string} */
    private function insertOwnerCode(Connection $db, int $issuanceId, int $campaignId, int $campaignVersionId, string $normalized): array
    {
        $lookupHash = $this->hasher->hash($normalized);
        if ($db->table('benefit_codes')->where('lookup_hash', $lookupHash)->exists()) {
            throw new DomainException('Owner-chosen benefit code is already in use.');
        }

        return $this->insertCode($db, $issuanceId, $campaignId, $campaignVersionId, $normalized, $lookupHash);
    }

    /** @return array{0:string,1:string} */
    private function insertRandomCode(Connection $db, int $issuanceId, int $campaignId, int $campaignVersionId): array
    {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $displayCode = $this->codec->generate();
            $normalized = $this->codec->normalize($displayCode);
            $lookupHash = $this->hasher->hash($normalized);
            if ($db->table('benefit_codes')->where('lookup_hash', $lookupHash)->exists()) {
                continue;
            }
            try {
                return $this->insertCode($db, $issuanceId, $campaignId, $campaignVersionId, $normalized, $lookupHash);
            } catch (QueryException $exception) {
                if ($this->isDuplicateKey($exception)) {
                    continue;
                }

                throw $exception;
            }
        }

        throw new RuntimeException('Unable to allocate a unique benefit code.');
    }

    private function isDuplicateKey(QueryException $exception): bool
    {
        return ($exception->errorInfo[0] ?? null) === '23000'
            && (int) ($exception->errorInfo[1] ?? 0) === 1062;
    }

    /** @return array{0:string,1:string} */
    private function insertCode(Connection $db, int $issuanceId, int $campaignId, int $campaignVersionId, string $normalized, string $lookupHash): array
    {
        $publicId = (string) Str::ulid();
        $db->table('benefit_codes')->insert([
            'public_id' => $publicId,
            'benefit_code_issuance_id' => $issuanceId,
            'benefit_code_campaign_id' => $campaignId,
            'benefit_code_campaign_version_id' => $campaignVersionId,
            'lookup_hash' => $lookupHash,
            'key_version' => $this->hasher->keyVersion(),
            'display_mask' => $this->codec->mask($normalized),
            'created_at' => $this->timestamp(),
        ]);

        return [$publicId, $this->codec->format($normalized)];
    }
}
