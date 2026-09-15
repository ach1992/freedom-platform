<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\TelegramInlineButtonStyle;
use App\Modules\Telegram\Application\TelegramInlineCallbackButton;
use App\Modules\Telegram\Application\TelegramInlineKeyboardSnapshot;
use App\Modules\Telegram\Application\TelegramMutationOutcome;
use App\Modules\Telegram\Application\TelegramMutationRequest;
use App\Modules\Telegram\Application\TelegramResolvedInlineKeyboardMarkup;
use App\Modules\Telegram\Domain\TelegramDeliveryAction;
use App\Modules\Telegram\Infrastructure\HttpTelegramMutationTransport;
use App\Modules\Telegram\Infrastructure\TelegramRuntimeConfiguration;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Support\NonRestrictedTelegramPresentationTestFactory;
use Tests\TestCase;

/** @requirement ARCH-004 SEC-002 SEC-008 INT-001 INT-002 OPS-003 QUA-001 QUA-004 */
final class TelegramMutationTransportTest extends TestCase
{
    public function test_inline_keyboard_is_sent_as_validated_reply_markup_without_changing_transport_retry_semantics(): void
    {
        Http::fake([
            '*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 501, 'chat' => ['id' => 900001]],
            ], 200),
        ]);

        $callbackPublicId = '01K5A1B2C3D4E5F6G7H8J9K0MN';
        $callbackData = 'i_'.str_repeat('A', 32);
        $snapshot = new TelegramInlineKeyboardSnapshot([[
            new TelegramInlineCallbackButton('Continue', $callbackPublicId, TelegramInlineButtonStyle::Primary),
        ]]);
        $markup = TelegramResolvedInlineKeyboardMarkup::resolve($snapshot, [
            $callbackPublicId => $callbackData,
        ]);

        $result = $this->transport()->mutate(new TelegramMutationRequest(
            TelegramDeliveryAction::Send,
            900001,
            null,
            NonRestrictedTelegramPresentationTestFactory::plainText('choose'),
            $markup,
        ));

        self::assertSame(TelegramMutationOutcome::Success, $result->outcome);
        Http::assertSentCount(1);
        $requests = Http::recorded();
        self::assertSame([
            'inline_keyboard' => [[
                ['text' => 'Continue', 'callback_data' => $callbackData, 'style' => 'primary'],
            ]],
        ], $requests[0][0]['reply_markup']);
        self::assertSame('[PROTECTED_TELEGRAM_INLINE_KEYBOARD]', (string) $markup);
        self::assertStringNotContainsString($callbackData, (string) $markup);
    }

    public function test_send_edit_and_delete_use_one_operation_specific_http_attempt(): void
    {
        Http::fakeSequence()
            ->push(['ok' => true, 'result' => ['message_id' => 101, 'chat' => ['id' => -1001234567890]]], 200)
            ->push(['ok' => true, 'result' => ['message_id' => 101, 'chat' => ['id' => -1001234567890]]], 200)
            ->push(['ok' => true, 'result' => true], 200);

        $transport = $this->transport();
        $send = $transport->mutate(new TelegramMutationRequest(
            TelegramDeliveryAction::Send,
            -1001234567890,
            null,
            NonRestrictedTelegramPresentationTestFactory::plainText('hello'),
        ));
        $edit = $transport->mutate(new TelegramMutationRequest(
            TelegramDeliveryAction::Edit,
            -1001234567890,
            101,
            NonRestrictedTelegramPresentationTestFactory::plainText('updated'),
        ));
        $delete = $transport->mutate(new TelegramMutationRequest(
            TelegramDeliveryAction::Delete,
            -1001234567890,
            101,
            null,
        ));

        self::assertSame(TelegramMutationOutcome::Success, $send->outcome);
        self::assertSame(101, $send->messageId);
        self::assertSame(TelegramMutationOutcome::Success, $edit->outcome);
        self::assertSame(101, $edit->messageId);
        self::assertSame(TelegramMutationOutcome::Success, $delete->outcome);
        self::assertNull($delete->messageId);
        Http::assertSentCount(3);

        $requests = Http::recorded();
        self::assertStringEndsWith('/sendMessage', $requests[0][0]->url());
        self::assertSame('hello', $requests[0][0]['text']);
        self::assertArrayNotHasKey('parse_mode', $requests[0][0]->data());
        self::assertStringEndsWith('/editMessageText', $requests[1][0]->url());
        self::assertSame(101, $requests[1][0]['message_id']);
        self::assertStringEndsWith('/deleteMessage', $requests[2][0]->url());
        self::assertSame(101, $requests[2][0]['message_id']);
    }

    public function test_send_and_edit_success_require_exact_recipient_chat_identity(): void
    {
        Http::fakeSequence()
            ->push(['ok' => true, 'result' => ['message_id' => 301]], 200)
            ->push(['ok' => true, 'result' => ['message_id' => 302, 'chat' => ['id' => 900002]]], 200)
            ->push(['ok' => true, 'result' => ['message_id' => 401, 'chat' => ['id' => 900002]]], 200)
            ->push(['ok' => true, 'result' => ['message_id' => 401, 'chat' => ['id' => 900001]]], 200);

        $transport = $this->transport();
        $sendRequest = new TelegramMutationRequest(
            TelegramDeliveryAction::Send,
            900001,
            null,
            NonRestrictedTelegramPresentationTestFactory::plainText('send identity'),
        );
        $editRequest = new TelegramMutationRequest(
            TelegramDeliveryAction::Edit,
            900001,
            401,
            NonRestrictedTelegramPresentationTestFactory::plainText('edit identity'),
        );

        $missingChat = $transport->mutate($sendRequest);
        $wrongSendChat = $transport->mutate($sendRequest);
        $wrongEditChat = $transport->mutate($editRequest);
        $validEdit = $transport->mutate($editRequest);

        self::assertSame(TelegramMutationOutcome::UncertainResult, $missingChat->outcome);
        self::assertSame('telegram_success_recipient_identity_missing', $missingChat->resultCode);
        self::assertSame(TelegramMutationOutcome::UncertainResult, $wrongSendChat->outcome);
        self::assertSame('telegram_success_recipient_mismatch', $wrongSendChat->resultCode);
        self::assertSame(TelegramMutationOutcome::UncertainResult, $wrongEditChat->outcome);
        self::assertSame('telegram_success_recipient_mismatch', $wrongEditChat->resultCode);
        self::assertSame(TelegramMutationOutcome::Success, $validEdit->outcome);
        self::assertSame(401, $validEdit->messageId);
        Http::assertSentCount(4);
    }

    public function test_send_success_rejects_string_and_zero_recipient_chat_identity(): void
    {
        Http::fakeSequence()
            ->push(['ok' => true, 'result' => ['message_id' => 303, 'chat' => ['id' => '900001']]], 200)
            ->push(['ok' => true, 'result' => ['message_id' => 304, 'chat' => ['id' => 0]]], 200);

        $request = new TelegramMutationRequest(
            TelegramDeliveryAction::Send,
            900001,
            null,
            NonRestrictedTelegramPresentationTestFactory::plainText('send identity shape'),
        );

        $stringChat = $this->transport()->mutate($request);
        $zeroChat = $this->transport()->mutate($request);

        self::assertSame(TelegramMutationOutcome::UncertainResult, $stringChat->outcome);
        self::assertSame('telegram_success_recipient_identity_missing', $stringChat->resultCode);
        self::assertSame(TelegramMutationOutcome::UncertainResult, $zeroChat->outcome);
        self::assertSame('telegram_success_recipient_identity_missing', $zeroChat->resultCode);
        Http::assertSentCount(2);
    }

    public function test_retry_after_is_preserved_but_transport_never_retries_it(): void
    {
        Http::fake([
            '*' => Http::response([
                'ok' => false,
                'error_code' => 429,
                'parameters' => ['retry_after' => 73],
            ], 429),
        ]);

        $result = $this->transport()->mutate(new TelegramMutationRequest(
            TelegramDeliveryAction::Send,
            900001,
            null,
            NonRestrictedTelegramPresentationTestFactory::plainText('rate-limited'),
        ));

        self::assertSame(TelegramMutationOutcome::RetryAfter, $result->outcome);
        self::assertSame(73, $result->retryAfterSeconds);
        Http::assertSentCount(1);
    }

    public function test_server_failure_is_uncertain_and_permanent_rejection_is_final(): void
    {
        Http::fakeSequence()
            ->push(['ok' => false, 'error_code' => 502], 502)
            ->push(['ok' => false, 'error_code' => 403], 403);

        $request = new TelegramMutationRequest(
            TelegramDeliveryAction::Send,
            900001,
            null,
            NonRestrictedTelegramPresentationTestFactory::plainText('message'),
        );

        $serverFailure = $this->transport()->mutate($request);
        self::assertSame(TelegramMutationOutcome::UncertainResult, $serverFailure->outcome);
        self::assertSame('telegram_http_server_error_uncertain', $serverFailure->resultCode);
        self::assertSame(TelegramMutationOutcome::DefinitiveFailure, $this->transport()->mutate($request)->outcome);
        Http::assertSentCount(2);
    }

    public function test_ok_false_with_non_error_http_status_is_uncertain(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => false, 'error_code' => 403], 200),
        ]);

        $result = $this->transport()->mutate(new TelegramMutationRequest(
            TelegramDeliveryAction::Send,
            900001,
            null,
            NonRestrictedTelegramPresentationTestFactory::plainText('message'),
        ));

        self::assertSame(TelegramMutationOutcome::UncertainResult, $result->outcome);
        self::assertSame('telegram_error_status_ambiguous', $result->resultCode);
        Http::assertSentCount(1);
    }

    public function test_timeout_and_edit_target_mismatch_are_uncertain_after_one_attempt(): void
    {
        $attempts = 0;
        Http::fake(function (Request $request) use (&$attempts) {
            $attempts++;
            if ($attempts === 1) {
                throw new RuntimeException('simulated timeout');
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => 202, 'chat' => ['id' => 900001]]], 200);
        });

        $transport = $this->transport();
        $timeout = $transport->mutate(new TelegramMutationRequest(
            TelegramDeliveryAction::Send,
            900001,
            null,
            NonRestrictedTelegramPresentationTestFactory::plainText('message'),
        ));
        $mismatch = $transport->mutate(new TelegramMutationRequest(
            TelegramDeliveryAction::Edit,
            900001,
            201,
            NonRestrictedTelegramPresentationTestFactory::plainText('message'),
        ));

        self::assertSame(TelegramMutationOutcome::UncertainResult, $timeout->outcome);
        self::assertSame('telegram_transport_uncertain', $timeout->resultCode);
        self::assertSame(TelegramMutationOutcome::UncertainResult, $mismatch->outcome);
        self::assertSame('telegram_edit_target_mismatch', $mismatch->resultCode);
        self::assertSame(2, $attempts);
    }

    public function test_non_restricted_presentation_debug_output_is_redacted(): void
    {
        $presentation = NonRestrictedTelegramPresentationTestFactory::plainText('confidential-but-not-restricted-display');

        self::assertSame('[NON_RESTRICTED_TELEGRAM_PRESENTATION]', (string) $presentation);
        self::assertSame(['redacted' => true, 'type' => 'plain_text'], $presentation->__debugInfo());
        self::assertStringNotContainsString('confidential-but-not-restricted-display', (string) $presentation);
    }

    private function transport(): HttpTelegramMutationTransport
    {
        return new HttpTelegramMutationTransport(
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
