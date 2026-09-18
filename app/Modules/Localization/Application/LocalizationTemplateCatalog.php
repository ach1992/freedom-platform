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

    /** @var array<string, mixed>|null */
    private ?array $mandatoryContract = null;

    /** @var list<string>|null */
    private ?array $mandatoryKeys = null;

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

        $template = $this->nestedString($this->fileGroup($locale, $group), $segments);
        if ($template !== null) {
            return $template;
        }

        return $this->nestedString($this->mandatoryFileGroup($locale, $group), $segments);
    }

    /** @return list<string> */
    public function mandatoryKeys(): array
    {
        if ($this->mandatoryKeys !== null) {
            return $this->mandatoryKeys;
        }

        $contract = $this->mandatoryContract();
        $families = $contract['families'] ?? null;
        if (! is_array($families) || $families === []) {
            throw new RuntimeException('Mandatory localization contract must define namespace families.');
        }

        $keys = [];
        foreach ($families as $family => $relativeKeys) {
            if (! is_string($family) || preg_match('/\A[A-Za-z0-9_][A-Za-z0-9_-]*\z/', $family) !== 1) {
                throw new RuntimeException('Mandatory localization contract contains an invalid namespace family.');
            }
            if (! is_array($relativeKeys) || $relativeKeys === []) {
                throw new RuntimeException('Mandatory localization namespace family must contain keys.');
            }

            foreach ($relativeKeys as $relativeKey) {
                if (! is_string($relativeKey) || preg_match('/\A[A-Za-z0-9_][A-Za-z0-9_.-]*\z/', $relativeKey) !== 1) {
                    throw new RuntimeException('Mandatory localization contract contains an invalid relative key.');
                }

                $key = $family.'.'.$relativeKey;
                $this->assertKey($key);
                $keys[] = $key;
            }
        }

        if (count($keys) !== count(array_unique($keys))) {
            throw new RuntimeException('Mandatory localization contract contains duplicate keys.');
        }

        sort($keys);

        return $this->mandatoryKeys = $keys;
    }

    /**
     * @return array{placeholders: list<string>, parse_mode: string, max_length: int, contexts: list<string>}
     */
    public function metadata(string $key): array
    {
        $this->assertKey($key);
        if (! in_array($key, $this->mandatoryKeys(), true)) {
            throw new InvalidArgumentException('Localization key is not part of the mandatory metadata contract.');
        }

        $contract = $this->mandatoryContract();
        $parseMode = $contract['parse_mode'] ?? null;
        $messageMaxLength = $contract['message_max_length'] ?? null;
        $buttonMaxLength = $contract['button_max_length'] ?? null;
        if ($parseMode !== 'plain_text'
            || ! is_int($messageMaxLength) || $messageMaxLength < 1 || $messageMaxLength > self::MAX_TEMPLATE_LENGTH
            || ! is_int($buttonMaxLength) || $buttonMaxLength < 1 || $buttonMaxLength > $messageMaxLength) {
            throw new RuntimeException('Mandatory localization metadata limits are invalid.');
        }

        $buttonKeys = $this->contractKeyList('button_keys');
        $mediaCaptionKeys = $this->contractKeyList('media_caption_keys');
        $keyMaxLengths = $this->contractKeyIntMap('key_max_lengths');
        $renderedMaxLengths = $this->contractKeyIntMap('rendered_max_lengths');
        $placeholderMaxLengths = $this->contractPlaceholderIntMap('placeholder_max_lengths');
        foreach (array_merge($buttonKeys, $mediaCaptionKeys, array_keys($keyMaxLengths), array_keys($renderedMaxLengths), array_keys($placeholderMaxLengths)) as $configuredKey) {
            if (! in_array($configuredKey, $this->mandatoryKeys(), true)) {
                throw new RuntimeException('Mandatory localization metadata references an undeclared key.');
            }
        }

        if (in_array($key, $buttonKeys, true) && in_array($key, $mediaCaptionKeys, true)) {
            throw new RuntimeException('Mandatory localization key cannot be both button-only and media-caption capable.');
        }

        $english = $this->template($key, 'en');
        $persian = $this->template($key, 'fa');
        $placeholders = $this->placeholders($english);
        if ($this->placeholders($persian) !== $placeholders) {
            throw new RuntimeException('Mandatory localization locale placeholders do not match.');
        }

        $isButton = in_array($key, $buttonKeys, true);
        $maxLength = $keyMaxLengths[$key] ?? ($isButton ? $buttonMaxLength : $messageMaxLength);
        if (mb_strlen($english) > $maxLength || mb_strlen($persian) > $maxLength) {
            throw new RuntimeException('Mandatory localization default exceeds its documented maximum length.');
        }

        if (array_key_exists($key, $renderedMaxLengths)) {
            $placeholderLimits = $placeholderMaxLengths[$key] ?? [];
            $this->assertRenderedBudgetConfiguration($placeholders, $placeholderLimits);
            if (! $this->usesCanonicalBudgetPlaceholderCasing($english)
                || ! $this->usesCanonicalBudgetPlaceholderCasing($persian)) {
                throw new RuntimeException('Mandatory localization rendered budget requires lowercase placeholder spelling.');
            }
            if ($this->renderedLengthUpperBound($english, $placeholderLimits) > $renderedMaxLengths[$key]
                || $this->renderedLengthUpperBound($persian, $placeholderLimits) > $renderedMaxLengths[$key]) {
                throw new RuntimeException('Mandatory localization default exceeds its rendered maximum length.');
            }
        } elseif (array_key_exists($key, $placeholderMaxLengths)) {
            throw new RuntimeException('Mandatory localization placeholder limits require a rendered maximum length.');
        }

        $contexts = $isButton ? ['button'] : ['message'];
        if (in_array($key, $mediaCaptionKeys, true)) {
            $contexts[] = 'media_caption';
        }

        return [
            'placeholders' => $placeholders,
            'parse_mode' => $parseMode,
            'max_length' => $maxLength,
            'contexts' => $contexts,
        ];
    }

    public function validateOverride(string $key, string $locale, string $value): string
    {
        $default = $this->template($key, $locale);
        $metadata = in_array($key, $this->mandatoryKeys(), true) ? $this->metadata($key) : null;
        $maxLength = $metadata['max_length'] ?? self::MAX_TEMPLATE_LENGTH;

        if ($value === '' || trim($value) === '' || mb_strlen($value) > $maxLength) {
            throw new InvalidArgumentException('Localization override value is invalid.');
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException('Localization override value contains unsupported control characters.');
        }

        $expectedPlaceholders = $metadata['placeholders'] ?? $this->placeholders($default);
        if ($this->placeholders($value) !== $expectedPlaceholders) {
            throw new InvalidArgumentException('Localization override placeholders must exactly match the file-backed template.');
        }

        $renderedMaxLengths = $this->contractKeyIntMap('rendered_max_lengths');
        if (array_key_exists($key, $renderedMaxLengths)) {
            $placeholderLimits = $this->contractPlaceholderIntMap('placeholder_max_lengths')[$key] ?? [];
            $this->assertRenderedBudgetConfiguration($expectedPlaceholders, $placeholderLimits);
            if (! $this->usesCanonicalBudgetPlaceholderCasing($value)) {
                throw new InvalidArgumentException('Localization override rendered budget requires lowercase placeholder spelling.');
            }
            if ($this->renderedLengthUpperBound($value, $placeholderLimits) > $renderedMaxLengths[$key]) {
                throw new InvalidArgumentException('Localization override rendered value exceeds its documented maximum length.');
            }
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

    /**
     * @param  array<string, mixed>|null  $values
     * @param  list<string>  $segments
     */
    private function nestedString(?array $values, array $segments): ?string
    {
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

    /** @return array<string, mixed>|null */
    private function fileGroup(string $locale, string $group): ?array
    {
        $cacheKey = $locale.'|'.$group;
        if (array_key_exists($cacheKey, $this->loadedGroups)) {
            return $this->loadedGroups[$cacheKey];
        }

        $path = $this->resourceRoot().'/'.$locale.'/'.$group.'.php';
        if (! $this->files->isFile($path)) {
            return $this->loadedGroups[$cacheKey] = null;
        }

        $values = $this->files->getRequire($path);
        if (! is_array($values)) {
            return $this->loadedGroups[$cacheKey] = null;
        }

        return $this->loadedGroups[$cacheKey] = $values;
    }

    /** @return array<string, mixed>|null */
    private function mandatoryFileGroup(string $locale, string $group): ?array
    {
        $cacheKey = $locale.'|_mandatory|'.$group;
        if (array_key_exists($cacheKey, $this->loadedGroups)) {
            return $this->loadedGroups[$cacheKey];
        }

        $path = $this->resourceRoot().'/'.$locale.'/_mandatory.php';
        if (! $this->files->isFile($path)) {
            return $this->loadedGroups[$cacheKey] = null;
        }

        $values = $this->files->getRequire($path);
        if (! is_array($values)) {
            throw new RuntimeException('Mandatory localization defaults must return an array.');
        }

        $groupValues = $values[$group] ?? null;
        if ($groupValues !== null && ! is_array($groupValues)) {
            throw new RuntimeException('Mandatory localization namespace default must be an array.');
        }

        return $this->loadedGroups[$cacheKey] = $groupValues;
    }

    /** @return array<string, mixed> */
    private function mandatoryContract(): array
    {
        if ($this->mandatoryContract !== null) {
            return $this->mandatoryContract;
        }

        $path = $this->resourceRoot().'/_mandatory.php';
        if (! $this->files->isFile($path)) {
            throw new RuntimeException('Mandatory localization contract is missing.');
        }

        $contract = $this->files->getRequire($path);
        if (! is_array($contract)) {
            throw new RuntimeException('Mandatory localization contract must return an array.');
        }

        return $this->mandatoryContract = $contract;
    }

    /** @return list<string> */
    private function contractKeyList(string $name): array
    {
        $value = $this->mandatoryContract()[$name] ?? null;
        if (! is_array($value)) {
            throw new RuntimeException('Mandatory localization metadata key list is missing.');
        }

        $keys = [];
        foreach ($value as $key) {
            if (! is_string($key)) {
                throw new RuntimeException('Mandatory localization metadata key list is invalid.');
            }
            $keys[] = $key;
        }

        if (count($keys) !== count(array_unique($keys))) {
            throw new RuntimeException('Mandatory localization metadata key list contains duplicates.');
        }

        return $keys;
    }

    /** @return array<string, int> */
    private function contractKeyIntMap(string $name): array
    {
        $value = $this->mandatoryContract()[$name] ?? [];
        if (! is_array($value)) {
            throw new RuntimeException('Mandatory localization metadata key limit map is invalid.');
        }
        $limits = [];
        foreach ($value as $key => $limit) {
            if (! is_string($key) || ! is_int($limit) || $limit < 1 || $limit > self::MAX_TEMPLATE_LENGTH) {
                throw new RuntimeException('Mandatory localization metadata key limit map is invalid.');
            }
            $this->assertKey($key);
            if (! in_array($key, $this->mandatoryKeys(), true)) {
                throw new RuntimeException('Mandatory localization metadata references an undeclared key.');
            }
            $limits[$key] = $limit;
        }

        return $limits;
    }

    /** @return array<string, array<string, int>> */
    private function contractPlaceholderIntMap(string $name): array
    {
        $value = $this->mandatoryContract()[$name] ?? [];
        if (! is_array($value)) {
            throw new RuntimeException('Mandatory localization placeholder limit map is invalid.');
        }
        $limits = [];
        foreach ($value as $key => $placeholderLimits) {
            if (! is_string($key) || ! is_array($placeholderLimits)) {
                throw new RuntimeException('Mandatory localization placeholder limit map is invalid.');
            }
            $this->assertKey($key);
            if (! in_array($key, $this->mandatoryKeys(), true)) {
                throw new RuntimeException('Mandatory localization metadata references an undeclared key.');
            }
            $limits[$key] = [];
            foreach ($placeholderLimits as $placeholder => $limit) {
                if (! is_string($placeholder) || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $placeholder) !== 1
                    || $placeholder !== strtolower($placeholder) || ! is_int($limit)
                    || $limit < 1 || $limit > self::MAX_TEMPLATE_LENGTH) {
                    throw new RuntimeException('Mandatory localization placeholder limit map is invalid.');
                }
                $limits[$key][$placeholder] = $limit;
            }
        }

        return $limits;
    }

    /** @param list<string> $placeholders
     * @param  array<string, int>  $placeholderLimits
     */
    private function assertRenderedBudgetConfiguration(array $placeholders, array $placeholderLimits): void
    {
        $configured = array_keys($placeholderLimits);
        sort($configured);
        if ($configured !== $placeholders) {
            throw new RuntimeException('Mandatory localization rendered budget must define every placeholder limit exactly once.');
        }
    }

    private function usesCanonicalBudgetPlaceholderCasing(string $template): bool
    {
        preg_match_all('/:([A-Za-z_][A-Za-z0-9_]*)/', $template, $matches);
        foreach ($matches[1] as $rawPlaceholder) {
            if ($rawPlaceholder !== strtolower($rawPlaceholder)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, int> $placeholderLimits */
    private function renderedLengthUpperBound(string $template, array $placeholderLimits): int
    {
        preg_match_all('/:([A-Za-z_][A-Za-z0-9_]*)/', $template, $matches, PREG_SET_ORDER);
        $length = mb_strlen($template);
        foreach ($matches as $match) {
            $placeholder = strtolower((string) $match[1]);
            $limit = $placeholderLimits[$placeholder] ?? null;
            if (! is_int($limit)) {
                throw new RuntimeException('Mandatory localization rendered budget is missing a placeholder limit.');
            }
            $length += $limit - mb_strlen((string) $match[0]);
        }

        return $length;
    }

    private function resourceRoot(): string
    {
        return rtrim($this->resourceRoot ?? resource_path('lang'), '/');
    }
}
