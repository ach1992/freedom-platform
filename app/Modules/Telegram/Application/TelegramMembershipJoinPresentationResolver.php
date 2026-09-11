<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use DomainException;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * Resolves access-bearing membership join links only inside the protected
 * presentation boundary. No membership/provider HTTP call is permitted here.
 */
final readonly class TelegramMembershipJoinPresentationResolver
{
    public function __construct(
        private DatabaseManager $database,
        private StringEncrypter $encrypter,
        private TelegramChannelMembershipRuleResolver $rules,
        private Translator $translator,
    ) {}

    public function resolveForSelf(
        int $userId,
        TelegramProtectedPresentationReference $reference,
    ): ProtectedTelegramPresentation {
        if ($userId < 1
            || ! $reference->isMembershipJoinPrompt()
            || $reference->action === null
            || $reference->configurationHash === null) {
            throw new DomainException('Protected Telegram membership join reference is unavailable.');
        }

        $request = new TelegramChannelMembershipResolutionRequest(
            $userId,
            $reference->action,
            $reference->planOfferingId,
        );
        $plan = $this->rules->resolve($request);
        $this->assertReferencedPlan($plan, $reference);

        $buttons = [];
        foreach ($plan->channels as $channel) {
            $row = $this->database->connection()->table('required_channels')->where('id', $channel->requiredChannelId)->first([
                'id',
                'channel_key',
                'telegram_chat_id',
                'chat_type',
                'visibility',
                'display_title',
                'join_url_ciphertext',
                'join_url_hash',
                'state',
                'version',
            ]);
            if ($row === null) {
                throw new DomainException('Protected Telegram membership channel is unavailable.');
            }
            /** @var object{id:int|string,channel_key:string,telegram_chat_id:int|string,chat_type:string,visibility:string,display_title:string,join_url_ciphertext:string,join_url_hash:string,state:string,version:int|string} $row */
            $this->assertCurrentChannel($row, $channel);

            if ($channel->state !== 'active') {
                continue;
            }

            $joinUrl = $this->decryptJoinUrl($row->join_url_ciphertext, $row->join_url_hash, $channel->visibility);
            try {
                $buttons[] = new ProtectedTelegramHttpsUrlButton(
                    $this->translation('telegram_membership.join_button', $reference->locale, [
                        'channel' => $channel->displayTitle,
                    ]),
                    $joinUrl,
                );
            } catch (InvalidArgumentException $exception) {
                throw new DomainException('Protected Telegram membership join presentation is invalid.', previous: $exception);
            }
        }

        if ($buttons === []) {
            throw new DomainException('Protected Telegram membership join targets are unavailable.');
        }

        $currentPlan = $this->rules->resolve($request);
        $this->assertSamePlanAfterResolution($plan, $currentPlan);
        $this->assertReferencedPlan($currentPlan, $reference);

        try {
            return ProtectedTelegramPresentation::plainTextWithHttpsUrlButtons(
                $this->translation('telegram_membership.join_prompt', $reference->locale),
                $buttons,
            );
        } catch (InvalidArgumentException $exception) {
            throw new DomainException('Protected Telegram membership join presentation is invalid.', previous: $exception);
        }
    }

    private function assertReferencedPlan(
        TelegramChannelMembershipRequirementPlan $plan,
        TelegramProtectedPresentationReference $reference,
    ): void {
        if (! $plan->required
            || $plan->action !== $reference->action
            || $plan->planOfferingId !== $reference->planOfferingId
            || $reference->configurationHash === null
            || ! hash_equals($reference->configurationHash, $plan->configurationHash)) {
            throw new DomainException('Protected Telegram membership configuration changed.');
        }
    }

    /** @param object{id:int|string,channel_key:string,telegram_chat_id:int|string,chat_type:string,visibility:string,display_title:string,state:string,version:int|string} $row */
    private function assertCurrentChannel(object $row, TelegramChannelMembershipRequirementChannel $channel): void
    {
        if ((int) $row->id !== $channel->requiredChannelId
            || $row->channel_key !== $channel->channelKey
            || (int) $row->telegram_chat_id !== $channel->telegramChatId
            || $row->chat_type !== $channel->chatType
            || $row->visibility !== $channel->visibility
            || $row->display_title !== $channel->displayTitle
            || $row->state !== $channel->state
            || (int) $row->version !== $channel->version) {
            throw new DomainException('Protected Telegram membership channel configuration changed.');
        }
    }

    private function decryptJoinUrl(#[SensitiveParameter] string $ciphertext, string $expectedHash, string $visibility): string
    {
        if ($ciphertext === '' || preg_match('/\A[0-9a-f]{64}\z/', $expectedHash) !== 1) {
            throw new DomainException('Protected Telegram membership join secret is invalid.');
        }

        try {
            $joinUrl = $this->encrypter->decryptString($ciphertext);
        } catch (Throwable) {
            throw new DomainException('Protected Telegram membership join secret cannot be decrypted.');
        }

        if (! hash_equals($expectedHash, hash('sha256', $joinUrl))) {
            throw new DomainException('Protected Telegram membership join secret integrity check failed.');
        }

        try {
            return TelegramRequiredChannelDefinition::normalizeJoinUrl($joinUrl, $visibility);
        } catch (InvalidArgumentException) {
            throw new DomainException('Protected Telegram membership join URL is invalid.');
        }
    }

    private function assertSamePlanAfterResolution(
        TelegramChannelMembershipRequirementPlan $before,
        TelegramChannelMembershipRequirementPlan $after,
    ): void {
        if ($before->ruleId !== $after->ruleId
            || $before->ruleKey !== $after->ruleKey
            || $before->ruleVersion !== $after->ruleVersion
            || $before->configurationHash !== $after->configurationHash) {
            throw new DomainException('Protected Telegram membership configuration changed during resolution.');
        }
    }

    /** @param array<string,int|string> $replace */
    private function translation(string $key, string $locale, array $replace = []): string
    {
        $text = $this->translator->get($key, $replace, $locale);
        if (! is_string($text) || $text === '' || $text === $key) {
            $text = $this->translator->get($key, $replace, 'en');
        }
        if (! is_string($text) || $text === '' || $text === $key) {
            throw new RuntimeException('Protected Telegram membership translation is unavailable.');
        }

        return $text;
    }
}
