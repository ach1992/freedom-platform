<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Telegram;

use App\Modules\Telegram\Application\Contracts\TelegramBotApi;
use App\Modules\Telegram\Application\Contracts\TelegramMembershipLookup;
use App\Modules\Telegram\Application\TelegramMembershipEvidence;
use App\Modules\Telegram\Infrastructure\HttpTelegramMembershipLookup;
use App\Modules\Telegram\Infrastructure\TelegramRuntimeConfiguration;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

/** @requirement ONB-003 CHN-001 SEC-001 SEC-008 INT-001 QUA-001 */
final class HttpTelegramMembershipLookupTest extends TestCase
{
    public function test_active_member_states_are_normalized_without_exposing_provider_payload(): void
    {
        Http::fakeSequence()
            ->push($this->memberResponse('creator'), 200)
            ->push($this->memberResponse('administrator'), 200)
            ->push($this->memberResponse('member'), 200);

        $lookup = $this->lookup();

        foreach ([
            'telegram_membership_creator',
            'telegram_membership_administrator',
            'telegram_membership_member',
        ] as $expectedCode) {
            $result = $lookup->lookup(-1001234567890, 900001);
            self::assertSame(TelegramMembershipEvidence::Member, $result->evidence);
            self::assertSame($expectedCode, $result->resultCode);
        }

        Http::assertSentCount(3);
    }

    public function test_restricted_left_and_kicked_states_preserve_authoritative_membership_meaning(): void
    {
        Http::fakeSequence()
            ->push($this->memberResponse('restricted', true), 200)
            ->push($this->memberResponse('restricted', false), 200)
            ->push($this->memberResponse('left'), 200)
            ->push($this->memberResponse('kicked'), 200);

        $lookup = $this->lookup();

        $restrictedMember = $lookup->lookup(-1001234567890, 900001);
        self::assertSame(TelegramMembershipEvidence::Member, $restrictedMember->evidence);
        self::assertSame('telegram_membership_restricted_member', $restrictedMember->resultCode);

        $restrictedLeft = $lookup->lookup(-1001234567890, 900001);
        self::assertSame(TelegramMembershipEvidence::NotMember, $restrictedLeft->evidence);
        self::assertSame('telegram_membership_restricted_left', $restrictedLeft->resultCode);

        foreach (['telegram_membership_left', 'telegram_membership_kicked'] as $expectedCode) {
            $result = $lookup->lookup(-1001234567890, 900001);
            self::assertSame(TelegramMembershipEvidence::NotMember, $result->evidence);
            self::assertSame($expectedCode, $result->resultCode);
        }

        Http::assertSentCount(4);
    }

    public function test_invalid_or_unknown_provider_evidence_never_collapses_to_not_member(): void
    {
        Http::fakeSequence()
            ->push(['ok' => true, 'result' => []], 200)
            ->push($this->memberResponse('member', null, 900002), 200)
            ->push($this->memberResponse('future_status'), 200)
            ->push($this->memberResponse('restricted'), 200)
            ->push(['ok' => false, 'error_code' => 400, 'description' => 'provider detail'], 200)
            ->push(['ok' => true, 'result' => 'invalid'], 200)
            ->push(['ok' => true, 'result' => ['user' => ['id' => 900001], 'status' => 123]], 200);

        $lookup = $this->lookup();
        $expectedCodes = [
            'telegram_membership_result_invalid',
            'telegram_membership_user_mismatch',
            'telegram_membership_status_unknown',
            'telegram_membership_restricted_invalid',
            'telegram_membership_api_unavailable',
            'telegram_membership_result_invalid',
            'telegram_membership_status_invalid',
        ];

        foreach ($expectedCodes as $expectedCode) {
            $result = $lookup->lookup(-1001234567890, 900001);
            self::assertSame(TelegramMembershipEvidence::Unavailable, $result->evidence);
            self::assertSame($expectedCode, $result->resultCode);
            self::assertStringNotContainsString('provider detail', $result->resultCode);
            self::assertStringNotContainsString('abcdefghijklmnopqrstuvwxyzABCDE', $result->resultCode);
        }

        Http::assertSentCount(count($expectedCodes));
    }

