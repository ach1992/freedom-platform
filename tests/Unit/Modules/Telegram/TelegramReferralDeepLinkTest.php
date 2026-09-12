<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Telegram;

use App\Modules\Telegram\Application\TelegramReferralDeepLink;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

final class TelegramReferralDeepLinkTest extends TestCase
{
    /** @requirement REF-001 ONB-002 SEC-002 SEC-003 QUA-001 */
    public function test_it_builds_the_canonical_t_me_start_link_from_public_configuration_and_existing_token(): void
    {
        $token = str_repeat('a', 32);

        self::assertSame(
            'https://t.me/FreedomReferralBot?start='.$token,
            TelegramReferralDeepLink::fromConfiguration('FreedomReferralBot')->forReferralToken($token),
        );
    }

    /** @requirement REF-001 SEC-003 */
    public function test_missing_public_bot_username_keeps_referral_link_unavailable(): void
    {
        self::assertNull(
            TelegramReferralDeepLink::fromConfiguration(null)->forReferralToken(str_repeat('b', 32)),
        );
        self::assertNull(
            TelegramReferralDeepLink::fromConfiguration('')->forReferralToken(str_repeat('c', 32)),
        );
    }

    /** @requirement REF-001 SEC-002 SEC-003 */
    public function test_malformed_bot_username_fails_closed_before_any_url_can_be_built(): void
    {
        $this->expectException(RuntimeException::class);

        TelegramReferralDeepLink::fromConfiguration('https://evil.example/path');
    }

    /** @requirement REF-001 SEC-002 */
    public function test_noncanonical_referral_token_is_rejected(): void
    {
        $builder = TelegramReferralDeepLink::fromConfiguration('FreedomReferralBot');
        $this->expectException(InvalidArgumentException::class);

        $builder->forReferralToken('../escape');
    }
}
