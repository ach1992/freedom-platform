<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Infrastructure;

use App\Modules\Telegram\Application\Contracts\TelegramMembershipLookup;
use App\Modules\Telegram\Application\TelegramMembershipEvidence;
use App\Modules\Telegram\Application\TelegramMembershipLookupResult;
use Illuminate\Http\Client\Factory;
use InvalidArgumentException;
use Throwable;

final readonly class HttpTelegramMembershipLookup implements TelegramMembershipLookup
{
    /** @requirement ONB-003 CHN-001 SEC-001 SEC-008 INT-001 */
    public function __construct(
        private Factory $http,
        private TelegramRuntimeConfiguration $configuration,
    ) {}

    public function lookup(int $chatId, int $telegramUserId): TelegramMembershipLookupResult
    {
        if ($chatId >= 0) {
            throw new InvalidArgumentException('Telegram membership chat identity is invalid.');
        }

        if ($telegramUserId < 1) {
            throw new InvalidArgumentException('Telegram membership user identity is invalid.');
        }

        try {
            $response = $this->http
                ->asJson()
                ->acceptJson()
                ->timeout($this->configuration->apiTimeoutSeconds)
                ->connectTimeout(min(5, $this->configuration->apiTimeoutSeconds))
                ->post(
                    $this->configuration->apiBaseUrl.'/bot'.$this->configuration->botToken.'/getChatMember',
                    [
                        'chat_id' => $chatId,
                        'user_id' => $telegramUserId,
                    ],
                );
        } catch (Throwable) {
            return $this->unavailable('telegram_membership_transport_unavailable');
        }

        if (! $response->successful()) {
            return $this->unavailable('telegram_membership_http_unavailable');
        }

        $decoded = $response->json();
        if (! is_array($decoded) || array_is_list($decoded) || ($decoded['ok'] ?? null) !== true) {
            return $this->unavailable('telegram_membership_api_unavailable');
        }

        $result = $decoded['result'] ?? null;
        if (! is_array($result) || array_is_list($result)) {
            return $this->unavailable('telegram_membership_result_invalid');
        }

        $user = $result['user'] ?? null;
        $returnedUserId = is_array($user) && ! array_is_list($user) ? ($user['id'] ?? null) : null;
        if (! is_int($returnedUserId) || $returnedUserId !== $telegramUserId) {
            return $this->unavailable('telegram_membership_user_mismatch');
        }

        $status = $result['status'] ?? null;
        if (! is_string($status)) {
            return $this->unavailable('telegram_membership_status_invalid');
        }

        return match ($status) {
            'creator' => $this->member('telegram_membership_creator'),
            'administrator' => $this->member('telegram_membership_administrator'),
            'member' => $this->member('telegram_membership_member'),
            'restricted' => $this->restricted($result),
            'left' => $this->notMember('telegram_membership_left'),
            'kicked' => $this->notMember('telegram_membership_kicked'),
            default => $this->unavailable('telegram_membership_status_unknown'),
        };
    }

    /** @param array<string, mixed> $result */
    private function restricted(array $result): TelegramMembershipLookupResult
    {
        $isMember = $result['is_member'] ?? null;
        if (! is_bool($isMember)) {
            return $this->unavailable('telegram_membership_restricted_invalid');
        }

        return $isMember
            ? $this->member('telegram_membership_restricted_member')
            : $this->notMember('telegram_membership_restricted_left');
    }

    private function member(string $resultCode): TelegramMembershipLookupResult
    {
        return new TelegramMembershipLookupResult(TelegramMembershipEvidence::Member, $resultCode);
    }

    private function notMember(string $resultCode): TelegramMembershipLookupResult
    {
        return new TelegramMembershipLookupResult(TelegramMembershipEvidence::NotMember, $resultCode);
    }

    private function unavailable(string $resultCode): TelegramMembershipLookupResult
    {
        return new TelegramMembershipLookupResult(TelegramMembershipEvidence::Unavailable, $resultCode);
    }
}
