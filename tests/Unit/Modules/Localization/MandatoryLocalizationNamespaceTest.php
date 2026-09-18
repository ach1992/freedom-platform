<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Localization;

use App\Modules\Localization\Application\LocalizationTemplateCatalog;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** @requirement LOC-001 CNT-001 QUA-004 */
final class MandatoryLocalizationNamespaceTest extends TestCase
{
    public function test_required_namespace_defaults_have_locale_placeholder_and_metadata_parity(): void
    {
        $catalog = new LocalizationTemplateCatalog(new Filesystem, dirname(__DIR__, 4).'/resources/lang');
        $keys = $catalog->mandatoryKeys();

        self::assertNotEmpty($keys);
        foreach ([
            'onboarding', 'identity', 'menu', 'catalog', 'quote', 'payment', 'order', 'service', 'agent',
            'wallet', 'promotion', 'ticket', 'telegram', 'broadcast', 'admin', 'alert', 'installer', 'updater', 'backup', 'error',
        ] as $family) {
            self::assertNotEmpty(
                array_filter($keys, static fn (string $key): bool => str_starts_with($key, $family.'.')),
                "Mandatory localization family [{$family}] must contain seeded keys.",
            );
        }

        foreach ([
            'payment.wallet.', 'payment.card.', 'payment.gift_card.', 'payment.usdt.', 'payment.zarinpal.',
            'payment.nowpayments.',
        ] as $prefix) {
            self::assertNotEmpty(
                array_filter($keys, static fn (string $key): bool => str_starts_with($key, $prefix)),
                "Mandatory payment localization namespace [{$prefix}] must contain seeded keys.",
            );
        }

        foreach ($keys as $key) {
            $english = $catalog->template($key, 'en');
            $persian = $catalog->template($key, 'fa');
            $metadata = $catalog->metadata($key);

            self::assertNotSame('', trim($english), "English mandatory localization [{$key}] must not be empty.");
            self::assertNotSame('', trim($persian), "Persian mandatory localization [{$key}] must not be empty.");
            self::assertSame($catalog->placeholders($english), $catalog->placeholders($persian), "Placeholder parity failed for [{$key}].");
            self::assertSame($catalog->placeholders($english), $metadata['placeholders']);
            self::assertSame('plain_text', $metadata['parse_mode']);
            self::assertGreaterThan(0, $metadata['max_length']);
            self::assertLessThanOrEqual($metadata['max_length'], mb_strlen($english));
            self::assertLessThanOrEqual($metadata['max_length'], mb_strlen($persian));
            self::assertNotEmpty($metadata['contexts']);
        }

        self::assertSame(['button'], $catalog->metadata('menu.back')['contexts']);
        self::assertSame(['message', 'media_caption'], $catalog->metadata('broadcast.preview')['contexts']);
        self::assertSame(['amount', 'currency'], $catalog->metadata('quote.final_price')['placeholders']);
    }

    public function test_all_locale_catalog_files_are_loadable_arrays_with_locale_file_parity(): void
    {
        $root = dirname(__DIR__, 4).'/resources/lang';
        $catalogFiles = [];

        foreach (['en', 'fa'] as $locale) {
            $paths = glob($root.'/'.$locale.'/*.php') ?: [];
            sort($paths);

            self::assertNotEmpty($paths, "Localization locale [{$locale}] must contain catalog files.");
            $catalogFiles[$locale] = array_map('basename', $paths);

            foreach ($paths as $path) {
                $catalog = require $path;
                self::assertIsArray($catalog, "Localization catalog [{$path}] must return an array.");
            }
        }

        self::assertSame($catalogFiles['en'], $catalogFiles['fa'], 'English and Persian locale catalogs must expose the same file set.');

        $mandatoryContract = require $root.'/_mandatory.php';
        self::assertIsArray($mandatoryContract, 'Mandatory localization metadata must return an array.');
        self::assertNotEmpty($mandatoryContract['families'] ?? [], 'Mandatory localization metadata must declare families.');
    }

