<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application;

final readonly class CustomerAccountSummary
{
    /**
     * @param  list<array{type: string, masked_value: string, state: string, ownership_check_status: string, version: int}>  $identityItems
     * @param  list<string>  $tags
     */
    public function __construct(
        public string $publicId,
        public string $accountType,
        public string $accountStatus,
        public string $locale,
        public string $joinedAt,
        public ?string $lastSeenAt,
        public ?string $tierCode,
        public bool $tierLocked,
        public string $phoneVerificationStatus,
        public ?string $phoneVerificationMethod,
        public ?string $phoneVerifiedAt,
        public string $identityVerificationStatus,
        public array $identityItems,
        public array $tags,
        public ?string $agentStatus,
        public ?string $agentApprovedAt,
        public ?string $agentApplicationState,
        public ?string $administratorStatus,
        public bool $isOwner,
    ) {}

    /** @return array<string, bool|string|array<int, array<string, int|string>|string>|null> */
    public function toSafeArray(): array
    {
        return [
            'public_id' => $this->publicId,
            'account_type' => $this->accountType,
            'account_status' => $this->accountStatus,
            'locale' => $this->locale,
            'joined_at' => $this->joinedAt,
            'last_seen_at' => $this->lastSeenAt,
            'tier_code' => $this->tierCode,
            'tier_locked' => $this->tierLocked,
            'phone_verification_status' => $this->phoneVerificationStatus,
            'phone_verification_method' => $this->phoneVerificationMethod,
            'phone_verified_at' => $this->phoneVerifiedAt,
            'identity_verification_status' => $this->identityVerificationStatus,
            'identity_items' => $this->identityItems,
            'tags' => $this->tags,
            'agent_status' => $this->agentStatus,
            'agent_approved_at' => $this->agentApprovedAt,
            'agent_application_state' => $this->agentApplicationState,
            'administrator_status' => $this->administratorStatus,
            'is_owner' => $this->isOwner,
        ];
    }
}
