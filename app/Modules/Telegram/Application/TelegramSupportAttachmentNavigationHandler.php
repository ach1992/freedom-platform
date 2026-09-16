<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Localization\Application\LocalizationResolver;
use App\Modules\Support\Application\SupportTicketAttachmentMetadataService;
use App\Modules\Support\Application\SupportTicketAttachmentSnapshot;
use App\Modules\Telegram\Domain\TelegramDeliveryAction;
use App\Modules\Telegram\Domain\TelegramInteractionActionKind;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Read-only Support attachment commands. Durable delivery stores only the
 * attachment ULID, audience, and locale; private-media references are resolved
 * later at the protected provider boundary.
 */
final readonly class TelegramSupportAttachmentNavigationHandler
{
    private const STATE_TICKET = 'support_ticket';

    private const STATE_QUEUE_TICKET = 'support_queue_ticket';

    public function __construct(
        private SupportTicketAttachmentMetadataService $attachments,
        private LocalizationResolver $localization,
        private ConfidentialTelegramPresentationFactory $presentations,
        private TelegramConfidentialDeliveryQueue $confidentialDelivery,
        private TelegramDeliveryQueueService $delivery,
        private DatabaseManager $database,
    ) {}

    public function supports(TelegramInteractionAction $action): bool
    {
        if ($action->kind !== TelegramInteractionActionKind::Message
            || $action->messageText === null
            || ! in_array($action->sessionState, [self::STATE_TICKET, self::STATE_QUEUE_TICKET], true)) {
            return false;
        }

        $command = trim($action->messageText);

        return preg_match('/\A\/attachments(?:@[A-Za-z0-9_]{5,32})?\z/u', $command) === 1
            || preg_match('/\A\/attachment(?:@[A-Za-z0-9_]{5,32})?(?:[ \t]+\S+)?\z/u', $command) === 1;
    }

    /** @requirement SUP-001 SUP-002 DAT-003 SEC-002 SEC-003 SEC-008 QUA-001 */
    public function handle(TelegramInteractionAction $action): void
    {
        if (! $this->supports($action)) {
            throw new RuntimeException('Telegram Support attachment command is unsupported.');
        }

        $ticketId = $this->positivePayloadId($action->sessionPayload, 'ticket_id');
        $staff = $action->sessionState === self::STATE_QUEUE_TICKET;
        $locale = $this->locale($action->userId);
        $command = trim((string) $action->messageText);

        if (preg_match('/\A\/attachments(?:@[A-Za-z0-9_]{5,32})?\z/u', $command) === 1) {
            $this->showAttachments($action, $ticketId, $staff, $locale);

            return;
        }

        $matches = [];
        if (preg_match(
            '/\A\/attachment(?:@[A-Za-z0-9_]{5,32})?[ \t]+([0-9A-HJKMNP-TV-Z]{26})\z/iu',
            $command,
            $matches,
        ) !== 1
            || ! Str::isUlid($matches[1])) {
            $this->queueSafeText(
                $action,
                $this->translation('telegram_support.attachment_request_unavailable', $locale),
                'attachment-request-unavailable',
            );

            return;
        }

        $attachmentPublicId = strtoupper($matches[1]);
        try {
            $attachment = $staff
                ? $this->attachments->attachmentForSupport($action->userId, $ticketId, $attachmentPublicId)
                : $this->attachments->attachmentForCustomer($ticketId, $action->userId, $attachmentPublicId);
        } catch (AuthorizationException|RuntimeException) {
            $this->queueSafeText(
                $action,
                $this->translation('telegram_support.attachment_request_unavailable', $locale),
                'attachment-request-unavailable',
            );

            return;
        }

        $reference = TelegramProtectedPresentationReference::supportAttachment(
            $attachment->publicId,
            $staff ? 'support' : 'customer',
            $locale,
        );
        $key = 'tg-support-attachment-delivery:'.hash('sha256', $action->requestKey.':'.$reference->durableText());
        $this->delivery->queueProtectedReference(
            TelegramDeliveryAction::Send,
            $action->telegramUserId,
            $reference,
            $key,
            hash('sha256', $key),
        );
    }

    private function showAttachments(
        TelegramInteractionAction $action,
        int $ticketId,
        bool $staff,
        string $locale,
    ): void {
        try {
            $attachments = $staff
                ? $this->attachments->forSupport($action->userId, $ticketId, 20)
                : $this->attachments->forCustomer($ticketId, $action->userId, 20);
        } catch (AuthorizationException|RuntimeException) {
            $this->queueSafeText(
                $action,
                $this->translation('telegram_support.attachment_request_unavailable', $locale),
                'attachments-unavailable',
            );

            return;
        }

        if ($attachments === []) {
            $text = $this->translation('telegram_support.attachments_empty', $locale);
        } else {
            $items = array_map(
                fn (SupportTicketAttachmentSnapshot $attachment): string => $this->translation(
                    'telegram_support.attachment_item',
                    $locale,
                    [
                        'attachment' => $attachment->publicId,
                        'kind' => $attachment->kind,
                        'mime' => $attachment->detectedMime,
                        'size' => $attachment->byteSize,
                    ],
                ),
                $attachments,
            );
            $text = $this->translation(
                'telegram_support.attachments_list',
                $locale,
                ['items' => implode("\n", $items)],
            );
        }

        $this->queueSafeText($action, $text, 'attachments-list');
    }

    private function queueSafeText(TelegramInteractionAction $action, string $text, string $surface): void
    {
        $presentation = $this->presentations->fromSource(
            new TelegramSupportAttachmentStatusPresentation($text),
        );
        $key = 'tg-support-attachment-metadata:'.hash('sha256', $action->requestKey.':'.$surface.':'.$text);
        $this->confidentialDelivery->send(
            $action->telegramUserId,
            $presentation,
            $key,
            hash('sha256', $key),
        );
    }

    /** @param array<string,mixed> $payload */
    private function positivePayloadId(array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;
        if ((! is_int($value) && ! is_string($value))
            || filter_var($value, FILTER_VALIDATE_INT) === false
            || (int) $value < 1) {
            throw new RuntimeException('Telegram Support attachment ticket identity is invalid.');
        }

        return (int) $value;
    }

    /** @param array<string,int|string> $replace */
    private function translation(string $key, string $locale, array $replace = []): string
    {
        $text = $this->localization->resolve($key, $replace, $locale);
        if ($text === '' || $text === '['.$key.']') {
            throw new RuntimeException('Telegram Support attachment translation is unavailable.');
        }

        return $text;
    }

    private function locale(int $userId): string
    {
        $locale = $this->database->connection()->table('users')->where('id', $userId)->value('locale');

        return $locale === 'en' ? 'en' : 'fa';
    }
}
