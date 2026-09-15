<?php

declare(strict_types=1);

namespace App\Modules\Promotions\BenefitCodes\Application;

use App\Modules\AccessControl\Application\AccessChangeContext;
use App\Modules\Promotions\BenefitCodes\Domain\BenefitCodeState;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
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
        $normalizedOwnerCodeSet = [];
        if ($ownerChosen) {
            foreach ($request->ownerChosenCodes as $code) {
                $normalized = $this->codec->normalize($code);
                $this->codec->assertOwnerChosenStrength($normalized);
                if (isset($normalizedOwnerCodeSet[$normalized])) {
                    throw new DomainException('Owner-chosen benefit code batch contains a duplicate.');
                }
                $normalizedOwnerCodeSet[$normalized] = true;
                $normalizedOwnerCodes[] = $normalized;
            }
        }
        $payloadHash = $this->hash([
            'actor_administrator_id' => $context->actorAdministratorId,
            'campaign_code' => $request->campaignCode->value,
            'owner_chosen' => $ownerChosen,
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
                    $this->assertOwnerChosenIssuanceReplay($db, $existing, $normalizedOwnerCodes);

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
            $db = $this->database->connection();
            $existing = $this->issuanceByKey($db, $request->issuanceKey);
            if ($existing !== null) {
                $this->assertOwnerChosenIssuanceReplay($db, $existing, $normalizedOwnerCodes);

                return $this->issuanceReceipt($db, $existing, $payloadHash, true);
            }

            throw $exception;
        }
    }

    /** @return array{0:string,1:string} */
    private function insertOwnerCode(Connection $db, int $issuanceId, int $campaignId, int $campaignVersionId, string $normalized): array
    {
        if ($this->codeExistsForSupportedLookup($db, $normalized)) {
            throw new DomainException('Owner-chosen benefit code is already in use.');
        }

        return $this->insertCode($db, $issuanceId, $campaignId, $campaignVersionId, $normalized);
    }

    /** @return array{0:string,1:string} */
    private function insertRandomCode(Connection $db, int $issuanceId, int $campaignId, int $campaignVersionId): array
    {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $displayCode = $this->codec->generate();
            $normalized = $this->codec->normalize($displayCode);
            if ($this->codeExistsForSupportedLookup($db, $normalized)) {
                continue;
            }
            try {
                return $this->insertCode($db, $issuanceId, $campaignId, $campaignVersionId, $normalized);
            } catch (QueryException $exception) {
                if ($this->isDuplicateKey($exception)) {
                    continue;
                }

                throw $exception;
            }
        }

        throw new RuntimeException('Unable to allocate a unique benefit code.');
    }

    private function codeExistsForSupportedLookup(Connection $db, string $normalized): bool
    {
        foreach ($this->hasher->supportedHashes($normalized) as $version => $lookupHash) {
            if ($db->table('benefit_codes')
                ->where('key_version', $version)
                ->where('lookup_hash', $lookupHash)
                ->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  object{id:int|string}  $issuance
     * @param  list<string>  $normalizedOwnerCodes
     */
    private function assertOwnerChosenIssuanceReplay(Connection $db, object $issuance, array $normalizedOwnerCodes): void
    {
        if ($normalizedOwnerCodes === []) {
            return;
        }
        /** @var Collection<int, object{lookup_hash:string,key_version:int|string}> $rows */
        $rows = $db->table('benefit_codes')
            ->where('benefit_code_issuance_id', $this->positive($issuance->id, 'Benefit code issuance ID'))
            ->orderBy('id')
            ->get(['lookup_hash', 'key_version']);
        if ($rows->count() !== count($normalizedOwnerCodes)) {
            throw new RuntimeException('Benefit code issuance key conflict.');
        }
        foreach ($rows->values() as $index => $row) {
            $normalized = $normalizedOwnerCodes[$index] ?? null;
            if (! is_string($normalized)
                || ! $this->hasher->matches(
                    $normalized,
                    $this->positive($row->key_version, 'Benefit code key version'),
                    (string) $row->lookup_hash,
                )) {
                throw new RuntimeException('Benefit code issuance key conflict.');
            }
        }
    }

    private function isDuplicateKey(QueryException $exception): bool
    {
        return ($exception->errorInfo[0] ?? null) === '23000'
            && (int) ($exception->errorInfo[1] ?? 0) === 1062;
    }

    /** @return array{0:string,1:string} */
    private function insertCode(Connection $db, int $issuanceId, int $campaignId, int $campaignVersionId, string $normalized): array
    {
        $lookup = $this->hasher->currentHash($normalized);
        $publicId = (string) Str::ulid();
        $db->table('benefit_codes')->insert([
            'public_id' => $publicId,
            'benefit_code_issuance_id' => $issuanceId,
            'benefit_code_campaign_id' => $campaignId,
            'benefit_code_campaign_version_id' => $campaignVersionId,
            'lookup_hash' => $lookup['lookup_hash'],
            'key_version' => $lookup['key_version'],
            'display_mask' => $this->codec->mask($normalized),
            'created_at' => $this->timestamp(),
        ]);

        return [$publicId, $this->codec->format($normalized)];
    }
}
