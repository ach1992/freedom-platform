<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use DomainException;
use JsonException;

final readonly class TelegramBroadcastAudienceDefinition
{
    /**
     * All dimensions are ANDed. Values inside one list dimension are ORed.
     *
     * @param list<string> $accountTypes
     * @param list<string> $tierCodes
     * @param list<string> $tagCodes
     * @param list<string> $offeringCodes
     * @param list<string> $categoryCodes
     * @param list<string> $serverCodes
     * @param list<string> $manualUserPublicIds
     * @param list<int> $channelChatIds
     */
    public function __construct(
        public array $accountTypes = [],
        public array $tierCodes = [],
        public array $tagCodes = [],
        public string $purchaseState = 'any',
        public array $offeringCodes = [],
        public array $categoryCodes = [],
        public array $serverCodes = [],
        public string $serviceState = 'any',
        public ?int $walletMinimumIrr = null,
        public ?int $walletMaximumIrr = null,
        public array $channelChatIds = [],
        public string $channelMembershipMode = 'all',
        public string $channelMembershipState = 'member',
        public array $manualUserPublicIds = [],
    ) {
        $this->assertAllowedList($accountTypes, ['customer', 'agent'], 'Broadcast account type');
        $this->assertCodeList($tierCodes, 'Broadcast tier code');
        $this->assertCodeList($tagCodes, 'Broadcast tag code');
        $this->assertOneOf($purchaseState, ['any', 'with_successful', 'without_successful'], 'Broadcast purchase state');
        $this->assertCodeList($offeringCodes, 'Broadcast offering code');
        $this->assertCodeList($categoryCodes, 'Broadcast category code');
        $this->assertCodeList($serverCodes, 'Broadcast server code');
        $this->assertOneOf($serviceState, ['any', 'active', 'expired'], 'Broadcast service state');

        if ($walletMinimumIrr !== null && $walletMinimumIrr < 0) {
            throw new DomainException('Broadcast wallet minimum must be non-negative.');
        }
        if ($walletMaximumIrr !== null && $walletMaximumIrr < 0) {
            throw new DomainException('Broadcast wallet maximum must be non-negative.');
        }
        if ($walletMinimumIrr !== null && $walletMaximumIrr !== null && $walletMinimumIrr > $walletMaximumIrr) {
            throw new DomainException('Broadcast wallet range is inverted.');
        }

        $seenChats = [];
        foreach ($channelChatIds as $chatId) {
            if (! is_int($chatId) || $chatId === 0) {
                throw new DomainException('Broadcast channel chat IDs must be non-zero integers.');
            }
            if (isset($seenChats[(string) $chatId])) {
                throw new DomainException('Broadcast channel chat IDs must be unique.');
            }
            $seenChats[(string) $chatId] = true;
        }
        if (count($channelChatIds) > 10) {
            throw new DomainException('Broadcast channel membership filter exceeds 10 chats.');
        }
        $this->assertOneOf($channelMembershipMode, ['all', 'any'], 'Broadcast channel membership mode');
        $this->assertOneOf($channelMembershipState, ['member', 'not_member'], 'Broadcast channel membership state');
        $this->assertUlidList($manualUserPublicIds, 'Broadcast manual user public ID');

        if ($this->isUnbounded()) {
            return;
        }

        if (count($manualUserPublicIds) > 500) {
            throw new DomainException('Broadcast manual audience exceeds 500 selected users.');
        }
    }

    public function isUnbounded(): bool
    {
        return $this->accountTypes === []
            && $this->tierCodes === []
            && $this->tagCodes === []
            && $this->purchaseState === 'any'
            && $this->offeringCodes === []
            && $this->categoryCodes === []
            && $this->serverCodes === []
            && $this->serviceState === 'any'
            && $this->walletMinimumIrr === null
            && $this->walletMaximumIrr === null
            && $this->channelChatIds === []
            && $this->manualUserPublicIds === [];
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return [
            'account_types' => $this->canonicalStrings($this->accountTypes),
            'tier_codes' => $this->canonicalStrings($this->tierCodes),
            'tag_codes' => $this->canonicalStrings($this->tagCodes),
            'purchase_state' => $this->purchaseState,
            'offering_codes' => $this->canonicalStrings($this->offeringCodes),
            'category_codes' => $this->canonicalStrings($this->categoryCodes),
            'server_codes' => $this->canonicalStrings($this->serverCodes),
            'service_state' => $this->serviceState,
            'wallet_minimum_irr' => $this->walletMinimumIrr,
            'wallet_maximum_irr' => $this->walletMaximumIrr,
            'channel_chat_ids' => $this->canonicalIntegers($this->channelChatIds),
            'channel_membership_mode' => $this->channelMembershipMode,
            'channel_membership_state' => $this->channelMembershipState,
            'manual_user_public_ids' => $this->canonicalStrings($this->manualUserPublicIds),
        ];
    }

    public function json(): string
    {
        try {
            return json_encode(
                $this->snapshot(),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException $exception) {
            throw new DomainException('Broadcast audience could not be encoded.', 0, $exception);
        }
    }

    public function hash(): string
    {
        return hash('sha256', $this->json());
    }

    public static function restore(string $json): self
    {
        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new DomainException('Stored broadcast audience is invalid.', 0, $exception);
        }

        if (! is_array($data) || array_is_list($data)) {
            throw new DomainException('Stored broadcast audience is invalid.');
        }

        $expected = [
            'account_types',
            'tier_codes',
            'tag_codes',
            'purchase_state',
            'offering_codes',
            'category_codes',
            'server_codes',
            'service_state',
            'wallet_minimum_irr',
            'wallet_maximum_irr',
            'channel_chat_ids',
            'channel_membership_mode',
            'channel_membership_state',
            'manual_user_public_ids',
        ];
        if (array_keys($data) !== $expected) {
            throw new DomainException('Stored broadcast audience shape is invalid.');
        }

        $instance = new self(
            self::stringList($data['account_types']),
            self::stringList($data['tier_codes']),
            self::stringList($data['tag_codes']),
            self::requiredString($data['purchase_state']),
            self::stringList($data['offering_codes']),
            self::stringList($data['category_codes']),
            self::stringList($data['server_codes']),
            self::requiredString($data['service_state']),
            self::nullableNonNegativeInt($data['wallet_minimum_irr']),
            self::nullableNonNegativeInt($data['wallet_maximum_irr']),
            self::integerList($data['channel_chat_ids']),
            self::requiredString($data['channel_membership_mode']),
            self::requiredString($data['channel_membership_state']),
            self::stringList($data['manual_user_public_ids']),
        );

        if (! hash_equals($instance->json(), $json)) {
            throw new DomainException('Stored broadcast audience is not canonical.');
        }

        return $instance;
    }

    /**
     * @param list<string> $values
     * @param list<string> $allowed
     */
    private function assertAllowedList(array $values, array $allowed, string $label): void
    {
        foreach ($values as $value) {
            if (! is_string($value) || ! in_array($value, $allowed, true)) {
                throw new DomainException($label.' is invalid.');
            }
        }
        if (count(array_unique($values)) !== count($values)) {
            throw new DomainException($label.' values must be unique.');
        }
    }

    /** @param list<string> $values */
    private function assertCodeList(array $values, string $label): void
    {
        foreach ($values as $value) {
            if (! is_string($value) || preg_match('/\A[a-z0-9][a-z0-9_.:-]{0,63}\z/', $value) !== 1) {
                throw new DomainException($label.' is invalid.');
            }
        }
        if (count(array_unique($values)) !== count($values)) {
            throw new DomainException($label.' values must be unique.');
        }
        if (count($values) > 50) {
            throw new DomainException($label.' list exceeds 50 values.');
        }
    }

    /** @param list<string> $values */
    private function assertUlidList(array $values, string $label): void
    {
        foreach ($values as $value) {
            if (! is_string($value) || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $value) !== 1) {
                throw new DomainException($label.' is invalid.');
            }
        }
        if (count(array_unique($values)) !== count($values)) {
            throw new DomainException($label.' values must be unique.');
        }
    }

    /** @param list<string> $allowed */
    private function assertOneOf(string $value, array $allowed, string $label): void
    {
        if (! in_array($value, $allowed, true)) {
            throw new DomainException($label.' is invalid.');
        }
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function canonicalStrings(array $values): array
    {
        $copy = $values;
        sort($copy, SORT_STRING);

        return $copy;
    }

    /**
     * @param list<int> $values
     * @return list<int>
     */
    private function canonicalIntegers(array $values): array
    {
        $copy = $values;
        sort($copy, SORT_NUMERIC);

        return $copy;
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new DomainException('Stored broadcast audience list is invalid.');
        }

        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new DomainException('Stored broadcast audience list is invalid.');
            }
        }

        return $value;
    }

    /** @return list<int> */
    private static function integerList(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new DomainException('Stored broadcast channel list is invalid.');
        }

        foreach ($value as $item) {
            if (! is_int($item)) {
                throw new DomainException('Stored broadcast channel list is invalid.');
            }
        }

        return $value;
    }

    private static function requiredString(mixed $value): string
    {
        if (! is_string($value)) {
            throw new DomainException('Stored broadcast audience scalar is invalid.');
        }

        return $value;
    }

    private static function nullableNonNegativeInt(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (! is_int($value) || $value < 0) {
            throw new DomainException('Stored broadcast wallet range is invalid.');
        }

        return $value;
    }
}