    public function test_mandatory_button_override_respects_documented_length_and_placeholders(): void
    {
        $catalog = new LocalizationTemplateCatalog(new Filesystem, dirname(__DIR__, 4).'/resources/lang');

        self::assertSame('Go back', $catalog->validateOverride('menu.back', 'en', 'Go back'));

        try {
            $catalog->validateOverride('menu.back', 'en', str_repeat('x', 65));
            self::fail('Mandatory button override must respect its documented maximum length.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }

        try {
            $catalog->validateOverride('quote.final_price', 'en', 'Final price: :amount');
            self::fail('Mandatory override must preserve the documented placeholder contract.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
    }

    public function test_direct_message_confirmation_override_reserves_the_full_3500_character_body_budget(): void
    {
        $catalog = new LocalizationTemplateCatalog(new Filesystem, dirname(__DIR__, 4).'/resources/lang');
        $confirmationKey = 'telegram.navigation.admin.customer_search.message_confirmation';
        $usernameUnavailableKey = 'telegram.navigation.admin.customer_search.username_unavailable';
        $maximumHeaderTemplate = str_repeat('x', 506).':telegram_id :account_id :username';
        $tooLargeHeaderTemplate = str_repeat('x', 507).':telegram_id :account_id :username';
        $repeatedHeaderTemplate = str_repeat('x', 454).':telegram_id :account_id :username :username';
        $uppercaseHeaderTemplate = str_repeat('x', 506).':telegram_id :account_id :USERNAME';
        $titleCaseHeaderTemplate = str_repeat('x', 506).':telegram_id :account_id :Username';
        $replacements = [
            'telegram_id' => str_repeat('9', 20),
            'account_id' => str_repeat('A', 26),
            'username' => str_repeat('u', 40),
        ];

        foreach (['fa', 'en'] as $locale) {
            self::assertSame(
                $maximumHeaderTemplate,
                $catalog->validateOverride($confirmationKey, $locale, $maximumHeaderTemplate),
            );
            $rendered = $catalog->render($maximumHeaderTemplate, $replacements);
            self::assertSame(594, mb_strlen($rendered));
            self::assertSame(4096, mb_strlen($rendered."\n\n".str_repeat('m', 3500)));

            self::assertSame(
                $repeatedHeaderTemplate,
                $catalog->validateOverride($confirmationKey, $locale, $repeatedHeaderTemplate),
            );
            self::assertSame(582, mb_strlen($catalog->render($repeatedHeaderTemplate, $replacements)));

            foreach ([$uppercaseHeaderTemplate, $titleCaseHeaderTemplate] as $caseTransformingTemplate) {
                try {
                    $catalog->validateOverride($confirmationKey, $locale, $caseTransformingTemplate);
                    self::fail('Budgeted confirmation placeholders must use lowercase spelling.');
                } catch (InvalidArgumentException) {
                    self::assertTrue(true);
                }
            }

            $expandingUnicodeFallback = str_repeat('ß', 40);
            self::assertSame(40, mb_strlen($expandingUnicodeFallback));
            self::assertSame(
                $expandingUnicodeFallback,
                $catalog->validateOverride($usernameUnavailableKey, $locale, $expandingUnicodeFallback),
            );
            $unicodeRendered = $catalog->render($maximumHeaderTemplate, [
                'telegram_id' => str_repeat('9', 20),
                'account_id' => str_repeat('A', 26),
                'username' => $expandingUnicodeFallback,
            ]);
            self::assertSame(594, mb_strlen($unicodeRendered));
            self::assertSame(4096, mb_strlen($unicodeRendered."\n\n".str_repeat('m', 3500)));

            try {
                $catalog->validateOverride($confirmationKey, $locale, $tooLargeHeaderTemplate);
                self::fail('Direct-message confirmation override must preserve the reserved 3500-character body budget.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }

            self::assertSame(
                str_repeat('u', 40),
                $catalog->validateOverride($usernameUnavailableKey, $locale, str_repeat('u', 40)),
            );
            try {
                $catalog->validateOverride($usernameUnavailableKey, $locale, str_repeat('u', 41));
                self::fail('Unavailable-username override must fit the confirmation replacement budget.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_mandatory_metadata_fails_closed_when_a_locale_default_is_missing(): void
    {
        $root = sys_get_temp_dir().'/freedom-localization-missing-'.bin2hex(random_bytes(6));
        $files = new Filesystem;
        $files->ensureDirectoryExists($root.'/fa');
        $files->ensureDirectoryExists($root.'/en');
        $files->put($root.'/_mandatory.php', <<<'PHP'
<?php
return [
    'parse_mode' => 'plain_text',
    'message_max_length' => 4096,
    'button_max_length' => 64,
    'families' => ['probe' => ['message']],
    'button_keys' => [],
    'media_caption_keys' => [],
];
PHP);
        $files->put($root.'/en/_mandatory.php', "<?php return ['probe' => ['message' => 'English']];");
        $files->put($root.'/fa/_mandatory.php', "<?php return ['probe' => []];");

        try {
            $catalog = new LocalizationTemplateCatalog($files, $root);
            $this->expectException(InvalidArgumentException::class);
            $catalog->metadata('probe.message');
        } finally {
            $files->deleteDirectory($root);
        }
    }

    public function test_mandatory_metadata_fails_closed_when_contract_limits_are_invalid(): void
    {
        $root = sys_get_temp_dir().'/freedom-localization-metadata-'.bin2hex(random_bytes(6));
        $files = new Filesystem;
        $files->ensureDirectoryExists($root.'/fa');
        $files->ensureDirectoryExists($root.'/en');
        $files->put($root.'/_mandatory.php', <<<'PHP'
<?php
return [
    'parse_mode' => 'html',
    'message_max_length' => 4096,
    'button_max_length' => 64,
    'families' => ['probe' => ['message']],
    'button_keys' => [],
    'media_caption_keys' => [],
];
PHP);
        $files->put($root.'/en/_mandatory.php', "<?php return ['probe' => ['message' => 'English']];");
        $files->put($root.'/fa/_mandatory.php', "<?php return ['probe' => ['message' => 'فارسی']];");

        try {
            $catalog = new LocalizationTemplateCatalog($files, $root);
            $this->expectException(RuntimeException::class);
            $catalog->metadata('probe.message');
        } finally {
            $files->deleteDirectory($root);
        }
    }
}