    public function test_http_and_transport_failures_are_unavailable_and_each_lookup_has_one_attempt(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => false, 'error_code' => 500], 500),
        ]);

        $httpFailure = $this->lookup()->lookup(-1001234567890, 900001);
        self::assertSame(TelegramMembershipEvidence::Unavailable, $httpFailure->evidence);
        self::assertSame('telegram_membership_http_unavailable', $httpFailure->resultCode);
        Http::assertSentCount(1);

        $transportAttempts = 0;
        Http::fake(static function (Request $request) use (&$transportAttempts): never {
            $transportAttempts++;
            throw new RuntimeException('simulated transport failure with provider payload');
        });

        $transportFailure = $this->lookup()->lookup(-1001234567890, 900001);
        self::assertSame(TelegramMembershipEvidence::Unavailable, $transportFailure->evidence);
        self::assertSame('telegram_membership_transport_unavailable', $transportFailure->resultCode);
        self::assertSame(1, $transportAttempts);
    }

    public function test_request_uses_exact_official_endpoint_and_requested_numeric_identities(): void
    {
        Http::fake([
            '*' => Http::response($this->memberResponse('member', null, 912345678), 200),
        ]);

        $result = $this->lookup()->lookup(-1009876543210, 912345678);

        self::assertSame(TelegramMembershipEvidence::Member, $result->evidence);
        Http::assertSentCount(1);
        Http::assertSent(static function (Request $request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://api.telegram.org/bot123456:abcdefghijklmnopqrstuvwxyzABCDE/getChatMember'
                && $request['chat_id'] === -1009876543210
                && $request['user_id'] === 912345678;
        });
    }

    public function test_invalid_local_identities_fail_before_any_provider_request(): void
    {
        Http::fake();
        $lookup = $this->lookup();

        foreach ([[0, 900001], [123, 900001], [-1001234567890, 0]] as [$chatId, $userId]) {
            try {
                $lookup->lookup($chatId, $userId);
                self::fail('Invalid Telegram membership identities must fail before provider access.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringStartsWith('Telegram membership ', $exception->getMessage());
            }
        }

        Http::assertNothingSent();
    }

    public function test_service_provider_resolves_the_dedicated_contract_without_widening_webhook_api(): void
    {
        $this->app->instance(TelegramRuntimeConfiguration::class, $this->configuration());
        $this->app->forgetInstance(TelegramMembershipLookup::class);

        self::assertInstanceOf(
            HttpTelegramMembershipLookup::class,
            $this->app->make(TelegramMembershipLookup::class),
        );

        $webhookApiMethods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(TelegramBotApi::class))->getMethods(),
        );
        sort($webhookApiMethods);
        self::assertSame(['configureWebhook', 'webhookInfo'], $webhookApiMethods);
    }

    /** @return array{ok: true, result: array<string, mixed>} */
    private function memberResponse(string $status, ?bool $isMember = null, int $userId = 900001): array
    {
        $result = [
            'user' => ['id' => $userId, 'is_bot' => false, 'first_name' => 'Example'],
            'status' => $status,
        ];

        if ($isMember !== null) {
            $result['is_member'] = $isMember;
        }

        return ['ok' => true, 'result' => $result];
    }

    private function lookup(): HttpTelegramMembershipLookup
    {
        return new HttpTelegramMembershipLookup(
            $this->app->make(Factory::class),
            $this->configuration(),
        );
    }

    private function configuration(): TelegramRuntimeConfiguration
    {
        return new TelegramRuntimeConfiguration(
            botToken: '123456:abcdefghijklmnopqrstuvwxyzABCDE',
            botId: '123456',
            webhookSecret: str_repeat('w', 32),
            webhookUrl: 'https://example.test/api/telegram/webhook',
            maximumBodyBytes: 1_048_576,
            queue: 'telegram-ingress',
            processingLeaseSeconds: 120,
            apiBaseUrl: 'https://api.telegram.org',
            apiTimeoutSeconds: 15,
        );
    }
}
