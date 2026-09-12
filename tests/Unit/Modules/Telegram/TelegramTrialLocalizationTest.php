<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Telegram;

use PHPUnit\Framework\TestCase;

/** @requirement CAT-006 LOC-001 QUA-004 */
final class TelegramTrialLocalizationTest extends TestCase
{
    public function test_fa_and_en_trial_localizations_have_matching_keys_and_placeholders(): void
    {
        /** @var array<string, mixed> $en */
        $en = require dirname(__DIR__, 4).'/resources/lang/en/telegram_trial.php';
        /** @var array<string, mixed> $fa */
        $fa = require dirname(__DIR__, 4).'/resources/lang/fa/telegram_trial.php';

        $enStrings = $this->flatten($en);
        $faStrings = $this->flatten($fa);

        self::assertSame(array_keys($enStrings), array_keys($faStrings));

        foreach ($enStrings as $key => $enText) {
            self::assertArrayHasKey($key, $faStrings);
            self::assertNotSame('', trim($enText), "English Trial localization [{$key}] must not be empty.");
            self::assertNotSame('', trim($faStrings[$key]), "Persian Trial localization [{$key}] must not be empty.");
            self::assertSame(
                $this->placeholders($enText),
                $this->placeholders($faStrings[$key]),
                "Trial localization [{$key}] must use the same placeholders in English and Persian.",
            );
        }

        self::assertSame(
            [':items', ':page', ':total_items', ':total_pages'],
            $this->placeholders($enStrings['list']),
        );
        self::assertSame(
            [':category', ':data', ':duration', ':membership', ':mode', ':phone', ':plan'],
            $this->placeholders($enStrings['detail']),
        );
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    private function flatten(array $values, string $prefix = ''): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            $path = $prefix === '' ? $key : $prefix.'.'.$key;
            if (is_array($value)) {
                $result += $this->flatten($value, $path);

                continue;
            }

            self::assertIsString($value, "Trial localization [{$path}] must be a string.");
            $result[$path] = $value;
        }

        ksort($result);

        return $result;
    }

    /** @return list<string> */
    private function placeholders(string $text): array
    {
        preg_match_all('/:[A-Za-z_][A-Za-z0-9_]*/', $text, $matches);
        $placeholders = array_values(array_unique($matches[0]));
        sort($placeholders);

        return $placeholders;
    }
}
