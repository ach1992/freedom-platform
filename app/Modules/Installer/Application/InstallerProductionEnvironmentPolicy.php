<?php

declare(strict_types=1);

namespace App\Modules\Installer\Application;

use Dotenv\Dotenv;
use Dotenv\Exception\InvalidFileException;
use Dotenv\Parser\Parser;
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

    /** @return list<string> */
    public static function sensitiveKeys(): array
    {
        return self::REQUIRED_PRODUCTION_KEYS;
    }

    /** @requirement INS-001 SEC-003 SEC-007 SEC-008 QUA-011 */
    public function assertSafe(string $contents): void
    {
        try {
            $entries = (new Parser)->parse($contents);
            $values = Dotenv::parse($contents);
        } catch (InvalidFileException) {
            throw new RuntimeException('The installer environment file is invalid.');
        }

        $counts = [];
        $interpolated = [];

        foreach ($entries as $entry) {
            $name = $entry->getName();

            if (! in_array($name, self::REQUIRED_PRODUCTION_KEYS, true)) {
                continue;
            }

            $counts[$name] = ($counts[$name] ?? 0) + 1;
            $entryValue = $entry->getValue();

            if ($entryValue->isDefined() && $entryValue->get()->getVars() !== []) {
                $interpolated[$name] = true;
            }
        }

        if (($counts['APP_ENV'] ?? 0) > 1) {
            throw new RuntimeException(
                'The installer production environment must define each security-critical setting exactly once.',
            );
        }

        $productionBoot = $this->runtimeEnvironment === 'production';
        $productionTarget = ($values['APP_ENV'] ?? null) === 'production';

        if (! $productionBoot && ! $productionTarget) {
            return;
        }

        foreach (self::REQUIRED_PRODUCTION_KEYS as $key) {
            if (($counts[$key] ?? 0) !== 1 || ! array_key_exists($key, $values) || $values[$key] === null) {
                throw new RuntimeException(
                    'The installer production environment must define each security-critical setting exactly once.',
                );
            }

            if ($interpolated[$key] ?? false) {
                throw new RuntimeException(
                    'The installer production environment must use literal security-critical values.',
                );
            }
        }

        if ($values['APP_ENV'] !== 'production'
            || (bool) $this->laravelEnvironmentValue($values['APP_DEBUG'])
            || ! $this->isSecureApplicationUrl($values['APP_URL'])
            || $this->laravelEnvironmentValue($values['SESSION_ENCRYPT']) !== true
            || $this->laravelEnvironmentValue($values['SESSION_SECURE_COOKIE']) !== true
        ) {
            throw new RuntimeException('The installer production environment is not safe to finalize.');
        }
    }

    private function laravelEnvironmentValue(string $value): mixed
    {
        return match (strtolower($value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'empty', '(empty)' => '',
            'null', '(null)' => null,
            default => $value,
        };
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
