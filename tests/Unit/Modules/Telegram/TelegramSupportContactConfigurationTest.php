<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Telegram;

use App\Modules\Telegram\Application\TelegramSupportContactConfiguration;
use App\Modules\Telegram\Application\TelegramSupportContactDisplayMode;
use Illuminate\Config\Repository;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** @requirement SUP-002 CNT-001 CNT-002 SEC-002 QUA-004 */
final class TelegramSupportContactConfigurationTest extends TestCase
{
    public function test_internal_is_the_behavior_preserving_default(): void
    {
        $configuration = new TelegramSupportContactConfiguration(new Repository([
            'support' => ['contact' => []],
        ]));

        self::assertSame(TelegramSupportContactDisplayMode::Internal, $configuration->mode);
        self::assertNull($configuration->externalUrl);
    }

    public function test_external_and_both_require_and_canonicalize_a_public_username(): void
    {
        foreach ([
            TelegramSupportContactDisplayMode::External,
            TelegramSupportContactDisplayMode::Both,
        ] as $mode) {
            $configuration = new TelegramSupportContactConfiguration(new Repository([
                'support' => [
                    'contact' => [
                        'display_mode' => $mode->value,
                        'external_username' => 'Freedom_Support',
                    ],
                ],
            ]));

            self::assertSame($mode, $configuration->mode);
            self::assertSame('https://t.me/Freedom_Support', $configuration->externalUrl);
        }
    }

    public function test_invalid_mode_or_required_external_username_fails_closed(): void
    {
        foreach ([
            ['display_mode' => 'unknown', 'external_username' => 'FreedomSupport'],
            ['display_mode' => 'external', 'external_username' => null],
            ['display_mode' => 'external', 'external_username' => 'abcd'],
            ['display_mode' => 'both', 'external_username' => '@FreedomSupport'],
            ['display_mode' => 'both', 'external_username' => 'Freedom-Support'],
        ] as $contact) {
            try {
                new TelegramSupportContactConfiguration(new Repository([
                    'support' => ['contact' => $contact],
                ]));
                self::fail('Invalid Telegram Support contact configuration must fail closed.');
            } catch (RuntimeException) {
                // Expected.
            }
        }
    }
}
