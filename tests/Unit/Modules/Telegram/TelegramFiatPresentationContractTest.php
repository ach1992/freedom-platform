<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Telegram;

use PHPUnit\Framework\TestCase;

final class TelegramFiatPresentationContractTest extends TestCase
{
    /** @requirement DAT-002 LOC-002 */
    public function test_telegram_application_does_not_raw_format_irr_named_values(): void
    {
        $files = glob(app_path('Modules/Telegram/Application/*.php'));
        self::assertIsArray($files);
        self::assertNotEmpty($files);

        $violations = [];
        foreach ($files as $file) {
            $source = file_get_contents($file);
            self::assertIsString($source);

            if (preg_match_all('/number_format\([^;\n]*(?:Irr|_irr)/', $source, $matches) > 0) {
                foreach ($matches[0] as $match) {
                    $violations[] = basename($file).': '.$match;
                }
            }
        }

        self::assertSame([], $violations, 'Raw IRR formatting bypasses the canonical Toman presentation formatter.');
    }

    /** @requirement DAT-002 LOC-002 */
    public function test_fiat_output_localizations_use_toman_while_explicit_irr_input_contracts_remain_allowed(): void
    {
        $allowedIrrInputText = [
            'fa/telegram.php' => [
                'نرخ جدید IRR برای هر USDT را فقط به‌صورت عدد ارسال کنید.',
                'یک عدد معتبر IRR برای هر USDT ارسال کنید.',
            ],
            'en/telegram.php' => [
                'Send the new IRR amount per USDT as a number only.',
                'Send a valid IRR amount per USDT.',
            ],
            'fa/telegram_admin_wallet.php' => [
                'مبلغ را به ریال ارسال کنید.',
                'یک مبلغ معتبر و مثبت به ریال وارد کنید.',
            ],
            'en/telegram_admin_wallet.php' => [
                'Send the amount in IRR.',
                'Enter a valid positive IRR amount.',
            ],
            'fa/telegram_wallet_top_up.php' => [
                'مبلغ موردنظر برای واریز به کیف پول نقدی را به ریال ارسال کنید.',
                'یک مبلغ معتبر و مثبت به ریال وارد کنید.',
            ],
            'en/telegram_wallet_top_up.php' => [
                'Send the amount in IRR that you want to add to your cash wallet.',
                'Enter a valid positive IRR amount.',
            ],
            'fa/telegram_wallet_transfer.php' => [
                'مبلغ انتقال را به ریال ارسال کنید.',
                'یک عدد صحیح مثبت به ریال ارسال کنید.',
            ],
            'en/telegram_wallet_transfer.php' => [
                'Send the IRR amount to transfer.',
                'Send a positive IRR integer.',
            ],
        ];

        foreach (glob(resource_path('lang/{fa,en}/telegram*.php'), GLOB_BRACE) ?: [] as $file) {
            $relative = basename(dirname($file)).'/'.basename($file);
            $source = file_get_contents($file);
            self::assertIsString($source);

            foreach ($allowedIrrInputText[$relative] ?? [] as $allowed) {
                self::assertStringContainsString($allowed, $source);
                $source = str_replace($allowed, '', $source);
            }

            self::assertDoesNotMatchRegularExpression(
                '/(?:\bIRR\b|ریال)/u',
                $source,
                'Telegram localization contains a raw IRR display outside an explicit input contract: '.$relative,
            );
        }
    }
}
