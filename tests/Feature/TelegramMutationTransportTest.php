<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\NonRestrictedTelegramPresentation;
use App\Modules\Telegram\Application\TelegramMutationOutcome;
use App\Modules\Telegram\Application\TelegramMutationRequest;
use App\Modules\Telegram\Domain\TelegramDeliveryAction;
use App\Modules\Telegram\Infrastructure\HttpTelegramMutationTransport;
use App\Modules\Telegram\Infrastructure\TelegramRuntimeConfiguration;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/** @requirement ARCH-004 SEC-002 SEC-008 INT-001 INT-002 OPS-003 QUA-001 QUA-004 */
final class TelegramMutationTransportTest extends TestCase
{
    public function test_send_edit_and_delete_use_one_operation_specific_http_attempt(): void
    {
        Http::fakeSequence()
            ->push(['ok' => true, 'result' => ['message_id' => 101]], 200)
            ->push(['ok' => true, 'result' => ['message_id' => 101]], 200)
            ->push(['ok' => true, 'result' => true], 200);

        $transport = $this->transport();
        $send = $transport->mutate(new TelegramMutationRequest(
            TelegramDeliveryAction::Send,
            -1001234567890,
            null,
            NonRestrictedTelegramPresentation::plainText('hello'),
        ));
        $edit = $transport->mutate(new TelegramMutationRequest(
            TelegramDeliveryAction::Edit,
            -1001234567890,
            101,
            NonRestrictedTelegramPresentation::plainText('updated'),
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
            NonRestrictedTelegramPresentation::plainText('rate-limited'),
        ));

        self::assertSame(TelegramMutationOutcome::RetryAfter, $result->outcome);
        self::assertSame(73, $result->retryAfterSeconds);
        Http::assertSentCount(1);
    }

    public function test_explicit_server_rejection_is_retryable_and_permanent_rejection_is_final(): void
    {
        Http::fakeSequence()
            ->push(['ok' => false, 'error_code' => 502], 502)
            ->push(['ok' => false, 'error_code' => 403], 403);

        $request = new TelegramMutationRequest(
            TelegramDeliveryAction::Send,
            900001,
            null,
            NonRestrictedTelegramPresentation::plainText('message'),
        );

        self::assertSame(TelegramMutationOutcome::RetryableFailure, $this->transport()->mutate($request)->outcome);
        self::assertSame(TelegramMutationOutcome::DefinitiveFailure, $this->transport()->mutate($request)->outcome);
        Http::assertSentCount(2);
    }

    public function test_timeout_and_edit_target_mismatch_are_uncertain_after_one_attempt(): void
    {
        $attempts = 0;
        Http::fake(function (Request $request) use (&$attempts) {
            $attempts++;
            if ($attempts === 1) {
                throw new RuntimeException('simulated timeout');
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => 202]], 200);
        });

        $transport = $this->transport();
        $timeout = $transport->mutate(new TelegramMutationRequest(
            TelegramDeliveryAction::Send,
            900001,
            null,
            NonRestrictedTelegramPresentation::plainText('message'),
        ));
        $mismatch = $transport->mutate(new TelegramMutationRequest(
            TelegramDeliveryAction::Edit,
            900001,
            201,
            NonRestrictedTelegramPresentation::plainText('message'),
        ));

        self::assertSame(TelegramMutationOutcome::UncertainResult, $timeout->outcome);
        self::assertSame('telegram_transport_uncertain', $timeout->resultCode);
        self::assertSame(TelegramMutationOutcome::UncertainResult, $mismatch->outcome);
        self::assertSame('telegram_edit_target_mismatch', $mismatch->resultCode);
        self::assertSame(2, $attempts);
    }

    public function test_non_restricted_presentation_debug_output_is_redacted(): void
    {
        $presentation = NonRestrictedTelegramPresentation::plainText('confidential-but-not-restricted-display');

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
