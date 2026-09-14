<?php

declare(strict_types=1);

namespace App\Modules\Localization\Application;

use Illuminate\Translation\Translator;
use InvalidArgumentException;
use RuntimeException;

final readonly class LocalizationTemplateCatalog
{
    private const MAX_TEMPLATE_LENGTH = 4096;

    public function __construct(private Translator $translator) {}

    public function assertKey(string $key): void
    {
        if (preg_match('/\A[A-Za-z0-9_][A-Za-z0-9_.-]{0,190}\z/', $key) !== 1) {
            throw new InvalidArgumentException('Localization key is invalid.');
        }
    }

    public function assertLocale(string $locale): void
    {
        if (! in_array($locale, ['fa', 'en'], true)) {
            throw new InvalidArgumentException('Localization locale is invalid.');
        }
    }

    public function template(string $key, string $locale): string
    {
        $template = $this->templateOrNull($key, $locale);
        if ($template === null) {
            throw new InvalidArgumentException('Localization key does not have a scalar file-backed default for this locale.');
        }

        return $template;
    }

    public function templateOrNull(string $key, string $locale): ?string
    {
        $this->assertKey($key);
        $this->assertLocale($locale);

        if (! $this->translator->hasForLocale($key, $locale)) {
            return null;
        }

        $value = $this->translator->get($key, [], $locale, false);
        if (! is_string($value)) {
            return null;
        }

        return $value;
    }

    public function validateOverride(string $key, string $locale, string $value): string
    {
        $default = $this->template($key, $locale);

        if ($value === '' || trim($value) === '' || mb_strlen($value) > self::MAX_TEMPLATE_LENGTH) {
            throw new InvalidArgumentException('Localization override value is invalid.');
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException('Localization override value contains unsupported control characters.');
        }

        if ($this->placeholders($value) !== $this->placeholders($default)) {
            throw new InvalidArgumentException('Localization override placeholders must exactly match the file-backed template.');
        }

        return $value;
    }

    /** @return list<string> */
    public function placeholders(string $template): array
    {
        preg_match_all('/:([A-Za-z_][A-Za-z0-9_]*)/', $template, $matches);
        $placeholders = array_map(static fn (string $value): string => strtolower($value), $matches[1]);
        $placeholders = array_values(array_unique($placeholders));
        sort($placeholders);

        return $placeholders;
    }

    /** @param array<string, bool|float|int|string> $replacements */
    public function render(string $template, array $replacements): string
    {
        $required = $this->placeholders($template);
        foreach ($required as $placeholder) {
            if (! array_key_exists($placeholder, $replacements)) {
                throw new RuntimeException('Localization replacement is missing for placeholder: '.$placeholder);
            }
        }

        $replace = [];
        foreach ($replacements as $key => $value) {
            if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $key) !== 1) {
                throw new InvalidArgumentException('Localization replacement key is invalid.');
            }
            if (! is_bool($value) && ! is_int($value) && ! is_float($value) && ! is_string($value)) {
                throw new InvalidArgumentException('Localization replacement value is invalid.');
            }

            $string = (string) $value;
            $replace[':'.$key] = $string;
            $replace[':'.ucfirst($key)] = ucfirst($string);
            $replace[':'.strtoupper($key)] = strtoupper($string);
        }

        $rendered = strtr($template, $replace);
        if ($this->placeholders($rendered) !== []) {
            throw new RuntimeException('Localization rendering left unresolved placeholders.');
        }

        return $rendered;
    }
}
