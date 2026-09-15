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
        ?string $confidentialPresentationHash = null,
    ): string {
        $confidentialHashes = $confidentialPresentationHash === null
            ? null
            : [$confidentialPresentationHash];

        return self::candidates(
            $request,
            $botId,
            $correlationId,
            $interactiveSnapshotHash,
            $confidentialHashes,
        )[0];
    }

    /**
     * V1/v2 return exactly one byte-compatible fingerprint. V3 accepts current +
     * previous keyed semantic hashes so durable replay survives APP_KEY rotation.
     *
     * @param  non-empty-list<string>|null  $confidentialPresentationHashes
     * @return non-empty-list<string>
     *
     * @throws JsonException
     */
    public static function candidates(
        TelegramMutationRequest $request,
        string $botId,
        string $correlationId,
        ?string $interactiveSnapshotHash = null,
        ?array $confidentialPresentationHashes = null,
    ): array {
        $isConfidential = $request->presentation instanceof ConfidentialTelegramPresentation;
        if ($isConfidential !== ($confidentialPresentationHashes !== null)) {
            throw new DomainException('Telegram confidential fingerprint semantics are incomplete.');
        }
        if ($interactiveSnapshotHash !== null
            && preg_match('/\A[0-9a-f]{64}\z/', $interactiveSnapshotHash) !== 1) {
            throw new DomainException('Telegram interactive presentation snapshot hash is invalid.');
        }

        $presentationText = match (true) {
            $request->presentation instanceof NonRestrictedTelegramPresentation => $request->presentation->text(),
            $request->presentation instanceof TelegramProtectedPresentationReference => $request->presentation->durableText(),
            default => null,
        };
        $hashes = $confidentialPresentationHashes ?? [null];
        $fingerprints = [];
        foreach ($hashes as $confidentialHash) {
            if ($confidentialHash !== null
                && preg_match('/\A[0-9a-f]{64}\z/', $confidentialHash) !== 1) {
                throw new DomainException('Telegram confidential presentation semantic hash is invalid.');
            }

            $values = [
                'action' => $request->action->value,
                'bot_id' => $botId,
                'correlation_id' => $correlationId,
                'presentation_text' => $presentationText,
                'recipient_chat_id' => $request->recipientChatId,
                'target_message_id' => $request->targetMessageId,
            ];
            if ($confidentialHash !== null) {
                $values['confidential_presentation_hash'] = $confidentialHash;
            }
            if ($interactiveSnapshotHash !== null) {
                $values['interactive_snapshot_hash'] = $interactiveSnapshotHash;
            }

            $encoded = json_encode(
                $values,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
            $fingerprint = hash('sha256', $encoded);
            if (! in_array($fingerprint, $fingerprints, true)) {
                $fingerprints[] = $fingerprint;
            }
        }

        return $fingerprints;
    }

    /** @param non-empty-list<string> $candidates */
    public static function matches(string $storedFingerprint, array $candidates): bool
    {
        foreach ($candidates as $candidate) {
            if (hash_equals($storedFingerprint, $candidate)) {
                return true;
            }
        }

        return false;
    }
}
