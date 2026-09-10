<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Telegram;

use PHPUnit\Framework\TestCase;

/** @requirement LOC-001 QUA-004 */
final class TelegramAgentLocalizationTest extends TestCase
{
    public function test_fa_and_en_agent_localizations_have_matching_keys_and_placeholders(): void
    {
        /** @var array<string, mixed> $en */
        $en = require dirname(__DIR__, 4).'/resources/lang/en/telegram_agent.php';
        /** @var array<string, mixed> $fa */
        $fa = require dirname(__DIR__, 4).'/resources/lang/fa/telegram_agent.php';

        $enStrings = $this->flatten($en);
        $faStrings = $this->flatten($fa);

        self::assertSame(array_keys($enStrings), array_keys($faStrings));

        foreach ($enStrings as $key => $enText) {
            self::assertArrayHasKey($key, $faStrings);
            self::assertNotSame('', trim($enText), "English Agent localization [{$key}] must not be empty.");
            self::assertNotSame('', trim($faStrings[$key]), "Persian Agent localization [{$key}] must not be empty.");
            self::assertSame(
                $this->placeholders($enText),
                $this->placeholders($faStrings[$key]),
                "Agent localization [{$key}] must use the same placeholders in English and Persian.",
            );
        }

        self::assertSame(
            [':approved_at', ':joined_at', ':status'],
            $this->placeholders($enStrings['agent.status']),
        );
        self::assertSame([], $this->placeholders($enStrings['application.not_submitted']));
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

            self::assertIsString($value, "Agent localization [{$path}] must be a string.");
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
