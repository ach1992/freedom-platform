<?php

declare(strict_types=1);

namespace App\Modules\Localization\Application;

use Illuminate\Database\DatabaseManager;

final readonly class LocalizationResolver
{
    public function __construct(
        private DatabaseManager $database,
        private LocalizationTemplateCatalog $templates,
    ) {}

    /** @param array<string, bool|float|int|string> $replacements */
    public function resolve(string $key, array $replacements = [], ?string $locale = null): string
    {
        $resolvedLocale = $locale ?? 'fa';
        $this->templates->assertKey($key);
        $this->templates->assertLocale($resolvedLocale);

        $override = $this->database->connection()->table('localization_overrides')
            ->where('translation_key', $key)
            ->where('locale', $resolvedLocale)
            ->value('override_value');

        if (is_string($override)) {
            return $this->templates->render($override, $replacements);
        }

        $requestedDefault = $this->templates->templateOrNull($key, $resolvedLocale);
        if ($requestedDefault !== null) {
            return $this->templates->render($requestedDefault, $replacements);
        }

        $englishDefault = $this->templates->templateOrNull($key, 'en');
        if ($englishDefault !== null) {
            return $this->templates->render($englishDefault, $replacements);
        }

        return '['.$key.']';
    }
}
