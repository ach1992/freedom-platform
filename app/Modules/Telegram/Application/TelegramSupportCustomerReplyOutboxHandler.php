<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Localization\Application\LocalizationResolver;
use App\Modules\Support\Application\SupportCustomerReplyNotification;
use App\Modules\Support\Application\SupportTicketCustomerNotificationSnapshot;
use App\Modules\Support\Application\SupportTicketService;
use App\Modules\Telegram\Application\Contracts\TelegramDeliveryRuntime;
use App\Shared\Application\OutboxDispatchOutcome;
use App\Shared\Application\OutboxEventHandler;
use App\Shared\Application\OutboxMessage;
use DomainException;
use Illuminate\Database\DatabaseManager;
use RuntimeException;
use Throwable;

final readonly class TelegramSupportCustomerReplyOutboxHandler implements OutboxEventHandler
{
    public function __construct(
        private DatabaseManager $database,
        private SupportTicketService $support,
        private LocalizationResolver $localization,
        private ConfidentialTelegramPresentationFactory $presentations,
        private TelegramConfidentialDeliveryQueue $delivery,
        private TelegramDeliveryRuntime $runtime,
    ) {}

    public function eventType(): string
    {
        return SupportCustomerReplyNotification::EVENT_TYPE;
    }

    public function contractVersion(): int
    {
        return SupportCustomerReplyNotification::CONTRACT_VERSION;
    }

    /** @requirement SUP-001 SUP-002 ARCH-004 DAT-002 DAT-003 SEC-002 SEC-003 OPS-003 QUA-004 */
    public function handle(OutboxMessage $message): OutboxDispatchOutcome
    {
        try {
            $messageId = $this->positiveInt($message->payload['message_id'] ?? null, 'Support notification message ID');
            $ticketId = $this->positiveInt($message->payload['ticket_id'] ?? null, 'Support notification ticket ID');

            if ($message->eventType !== SupportCustomerReplyNotification::EVENT_TYPE
                || $message->contractVersion !== SupportCustomerReplyNotification::CONTRACT_VERSION
                || $message->aggregateType !== SupportCustomerReplyNotification::AGGREGATE_TYPE
                || ! hash_equals((string) $messageId, $message->aggregateId)
                || ! hash_equals(SupportCustomerReplyNotification::eventKey($messageId), $message->eventKey)
                || ! hash_equals(SupportCustomerReplyNotification::correlationId($messageId), $message->correlationId)
            ) {
                return OutboxDispatchOutcome::DefinitiveFailure;
            }

            $notification = $this->support->customerNotificationForMessage($messageId);
            if ($notification->ticketId !== $ticketId) {
                return OutboxDispatchOutcome::DefinitiveFailure;
            }

            $telegramUserId = $this->recipientTelegramUserId($notification);
            $presentation = $this->presentation($notification);

            $this->delivery->send(
                $telegramUserId,
                $presentation,
                'tg-support-customer-reply:'.$notification->messageId,
                SupportCustomerReplyNotification::correlationId($notification->messageId),
            );

            return OutboxDispatchOutcome::Success;
        } catch (DomainException) {
            return OutboxDispatchOutcome::DefinitiveFailure;
        } catch (Throwable) {
            return OutboxDispatchOutcome::RetryableFailure;
        }
    }

    private function recipientTelegramUserId(SupportTicketCustomerNotificationSnapshot $notification): int
    {
        $value = $this->database->connection()->table('telegram_accounts')
            ->where('bot_id', $this->runtime->botId())
            ->where('user_id', $notification->requesterUserId)
            ->where('is_bot', 0)
            ->value('telegram_user_id');

        $telegramUserId = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($telegramUserId === false) {
            throw new DomainException('Support customer Telegram recipient is unavailable.');
        }

        return $telegramUserId;
    }

    private function presentation(
        SupportTicketCustomerNotificationSnapshot $notification,
    ): ConfidentialTelegramPresentation {
        $locale = $this->database->connection()->table('users')
            ->where('id', $notification->requesterUserId)
            ->value('locale');
        $locale = $locale === 'en' ? 'en' : 'fa';

        $text = $this->localizedText($notification, $locale, $notification->body);
        if (mb_strlen($text) > ConfidentialTelegramPresentation::MAXIMUM_TEXT_CHARACTERS) {
            $withoutBody = $this->localizedText($notification, $locale, '');
            $bodyLimit = ConfidentialTelegramPresentation::MAXIMUM_TEXT_CHARACTERS
                - mb_strlen($withoutBody)
                - 1;
            if ($bodyLimit < 1) {
                throw new RuntimeException('Support reply notification template exceeds Telegram limits.');
            }
            $text = $this->localizedText(
                $notification,
                $locale,
                mb_substr($notification->body, 0, $bodyLimit).'…',
            );
        }

        if (mb_strlen($text) > ConfidentialTelegramPresentation::MAXIMUM_TEXT_CHARACTERS) {
            throw new RuntimeException('Support reply notification could not be bounded for Telegram.');
        }

        $source = new readonly class($text) implements ConfidentialTelegramPresentationSource
        {
            public function __construct(private string $text) {}

            public function confidentialTelegramText(): string
            {
                return $this->text;
            }
        };

        return $this->presentations->fromSource($source);
    }

    private function localizedText(
        SupportTicketCustomerNotificationSnapshot $notification,
        string $locale,
        string $body,
    ): string {
        $text = $this->localization->resolve(
            'ticket.customer_reply_notification',
            [
                'tracking' => $notification->trackingNumber,
                'body' => $body,
            ],
            $locale,
        );

        if ($text === '' || $text === '[ticket.customer_reply_notification]') {
            throw new RuntimeException('Support reply notification localization is unavailable.');
        }

        return $text;
    }

    private function positiveInt(mixed $value, string $label): int
    {
        $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($validated === false) {
            throw new DomainException($label.' is invalid.');
        }

        return $validated;
    }
}
