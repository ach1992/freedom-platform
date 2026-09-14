<?php

declare(strict_types=1);

namespace App\Modules\Localization\Application;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class LocalizationTemplateCatalog
{
    private const MAX_TEMPLATE_LENGTH = 4096;

    /** @var array<string, array<string, mixed>|null> */
    private array $loadedGroups = [];

    public function __construct(
        private readonly Filesystem $files,
        private readonly ?string $resourceRoot = null,
    ) {}

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

        $segments = explode('.', $key);
        $group = array_shift($segments);
        if ($group === null || $group === '' || $segments === []) {
            return null;
        }

        $values = $this->fileGroup($locale, $group);
        if ($values === null) {
            return null;
        }

        $value = $values;
        foreach ($segments as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return is_string($value) ? $value : null;
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
        if (preg_match('/:{2,}[A-Za-z_]/', $template) === 1) {
            throw new InvalidArgumentException('Localization template contains malformed placeholder syntax.');
        }

        preg_match_all('/:([A-Za-z_][A-Za-z0-9_]*)/', $template, $matches);
        $placeholders = [];
        foreach ($matches[1] as $rawPlaceholder) {
            $placeholder = strtolower($rawPlaceholder);
            if (! in_array($rawPlaceholder, [$placeholder, ucfirst($placeholder), strtoupper($placeholder)], true)) {
                throw new InvalidArgumentException('Localization template contains unsupported placeholder casing.');
            }
            $placeholders[] = $placeholder;
        }

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
            $replace[':'.Str::ucfirst($key)] = Str::ucfirst($string);
            $replace[':'.Str::upper($key)] = Str::upper($string);
            $replace[':'.$key] = $string;
        }

        return strtr($template, $replace);
    }

    /** @return array<string, mixed>|null */
    private function fileGroup(string $locale, string $group): ?array
    {
        $cacheKey = $locale.'|'.$group;
        if (array_key_exists($cacheKey, $this->loadedGroups)) {
            return $this->loadedGroups[$cacheKey];
        }

        $root = $this->resourceRoot ?? resource_path('lang');
        $path = rtrim($root, '/').'/'.$locale.'/'.$group.'.php';
        if (! $this->files->isFile($path)) {
            return $this->loadedGroups[$cacheKey] = null;
        }

        $values = $this->files->getRequire($path);
        if (! is_array($values)) {
            return $this->loadedGroups[$cacheKey] = null;
        }

        return $this->loadedGroups[$cacheKey] = $values;
    }
}
