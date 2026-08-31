<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use DomainException;
use JsonException;

final readonly class TelegramDeliveryRequestFingerprint
{
    /** @throws JsonException */
    public static function make(
        TelegramMutationRequest $request,
        string $botId,
        string $correlationId,
        ?string $interactiveSnapshotHash = null,
    ): string {
        $values = [
            'action' => $request->action->value,
            'bot_id' => $botId,
            'correlation_id' => $correlationId,
            'presentation_text' => $request->presentation?->text(),
            'recipient_chat_id' => $request->recipientChatId,
            'target_message_id' => $request->targetMessageId,
        ];
        if ($interactiveSnapshotHash !== null) {
            if (preg_match('/\A[0-9a-f]{64}\z/', $interactiveSnapshotHash) !== 1) {
                throw new DomainException('Telegram interactive presentation snapshot hash is invalid.');
            }
            $values['interactive_snapshot_hash'] = $interactiveSnapshotHash;
        }

        $encoded = json_encode(
            $values,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        return hash('sha256', $encoded);
    }
}
