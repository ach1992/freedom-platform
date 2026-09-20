<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\TelegramBroadcastLifecycleMutationRequest;
use App\Modules\Telegram\Application\TelegramMutationOutcome;
use App\Modules\Telegram\Domain\TelegramBroadcastLifecycleAction;
use App\Modules\Telegram\Infrastructure\HttpTelegramBroadcastLifecycleTransport;
use App\Modules\Telegram\Infrastructure\TelegramRuntimeConfiguration;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class TelegramBroadcastLifecycleTransportTest extends TestCase
{
    private const CHAT_ID = 99887766;

    private const MESSAGE_ID = 771;

    public function test_buttons_with_null_markup_explicitly_remove_existing_keyboard(): void
    {
        Http::fake([
            '*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => self::MESSAGE_ID],
            ], 200),
        ]);

        $result = $this->transport()->mutate(
            TelegramBroadcastLifecycleMutationRequest::buttons(
                self::CHAT_ID,
                self::MESSAGE_ID,
                null,
            ),
        );

        self::assertSame(TelegramMutationOutcome::Success, $result->outcome);
        Http::assertSent(function (Request $request): bool {
            return str_ends_with($request->url(), '/editMessageReplyMarkup')
                && $request['chat_id'] === self::CHAT_ID
                && $request['message_id'] === self::MESSAGE_ID
                && $request['reply_markup'] === ['inline_keyboard' => []];
        });
    }

    public function test_pin_and_unpin_target_the_exact_message_without_unpin_all(): void
    {
        Http::fakeSequence()
            ->push(['ok' => true, 'result' => true], 200)
            ->push(['ok' => true, 'result' => true], 200);

        $transport = $this->transport();
        $pin = $transport->mutate(TelegramBroadcastLifecycleMutationRequest::pin(
            self::CHAT_ID,
            self::MESSAGE_ID,
        ));
        $unpin = $transport->mutate(TelegramBroadcastLifecycleMutationRequest::unpin(
            self::CHAT_ID,
            self::MESSAGE_ID,
        ));

        self::assertSame(TelegramMutationOutcome::Success, $pin->outcome);
        self::assertSame(TelegramMutationOutcome::Success, $unpin->outcome);

        $requests = Http::recorded();
        self::assertStringEndsWith('/pinChatMessage', $requests[0][0]->url());
        self::assertSame(self::MESSAGE_ID, $requests[0][0]['message_id']);
        self::assertTrue($requests[0][0]['disable_notification']);
        self::assertStringEndsWith('/unpinChatMessage', $requests[1][0]->url());
        self::assertSame(self::MESSAGE_ID, $requests[1][0]['message_id']);
        self::assertStringNotContainsString('unpinAll', $requests[1][0]->url());
    }

    public function test_transport_timeout_is_uncertain_after_one_attempt(): void
    {
        $attempts = 0;
        Http::fake(function (Request $request) use (&$attempts): never {
            $attempts++;

            throw new RuntimeException('simulated timeout');
        });

        $result = $this->transport()->mutate(
            TelegramBroadcastLifecycleMutationRequest::pin(
                self::CHAT_ID,
                self::MESSAGE_ID,
            ),
        );

        self::assertSame(TelegramMutationOutcome::UncertainResult, $result->outcome);
        self::assertSame('tg_broadcast_lifecycle_transport_uncertain', $result->resultCode);
        self::assertSame(1, $attempts);
    }

    public function test_retry_after_is_exposed_without_hidden_transport_retry(): void
    {
        Http::fake([
            '*' => Http::response([
                'ok' => false,
                'error_code' => 429,
                'parameters' => ['retry_after' => 41],
            ], 429),
        ]);

        $result = $this->transport()->mutate(
            TelegramBroadcastLifecycleMutationRequest::unpin(
                self::CHAT_ID,
                self::MESSAGE_ID,
            ),
        );

        self::assertSame(TelegramMutationOutcome::RetryAfter, $result->outcome);
        self::assertSame(41, $result->retryAfterSeconds);
        Http::assertSentCount(1);
    }

    public function test_edit_success_requires_exact_message_identity(): void
    {
        Http::fake([
            '*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => self::MESSAGE_ID + 1],
            ], 200),
        ]);

        $result = $this->transport()->mutate(
            TelegramBroadcastLifecycleMutationRequest::editCaption(
                self::CHAT_ID,
                self::MESSAGE_ID,
                'updated',
                null,
            ),
        );

        self::assertSame(TelegramMutationOutcome::UncertainResult, $result->outcome);
        self::assertSame('tg_broadcast_lifecycle_identity_mismatch', $result->resultCode);
    }

    public function test_delete_is_not_exposed_by_direct_lifecycle_transport_request(): void
    {
        self::assertSame(
            'delete',
            TelegramBroadcastLifecycleAction::Delete->value,
        );
        self::assertFalse(method_exists(TelegramBroadcastLifecycleMutationRequest::class, 'delete'));
    }

    private function transport(): HttpTelegramBroadcastLifecycleTransport
    {
        return new HttpTelegramBroadcastLifecycleTransport(
            $this->app->make(Factory::class),
            new TelegramRuntimeConfiguration(
                botToken: '123456:abcdefghijklmnopqrstuvwxyzABCDE',
                botId: '123456',
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
