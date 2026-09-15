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
            'wallet', 'promotion', 'ticket', 'broadcast', 'admin', 'alert', 'installer', 'updater', 'backup', 'error',
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
