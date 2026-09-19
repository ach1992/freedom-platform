<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\TelegramInlineHttpsUrlButton;
use App\Modules\Telegram\Application\TelegramInlineHttpsUrlPurpose;
use App\Modules\Telegram\Application\TelegramInlineKeyboardSnapshot;
use App\Modules\Telegram\Application\TelegramMutationOutcome;
use App\Modules\Telegram\Application\TelegramResolvedInlineKeyboardMarkup;
use App\Modules\Telegram\Application\TelegramResolvedSourceMessagePresentation;
use App\Modules\Telegram\Application\TelegramSourceMessageMode;
use App\Modules\Telegram\Infrastructure\HttpTelegramSourceMessageSender;
use App\Modules\Telegram\Infrastructure\TelegramRuntimeConfiguration;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/** @requirement COM-001 SEC-002 SEC-003 SEC-008 INT-001 INT-002 OPS-003 QUA-001 QUA-004 */
final class TelegramSourceMessageSenderTest extends TestCase
{
    private const BOT_ID = 123456;

    private const RECIPIENT_CHAT_ID = 99887766;

    private const SOURCE_CHAT_ID = 11223344;

    private const SOURCE_MESSAGE_ID = 731;

    public function test_forward_uses_forward_message_and_verifies_target_message_identity(): void
    {
        Http::fake([
            '*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 8801,
                    'chat' => ['id' => self::RECIPIENT_CHAT_ID],
                ],
            ], 200),
        ]);

        $result = $this->sender()->send(
            self::RECIPIENT_CHAT_ID,
            new TelegramResolvedSourceMessagePresentation(
                TelegramSourceMessageMode::Forward,
                self::SOURCE_CHAT_ID,
                self::SOURCE_MESSAGE_ID,
            ),
        );

        self::assertSame(TelegramMutationOutcome::Success, $result->outcome);
        self::assertSame(8801, $result->messageId);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();

            return str_ends_with($request->url(), '/forwardMessage')
                && $payload['chat_id'] === self::RECIPIENT_CHAT_ID
                && $payload['from_chat_id'] === self::SOURCE_CHAT_ID
                && $payload['message_id'] === self::SOURCE_MESSAGE_ID
                && ! array_key_exists('reply_markup', $payload);
        });
    }

    public function test_copy_accepts_safe_resolved_keyboard_and_integer_message_id_result(): void
    {
        Http::fake([
            '*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 8802],
            ], 200),
        ]);

        $snapshot = new TelegramInlineKeyboardSnapshot([[
            new TelegramInlineHttpsUrlButton(
                'Support',
                'https://t.me/example_support',
                TelegramInlineHttpsUrlPurpose::SupportContact,
            ),
        ]]);
        $keyboard = TelegramResolvedInlineKeyboardMarkup::resolve($snapshot, []);

        $result = $this->sender()->send(
            self::RECIPIENT_CHAT_ID,
            new TelegramResolvedSourceMessagePresentation(
                TelegramSourceMessageMode::Copy,
                self::SOURCE_CHAT_ID,
                self::SOURCE_MESSAGE_ID,
            ),
            $keyboard,
        );

        self::assertSame(TelegramMutationOutcome::Success, $result->outcome);
        self::assertSame(8802, $result->messageId);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();

            return str_ends_with($request->url(), '/copyMessage')
                && $payload['chat_id'] === self::RECIPIENT_CHAT_ID
                && $payload['from_chat_id'] === self::SOURCE_CHAT_ID
                && $payload['message_id'] === self::SOURCE_MESSAGE_ID
                && ($payload['reply_markup']['inline_keyboard'][0][0]['text'] ?? null) === 'Support'
                && ($payload['reply_markup']['inline_keyboard'][0][0]['url'] ?? null) === 'https://t.me/example_support';
        });
    }

    public function test_forward_rejects_authored_keyboard_before_provider_io(): void
    {
        Http::fake();

        $snapshot = new TelegramInlineKeyboardSnapshot([[
            new TelegramInlineHttpsUrlButton(
                'Support',
                'https://t.me/example_support',
                TelegramInlineHttpsUrlPurpose::SupportContact,
            ),
        ]]);

        try {
            $this->sender()->send(
                self::RECIPIENT_CHAT_ID,
                new TelegramResolvedSourceMessagePresentation(
                    TelegramSourceMessageMode::Forward,
                    self::SOURCE_CHAT_ID,
                    self::SOURCE_MESSAGE_ID,
                ),
                TelegramResolvedInlineKeyboardMarkup::resolve($snapshot, []),
            );
            self::fail('Forward must reject reply markup before provider I/O.');
        } catch (InvalidArgumentException) {
            Http::assertNothingSent();
        }
    }

    public function test_transport_exception_is_uncertain_without_hidden_retry(): void
    {
        $attempts = 0;
        Http::fake(function (Request $request) use (&$attempts): never {
            $attempts++;

            throw new RuntimeException('Simulated Telegram source-message timeout.');
        });

        $result = $this->sender()->send(
            self::RECIPIENT_CHAT_ID,
            new TelegramResolvedSourceMessagePresentation(
                TelegramSourceMessageMode::Copy,
                self::SOURCE_CHAT_ID,
                self::SOURCE_MESSAGE_ID,
            ),
        );

        self::assertSame(TelegramMutationOutcome::UncertainResult, $result->outcome);
        self::assertSame('telegram_source_message_transport_uncertain', $result->resultCode);
        self::assertSame(1, $attempts);
    }

    private function sender(): HttpTelegramSourceMessageSender
    {
        return new HttpTelegramSourceMessageSender(
            $this->app->make(Factory::class),
            new TelegramRuntimeConfiguration(
                botToken: self::BOT_ID.':abcdefghijklmnopqrstuvwxyzABCDE',
                botId: (string) self::BOT_ID,
                webhookSecret: str_repeat('w', 32),
                webhookUrl: 'https://example.test/api/telegram/webhook',
                maximumBodyBytes: 1_048_576,
                queue: 'telegram-ingress',
                processingLeaseSeconds: 120,
                apiBaseUrl: 'https://api.telegram.org',
                apiTimeoutSeconds: 15,
            ),
        );
    }
}
