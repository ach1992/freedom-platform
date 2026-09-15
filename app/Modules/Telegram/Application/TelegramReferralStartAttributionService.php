<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Application;

use App\Modules\Promotions\Application\ReferralAttributionService;
use DomainException;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Database\DatabaseManager;
use RuntimeException;
use Throwable;

final readonly class TelegramReferralStartAttributionService
{
    public function __construct(
        private DatabaseManager $database,
        private StringEncrypter $encrypter,
        private ReferralAttributionService $referrals,
    ) {}

    /** @requirement ONB-001 ONB-002 REF-001 SEC-003 DAT-003 */
    public function bindFirstStart(string $botId, int $updateId, int $userId): void
    {
        if ($botId === '' || $updateId < 1 || $userId < 1) {
            throw new RuntimeException('Telegram referral attribution identity is invalid.');
        }

        $row = $this->database->connection()
            ->table('telegram_start_attributions')
            ->where('user_id', $userId)
            ->first(['bot_id', 'payload_hash', 'payload_ciphertext', 'first_update_id']);

        if ($row === null || (int) $row->first_update_id !== $updateId) {
            return;
        }

        if (! is_string($row->bot_id) || ! hash_equals($botId, $row->bot_id)) {
            throw new RuntimeException('Telegram referral attribution bot identity is invalid.');
        }
        if (! is_string($row->payload_hash) || preg_match('/\A[0-9a-f]{64}\z/', $row->payload_hash) !== 1) {
            throw new RuntimeException('Telegram referral attribution hash is invalid.');
        }
        if (! is_string($row->payload_ciphertext) || $row->payload_ciphertext === '') {
            throw new RuntimeException('Telegram referral attribution payload is unavailable.');
        }

        try {
            $payload = $this->encrypter->decryptString($row->payload_ciphertext);
        } catch (Throwable $exception) {
            throw new RuntimeException('Telegram referral attribution payload cannot be decrypted.', previous: $exception);
        }

        if (! hash_equals($row->payload_hash, hash('sha256', $payload))) {
            throw new RuntimeException('Telegram referral attribution payload integrity check failed.');
        }
        if (preg_match('/\A[0-9a-f]{32}\z/', $payload) !== 1) {
            return;
        }

        try {
            $this->referrals->bind($userId, $payload);
        } catch (DomainException) {
            // Expected referral-policy rejection must not trap Telegram navigation.
        }
    }
}
