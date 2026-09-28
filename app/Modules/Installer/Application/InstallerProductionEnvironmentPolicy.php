<?php

declare(strict_types=1);

namespace App\Modules\Installer\Application;

use RuntimeException;

final readonly class InstallerProductionEnvironmentPolicy
{
    /** @var list<string> */
    private const REQUIRED_PRODUCTION_KEYS = [
        'APP_ENV',
        'APP_DEBUG',
        'APP_URL',
        'SESSION_ENCRYPT',
        'SESSION_SECURE_COOKIE',
    ];

    public function __construct(private string $runtimeEnvironment) {}

    /** @requirement INS-001 SEC-003 SEC-007 SEC-008 QUA-011 */
    public function assertSafe(string $contents): void
    {
        $appEnvironmentValues = $this->valuesFor($contents, 'APP_ENV');
        $productionBoot = strtolower(trim($this->runtimeEnvironment)) === 'production';
        $productionTarget = false;

        foreach ($appEnvironmentValues as $appEnvironmentValue) {
            if (strtolower($this->decode($appEnvironmentValue)) === 'production') {
                $productionTarget = true;
                break;
            }
        }

        if (! $productionBoot && ! $productionTarget) {
            return;
        }

        $values = [];

        foreach (self::REQUIRED_PRODUCTION_KEYS as $key) {
            $matches = $this->valuesFor($contents, $key);

            if (count($matches) !== 1) {
                throw new RuntimeException(
                    'The installer production environment must define each security-critical setting exactly once.',
                );
            }

            $values[$key] = $this->decode($matches[0]);
        }

        if ($values['APP_ENV'] !== 'production'
            || ! $this->isFalse($values['APP_DEBUG'])
            || ! $this->isSecureApplicationUrl($values['APP_URL'])
            || ! $this->isTrue($values['SESSION_ENCRYPT'])
            || ! $this->isTrue($values['SESSION_SECURE_COOKIE'])
        ) {
            throw new RuntimeException('The installer production environment is not safe to finalize.');
        }
    }

    /** @return list<string> */
    private function valuesFor(string $contents, string $key): array
    {
        $pattern = '/^\s*(?:export\s+)?'.preg_quote($key, '/').'\s*=\s*(.*?)\s*$/';
        $values = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            if (preg_match($pattern, $line, $matches) === 1) {
                $values[] = $matches[1];
            }
        }

        return $values;
    }

    private function decode(string $value): string
    {
        $value = trim($value);

        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];

            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        return trim($value);
    }

    private function isTrue(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['true', '(true)', '1'], true);
    }

    private function isFalse(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['false', '(false)', '0'], true);
    }

    private function isSecureApplicationUrl(string $value): bool
    {
        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($value);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && is_string($parts['host'] ?? null)
            && $parts['host'] !== ''
            && ! isset($parts['user'])
            && ! isset($parts['pass']);
    }
}
